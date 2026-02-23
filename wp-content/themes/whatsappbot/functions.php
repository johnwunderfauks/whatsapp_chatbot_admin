<?php

use function PHPSTORM_META\type;

/**
 * Customer Portal Theme Functions
 */

// Enqueue styles and scripts
function customer_portal_enqueue_assets() {
    wp_enqueue_style('customer-portal-main', get_template_directory_uri() . '/assets/css/main.css', array(), '1.0');
    wp_enqueue_script('customer-portal-app', get_template_directory_uri() . '/assets/js/app.js', array('jquery'), '1.0', true);
    
    // Pass WordPress REST API URL to JavaScript
    wp_localize_script('customer-portal-app', 'wpApiSettings', array(
        'root' => esc_url_raw(rest_url()),
        'nonce' => wp_create_nonce('wp_rest')
    ));
}
add_action('wp_enqueue_scripts', 'customer_portal_enqueue_assets');

// Theme support
function customer_portal_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
}
add_action('after_setup_theme', 'customer_portal_setup');

// Remove admin bar for frontend
add_filter('show_admin_bar', '__return_false');





// Custom API endpoints

// 1. WhatsApp Login - Send OTP
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/whatsapp-login', array(
        'methods' => 'POST',
        'callback' => 'custom_whatsapp_login',
        'permission_callback' => '__return_true',
    ));
});

function custom_whatsapp_login(WP_REST_Request $request)
{
    $phone = $request->get_param('phone');
    
    if (empty($phone)) {
        return new WP_Error('no_phone', 'Phone number is required', array('status' => 400));
    }
    
    // Find user by phone number
    $args = array(
        'post_type' => 'whatsapp_user',
        'meta_query' => array(
            array(
                'key' => 'phone',
                'value' => $phone,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    );
    
    $query = new WP_Query($args);
    
    if (!$query->have_posts()) {
        return new WP_Error('user_not_found', 'No account found with this phone number', array('status' => 404));
    }
    
    $user = $query->posts[0];
    $user_id = $user->ID;
    
    // Generate 6-digit OTP
    $otp = rand(100000, 999999);
    set_transient('whatsapp_otp_' . $phone, $otp, 5 * MINUTE_IN_SECONDS); // 5 min expiry
    
    // Send OTP via WhatsApp
    $webhook_url = get_option('whatsapp_webhook_url', '');
    if ($webhook_url) {
        wp_remote_post($webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'phone' => $phone,
                'message' => "🔐 Your login code is: *{$otp}*\n\nThis code will expire in 5 minutes.\n\nIf you didn't request this, please ignore this message.",
                'use_template' => true, 
                'template_name' => 'otp_login', 
                'template_params' => array($otp),
            )),
            'timeout' => 30
        ));
    }
    
    return array(
        'success' => true,
        'message' => 'OTP sent to your WhatsApp',
        'phone' => $phone
    );
}

// 2. Verify OTP
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/verify-otp', array(
        'methods' => 'POST',
        'callback' => 'custom_verify_otp',
        'permission_callback' => '__return_true',
    ));
});

function custom_verify_otp(WP_REST_Request $request)
{
    $phone = $request->get_param('phone');
    $otp = $request->get_param('otp');
    
    if (empty($phone) || empty($otp)) {
        return new WP_Error('missing_data', 'Phone and OTP are required', array('status' => 400));
    }
    
    $stored_otp = get_transient('whatsapp_otp_' . $phone);
    
    if (!$stored_otp) {
        return new WP_Error('otp_expired', 'OTP has expired. Please request a new one.', array('status' => 400));
    }
    
    if ($stored_otp != $otp) {
        return new WP_Error('invalid_otp', 'Invalid OTP', array('status' => 400));
    }
    
    // OTP is valid, find user
    $args = array(
        'post_type' => 'whatsapp_user',
        'meta_query' => array(
            array(
                'key' => 'phone',
                'value' => $phone,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    );
    
    $query = new WP_Query($args);
    
    if (!$query->have_posts()) {
        return new WP_Error('user_not_found', 'User not found', array('status' => 404));
    }
    
    $user = $query->posts[0];
    
    // Delete OTP
    delete_transient('whatsapp_otp_' . $phone);
    
    // Create session token
    $token = wp_generate_password(32, false);
    set_transient('whatsapp_session_' . $token, $user->ID, 24 * HOUR_IN_SECONDS);
    
    // Store token in cookie
    setcookie('whatsapp_token', $token, [
        'expires' => time() + (24 * HOUR_IN_SECONDS),
        'path' => '/',
        'domain' => '', // Use current domain
        'secure' => is_ssl(),
        'httponly' => false, 
        'samesite' => 'Lax'
    ]);
    
    return array(
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user_id' => $user->ID
    );
}

// 3. Get User Data (Dashboard)
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/user-data', array(
        'methods' => 'GET',
        'callback' => 'custom_get_user_data',
        'permission_callback' => 'custom_check_whatsapp_auth',
    ));
});

function custom_check_whatsapp_auth()
{
    $token = isset($_COOKIE['whatsapp_token']) ? $_COOKIE['whatsapp_token'] : '';
    $user_id = get_transient('whatsapp_session_' . $token);
    return $user_id !== false;
}

function custom_get_user_data(WP_REST_Request $request)
{
    $token = isset($_COOKIE['whatsapp_token']) ? $_COOKIE['whatsapp_token'] : '';
    $user_id = get_transient('whatsapp_session_' . $token);
    
    if (!$user_id) {
        return new WP_Error('unauthorized', 'Not authorized', array('status' => 401));
    }
    
    $user = get_post($user_id);
    if (!$user) {
        return new WP_Error('user_not_found', 'User not found', array('status' => 404));
    }
    
    $phone = get_post_meta($user_id, 'phone', true);
    $loyalty_points = intval(get_post_meta($user_id, 'loyalty_points', true));
    
    // Get receipts
    $receipts_query = new WP_Query(array(
        'post_type' => 'receipt',
        'meta_query' => array(
            array(
                'key' => 'profile_id',
                'value' => $user_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC'
    ));
    
    $receipts = array();
    $total_spent = 0;
    
    while ($receipts_query->have_posts()) {
        $receipts_query->the_post();
        $receipt_id = get_the_ID();
        
        $store_name = get_post_meta($receipt_id, 'store_name', true);
        $total_amount = get_post_meta($receipt_id, 'total_amount', true);
        $currency = get_post_meta($receipt_id, 'currency', true);
        $loyalty_points_earned = intval(get_post_meta($receipt_id, 'loyalty_points', true));
        $items = get_post_meta($receipt_id, 'receipt_items', true);
        $image_url = get_the_post_thumbnail_url($receipt_id, 'medium');
        
        // Add to total spent
        if (is_numeric($total_amount)) {
            $total_spent += floatval($total_amount);
        }
        
        $receipts[] = array(
            'id' => $receipt_id,
            'date' => get_the_date('Y-m-d H:i:s'),
            'store_name' => $store_name,
            'total_amount' => $total_amount,
            'currency' => $currency ?: 'THB',
            'loyalty_points' => $loyalty_points_earned,
            'items' => $items ?: array(),
            'image_url' => $image_url
        );
    }
    wp_reset_postdata();

    // Get available rewards count
    $rewards_count = wp_count_posts('reward')->publish;
    
    return array(
        'success' => true,
        'user' => array(
            'id' => $user_id,
            'name' => $user->post_title,
            'phone' => $phone,
            'loyalty_points' => $loyalty_points,
            'member_since' => get_the_date('F Y', $user_id)
        ),
        'receipts' => $receipts,
        'total_receipts' => count($receipts),
        'total_spent' => number_format($total_spent, 2, '.', ''),
        'rewards_available' => $rewards_count
    );
}

// 4. Logout
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/logout', array(
        'methods' => 'POST',
        'callback' => 'custom_whatsapp_logout',
        'permission_callback' => '__return_true',
    ));
});

function custom_whatsapp_logout()
{
    if (isset($_COOKIE['whatsapp_token'])) {
        $token = $_COOKIE['whatsapp_token'];
        delete_transient('whatsapp_session_' . $token);
        setcookie('whatsapp_token', '', time() - 3600, '/', '', is_ssl(), true);
    }
    
    return array('success' => true, 'message' => 'Logged out successfully');
}

/**
 * Get available rewards
 */
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/rewards', array(
        'methods' => 'GET',
        'callback' => 'custom_get_rewards',
        'permission_callback' => '__return_true',
    ));
});

