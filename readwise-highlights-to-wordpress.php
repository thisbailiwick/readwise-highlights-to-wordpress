<?php
/*
Plugin Name: Readwise Highlights to WordPress
Description: This plugin fetches Readwise highlights and creates a new WordPress post for each one.
Version: 1.0
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Add function to fetch Readwise highlights and save as WP posts
function update_readwise_highlights()
{
    $new_name_sets_processed = getNewNameSetsProcessed();
    $lastFetchWasAt = current_time('Y-m-d\TH:i:s.v\Z', true);
    $data = fetchNewReadwise();

    while ($data['nextPageCursor'] !== null) {
        $data = fetchNewReadwise($lastFetchWasAt);
    }

    processAnyHighlights($data['results'], $new_name_sets_processed);

    update_option('rwhtwp_time_last_checked', date('Y-m-d\TH:i:s\Z'));

    wp_send_json_success($data);
}

/**
 * @return array
 */
function getNewNameSetsProcessed(): array
{
    $new_name_sets = get_option('rwhtwp_new_name_sets', '');
    $new_name_sets = explode("\n", $new_name_sets);
    $new_name_sets_processed = [];

    if (is_array($new_name_sets)) {

        foreach ($new_name_sets as $set) {
            list($original_name, $new_name) = explode('===', $set);
            $original_name = trim($original_name);
            $new_name = trim($new_name);
            $new_name_sets_processed[$original_name] = $new_name;
        }
    }
    return $new_name_sets_processed;
}

function possiblyReplaceName($author, $new_name_sets_processed)
{
    if (array_key_exists($author, $new_name_sets_processed)) {
        return $new_name_sets_processed[$author];
    }

    return $author;
}


/**
 * @param $results
 * @param $new_name_sets_processed
 * @return void
 */
function processAnyHighlights($results, $new_name_sets_processed): void
{
    $user_added_tags = trim(get_option('rwhtwp_user_added_tags'));
    $user_added_tags = $user_added_tags !== '' ? explode(',', $user_added_tags) : [];
    $user_removed_tags = trim(get_option('rwhtwp_user_removed_tags'));
    $user_removed_tags = $user_removed_tags !== '' ? explode(',', $user_removed_tags) : [];

    foreach ($results as $book) {
        $book['author'] = possiblyReplaceName($book['author'], $new_name_sets_processed);

        foreach ($book['highlights'] as $index => $highlight) {

            $tags = $user_added_tags;
            $create_post_tag_found = false;
            $post_status = 'draft';

            foreach ($highlight['tags'] as $tag) {

                if (strpos($tag['name'], 'wppost') !== false) {
                    unset($book['highlights'][$index]);
                    $create_post_tag_found = true;

                    if ($tag['name'] === 'wppost:publish') {
                        $post_status = 'publish';

                    } elseif ($tag['name'] === 'wppost:draft') {
                        $post_status = 'draft';
                    }

                } else {
                    $tags[] = $tag['name'];
                }
            }

            if (!$create_post_tag_found) {
                continue;
            }

            $tags = array_diff($tags, $user_removed_tags);

//            $url = $book['category'] === 'books' ? 'https://www.goodreads.com/search?utf8=%E2%9C%93&q=' . urlencode($book['title'] . ' ' . $book['author']) . '&search_type=books' : '';
            $url = $book['category'] === 'books' ? 'https://amazon.com/s?k==' . urlencode($book['title'] . ' ' . $book['author']) : $book['source_url'];


            $page = !empty($highlight['location']) && $book['category'] === 'books' ? 'page ' . wp_strip_all_tags($highlight['location']) : '';

            $content = <<<HTML
                <p>
                    "{$highlight['text']}"
                    <br />
                    <p><a href="{$url}"><em><b>{$book['title']}</b></em></a> {$page}
                    —{$book['author']}</p>
                </p>
HTML;
            $content = wp_kses_post($content);
            
            $image = $book['cover_image_url'];


            // Create post object
            $new_post = array(
                'post_title' => wp_strip_all_tags($book['title']),
                'post_content' => $content,
                'post_status' => $post_status,
                'post_author' => 1,
                'tags_input' => $tags,
                'post_format' => 'standard',
                'post_thumbnail' => $image
            );

            // Insert the post into the database
            $post_id = wp_insert_post($new_post);

            if ($post_id !== 0) {
                $meta_key = 'rwhtwp';   // Replace with your meta key
                $meta_value = 'your_meta_value'; // Replace with your meta value

                // Add the meta field
                add_post_meta($post_id, $meta_key, $meta_value, true);
            }

        }
    }
}

