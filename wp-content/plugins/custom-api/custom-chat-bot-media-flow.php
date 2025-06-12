<?php
/*
Plugin Name: Chatbot Media CRUD
Description: Manage WhatsApp media logs with CRUD admin pages.
Version: 1.0
Author: Wunderfauks
*/

// Create the table on plugin activation
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'chatbot_media';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sender VARCHAR(100) NOT NULL,
        media_urls LONGTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});

// Admin Menu
add_action('admin_menu', function () {
    add_menu_page(
        'Chatbot Media Logs',
        'Media Logs',
        'manage_options',
        'chatbot-media-logs',
        'chatbot_media_list_page',
        'dashicons-format-image'
    );

    add_submenu_page(null, 'Add Media Log', 'Add Media Log', 'manage_options', 'chatbot-media-add', 'chatbot_media_add_page');
    add_submenu_page(null, 'Edit Media Log', 'Edit Media Log', 'manage_options', 'chatbot-media-edit', 'chatbot_media_edit_page');
});

// Handle insert/update save logic
function chatbot_media_handle_save($edit_id = null)
{
    ob_start();
    global $wpdb;
    $table = $wpdb->prefix . 'chatbot_media';

    // $sender = sanitize_text_field($_POST['sender']);
    // $media_urls = sanitize_textarea_field($_POST['media_urls']);
    $status = sanitize_text_field($_POST['status']);

    if ($edit_id) {
        $wpdb->update(
            $table,
            [
                'status' => $status,
                'uploaded_at' => current_time('mysql'),
            ],
            ['id' => $edit_id]
        );
    }
    // else {
    //     $wpdb->insert(
    //         $table,
    //         [
    //             'status' => $status,
    //             'uploaded_at' => current_time('mysql'),
    //         ]
    //     );
    // }

    if ($edit_id) {
        $url = add_query_arg(
            [
                'page' => 'chatbot-media-edit',
                'id'   => $edit_id,
                'msg'  => 'updated',
            ],
            admin_url('admin.php')
        );

        echo '<script>
                    window.location.href = "' . $url . '";
              </script>';
        exit;
    } else {
        echo '<script>window.location.href="' . esc_url(admin_url('admin.php?page=chatbot-media-logs&msg=saved')) . '"</script>';
        exit;
    }
}

// List page
function chatbot_media_list_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'chatbot_media';

    if (isset($_GET['delete'])) {
        $wpdb->delete($table, ['id' => intval($_GET['delete'])]);
        echo '<div class="updated"><p>Deleted successfully.</p></div>';
    } elseif (isset($_GET['msg']) && $_GET['msg'] === 'saved') {
        echo '<div class="updated"><p>Saved successfully.</p></div>';
    }

    $results = $wpdb->get_results("SELECT * FROM $table ORDER BY uploaded_at DESC");

    echo '<div class="wrap">';
    // echo '<h1>Chatbot Media Logs <a href="' . admin_url('admin.php?page=chatbot-media-add') . '" class="page-title-action">Add New</a></h1>';

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Sender</th><th>Uploaded At</th><th>Media URLs</th><th>Status</th><th>Actions</th></tr></thead><tbody>';

    if ($results) {
        foreach ($results as $row) {
            $phone_number = explode('@', $row->sender)[0];

            echo '<tr>';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . esc_html($phone_number) . '</td>';
            echo '<td>' . esc_html($row->uploaded_at) . '</td>';

            $raw = $row->media_urls;

            // Remove escaping backslashes if present
            $raw = stripslashes($raw);

            // Unserialize safely
            $media_urls = maybe_unserialize($raw);

            echo '<td>';
            if (is_array($media_urls)) {
                foreach ($media_urls as $url) {
                    echo '<img src="' . esc_url($url) . '" style="max-width:100px; height:auto; margin-bottom:5px; display:block;">';
                }
            } else {
                // fallback if not an array
                echo esc_html($row->media_urls);
            }
            echo '</td>';

            echo '<td>' . esc_html(ucfirst($row->status)) . '</td>';
            echo '<td>
            <a href="' . admin_url('admin.php?page=chatbot-media-edit&id=' . $row->id) . '" class="button">Edit</a> 
            <a href="' . admin_url('admin.php?page=chatbot-media-logs&delete=' . $row->id) . '" class="button" onclick="return confirm(\'Delete this log?\')">Delete</a>
          </td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No media logs found.</td></tr>';
    }

    echo '</tbody></table></div>';
}

// Add page
function chatbot_media_add_page()
{
    if (isset($_POST['save_media'])) {
        chatbot_media_handle_save();
    }
?>
    <div class="wrap">
        <h1>Add New Media Log</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="sender">Sender</label></th>
                    <td><input name="sender" type="text" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="media_urls">Media URLs</label></th>
                    <td><textarea name="media_urls" rows="5" class="large-text" required></textarea></td>
                </tr>
                <tr>
                    <th><label for="status">Status</label></th>
                    <td>
                        <select name="status" required>
                            <option value="pending" selected>Pending</option>
                            <option value="approve">Approve</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p>
                <input type="submit" name="save_media" class="button button-primary" value="Save Media Log">
                <a href="<?php echo admin_url('admin.php?page=chatbot-media-logs'); ?>" class="button">Back</a>
            </p>
        </form>
    </div>
<?php
}

// Edit page
function chatbot_media_edit_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'chatbot_media';
    $id = intval($_GET['id'] ?? 0);
    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

    if (!$item) {
        echo '<div class="error"><p>Media log not found.</p></div>';
        return;
    }

    if (isset($_POST['save_media'])) {
        chatbot_media_handle_save($id);
    }

    if(isset($_GET['msg']) && isset($_GET['msg']) == "updated") {
        echo '<div class="updated"><p>Updated successfully.</p></div>';
    }

    $statuses = ['pending' => 'Pending', 'approve' => 'Approve', 'rejected' => 'Rejected'];
    
?>
    <div class="wrap">
        <h1>Edit Media Log</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="sender">Sender</label></th>
                    <td><input disabled name="sender" type="text" class="regular-text" required value="<?php echo esc_attr(explode('@', $item->sender)[0]); ?>"></td>
                </tr>
                <tr>
                    <th><label for="media_urls">Media URLs</label></th>
                    <td>
                        <?php
                        // Remove escaping slashes from the stored serialized string
                        $raw = stripslashes($item->media_urls);

                        // Unserialize safely
                        $media_urls = maybe_unserialize($raw);

                        if (is_array($media_urls)) {
                            foreach ($media_urls as $url) {
                                echo '<img src="' . esc_url($url) . '" style="max-width:100px; height:auto; margin-bottom:5px; display:block;">';
                            }
                        } else {
                            echo '<img src="' . esc_url($item->media_urls) . '" style="max-width:100px; height:auto; margin-bottom:5px; display:block;">';
                        }
                        ?>

                    </td>

                </tr>
                <tr>
                    <th><label for="status">Status</label></th>
                    <td>
                        <select name="status" required>
                            <?php
                            foreach ($statuses as $key => $label) {
                                $selected = ($item->status === $key) ? 'selected' : '';
                                echo '<option value="' . esc_attr($key) . '" ' . $selected . '>' . esc_html($label) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p>
                <input type="submit" name="save_media" class="button button-primary" value="Update Media Log">
                <a href="<?php echo admin_url('admin.php?page=chatbot-media-logs'); ?>" class="button">Back</a>
            </p>
        </form>
    </div>
<?php
}