function custom_get_rewards()
{
    $args = array(
        'post_type' => 'reward',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'is_active',
                'value' => '1',
                'compare' => '='
            )
        ),
        'orderby' => 'meta_value_num',
        'meta_key' => 'points_cost',
        'order' => 'ASC'
    );

    $query = new WP_Query($args);
    $rewards = array();

    while ($query->have_posts()) {
        $query->the_post();
        $reward_id = get_the_ID();

        $points_cost = intval(get_post_meta($reward_id, 'points_cost', true));
        $max_quantity = intval(get_post_meta($reward_id, 'max_quantity', true));
        $current_quantity = intval(get_post_meta($reward_id, 'current_quantity', true));
        $reward_type = get_post_meta($reward_id, 'reward_type', true);
        
        $is_available = ($max_quantity == 0 || $current_quantity > 0);

        $rewards[] = array(
            'id' => $reward_id,
            'title' => get_the_title(),
            'description' => get_the_content(),
            'image_url' => get_the_post_thumbnail_url($reward_id, 'medium'),
            'points_cost' => $points_cost,
            'reward_type' => $reward_type,
            'max_quantity' => $max_quantity,
            'current_quantity' => $current_quantity,
            'is_available' => $is_available
        );
    }
    wp_reset_postdata();

    return array(
        'success' => true,
        'rewards' => $rewards
    );
}

/**
 * Redeem a reward
 */
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/redeem-reward', array(
        'methods' => 'POST',
        'callback' => 'custom_redeem_reward',
        'permission_callback' => 'custom_check_whatsapp_auth',
    ));
});

function custom_redeem_reward(WP_REST_Request $request)
{
    global $wpdb;
    
    // Get authenticated user
    $token = isset($_COOKIE['whatsapp_token']) ? $_COOKIE['whatsapp_token'] : '';
    if (empty($token)) {
        $headers = getallheaders();
        $token = isset($headers['X-Auth-Token']) ? $headers['X-Auth-Token'] : '';
    }
    
    $user_id = get_transient('whatsapp_session_' . $token);
    
    if (!$user_id) {
        return new WP_Error('unauthorized', 'Not authorized', array('status' => 401));
    }

    $reward_id = $request->get_param('reward_id');
    
    if (!$reward_id) {
        return new WP_Error('missing_data', 'Reward ID is required', array('status' => 400));
    }

    // Get reward details
    $reward = get_post($reward_id);
    if (!$reward || $reward->post_type !== 'reward') {
        return new WP_Error('invalid_reward', 'Reward not found', array('status' => 404));
    }

    $points_cost = intval(get_post_meta($reward_id, 'points_cost', true));
    $current_quantity = intval(get_post_meta($reward_id, 'current_quantity', true));
    $max_quantity = intval(get_post_meta($reward_id, 'max_quantity', true));
    $is_active = get_post_meta($reward_id, 'is_active', true);
    $reward_type = get_post_meta($reward_id, 'reward_type', true);

    // Check if reward is active
    if ($is_active !== '1') {
        return new WP_Error('inactive_reward', 'This reward is no longer available', array('status' => 400));
    }

    // Check quantity
    if ($max_quantity > 0 && $current_quantity <= 0) {
        return new WP_Error('out_of_stock', 'This reward is out of stock', array('status' => 400));
    }

    // Get user's loyalty points
    $user_points = intval(get_post_meta($user_id, 'loyalty_points', true));

    if ($user_points < $points_cost) {
        return new WP_Error('insufficient_points', 'Not enough points. You need ' . $points_cost . ' points.', array('status' => 400));
    }

    // Generate unique redemption code
    $redemption_code = 'RD-' . strtoupper(substr(md5(uniqid()), 0, 8));

    $redemption_status = ($reward_type === 'voucher') ? 'fulfilled' : 'pending';

    // Start transaction
    $wpdb->query('START TRANSACTION');

    try {
        // Deduct points from user
        $new_points = $user_points - $points_cost;
        update_post_meta($user_id, 'loyalty_points', $new_points);

        // Decrease reward quantity
        if ($max_quantity > 0) {
            update_post_meta($reward_id, 'current_quantity', $current_quantity - 1);
        }

        // Insert redemption record
        $table_name = $wpdb->prefix . 'reward_redemptions';
        $redemption_data = array(
            'user_id' => $user_id,
            'reward_id' => $reward_id,
            'points_spent' => $points_cost,
            'redemption_code' => $redemption_code,
            'status' => $redemption_status,
            'redeemed_at' => current_time('mysql')
        );

        $data_format = array('%d', '%d', '%d', '%s', '%s', '%s');

        // If voucher, auto-fill fulfilled_at
        if ($reward_type === 'voucher') {
            $redemption_data['fulfilled_at'] = current_time('mysql');
            $data_format[] = '%s';
        }

        $inserted = $wpdb->insert(
            $table_name,
            $redemption_data,
            $data_format
        );

        if (!$inserted) {
            throw new Exception('Failed to create redemption record');
        }

        $wpdb->query('COMMIT');

        // Get user info for WhatsApp notification
        $user = get_post($user_id);
        $phone = get_post_meta($user_id, 'phone', true);

        // Send WhatsApp notification
        $webhook_url = get_option('whatsapp_webhook_url', '');
        if ($webhook_url && $phone) {
            $message = "🎁 Reward Redeemed Successfully!\n\n" .
                      "Reward: {$reward->post_title}\n" .
                      "Points Used: {$points_cost} 💎\n" .
                      "Remaining Points: {$new_points} 💎\n\n" .
                      "Redemption Code: *{$redemption_code}*\n\n" .
                      "Please show this code to claim your reward.\n" .
                      "Status: Pending\n\n" .
                      "Thank you for being a loyal customer!";

            // wp_remote_post($webhook_url, array(
            //     'headers' => array('Content-Type' => 'application/json'),
            //     'body' => json_encode(array(
            //         'phone' => $phone,
            //         'message' => $message
            //     )),
            //     'timeout' => 30
            // ));

            if ($reward_type === 'voucher') {
                wp_remote_post($webhook_url, array(
                    'headers' => array('Content-Type' => 'application/json'),
                    'body' => wp_json_encode(array(
                        'phone' => $phone,
                        'use_template' => true,
                        'template_name' => 'reward_request_confirmation',
                        'template_params' => array(
                            $reward->post_title,
                            $redemption_code 
                        ),

                        // OPTIONAL fallback message
                        'message' => "🎁 Reward Redeemed!\nCode: {$redemption_code}"
                    )),
                    'timeout' => 30
                ));

            }else{

                wp_remote_post($webhook_url, array(
                    'headers' => array('Content-Type' => 'application/json'),
                    'body' => wp_json_encode(array(
                        'phone' => $phone,
                        'use_template' => true,
                        'template_name' => 'reward_redemption',
                        'template_params' => array(
                            $reward->post_title,   // {{1}}
                            $points_cost,          // {{2}}
                            $new_points,           // {{3}}
                            $redemption_code       // {{4}}
                        ),

                        // OPTIONAL fallback message
                        'message' => "🎁 Reward Redeemed!\nCode: {$redemption_code}"
                    )),
                    'timeout' => 30
                ));
            }
        }

        return array(
            'success' => true,
            'message' => 'Reward redeemed successfully!',
            'redemption_code' => $redemption_code,
            'new_points_balance' => $new_points,
            'redemption_id' => $wpdb->insert_id
        );

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('redemption_failed', $e->getMessage(), array('status' => 500));
    }
}

/**
 * Get user's redemption history
 */
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/my-redemptions', array(
        'methods' => 'GET',
        'callback' => 'custom_get_user_redemptions',
        'permission_callback' => 'custom_check_whatsapp_auth',
    ));
});

function custom_get_user_redemptions()
{
    global $wpdb;
    
    $token = isset($_COOKIE['whatsapp_token']) ? $_COOKIE['whatsapp_token'] : '';
    if (empty($token)) {
        $headers = getallheaders();
        $token = isset($headers['X-Auth-Token']) ? $headers['X-Auth-Token'] : '';
    }
    
    $user_id = get_transient('whatsapp_session_' . $token);
    
    if (!$user_id) {
        return new WP_Error('unauthorized', 'Not authorized', array('status' => 401));
    }

    $table_name = $wpdb->prefix . 'reward_redemptions';
    
    $redemptions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d ORDER BY redeemed_at DESC",
        $user_id
    ));

    $result = array();
    foreach ($redemptions as $redemption) {
        $reward = get_post($redemption->reward_id);
        
        $result[] = array(
            'id' => $redemption->id,
            'reward_title' => $reward ? $reward->post_title : 'Deleted Reward',
            'reward_image' => $reward ? get_the_post_thumbnail_url($redemption->reward_id, 'thumbnail') : null,
            'points_spent' => intval($redemption->points_spent),
            'redemption_code' => $redemption->redemption_code,
            'status' => $redemption->status,
            'redeemed_at' => $redemption->redeemed_at,
            'fulfilled_at' => $redemption->fulfilled_at,
            'notes' => $redemption->notes
        );
    }

    return array(
        'success' => true,
        'redemptions' => $result
    );
}

