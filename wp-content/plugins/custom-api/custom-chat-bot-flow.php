<?php
/*
Plugin Name: Chatbot Flow CRUD
Description: Chatbot Flow system with proper CRUD admin pages.
Version: 1.0
Author: Wunderfauks
*/

// Create the table
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'bot_responses';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        keywords TEXT NOT NULL,
        response TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});

// Admin Menu
add_action('admin_menu', function () {
    add_menu_page('Chatbot Flow', 'Chatbot Flow', 'manage_options', 'chatbot-flow', 'chatbot_flow_list_page', 'dashicons-format-chat');
    add_submenu_page(null, 'Create Response', 'Create Response', 'manage_options', 'chatbot-flow-create', 'chatbot_flow_create_page');
    add_submenu_page(null, 'Edit Response', 'Edit Response', 'manage_options', 'chatbot-flow-edit', 'chatbot_flow_edit_page');
});

// Common: save logic
function chatbot_flow_handle_save($edit_id = null)
{
    global $wpdb;
    $table = $wpdb->prefix . 'bot_responses';
    $keywords = isset($_POST['keywords']) ? sanitize_text_field(wp_unslash($_POST['keywords'])) : '';
    $response = isset($_POST['response']) ? sanitize_textarea_field(wp_unslash($_POST['response'])) : '';

    if ($edit_id) {
        $wpdb->update($table, ['keywords' => $keywords, 'response' => $response], ['id' => $edit_id]);
    } else {
        $wpdb->insert($table, ['keywords' => $keywords, 'response' => $response]);
    }

    $url = add_query_arg(
        [
            'page' => 'chatbot-flow',
            'msg'  => 'saved',
        ],
        admin_url('admin.php')
    );

    echo '<script>
                    window.location.href = "' . $url . '";
              </script>';
    exit;
}

// List page
function chatbot_flow_list_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'bot_responses';

    if (isset($_GET['delete'])) {
        $wpdb->delete($table, ['id' => intval($_GET['delete'])]);
        echo '<div class="updated"><p>Deleted successfully.</p></div>';
    } elseif (isset($_GET['msg']) && $_GET['msg'] == 'saved') {
        echo '<div class="updated"><p>Saved successfully.</p></div>';
    }

    $results = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");

    echo '<div class="wrap"><h1>Chatbot Responses <a href="' . admin_url('admin.php?page=chatbot-flow-create') . '" class="page-title-action">Add New</a></h1>';
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>Keywords</th><th>Response</th><th>Actions</th></tr></thead><tbody>';
    if ($results) {
        foreach ($results as $r) {
            echo '<tr>';
            echo '<td>' . $r->id . '</td>';
            echo '<td>' . esc_html($r->keywords) . '</td>';
            echo '<td>' . esc_html($r->response) . '</td>';
            echo '<td>
                    <a href="' . admin_url('admin.php?page=chatbot-flow-edit&id=' . $r->id) . '" class="button">Edit</a>
                    <a href="' . admin_url('admin.php?page=chatbot-flow&delete=' . $r->id) . '" class="button" onclick="return confirm(\'Delete?\')">Delete</a>
                  </td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="4">No responses found.</td></tr>';
    }
    echo '</tbody></table></div>';
}

// Create page
function chatbot_flow_create_page()
{
    if (isset($_POST['save_response'])) {
        chatbot_flow_handle_save();
    }
?>
    <div class="wrap">
        <h1>Add New Response</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="keywords">Keywords</label></th>
                    <td>
                        <input name="keywords" type="text" class="regular-text" required>     
                        <p class="description" style="margin-top: 4px;">
                            You can enter multiple keywords separated by commas.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="response">Response</label></th>
                    <td>
                        <textarea name="response" class="large-text" rows="4" required></textarea>
                    
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="save_response" class="button button-primary" value="Save Response">
                <a href="<?php echo admin_url('admin.php?page=chatbot-flow'); ?>" class="button">Back</a>
            </p>
        </form>
    </div>
<?php
}

// Edit page
function chatbot_flow_edit_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'bot_responses';
    $id = intval($_GET['id'] ?? 0);
    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

    if (!$item) {
        echo '<div class="error"><p>Response not found.</p></div>';
        return;
    }

    if (isset($_POST['save_response'])) {
        chatbot_flow_handle_save($id);
    }

?>
    <div class="wrap">
        <h1>Edit Response</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="keywords">Keywords</label></th>
                    <td>
                        <input name="keywords" type="text" class="regular-text" required value="<?php echo esc_attr($item->keywords); ?>">
                        <p class="description" style="margin-top: 4px;">
                                You can enter multiple keywords separated by commas.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="response">Response</label></th>
                    <td>
                        <textarea name="response" class="large-text" rows="4" required><?php echo esc_textarea($item->response); ?></textarea>
                     
                </td>
                </tr>
            </table>
            <p><input type="submit" name="save_response" class="button button-primary" value="Update Response">
                <a href="<?php echo admin_url('admin.php?page=chatbot-flow'); ?>" class="button">Back</a>
            </p>
        </form>
    </div>
<?php
}
