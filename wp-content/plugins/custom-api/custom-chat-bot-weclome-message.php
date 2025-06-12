<?php
/*
Plugin Name: Chatbot Welcome Message
Description: Editable welcome message and image with REST API endpoint.
Version: 1.0
Author: Wunderfauks
*/

if (!defined('ABSPATH')) exit;

// Activation: create welcome table
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $table = $wpdb->prefix . 'bot_welcome_settings';
    $sql = "CREATE TABLE $table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        welcome_text TEXT NOT NULL,
        welcome_image TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Insert default welcome if empty
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    if (intval($count) === 0) {
        $wpdb->insert($table, [
            'welcome_text' => '👋 Welcome to our chatbot! How can I assist you today?',
            'welcome_image' => '',
        ]);
    }
});

// Admin menu for Welcome Settings
add_action('admin_menu', function () {
    add_menu_page(
        'Welcome Settings',
        'Welcome Settings',
        'manage_options',
        'chatbot-welcome-settings',
        'chatbot_welcome_settings_page',
        'dashicons-smiley'
    );
});

// Welcome Settings Admin Page
function chatbot_welcome_settings_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'bot_welcome_settings';
    $setting = $wpdb->get_row("SELECT * FROM $table LIMIT 1");

    if (isset($_POST['save_welcome'])) {
        check_admin_referer('save_welcome_settings');
        $text = sanitize_textarea_field($_POST['welcome_text']);
        $image = esc_url_raw($_POST['welcome_image']);

        $wpdb->update($table, [
            'welcome_text' => $text,
            'welcome_image' => $image
        ], ['id' => $setting->id]);

        echo '<div class="updated"><p>Settings saved successfully.</p></div>';
        $setting = $wpdb->get_row("SELECT * FROM $table LIMIT 1");
    }
    ?>
    <div class="wrap">
        <h1>Welcome Message Settings</h1>
        <form method="post">
            <?php wp_nonce_field('save_welcome_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="welcome_text">Welcome Message</label></th>
                    <td>
                        <textarea name="welcome_text" rows="5" class="large-text" required><?php echo esc_textarea($setting->welcome_text); ?></textarea>
                        <p class="description">You can use plain text or emojis.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="welcome_image">Welcome Image URL</label></th>
                    <td>
                        <input type="url" name="welcome_image" class="regular-text" value="<?php echo esc_url($setting->welcome_image); ?>">
                        <p class="description">URL to an image displayed with the welcome message.</p>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="save_welcome" class="button button-primary" value="Save Settings"></p>
        </form>
    </div>
    <?php
}

// REST API for welcome message
add_action('rest_api_init', function () {
    register_rest_route('chatbot/v1', '/welcome', [
        'methods' => 'GET',
        'callback' => 'chatbot_api_welcome_response',
        'permission_callback' => '__return_true',
    ]);
});

function chatbot_api_welcome_response($request) {
    global $wpdb;
    $user_name = sanitize_text_field($request->get_param('user_name') ?? '');

    $table = $wpdb->prefix . 'bot_welcome_settings';
    $setting = $wpdb->get_row("SELECT * FROM $table LIMIT 1");

    if (!$setting) {
        return new WP_REST_Response(['error' => 'Welcome settings not found'], 404);
    }

    $welcome_text = str_replace('{name}', $user_name ?: 'there', $setting->welcome_text);

    return [
        'welcome_text' => $welcome_text,
        'welcome_image' => $setting->welcome_image,
    ];
}