add_action('init', function () {
    if (!is_admin()) {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
    }
});

/**
 * Check for duplicate image hash
 */
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/check-duplicate-hash', array(
        'methods' => 'POST',
        'callback' => 'custom_check_duplicate_hash',
        'permission_callback' => function () {
            return current_user_can('upload_files');
        },
    ));
});

function custom_check_duplicate_hash(WP_REST_Request $request)
{
    $receipt_id = $request->get_param('receipt_id');
    
    if (empty($receipt_id)) {
        return new WP_Error('no_hash', 'receipt_id is required', array('status' => 400));
    }
    
    // Search for existing receipts with this hash
    $args = array(
        'post_type' => 'receipt',
        'meta_query' => array(
            array(
                'key' => 'receipt_id_on_receipt',
                'value' => $receipt_id,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1,
        'post_status' => 'any'
    );
    
    $query = new WP_Query($args);
    $is_duplicate = $query->have_posts();
    wp_reset_postdata();
    
    return array(
        'is_duplicate' => $is_duplicate,
        'receipt_id' => $receipt_id
    );
}


/**
 * Register Client User Post Type
 */
function register_client_user_post_type() {
    register_post_type('client_user', array(
        'labels' => array(
            'name' => 'Client Users',
            'singular_name' => 'Client User',
            'add_new' => 'Add New Client',
            'add_new_item' => 'Add New Client User',
            'edit_item' => 'Edit Client User',
            'view_item' => 'View Client User',
            'search_items' => 'Search Clients',
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_icon' => 'dashicons-businessman',
        'supports' => array('title'),
        'capability_type' => 'post',
        'has_archive' => false,
        'rewrite' => false,
    ));
}
add_action('init', 'register_client_user_post_type');

/**
 * Add meta boxes for client users
 */
function client_user_meta_boxes() {
    add_meta_box(
        'client_user_details',
        'Client Login Details',
        'client_user_details_callback',
        'client_user',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'client_user_meta_boxes');

function client_user_details_callback($post) {
    wp_nonce_field('client_user_meta_box', 'client_user_meta_box_nonce');
    
    $username = get_post_meta($post->ID, 'username', true);
    $password_hash = get_post_meta($post->ID, 'password_hash', true);
    $email = get_post_meta($post->ID, 'email', true);
    $brand_title = get_post_meta($post->ID, 'brand_title', true);
    $is_active = get_post_meta($post->ID, 'is_active', true);
    
    ?>
    <table class="form-table">
        <tr>
            <th><label for="brand_title">Brand/Title</label></th>
            <td>
                <input type="text" id="brand_title" name="brand_title" value="<?php echo esc_attr($brand_title); ?>" class="regular-text">
                <p class="description">Display name shown in dashboard header (e.g., "Acme Corp Analytics")</p>
            </td>
        </tr>
        <tr>
            <th><label for="username">Username</label></th>
            <td>
                <input type="text" id="username" name="username" value="<?php echo esc_attr($username); ?>" class="regular-text" required>
                <p class="description">Unique username for login</p>
            </td>
        </tr>
        <tr>
            <th><label for="email">Email</label></th>
            <td>
                <input type="email" id="email" name="email" value="<?php echo esc_attr($email); ?>" class="regular-text">
            </td>
        </tr>
        
        <tr>
            <th><label for="new_password">Password</label></th>
            <td>
                <input type="password" id="new_password" name="new_password" class="regular-text" autocomplete="new-password">
                <p class="description">
                    <?php echo $password_hash ? 'Leave blank to keep current password' : 'Set initial password'; ?>
                </p>
                <?php if ($password_hash): ?>
                    <p class="description">Current password is set ✓</p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="is_active">Account Status</label></th>
            <td>
                <label>
                    <input type="checkbox" id="is_active" name="is_active" value="1" <?php checked($is_active, '1'); ?>>
                    Active
                </label>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Save client user meta
 */
function save_client_user_meta($post_id) {
    if (!isset($_POST['client_user_meta_box_nonce'])) {
        return;
    }
    
    if (!wp_verify_nonce($_POST['client_user_meta_box_nonce'], 'client_user_meta_box')) {
        return;
    }
    
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['brand_title'])) {
        update_post_meta($post_id, 'brand_title', sanitize_text_field($_POST['brand_title']));
    }
    
    
    if (isset($_POST['username'])) {
        update_post_meta($post_id, 'username', sanitize_text_field($_POST['username']));
    }
    
   
    if (isset($_POST['email'])) {
        update_post_meta($post_id, 'email', sanitize_email($_POST['email']));
    }
    
    
   
    if (!empty($_POST['new_password'])) {
        $password_hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        update_post_meta($post_id, 'password_hash', $password_hash);
    }
    

    update_post_meta($post_id, 'is_active', isset($_POST['is_active']) ? '1' : '0');
}
add_action('save_post_client_user', 'save_client_user_meta');


/**
 * Client Login - Username/Password
 */
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/client-login', array(
        'methods' => 'POST',
        'callback' => 'custom_client_login',
        'permission_callback' => '__return_true',
    ));
});

function custom_client_login(WP_REST_Request $request)
{
    $username = $request->get_param('username');
    
    $password = $request->get_param('password');
    
    if (empty($username) || empty($password)) {
        return new WP_Error('missing_credentials', 'Username and password are required', array('status' => 400));
    }
    
    // Find client by username
    $args = array(
        'post_type' => 'client_user',
        'meta_query' => array(
            array(
                'key' => 'username',
                'value' => $username,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    );
    
    $query = new WP_Query($args);
    
    if (!$query->have_posts()) {
        return new WP_Error('invalid_credentials', 'Invalid username or password', array('status' => 401));
    }
    
    $client = $query->posts[0];
    $client_id = $client->ID;
    
    // Check if account is active
    $is_active = get_post_meta($client_id, 'is_active', true);
    if ($is_active !== '1') {
        return new WP_Error('account_disabled', 'Account has been disabled', array('status' => 403));
    }
    
    // Verify password
    $password_hash = get_post_meta($client_id, 'password_hash', true);
    if (!password_verify($password, $password_hash)) {
        return new WP_Error('invalid_credentials', 'Invalid username or password', array('status' => 401));
    }
    
    // Create session token
    $token = wp_generate_password(32, false);
    set_transient('client_session_' . $token, $client_id, 8 * HOUR_IN_SECONDS); // 8 hour session
    
    // Store token in cookie
    setcookie('client_token', $token, [
        'expires' => time() + (8 * HOUR_IN_SECONDS),
        'path' => '/',
        'domain' => '',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    $brand_title = get_post_meta($client_id, 'brand_title', true);
    $email = get_post_meta($client_id, 'email', true);
    
    return array(
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'client' => array(
            'id' => $client_id,
            'name' => $client->post_title,
            'username' => $username,
            'email' => $email,
            'brand_title' => $brand_title ?: $client->post_title
        )
    );
}

/**
 * Check Client Auth
 */
function custom_check_client_auth()
{
    $token = isset($_COOKIE['client_token']) ? $_COOKIE['client_token'] : '';
    if (empty($token)) {
        $headers = getallheaders();
        $token = isset($headers['X-Client-Token']) ? $headers['X-Client-Token'] : '';
    }
    
    $client_id = get_transient('client_session_' . $token);
    return $client_id !== false;
}

/**
 * Client Logout
 */
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/client-logout', array(
        'methods' => 'POST',
        'callback' => 'custom_client_logout',
        'permission_callback' => '__return_true',
    ));
});

function custom_client_logout()
{
    if (isset($_COOKIE['client_token'])) {
        $token = $_COOKIE['client_token'];
        delete_transient('client_session_' . $token);
        setcookie('client_token', '', time() - 3600, '/', '', is_ssl(), true);
    }
    
    return array('success' => true, 'message' => 'Logged out successfully');
}

/**
 * Extract brand from item name
 * This is a simple implementation - you can enhance it with a brand database
 */