/**
 * @param WP_Error|array $response
 * @return false
 */
function checkForErrors($response)
{
    if (is_wp_error($response)) {
        error_log('Error fetching Readwise highlights: ' . $response->get_error_message());
        return true;
    }

    return false;
}

/**
 * @param bool|string $page_cursor
 * @return array|bool|WP_Error
 */
function fetchNewReadwise($page_cursor = false)
{
    $page_cursor = $page_cursor !== false ? '&pageCursor=' . $page_cursor : '';
    $time_last_checked = $_POST['fetchAll'] === 'true' ? '1970-01-01T00:00:00Z' : get_option('rwhtwp_time_last_checked', '1970-01-01T00:00:00Z');

    $readwise_access_token = get_option('rwhtwp_readwise_access_token', '');

    if (empty($readwise_access_token)) {
        return new WP_Error('no_access_token', 'Readwise access token needs to be set in plugin admin settings.');
    }

    $response = wp_remote_get('https://readwise.io/api/v2/export?updatedAfter=' . $time_last_checked . $page_cursor, [
        'headers' => [
            'Authorization' => 'Token ' . $readwise_access_token
        ]
    ]);

    // Check for errors
    $has_errors = checkForErrors($response);

    if ($has_errors) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($data) {
        return $data;
    }
}

function rwhtwp_add_options_page()
{
    add_options_page('Readwise Highlights to WordPress', 'Readwise Highlights to WordPress', 'manage_options', 'rwhtwp', 'rwhtwp_options_page');
}

add_action('admin_menu', 'rwhtwp_add_options_page');

