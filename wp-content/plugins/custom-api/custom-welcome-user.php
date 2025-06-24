<?php
/*
Plugin Name: Welcomed Users Manager
Description: Stores and displays welcomed users with name and phone in the admin dashboard.
Version: 1.0
Author: Wunderfauks
*/

// Create table on plugin activation
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'bot_welcomed_users';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        welcomed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});

// Add admin menu
add_action('admin_menu', function () {
    add_menu_page(
        'Welcomed Users',
        'Welcomed Users',
        'manage_options',
        'welcomed-users',
        'welcomed_users_admin_page',
        'dashicons-smiley',
        25
    );
});

function welcomed_users_admin_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'bot_welcomed_users';

    // Handle deletion
    if (isset($_GET['delete_user'])) {
        $id = intval($_GET['delete_user']);
        $wpdb->delete($table, ['id' => $id]);
        echo '<div class="updated"><p>User deleted successfully.</p></div>';
    }

    // Handle broadcast form submission (just demo, no actual send yet)
    if (isset($_POST['broadcast_users']) && check_admin_referer('broadcast_welcomed_users')) {
        $selected_users = $_POST['selected_users'] ?? [];
        if (empty($selected_users)) {
            echo '<div class="error"><p>No users selected for broadcast.</p></div>';
        } else {
            echo '<div class="updated"><p>Selected user IDs for broadcast: ' . esc_html(implode(', ', $selected_users)) . '</p></div>';
            // TODO: Implement actual broadcast sending here
        }
    }

    // Fetch users
    $users = $wpdb->get_results("SELECT * FROM $table ORDER BY welcomed_at DESC");

    echo '<div class="wrap">';
    echo '<h1>Welcomed Users</h1>';

    if (!$users) {
        echo '<p>No users have been welcomed yet.</p>';
    } else {
        // Begin form
        echo '<form method="post">';
        wp_nonce_field('broadcast_welcomed_users');

        // echo '<p><input type="submit" name="broadcast_users" class="button button-primary" value="Send Broadcast Message to Selected"></p>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width: 30px;"><input type="checkbox" id="select-all-users"></th>';
        echo '<th>ID</th><th>Name</th><th>Phone</th><th>Welcomed At</th><th>Actions</th></tr></thead><tbody>';

        foreach ($users as $user) {
            $phone_number = explode('@', $user->phone)[0];
            echo '<tr>';
            echo '<td><input type="checkbox" name="selected_users[]" value="' . esc_attr($user->id) . '"></td>';
            echo '<td>' . esc_html($user->id) . '</td>';
            echo '<td>' . esc_html($user->name) . '</td>';
            echo '<td>' . esc_html($phone_number) . '</td>';
            echo '<td>' . esc_html($user->welcomed_at) . '</td>';
            echo '<td><a href="' . admin_url('admin.php?page=welcomed-users&delete_user=' . $user->id) . '" class="button" onclick="return confirm(\'Delete this user?\')">Delete</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // echo '<p><input type="submit" name="broadcast_users" class="button button-primary" value="Send Broadcast Message to Selected"></p>';

        echo '</form>';

        // Add JS for "select all" checkbox
        ?>
        <script>
            document.getElementById('select-all-users').addEventListener('click', function(event) {
                const checked = event.target.checked;
                document.querySelectorAll('input[name="selected_users[]"]').forEach(cb => {
                    cb.checked = checked;
                });
            });
        </script>
        <?php
    }

    echo '</div>';
}