function extract_brand_from_item($item_name) {
    // Common brand patterns (expand this list based on your market)
    $known_brands = array(
        // Beverages
        'Coca Cola', 'Coca-Cola', 'Coke',
        'Pepsi',
        'Sprite',
        'Fanta',
        '7UP', '7-UP',
        'Red Bull',
        'Monster',
        'Minute Maid',
        'Tropicana',
        
        // Food & Snacks
        'Lays', 'Lay\'s',
        'Doritos',
        'Pringles',
        'Kit Kat',
        'Snickers',
        'M&M', 'M&Ms',
        'Cadbury',
        'Dairy Milk',
        'Oreo',
        'Ritz',
        'Kellogg', 'Kelloggs',
        'Quaker',
        
        // Personal Care
        'Dove',
        'Lux',
        'Ponds', 'Pond\'s',
        'Fair & Lovely', 'Fair and Lovely',
        'Sunsilk',
        'Colgate',
        'Sensodyne',
        'Oral-B',
        'Pampers',
        'Huggies',
        'Johnson', 'Johnson\'s',
        'Nivea',
        'Garnier',
        'Loreal', 'L\'Oreal', 'L\'Oréal',
        'Pantene',
        'Head & Shoulders',
        'Gillette',
        
        // Household
        'Tide',
        'Ariel',
        'Surf',
        'OMO',
        'Dettol',
        'Lifebuoy',
        'Vim',
        'Comfort',
        'Downy',
        
        // Food Brands
        'Nestle', 'Nestlé',
        'Maggi',
        'Milo',
        'Lipton',
        'Knorr',
        'Del Monte',
        'Heinz',
        'Kraft',
        'Nescafe', 'Nescafé',
        'Kikkoman',
        'Lee Kum Kee',
        
        // Dairy
        'Anchor',
        'Meadow Gold',
        'Dutch Mill',
        'Meiji',
        'Foremost',
        
        // Thai Brands (add local brands)
        'Mama',
        'Yum Yum',
        'Thai Tea',
        'Tipco',
        'Oishi',
        'Tao Kae Noi',
        'Singha',
        'Chang',
        'Leo',
        
        // Singapore Brands
        'Tiger',
        'Yakult',
        'F&N',
        'Pokka',
        'Ayam',
    );
    
    // Normalize item name for comparison
    $item_upper = strtoupper($item_name);
    $item_lower = strtolower($item_name);
    
    // Check for known brands (case-insensitive)
    foreach ($known_brands as $brand) {
        if (stripos($item_name, $brand) !== false) {
            return $brand;
        }
    }
    
    // Try to extract first word as brand (fallback)
    // This assumes brand name is usually the first word
    $words = preg_split('/[\s\-_]+/', trim($item_name));
    if (!empty($words[0]) && strlen($words[0]) > 2) {
        // Skip common non-brand words
        $skip_words = array('the', 'and', 'or', 'with', 'for', 'pack', 'bottle', 'can', 'box');
        $first_word = strtolower($words[0]);
        
        if (!in_array($first_word, $skip_words) && !is_numeric($first_word)) {
            return ucfirst($first_word);
        }
    }
    
    // If still no brand found, return "Unknown"
    return 'Unknown';
}

function parse_receipt_items($items_text) {

    if (empty($items_text) || !is_string($items_text)) {
        return array();
    }

    $parsed_items = array();
    $lines = explode("\n", $items_text);

    foreach ($lines as $line) {

        $line = trim($line);
        if ($line === '') {
            continue;
        }

        // Extract last number as price
        if (preg_match('/^(.+?)\s+([\d,]+(?:\.\d+)?)$/', $line, $matches)) {

            $item_name = trim($matches[1]);
            $price = floatval(str_replace(',', '', $matches[2]));
            $quantity = 1;

            // Only treat as quantity if it's a clean integer at end
            if (preg_match('/^(.*)\s+(\d+)$/', $item_name, $qty_matches)) {
                $item_name = trim($qty_matches[1]);
                $quantity = intval($qty_matches[2]);
            }

            $parsed_items[] = array(
                'name' => $item_name,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $price * $quantity
            );
        }
    }

    return $parsed_items;
}

/**
 * Get Comprehensive Analytics Data
 */
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/analytics', array(
        'methods' => 'GET',
        'callback' => 'custom_get_comprehensive_analytics',
        'permission_callback' => 'custom_check_client_auth',
    ));
});

