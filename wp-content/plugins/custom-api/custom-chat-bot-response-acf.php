<?php

/*
Plugin Name: Chatbot Response ACF
Description: Editable Response ACF
Version: 1.0
Author: Wunderfauks
*/

function register_chatbot_response_cpt() {
    register_post_type('chatbot_response', [
        'label' => 'Chatbot Responses',
        'public' => false,
        'show_ui' => true,
        'menu_icon' => 'dashicons-format-chat',
        'supports' => ['title'],
    ]);
}
add_action('init', 'register_chatbot_response_cpt');

// Add columns
add_filter('manage_chatbot_response_posts_columns', function ($columns) {
    $columns['match_type'] = 'Match Type';
    $columns['match_pattern'] = 'Match Pattern';
    $columns['response_text'] = 'Response Text';
    return $columns;
});

// Populate custom columns
add_action('manage_chatbot_response_posts_custom_column', function ($column, $post_id) {
    switch ($column) {
        case 'match_type':
            echo esc_html(get_field('match_type', $post_id));
            break;
        case 'match_pattern':
            echo esc_html(get_field('match_pattern', $post_id));
            break;
        case 'response_text':
            $response = get_field('response_text', $post_id);
            echo wp_trim_words($response, 15);  // Show first 15 words
            break;
    }
}, 10, 2);

// Make columns sortable (optional)
add_filter('manage_edit-chatbot_response_sortable_columns', function ($columns) {
    $columns['match_type'] = 'match_type';
    return $columns;
});




// custom table for temporary user sessions

function register_chatbot_step_cpt() {
    register_post_type('chatbot_step', [
        'label' => 'Chatbot Steps',
        'public' => false,
        'show_ui' => true,
        'menu_icon' => 'dashicons-randomize',
        'supports' => ['title'],
    ]);
}
add_action('init', 'register_chatbot_step_cpt');

function chatbot_create_user_sessions_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'bot_user_sessions';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      phone varchar(20) NOT NULL,
      state varchar(100) DEFAULT NULL,
      data longtext DEFAULT NULL,
      updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      UNIQUE KEY phone (phone)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Run on plugin activation or theme setup
register_activation_hook(__FILE__, 'chatbot_create_user_sessions_table');


// ---------- Chatbot log
function chatbot_create_logs_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chatbot_logs';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        phone VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        state VARCHAR(100),
        user_input TEXT,
        session_data LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'chatbot_create_logs_table');


add_action('admin_menu', function () {
    add_menu_page(
        'Chatbot Logs',
        'Chatbot Logs',
        'manage_options',
        'chatbot-logs',
        'chatbot_logs_admin_page',
        'dashicons-format-chat',
        26
    );
});


function chatbot_logs_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'chatbot_logs';

    // Handle delete
    if (isset($_GET['delete_log'])) {
        $id = intval($_GET['delete_log']);
        $wpdb->delete($table, ['id' => $id]);
        echo '<div class="updated"><p>Log deleted successfully.</p></div>';
    }

    // Pagination setup
    $per_page = 20;
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($page - 1) * $per_page;

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ));

    echo '<div class="wrap"><h1>Chatbot Logs</h1>';

    if (!$logs) {
        echo '<p>No chatbot logs found.</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>
                <th>ID</th>
                <th>Phone</th>
                <th>Name</th>
                <th>State</th>
                <th>User Input</th>
                <th>Created At</th>
                <th>Actions</th>
              </tr></thead><tbody>';

        foreach ($logs as $log) {
            $phone_number = explode('@', $log->phone)[0];
            echo '<tr>';
            echo '<td>' . esc_html($log->id) . '</td>';
            echo '<td>' . esc_html($phone_number) . '</td>';
            echo '<td>' . esc_html($log->name) . '</td>';
            echo '<td>' . esc_html($log->state) . '</td>';
            echo '<td>' . esc_html($log->user_input) . '</td>';
            // echo '<td><pre>' . esc_html($log->session_data) . '</pre></td>';
            echo '<td>' . esc_html($log->created_at) . '</td>';
            echo '<td><a href="' . admin_url('admin.php?page=chatbot-logs&delete_log=' . $log->id) . '" class="button" onclick="return confirm(\'Delete this log?\')">Delete</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Pagination links
        $total_pages = ceil($total / $per_page);
        $base_url = admin_url('admin.php?page=chatbot-logs');

        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([
                'base' => $base_url . '%_%',
                'format' => '&paged=%#%',
                'current' => $page,
                'total' => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]);
            echo '</div></div>';
        }
    }

    echo '</div>';
}