function rwhtwp_options_page()
{
    $access_token = get_option('rwhtwp_readwise_access_token');

    $new_name_sets = get_option('rwhtwp_new_name_sets');
    $user_added_tags = get_option('rwhtwp_user_added_tags');
    $user_removed_tags = get_option('rwhtwp_user_removed_tags');

    ob_start();
    settings_fields('rwhtwp_options_group');
    $settings_fields = ob_get_contents();
    ob_end_clean();

    $html = <<<HTML
<style type="text/css">
.rwhtwp-wrap textarea {
    max-width: 400px;
    width: 100%;
}

.rwhtwp-wrap #rwhtwp-readwise-access-token{
    width: 25em;
</style>
<div class="rwhtwp-wrap">
    <h2>Readwise Highlights to WordPress</h2>
    <form method="post" action="options.php">
        {$settings_fields}
        <h3>Readwise Access Token</h3>
        <p>Enter your Readwise access token below. You can find your access token on the <a
                href="https://readwise.io/access-token">Readwise access token</a> page.</p>
        <table>
            <tr valign="top">
                <th scope="row"><label for="rwhtwp-readwise-access-token">Access Token</label></th>
                <td><input type="password" id="rwhtwp-readwise-access-token" name="rwhtwp_readwise_access_token"
                           value="{$access_token}"/></td>
            </tr>
        </table>
        <h3>Change Author Name</h3>
        <p>If the name of the author is not what is expected you can add the original author name and we will
            replace it with the new name.
            <br><b>The original name and new name should be separated by <code>===</code></b>.
            <br><b>Each set of replacement's should be on a new line.</b>
        </p>
        <div id="names-wrapper">
            <div class="name-group">
                <textarea id="rwhtwp-new-name-sets" name="rwhtwp_new_name_sets" type="textarea"
                          value="{$new_name_sets}">{$new_name_sets}</textarea>
            </div>
        </div>
        <h3>Add Tags</h3>
        <p>Enter a comma separated list of tags to add to each post.</p>
        <div id="tags-wrapper">
            <div class="name-group">
                <textarea id="rwhtwp-user-added-tags" name="rwhtwp_user_added_tags" type="textarea"
                          value="{$user_added_tags}">{$user_added_tags}</textarea>
            </div>
        </div>
        <h3>Remove Tags</h3>
        <p>Enter a comma separated list of tags to remove from highlights before creating post. Currently, this plugin adds all tags already added to the highlight from within Readwise (except for the <code>wppost:publish</code> or <code>wppost:draft</code> tags.</p>
        <div id="tags-wrapper">
            <div class="name-group">
                <textarea id="rwhtwp-user-added-tags" name="rwhtwp_user_removed_tags" type="textarea"
                          value="{$user_removed_tags}">{$user_removed_tags}</textarea>
            </div>
        </div>
        <p><input type="submit" name="submit" id="submit" class="button button-primary" value="Save"></p>
        <h3>Fetch Readwise Highlights</h3>
        <p>Click the button below to fetch Readwise highlights and create a new WordPress post for each one.</p>
        <button type="button" id="fetch-readwise-highlights" class="button button-primary">Fetch Readwise
            Highlights Since Last Update
        </button>
        <br>
        <p>Click the button below to fetch all Readwise highlights and create a new WordPress post for each one.
            <br><b>Warning: this is going to create posts for all your highlights, not just new ones from the last
                update!</b></p>
        <button type="button" id="fetch-all-readwise-highlights" class="button button-primary">Fetch All Readwise
            Highlights
        </button>
        <script>
            document.addEventListener("DOMContentLoaded", function () {

                function updateReadwise(fetchAll = false) {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '/wp-json/readwise-highlights-to-wordpress/v1/fetch', true);
                    xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

                    xhr.onload = function () {

                        if (xhr.status === 200) {
                            alert('Readwise highlights fetched successfully.');

                        } else {
                            alert('Error fetching Readwise highlights.');
                        }
                    };

                    xhr.onerror = function () {
                        alert('Error fetching Readwise highlights.');
                    };

                    var requestData = 'fetchAll=' + fetchAll;
                    xhr.send(requestData);
                }

                document.getElementById('fetch-readwise-highlights').addEventListener('click', function () {
                    updateReadwise();
                });

                document.getElementById('fetch-all-readwise-highlights').addEventListener('click', function () {
                    updateReadwise(true);
                });
            });
        </script>
    </form>
</div>
HTML;

    echo $html;
}

function rwhtwp_register_settings()
{
    register_setting('rwhtwp_options_group', 'rwhtwp_readwise_access_token');
    register_setting('rwhtwp_options_group', 'rwhtwp_original_name');
    register_setting('rwhtwp_options_group', 'rwhtwp_new_name');
    register_setting('rwhtwp_options_group', 'rwhtwp_new_name_sets');
    register_setting('rwhtwp_options_group', 'rwhtwp_user_added_tags');
    register_setting('rwhtwp_options_group', 'rwhtwp_user_removed_tags');
}

add_action('admin_init', 'rwhtwp_register_settings');


// add endpoint for manual fetch of Readwise highlights
add_action('rest_api_init', function () {
    register_rest_route('readwise-highlights-to-wordpress/v1', '/fetch', array(
        'methods' => 'POST',
        'callback' => 'update_readwise_highlights',
    ));
});


// Add WP Cron job to fetch Readwise highlights every hour
if (!wp_next_scheduled('update_readwise_highlights')) {
    wp_schedule_event(time(), 'hourly', 'update_readwise_highlights');
}
add_action('update_readwise_highlights', 'update_readwise_highlights');
?>