function custom_get_comprehensive_analytics(WP_REST_Request $request)
{
    global $wpdb;
    
    // Verify client authentication
    $token = isset($_COOKIE['client_token']) ? $_COOKIE['client_token'] : '';
    if (empty($token)) {
        $headers = getallheaders();
        $token = isset($headers['X-Client-Token']) ? $headers['X-Client-Token'] : '';
    }
    
    $client_id = get_transient('client_session_' . $token);
    if (!$client_id) {
        return new WP_Error('unauthorized', 'Not authorized', array('status' => 401));
    }
    
    // Get date range
    $start_date = $request->get_param('start_date');
    $end_date = $request->get_param('end_date');

    // Build query args
    $query_args = array(
        'post_type'      => 'receipt',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => 'receipt_status',
                'value'   => array('accepted', 'rejected', 'pending'),
                'compare' => 'IN',
            )
        )
    );

    if (!empty($start_date) && !empty($end_date)) {
        $query_args['date_query'] = array(
            array(
                'after' => $start_date,
                'before' => $end_date,
                'inclusive' => true,
            )
        );
    }
    
    // ========================================
    // 1. EXECUTIVE OVERVIEW
    // ========================================
    
    // Total receipts in date range
    $receipts_query = new WP_Query($query_args);
    
    $all_receipts = $receipts_query->posts;
    $total_receipts = count($all_receipts);

    // Initialize counters
    $valid_receipts = 0;
    $rejected_receipts = 0;
    $total_points_issued = 0;
    $total_revenue = 0;
    $merchant_counts = array();
    $receipts_by_date = array();
    $rejection_reasons = array();

    // Single loop to process all receipt data
    foreach ($all_receipts as $receipt) {
        $receipt_id = $receipt->ID;
        $status = get_post_status($receipt);
        $date = get_the_date('Y-m-d', $receipt);
        
        // Initialize date array if needed
        if (!isset($receipts_by_date[$date])) {
            $receipts_by_date[$date] = array('valid' => 0, 'rejected' => 0, 'total' => 0);
        }
        
        // Process based on status
        $receipt_status = get_post_meta($receipt_id, 'receipt_status', true) ?: 'pending';

        if ($receipt_status === 'accepted') {
            $valid_receipts++;
            $receipts_by_date[$date]['valid']++;
            
            // Sum points
            $points = intval(get_post_meta($receipt_id, 'loyalty_points', true));
            $total_points_issued += $points;
            
            // Sum revenue
            $amount = floatval(get_post_meta($receipt_id, 'total_amount', true));
            $total_revenue += $amount;
            
            // Merchant counts
            $merchant = get_post_meta($receipt_id, 'store_name', true);
            if (!empty($merchant)) {
                if (!isset($merchant_counts[$merchant])) {
                    $merchant_counts[$merchant] = 0;
                }
                $merchant_counts[$merchant]++;
            }
        } elseif ($receipt_status === 'rejected') {
            $rejected_receipts++;
            $receipts_by_date[$date]['rejected']++;
            
            // Get fraud reasons (array)
            $fraud_reasons = get_post_meta($receipt_id, 'fraud_reasons', true);
            if (is_array($fraud_reasons) && !empty($fraud_reasons)) {
                foreach ($fraud_reasons as $reason) {
                    if (!isset($rejection_reasons[$reason])) {
                        $rejection_reasons[$reason] = 0;
                    }
                    $rejection_reasons[$reason]++;
                }
            } else {
                // Fallback if no fraud_reasons array exists
                if (!isset($rejection_reasons['Unknown'])) {
                    $rejection_reasons['Unknown'] = 0;
                }
                $rejection_reasons['Unknown']++;
            }
        }
        
        // Total receipts per date
        $receipts_by_date[$date]['total']++;
    }

    // Calculate rates
    $approval_rate = $total_receipts > 0 ? round(($valid_receipts / $total_receipts) * 100, 1) : 0;
    $rejection_rate = $total_receipts > 0 ? round(($rejected_receipts / $total_receipts) * 100, 1) : 0;
    
    // Active WhatsApp Users
    $total_users_query = new WP_Query(array(
        'post_type' => 'whatsapp_user',
        'post_status' => 'publish',
        'posts_per_page' => -1
    ));
    $total_users = $total_users_query->found_posts;
    
    // Active users (submitted receipt in date range)
    $active_users = array();
    foreach ($all_receipts as $receipt) {
        $profile_id = get_post_meta($receipt->ID, 'profile_id', true);
        if ($profile_id) {
            $active_users[$profile_id] = true;
        }
    }
    $active_user_count = count($active_users);
    
    // ========================================
    // 2. REDEMPTION ANALYTICS
    // ========================================
    
    $redemptions_table = $wpdb->prefix . 'reward_redemptions';
    
    // Total redemptions
    if (!empty($start_date) && !empty($end_date)) {
        $redemptions_where = $wpdb->prepare(
            "WHERE redeemed_at BETWEEN %s AND %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        );
    } else {
        $redemptions_where = "WHERE 1=1"; // Get all redemptions
    }

    $total_redemptions = $wpdb->get_var(
        "SELECT COUNT(*) FROM $redemptions_table $redemptions_where"
    );
    
    // Points redeemed
    $total_points_redeemed = $wpdb->get_var(
        "SELECT SUM(points_spent) FROM $redemptions_table $redemptions_where"
    ) ?: 0;
    
    // Fulfilled vs Pending
    $fulfilled_redemptions = $wpdb->get_var(
        "SELECT COUNT(*) FROM $redemptions_table $redemptions_where AND status = 'fulfilled'"
    );
    
    $pending_redemptions = $wpdb->get_var(
        "SELECT COUNT(*) FROM $redemptions_table $redemptions_where AND status = 'pending'"
    );
    
    // Redemptions over time
    $redemptions_by_date_raw = $wpdb->get_results(
        "SELECT DATE(redeemed_at) as date, COUNT(*) as count 
         FROM $redemptions_table 
         $redemptions_where 
         GROUP BY DATE(redeemed_at) 
         ORDER BY date",
        ARRAY_A
    );
    
    $redemptions_by_date = array();
    foreach ($redemptions_by_date_raw as $row) {
        $redemptions_by_date[$row['date']] = intval($row['count']);
    }
    
    // Top rewards
    $top_rewards_data = $wpdb->get_results(
        "SELECT reward_id, COUNT(*) as redemption_count, SUM(points_spent) as total_points
         FROM $redemptions_table
         $redemptions_where
         GROUP BY reward_id
         ORDER BY redemption_count DESC
         LIMIT 10",
        ARRAY_A
    );
    
    $top_rewards = array();
    foreach ($top_rewards_data as $reward_data) {
        $reward = get_post($reward_data['reward_id']);
        if ($reward) {
            $reward_type = get_post_meta($reward_data['reward_id'], 'reward_type', true);
            $top_rewards[] = array(
                'name' => $reward->post_title,
                'type' => $reward_type,
                'redemptions' => intval($reward_data['redemption_count']),
                'points_spent' => intval($reward_data['total_points'])
            );
        }
    }
    
    // ========================================
    // 3. FRAUD ANALYTICS
    // ========================================
    
   // Fraud rate
    $fraud_rate = $rejection_rate; // Same as rejection rate for now

    // Collect all fraud reasons across receipts
    $all_fraud_reasons = array();
    $fraud_scores = array();
    $fraud_decisions = array(
        'approve' => 0,
        'review' => 0,
        'reject' => 0
    );

    foreach ($all_receipts as $receipt) {
        $status = get_post_status($receipt);
        
        // Get fraud score
        $fraud_score = get_post_meta($receipt->ID, 'fraud_score', true);
        if (!empty($fraud_score)) {
            $fraud_scores[] = floatval($fraud_score);
        }
        
        // Get fraud decision
        $fraud_decision = get_post_meta($receipt->ID, 'fraud_decision', true);
        if (!empty($fraud_decision)) {
            $fraud_decision = strtolower($fraud_decision); // Normalize to lowercase
            if ($fraud_decision === 'accept' || $fraud_decision === 'accepted') {
                $fraud_decisions['approve']++;
            } elseif ($fraud_decision === 'review') {
                $fraud_decisions['review']++;
            } elseif ($fraud_decision === 'reject' || $fraud_decision === 'rejected') {
                $fraud_decisions['reject']++;
            }
        }
        
        // Get fraud reasons (array)
        $fraud_reasons = get_post_meta($receipt->ID, 'fraud_reasons', true);
        if (is_array($fraud_reasons) && !empty($fraud_reasons)) {
            foreach ($fraud_reasons as $reason) {
                if (!isset($all_fraud_reasons[$reason])) {
                    $all_fraud_reasons[$reason] = 0;
                }
                $all_fraud_reasons[$reason]++;
            }
        }
    }

    // Calculate average fraud score
    $avg_fraud_score = !empty($fraud_scores) 
        ? round(array_sum($fraud_scores) / count($fraud_scores), 1) 
        : 0;

    // Calculate fraud score distribution (0-20, 21-40, 41-60, 61-80, 81-100)
    $fraud_score_distribution = array(
        '0-20' => 0,
        '21-40' => 0,
        '41-60' => 0,
        '61-80' => 0,
        '81-100' => 0
    );

    foreach ($fraud_scores as $score) {
        if ($score <= 20) {
            $fraud_score_distribution['0-20']++;
        } elseif ($score <= 40) {
            $fraud_score_distribution['21-40']++;
        } elseif ($score <= 60) {
            $fraud_score_distribution['41-60']++;
        } elseif ($score <= 80) {
            $fraud_score_distribution['61-80']++;
        } else {
            $fraud_score_distribution['81-100']++;
        }
    }

    // Duplicate attempts (check for duplicate receipt_id_on_receipt)
    $duplicate_attempts = 0;
    $receipt_ids_seen = array();
    foreach ($all_receipts as $receipt) {
        $receipt_id_on_receipt = get_post_meta($receipt->ID, 'receipt_id_on_receipt', true);
        if (!empty($receipt_id_on_receipt)) {
            if (isset($receipt_ids_seen[$receipt_id_on_receipt])) {
                $duplicate_attempts++;
            }
            $receipt_ids_seen[$receipt_id_on_receipt] = true;
        }
    }

    // Sort fraud reasons by frequency
    arsort($all_fraud_reasons);
    
    // ========================================
    // 4. USER ENGAGEMENT ANALYTICS
    // ========================================
    
    // Receipts per user distribution
    $receipts_per_user = array();
    foreach ($all_receipts as $receipt) {
        $profile_id = get_post_meta($receipt->ID, 'profile_id', true);
        if ($profile_id) {
            if (!isset($receipts_per_user[$profile_id])) {
                $receipts_per_user[$profile_id] = 0;
            }
            $receipts_per_user[$profile_id]++;
        }
    }
    
    $avg_receipts_per_user = count($receipts_per_user) > 0 
        ? round(array_sum($receipts_per_user) / count($receipts_per_user), 1) 
        : 0;
    
    // New vs returning users (users who submitted their first receipt in this period)
    $new_users = 0;
    $returning_users = 0;
    $new_users_by_date = array();
    $returning_users_by_date = array();
    
    foreach ($receipts_per_user as $user_id => $count) {
        // Get user's first receipt ever
        $first_receipt = new WP_Query(array(
            'post_type' => 'receipt',
            'meta_query' => array(
                array(
                    'key' => 'profile_id',
                    'value' => $user_id,
                    'compare' => '='
                ),
                array(
                    'key'     => 'receipt_status',
                    'value'   => 'accepted',
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'ASC'
        ));
    
    if ($first_receipt->have_posts()) {
        $first_date = get_the_date('Y-m-d', $first_receipt->posts[0]);
        
        // Get user's first receipt in current date range
        $first_in_range = new WP_Query(array(
            'post_type' => 'receipt',
            'meta_query' => array(
                array(
                    'key' => 'profile_id',
                    'value' => $user_id,
                    'compare' => '='
                ),
                array(
                    'key'     => 'receipt_status',
                    'value'   => 'accepted',
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'ASC',
            'date_query' => !empty($start_date) && !empty($end_date) ? array(
                array(
                    'after' => $start_date,
                    'before' => $end_date,
                    'inclusive' => true,
                )
            ) : array()
        ));
        
        if ($first_in_range->have_posts()) {
            $first_in_range_date = get_the_date('Y-m-d', $first_in_range->posts[0]);
            
            if ($first_date === $first_in_range_date) {
                // New user - first ever receipt is in this period
                $new_users++;
                if (!isset($new_users_by_date[$first_in_range_date])) {
                    $new_users_by_date[$first_in_range_date] = 0;
                }
                $new_users_by_date[$first_in_range_date]++;
            } else {
                // Returning user - had receipts before this period
                $returning_users++;
                if (!isset($returning_users_by_date[$first_in_range_date])) {
                    $returning_users_by_date[$first_in_range_date] = 0;
                }
                $returning_users_by_date[$first_in_range_date]++;
            }
        }
    }
    wp_reset_postdata();
    }
    
    // User funnel
        $users_with_first_receipt = count($receipts_per_user);
        $users_with_approved = 0;
        $users_with_points = 0;
        $users_who_redeemed = 0;

        foreach ($receipts_per_user as $user_id => $count) {
            // Check if user has approved receipt
            $approved_receipts = new WP_Query(array(
                'post_type' => 'receipt',
                
                'meta_query' => array(
                    array(
                        'key' => 'profile_id',
                        'value' => $user_id,
                        'compare' => '='
                    ),
                    array(
                    'key'     => 'receipt_status',
                    'value'   => 'accepted',
                    'compare' => '='
                    )
                ),
                'posts_per_page' => 1
            ));
            
            if ($approved_receipts->found_posts > 0) {
                $users_with_approved++;
                
                // Check if user has points
                $points = intval(get_post_meta($user_id, 'loyalty_points', true));
                if ($points > 0) {
                    $users_with_points++;
                }
            }
            
            // Check if user redeemed
            $user_redemptions = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $redemptions_table WHERE user_id = %d",
                $user_id
            ));
            
            if ($user_redemptions > 0) {
                $users_who_redeemed++;
            }
            
            wp_reset_postdata();
        }

        // Conversion funnel percentages (step-by-step)
        $funnel_submitted_to_approved = $users_with_first_receipt > 0 
            ? round(($users_with_approved / $users_with_first_receipt) * 100, 1) 
            : 0;

        $funnel_approved_to_points = $users_with_approved > 0 
            ? round(($users_with_points / $users_with_approved) * 100, 1) 
            : 0;

        $funnel_points_to_redeemed = $users_with_points > 0 
            ? round(($users_who_redeemed / $users_with_points) * 100, 1) 
            : 0;

        // ========================================
        // RECEIPTS WITH POINTS (for conversion rate)
        // ========================================

        $receipts_with_points = 0;

        foreach ($all_receipts as $receipt) {

            $receipt_status = get_post_meta($receipt->ID, 'receipt_status', true);

            if ($receipt_status === 'accepted') {

                $points = intval(get_post_meta($receipt->ID, 'loyalty_points', true));

                if ($points > 0) {
                    $receipts_with_points++;
                }
            }
        }

        // Calculate conversion rate based on receipts that earned points
        $conversion_rate = $total_receipts > 0 
            ? round(($receipts_with_points / $total_receipts) * 100, 1) 
            : 0;
            
    // ========================================
    // 5. SUBMISSION TIME HEATMAP
    // ========================================
    
    $submissions_by_hour = array_fill(0, 24, 0);
    $submissions_by_day = array_fill(0, 7, 0); // 0 = Sunday, 6 = Saturday
    
    foreach ($all_receipts as $receipt) {
        $hour = intval(get_the_date('H', $receipt));
        $day_of_week = intval(get_the_date('w', $receipt));
        
        $submissions_by_hour[$hour]++;
        $submissions_by_day[$day_of_week]++;
    }
    
    // Find peak submission hour
    $peak_hour = array_search(max($submissions_by_hour), $submissions_by_hour);
    
    // ========================================
    // TOP MERCHANTS
    // ========================================
    
    arsort($merchant_counts);
    $top_merchants = array_slice($merchant_counts, 0, 10, true);

    $display_start_date = $start_date ?: 'All time';
    $display_end_date = $end_date ?: 'Present';

    // ========================================
    // 6. ITEM & BASKET ANALYTICS (ENHANCED)
    // ========================================

    $all_items = array();
    $basket_sizes = array(); // Number of items per receipt
    $basket_totals = array(); // Total amount per receipt
    $approved_basket_sizes = array(); // Only approved receipts
    $approved_basket_totals = array(); // Only approved receipts
    $brand_data = array(); // Track brand statistics
    $merchant_basket_data = array(); // Basket data per merchant

    foreach ($all_receipts as $receipt) {

        $receipt_id = $receipt->ID;
        $receipt_status = get_post_meta($receipt->ID, 'receipt_status', true);

        $receipt_items = get_post_meta($receipt_id, 'receipt_items', true);
        $receipt_total = floatval(get_post_meta($receipt_id, 'total_amount', true));
        $merchant      = get_post_meta($receipt_id, 'store_name', true);

        // Ensure items is always an array
        if (!is_array($receipt_items) || empty($receipt_items)) {
            continue;
        }

        $item_count = count($receipt_items);

        // =========================
        // Basket (All Receipts)
        // =========================
        $basket_sizes[] = $item_count;

        if ($receipt_total > 0) {
            $basket_totals[] = $receipt_total;
        }

        // =========================
        // Merchant Init
        // =========================
        if (!empty($merchant)) {

            if (!isset($merchant_basket_data[$merchant])) {
                $merchant_basket_data[$merchant] = array(
                    'basket_sizes'   => array(),
                    'basket_totals'  => array(),
                    'receipt_count'  => 0,
                    'approved_count' => 0
                );
            }

            $merchant_basket_data[$merchant]['basket_sizes'][] = $item_count;

            if ($receipt_total > 0) {
                $merchant_basket_data[$merchant]['basket_totals'][] = $receipt_total;
            }

            $merchant_basket_data[$merchant]['receipt_count']++;
        }

        // =========================
        // Approved Only Analytics
        // =========================
        if ($receipt_status !== 'accepted') {
            continue;
        }

        $approved_basket_sizes[] = $item_count;

        if ($receipt_total > 0) {
            $approved_basket_totals[] = $receipt_total;
        }

        if (!empty($merchant)) {
            $merchant_basket_data[$merchant]['approved_count']++;
        }

        // =========================
        // Item-Level Analytics
        // =========================
        foreach ($receipt_items as $item) {

            // Skip legacy string items safely
            if (!is_array($item)) {
                continue;
            }

            $item_name     = sanitize_text_field($item['name'] ?? '');
            $item_quantity = intval($item['quantity'] ?? 1);
            $item_price    = floatval($item['price'] ?? 0);
            $item_total    = $item_price * $item_quantity;

            if ($item_price <= 0) {
                continue;
            }

            $brand = extract_brand_from_item($item_name);

            $all_items[] = array(
                'name'     => $item_name,
                'brand'    => $brand ?: 'Unknown',
                'quantity' => $item_quantity,
                'price'    => $item_price,
                'total'    => $item_total,
                'merchant' => $merchant
            );

            // =========================
            // Brand Aggregation
            // =========================
            if (!empty($brand) && $brand !== 'Unknown') {

                if (!isset($brand_data[$brand])) {
                    $brand_data[$brand] = array(
                        'total_purchases' => 0,
                        'total_amount'    => 0,
                        'item_count'      => 0
                    );
                }

                $brand_data[$brand]['total_purchases']++;
                $brand_data[$brand]['total_amount'] += $item_total;
                $brand_data[$brand]['item_count'] += $item_quantity;
            }
        }
    }

    // Calculate general averages (all receipts)
    $avg_basket_size = !empty($basket_sizes) 
        ? round(array_sum($basket_sizes) / count($basket_sizes), 1) 
        : 0;

    $avg_basket_total = !empty($basket_totals) 
        ? round(array_sum($basket_totals) / count($basket_totals), 2) 
        : 0;

    // Calculate approved receipts averages
    $avg_approved_basket_size = !empty($approved_basket_sizes) 
        ? round(array_sum($approved_basket_sizes) / count($approved_basket_sizes), 1) 
        : 0;

    $avg_approved_basket_total = !empty($approved_basket_totals) 
        ? round(array_sum($approved_basket_totals) / count($approved_basket_totals), 2) 
        : 0;

    $avg_item_price = !empty($all_items) 
        ? round(array_sum(array_column($all_items, 'price')) / count($all_items), 2) 
        : 0;

    // Calculate average purchase ratio (item price vs basket total)
    $item_to_basket_ratio = $avg_approved_basket_total > 0 
        ? round(($avg_item_price / $avg_approved_basket_total) * 100, 1) 
        : 0;

    // Process merchant basket data
    $merchant_analytics = array();
    foreach ($merchant_basket_data as $merchant => $data) {
        $avg_merchant_basket_size = !empty($data['basket_sizes']) 
            ? round(array_sum($data['basket_sizes']) / count($data['basket_sizes']), 1) 
            : 0;
        
        $avg_merchant_basket_total = !empty($data['basket_totals']) 
            ? round(array_sum($data['basket_totals']) / count($data['basket_totals']), 2) 
            : 0;
        
        $merchant_analytics[$merchant] = array(
            'merchant' => $merchant,
            'avg_basket_size' => $avg_merchant_basket_size,
            'avg_basket_total' => $avg_merchant_basket_total,
            'total_receipts' => $data['receipt_count'],
            'approved_receipts' => $data['approved_count']
        );
    }

    // Sort merchants by basket size
    uasort($merchant_analytics, function($a, $b) {
        return $b['avg_basket_size'] <=> $a['avg_basket_size'];
    });

    $top_merchants_by_basket = array_slice($merchant_analytics, 0, 10, true);

    // Sort brands by total amount
    uasort($brand_data, function($a, $b) {
        return $b['total_amount'] <=> $a['total_amount'];
    });

    // Calculate average cost per brand
    $brand_analytics = array();
    foreach ($brand_data as $brand => $data) {
        $brand_analytics[$brand] = array(
            'brand' => $brand,
            'total_purchases' => $data['total_purchases'],
            'total_amount' => round($data['total_amount'], 2),
            'avg_purchase_cost' => round($data['total_amount'] / $data['total_purchases'], 2),
            'total_items' => $data['item_count']
        );
    }

    // Get top brands
    $top_brands = array_slice($brand_analytics, 0, 10, true);

    // Most expensive items
    usort($all_items, function($a, $b) {
        return $b['price'] <=> $a['price'];
    });
    $most_expensive_items = array_slice($all_items, 0, 10);

    // Most purchased items (by name)
    $item_frequency = array();
    foreach ($all_items as $item) {
        $name = $item['name'];
        if (!isset($item_frequency[$name])) {
            $item_frequency[$name] = array(
                'name' => $name,
                'count' => 0,
                'total_amount' => 0
            );
        }
        $item_frequency[$name]['count']++;
        $item_frequency[$name]['total_amount'] += $item['total'];
    }

    uasort($item_frequency, function($a, $b) {
        return $b['count'] <=> $a['count'];
    });
    $most_purchased_items = array_slice($item_frequency, 0, 10, true);
    
    // ========================================
    // RETURN COMPREHENSIVE DATA
    // ========================================
    
    return array(
        'success' => true,
        'date_range' => array(
            'start' => $display_start_date,
            'end' => $display_end_date,
            'is_filtered' => !empty($start_date) && !empty($end_date)
        ),
        
        // Executive Overview
        'executive_overview' => array(
            'total_receipts' => $total_receipts,
            'valid_receipts' => $valid_receipts,
            'rejected_receipts' => $rejected_receipts,
            'approval_rate' => $approval_rate,
            'rejection_rate' => $rejection_rate,
            'total_redemptions' => intval($total_redemptions),
            'points_issued' => $total_points_issued,
            'points_redeemed' => intval($total_points_redeemed),
            'active_users' => $active_user_count,
            'total_users' => $total_users,
            'total_revenue' => number_format($total_revenue, 2, '.', ''),
            'conversion_rate' => $conversion_rate,
            'receipts_with_points' => $receipts_with_points
        ),
        
        // Charts
        'charts' => array(
            'receipts_over_time' => $receipts_by_date,
            'redemptions_over_time' => $redemptions_by_date,
            'top_merchants' => $top_merchants,
            'top_rewards' => $top_rewards,
            'submissions_by_hour' => $submissions_by_hour,
            'submissions_by_day' => $submissions_by_day,
            'peak_submission_hour' => $peak_hour
        ),
        
        'fraud_analytics' => array(
            'fraud_rate' => $fraud_rate,
            'avg_fraud_score' => $avg_fraud_score,
            'duplicate_attempts' => $duplicate_attempts,
            'rejection_reasons' => $rejection_reasons,
            'fraud_reasons' => $all_fraud_reasons,
            'fraud_decisions' => $fraud_decisions, // Always defined as array
            'fraud_score_distribution' => $fraud_score_distribution,
            'total_blocked' => $rejected_receipts,
            'total_scored' => count($fraud_scores)
        ),

        
        
        // Redemption Analytics
        'redemption_analytics' => array(
            'total_redemptions' => intval($total_redemptions),
            'fulfilled' => intval($fulfilled_redemptions),
            'pending' => intval($pending_redemptions),
            'points_redeemed' => intval($total_points_redeemed),
            'avg_points_per_redemption' => $total_redemptions > 0 
                ? round($total_points_redeemed / $total_redemptions, 1) 
                : 0
        ),
        
        // User Engagement
        'user_engagement' => array(
            'total_users' => $total_users,
            'active_users' => $active_user_count,
            'new_users' => $new_users,
            'returning_users' => $returning_users,
            'new_users_by_date' => $new_users_by_date,
            'returning_users_by_date' => $returning_users_by_date,
            'avg_receipts_per_user' => $avg_receipts_per_user,
            'funnel' => array(
                'submitted_receipt' => $users_with_first_receipt,
                'approved_receipt' => $users_with_approved,
                'earned_points' => $users_with_points,
                'redeemed_reward' => $users_who_redeemed,
                'submitted_to_approved_rate' => $funnel_submitted_to_approved,
                'approved_to_points_rate' => $funnel_approved_to_points,
                'points_to_redeemed_rate' => $funnel_points_to_redeemed
            )
        ),

        // Item & Basket Analytics
        'item_analytics' => array(
            // General basket stats (all receipts)
            'avg_basket_size' => $avg_basket_size,
            'avg_basket_total' => $avg_basket_total,
            'total_baskets_analyzed' => count($basket_sizes),
            
            // Approved receipts only
            'avg_approved_basket_size' => $avg_approved_basket_size,
            'avg_approved_basket_total' => $avg_approved_basket_total,
            'total_approved_baskets' => count($approved_basket_sizes),
            
            // Item pricing
            'avg_item_price' => $avg_item_price,
            'item_to_basket_ratio' => $item_to_basket_ratio,
            'total_items_analyzed' => count($all_items),
        ),

        'merchant_basket_analytics' => array(
            'top_merchants_by_basket_size' => array_values($top_merchants_by_basket),
            'total_merchants' => count($merchant_analytics)
        ),

        'brand_analytics' => array(
            'top_brands' => array_values($top_brands),
            'total_brands' => count($brand_data)
        ),

        'item_insights' => array(
            'most_expensive' => $most_expensive_items,
            'most_purchased' => array_values($most_purchased_items),
            'receipt_items_temp' => empty($receipt_items)
        )
    );
}

/**
 * Send email notification to admins when receipt is submitted
 */
function notify_admins_new_receipt($receipt_id) {
    // Get receipt details
    $receipt = get_post($receipt_id);
    if (!$receipt) {
        return false;
    }
    
    $profile_id = get_post_meta($receipt_id, 'profile_id', true);
    $store_name = get_post_meta($receipt_id, 'store_name', true);
    $total_amount = get_post_meta($receipt_id, 'total_amount', true);
    $currency = get_post_meta($receipt_id, 'currency', true) ?: 'SGD';
    $purchase_date = get_post_meta($receipt_id, 'purchase_date', true);
    $fraud_score = get_post_meta($receipt_id, 'fraud_score', true);
    
    // Get user details
    $user_post = get_post($profile_id);
    $user_name = $user_post ? $user_post->post_title : 'Unknown User';
    $user_phone = get_post_meta($profile_id, 'phone', true);
    
    // Get admin emails
    $admin_emails = get_admin_notification_emails();
    
    if (empty($admin_emails)) {
        return false;
    }
    
    // Determine priority based on fraud score
    $priority = 'Normal';
    $priority_emoji = '📝';
    if ($fraud_score >= 80) {
        $priority = 'HIGH RISK';
        $priority_emoji = '🚨';
    } elseif ($fraud_score >= 60) {
        $priority = 'Medium Risk';
        $priority_emoji = '⚠️';
    }
    
    // Email subject
    $subject = $priority_emoji . ' New Receipt Submission';
    if ($fraud_score >= 60) {
        $subject .= ' - ' . $priority;
    }
    
    // Email body (HTML)
    $edit_url = admin_url('post.php?post=' . $receipt_id . '&action=edit');
    $all_receipts_url = admin_url('edit.php?post_type=receipt');
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
            }
            .container {
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }
            .header {
                background: <?php echo $fraud_score >= 80 ? '#fee' : '#f0f6fc'; ?>;
                padding: 20px;
                border-left: 4px solid <?php echo $fraud_score >= 80 ? '#ef4444' : '#2271b1'; ?>;
                margin-bottom: 20px;
            }
            .header h2 {
                margin: 0 0 10px 0;
                color: <?php echo $fraud_score >= 80 ? '#ef4444' : '#2271b1'; ?>;
            }
            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            .info-table td {
                padding: 10px;
                border-bottom: 1px solid #eee;
            }
            .info-table td:first-child {
                font-weight: bold;
                width: 150px;
                color: #666;
            }
            .fraud-score {
                font-size: 24px;
                font-weight: bold;
                padding: 10px 20px;
                border-radius: 8px;
                display: inline-block;
                margin: 10px 0;
            }
            .fraud-low {
                background: #d4edda;
                color: #155724;
            }
            .fraud-medium {
                background: #fff3cd;
                color: #856404;
            }
            .fraud-high {
                background: #f8d7da;
                color: #721c24;
            }
            .button {
                display: inline-block;
                padding: 12px 24px;
                background: #2271b1;
                color: white;
                text-decoration: none;
                border-radius: 4px;
                margin: 10px 10px 10px 0;
            }
            .button:hover {
                background: #135e96;
            }
            .footer {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #eee;
                font-size: 12px;
                color: #999;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2><?php echo $priority_emoji; ?> New Receipt Submitted</h2>
                <?php if ($fraud_score >= 60): ?>
                    <p style="margin: 0; color: #ef4444; font-weight: bold;">⚠️ Requires immediate attention!</p>
                <?php endif; ?>
            </div>
            
            <p>A new receipt has been submitted and is awaiting review.</p>
            
            <table class="info-table">
                <tr>
                    <td>Receipt ID:</td>
                    <td><strong>#<?php echo $receipt_id; ?></strong></td>
                </tr>
                <tr>
                    <td>User:</td>
                    <td><?php echo esc_html($user_name); ?></td>
                </tr>
                <tr>
                    <td>Phone:</td>
                    <td><?php echo esc_html($user_phone); ?></td>
                </tr>
                <tr>
                    <td>Store:</td>
                    <td><?php echo esc_html($store_name); ?></td>
                </tr>
                <tr>
                    <td>Amount:</td>
                    <td><strong><?php echo $currency . ' ' . $total_amount; ?></strong></td>
                </tr>
                <tr>
                    <td>Purchase Date:</td>
                    <td><?php echo esc_html($purchase_date); ?></td>
                </tr>
                <tr>
                    <td>Submitted:</td>
                    <td><?php echo get_the_date('Y-m-d H:i', $receipt_id); ?></td>
                </tr>
            </table>
            
            <?php if (!empty($fraud_score)): ?>
                <div>
                    <strong>Fraud Score:</strong><br>
                    <span class="fraud-score <?php 
                        if ($fraud_score >= 80) echo 'fraud-high';
                        elseif ($fraud_score >= 40) echo 'fraud-medium';
                        else echo 'fraud-low';
                    ?>">
                        <?php echo number_format($fraud_score, 1); ?>/100
                    </span>
                </div>
            <?php endif; ?>
            
            <?php
            // Show fraud reasons if any
            $fraud_reasons = get_post_meta($receipt_id, 'fraud_reasons', true);
            if (is_array($fraud_reasons) && !empty($fraud_reasons)):
            ?>
                <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;">
                    <strong>⚠️ Fraud Flags:</strong>
                    <ul style="margin: 10px 0;">
                        <?php foreach ($fraud_reasons as $reason): ?>
                            <li><?php echo esc_html($reason); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div style="margin: 30px 0;">
                <a href="<?php echo $edit_url; ?>" class="button">📝 Review Receipt</a>
                <a href="<?php echo $all_receipts_url; ?>" class="button" style="background: #6b7280;">📋 View All Receipts</a>
            </div>
            
            <div class="footer">
                <p>This is an automated notification from your Receipt Management System.</p>
                <p>To manage notification settings, go to WordPress Admin → Settings → Receipt Notifications</p>
            </div>
        </div>
    </body>
    </html>
    <?php
    $message = ob_get_clean();
    
    // Email headers
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Receipt System <noreply@' . parse_url(get_site_url(), PHP_URL_HOST) . '>'
    );
    
    // Send email
    $sent = wp_mail($admin_emails, $subject, $message, $headers);
    
    // Log the notification
    if ($sent) {
        update_post_meta($receipt_id, 'admin_notified_at', current_time('mysql'));
        error_log("Receipt notification sent to admins for receipt #{$receipt_id}");
    } else {
        error_log("Failed to send receipt notification for receipt #{$receipt_id}");
    }
    
    return $sent;
}

/**
 * Get admin email addresses for notifications
 */
function get_admin_notification_emails() {
    // Option 1: Use custom option (recommended)
    $custom_emails = get_option('receipt_notification_emails', '');
    
    if (!empty($custom_emails)) {
        // Split by comma or newline
        $emails = preg_split('/[\r\n,]+/', $custom_emails);
        $emails = array_map('trim', $emails);
        $emails = array_filter($emails, 'is_email');
        
        if (!empty($emails)) {
            return $emails;
        }
    }
    
    // Option 2: Fallback to all admin users
    $admins = get_users(array('role' => 'administrator'));
    $emails = array();
    
    foreach ($admins as $admin) {
        if (!empty($admin->user_email)) {
            $emails[] = $admin->user_email;
        }
    }
    
    return $emails;
}

/**
 * Add settings page for receipt notifications
 */
add_action('admin_menu', 'add_receipt_notification_settings');
function add_receipt_notification_settings() {
    add_options_page(
        'Receipt Notifications',
        'Receipt Notifications',
        'manage_options',
        'receipt-notifications',
        'render_receipt_notification_settings'
    );
}

function render_receipt_notification_settings() {
    // Save settings
    if (isset($_POST['save_notification_settings']) && check_admin_referer('receipt_notification_settings')) {
        $emails = sanitize_textarea_field($_POST['notification_emails']);
        $min_fraud_score = intval($_POST['min_fraud_score']);
        $send_for_all = isset($_POST['send_for_all']) ? '1' : '0';
        
        update_option('receipt_notification_emails', $emails);
        update_option('receipt_notification_min_fraud_score', $min_fraud_score);
        update_option('receipt_notification_send_for_all', $send_for_all);
        
        echo '<div class="notice notice-success"><p>✅ Settings saved successfully!</p></div>';
    }
    
    $emails = get_option('receipt_notification_emails', '');
    $min_fraud_score = get_option('receipt_notification_min_fraud_score', 0);
    $send_for_all = get_option('receipt_notification_send_for_all', '1');
    
    ?>
    <div class="wrap">
        <h1>📧 Receipt Notification Settings</h1>
        
        <form method="POST">
            <?php wp_nonce_field('receipt_notification_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="notification_emails">Notification Email Addresses</label>
                    </th>
                    <td>
                        <textarea name="notification_emails" id="notification_emails" rows="5" class="large-text"><?php echo esc_textarea($emails); ?></textarea>
                        <p class="description">Enter email addresses to receive receipt notifications (one per line or comma-separated). Leave empty to send to all administrators.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="send_for_all">Send Notifications</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="send_for_all" id="send_for_all" value="1" <?php checked($send_for_all, '1'); ?>>
                            Send notification for every receipt submission
                        </label>
                        <p class="description">If unchecked, only receipts meeting the fraud score threshold below will trigger notifications.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="min_fraud_score">Minimum Fraud Score for Alerts</label>
                    </th>
                    <td>
                        <input type="number" name="min_fraud_score" id="min_fraud_score" value="<?php echo esc_attr($min_fraud_score); ?>" min="0" max="100" class="small-text">
                        <p class="description">Only send notifications for receipts with fraud score at or above this threshold (0 = all receipts). Recommended: 60 for high-risk only.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" name="save_notification_settings" class="button button-primary">💾 Save Settings</button>
            </p>
        </form>
        
        <hr>
        
        <h2>📨 Test Email Notification</h2>
        <p>Send a test email to verify your settings are working correctly.</p>
        <form method="POST">
            <?php wp_nonce_field('test_receipt_notification'); ?>
            <button type="submit" name="send_test_email" class="button">📧 Send Test Email</button>
        </form>
        
        <?php
        if (isset($_POST['send_test_email']) && check_admin_referer('test_receipt_notification')) {
            $test_emails = get_admin_notification_emails();
            
            $subject = '✅ Test: Receipt Notification System';
            $message = '<h2>Test Email</h2><p>This is a test email from your Receipt Notification System. If you received this, your notification settings are working correctly!</p>';
            $headers = array('Content-Type: text/html; charset=UTF-8');
            
            $sent = wp_mail($test_emails, $subject, $message, $headers);
            
            if ($sent) {
                echo '<div class="notice notice-success"><p>✅ Test email sent successfully to: ' . implode(', ', $test_emails) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Failed to send test email. Please check your WordPress email configuration.</p></div>';
            }
        }
        ?>
    </div>
    <?php
}


/**
 * Disable SiteGround Security for custom API endpoints
 */
add_filter('siteground_optimizer_disable_security_headers', function($disable) {
    // Check if this is our custom API endpoint
    if (isset($_SERVER['REQUEST_URI']) && 
        strpos($_SERVER['REQUEST_URI'], '/wp-json/custom/v1/') !== false) {
        return true;
    }
    return $disable;
});

// Also bypass the captcha challenge
add_filter('sg_security_disable_firewall', function($disable) {
    if (isset($_SERVER['REQUEST_URI']) && 
        strpos($_SERVER['REQUEST_URI'], '/wp-json/custom/v1/') !== false) {
        // Additional check: only bypass if valid auth header exists
        $headers = getallheaders();
        if (isset($headers['Authorization']) && 
            (strpos($headers['Authorization'], 'Basic ') === 0 || 
             strpos($headers['Authorization'], 'Bearer ') === 0)) {
            return true;
        }
    }
    return $disable;
});

// Bypass rate limiting for authenticated API requests
add_filter('sg_security_rate_limiter_exclude', function($exclude) {
    if (isset($_SERVER['REQUEST_URI']) && 
        strpos($_SERVER['REQUEST_URI'], '/wp-json/custom/v1/') !== false) {
        return true;
    }
    return $exclude;
});