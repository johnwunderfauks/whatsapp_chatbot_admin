<?php
/*
Plugin Name: Custom API Endpoints
Description: A custom plugin to handle media uploads and store WhatsApp user data.
Version: 1.0
Author: Your Name
*/

// Include the second file
include_once(plugin_dir_path(__FILE__) . 'custom-post-type-and-admin.php');

// Enable media upload capabilities
function custom_api_media_permissions($caps, $cap, $user_id, $args)
{
    if ($cap === 'upload_files') {
        return array('upload_files');
    }
    return $caps;
}
add_filter('map_meta_cap', 'custom_api_media_permissions', 10, 4);

// Register custom REST route for media upload
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/upload', array(
        'methods' => 'POST',
        'callback' => 'custom_handle_upload',
        'permission_callback' => function () {
            return current_user_can('upload_files');
        },
    ));
});

function custom_handle_upload(WP_REST_Request $request)
{
    $files = $request->get_file_params();
    $profile_id = $request->get_param('profile_id');

    if (empty($files) || empty($files['file'])) {
        return new WP_Error('no_file', 'No file uploaded', array('status' => 400));
    }

    require_once(ABSPATH . 'wp-admin/includes/file.php');

    $file = $files['file'];
    $upload_overrides = array('test_form' => false);
    $movefile = wp_handle_upload($file, $upload_overrides);

    if ($movefile && !isset($movefile['error'])) {
        $wp_upload_dir = wp_upload_dir();
        $attachment = array(
            'guid' => $wp_upload_dir['url'] . '/' . basename($movefile['file']),
            'post_mime_type' => $movefile['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($movefile['file'])),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $movefile['file']);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Retrieve user data
        $user_post = get_post($profile_id);
        $username = $user_post->post_title;
        $phone = get_post_meta($profile_id, 'phone', true);

        // Create a new receipt post
        $receipt_post = array(
            'post_title' => "Receipt - $attach_id - $username - $phone",
            'post_content' => '',
            'post_status' => 'publish',
            'post_type' => 'receipt',
        );

        $receipt_id = wp_insert_post($receipt_post);
        update_post_meta($receipt_id, '_thumbnail_id', $attach_id);
        update_post_meta($receipt_id, 'profile_id', $profile_id); // Store profile ID in receipt

        return array('success' => true, 'receipt_id' => $receipt_id);
    } else {
        return new WP_Error('upload_error', $movefile['error'], array('status' => 500));
    }
}

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/upload/', array(
        'methods' => 'POST',
        'callback' => 'custom_handle_upload',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
    ));
});
// Register custom REST route for storing WhatsApp user data
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/store-whatsapp-user', array(
        'methods' => 'POST',
        'callback' => 'custom_store_whatsapp_user_data',
        'permission_callback' => function () {
            return current_user_can('edit_posts'); // Adjust permissions as needed
        }
    ));
});

// Store WhatsApp user data
function custom_store_whatsapp_user_data(WP_REST_Request $request)
{
    $params = $request->get_params();
    $phone = sanitize_text_field($params['phone']);
    $name = sanitize_text_field($params['name']);

    // Check if a post exists with this phone number
    $existing_posts = get_posts(array(
        'post_type'   => 'whatsapp_user',
        'meta_key'    => 'phone',
        'meta_value'  => $phone,
        'post_status' => 'publish',
        'numberposts' => 1,
    ));

    if (!empty($existing_posts)) {
        // Post already exists, update it
        $post_id = $existing_posts[0]->ID;
        $post_data = array(
            'ID'           => $post_id,
            'post_title'    => $name,
            'post_content'  => 'Updated data for ' . $name,
        );
        wp_update_post($post_data);
    } else {
        // Create a new post
        $post_data = array(
            'post_type'    => 'whatsapp_user',
            'post_title'   => $name,
            'post_content' => 'Data for ' . $name,
            'post_status'  => 'publish',
        );
        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return new WP_Error('post_creation_failed', 'Failed to create post.', array('status' => 500));
        }
    }

    // Store phone number as meta field
    update_post_meta($post_id, 'phone', $phone);

    // Return the stored data
    return array(
        'post_id' => $post_id,
        'phone'   => $phone,
        'name'    => $name,
    );
}

// Add meta box for loyalty points
add_action('add_meta_boxes', 'add_receipt_meta_box');
function add_receipt_meta_box()
{
    add_meta_box(
        'receipt_meta_box',        // ID
        'Receipt Details',         // Title
        'display_receipt_meta_box', // Callback
        'receipt',                 // Post type
        'normal',                  // Context
        'high'                     // Priority
    );
}

function display_receipt_meta_box($post)
{
    $profile_id = get_post_meta($post->ID, 'profile_id', true);
    $loyalty_points = get_post_meta($post->ID, 'loyalty_points', true);
    $attachment_id = get_post_thumbnail_id($post->ID);

    $username = get_the_title($profile_id);
    $phone = get_post_meta($profile_id, 'phone', true);

    echo "<p><strong>User:</strong> $username</p>";
    echo "<p><strong>Phone:</strong> $phone</p>";
    if ($attachment_id) {
        echo wp_get_attachment_image($attachment_id, 'medium');
    }

    // Loyalty points input
    echo '<p><label for="loyalty_points">Loyalty Points: </label>';
    echo '<input type="number" name="loyalty_points" value="' . esc_attr($loyalty_points) . '" /></p>';
}

// Save the loyalty points
add_action('save_post', 'save_receipt_meta');
function save_receipt_meta($post_id)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (wp_is_post_revision($post_id)) {
        return;
    }

    if (get_post_type($post_id) !== 'receipt') {
        return;
    }

    if (isset($_POST['loyalty_points'])) {
        $loyalty_points = intval($_POST['loyalty_points']);
        update_post_meta($post_id, 'loyalty_points', $loyalty_points);

        // Update the WhatsApp user's loyalty points
        $profile_id = get_post_meta($post_id, 'profile_id', true);
        if ($profile_id) {
            $user_post = get_post($profile_id);
            if ($user_post) {
                // Get current points and cast to integer
                $current_points = intval(get_post_meta($user_post->ID, 'loyalty_points', true));
                $new_points = $current_points + $loyalty_points;
                update_post_meta($user_post->ID, 'loyalty_points', $new_points);
            }
        }
    }
}

// Add custom columns to WhatsApp user post type
add_filter('manage_whatsapp_user_posts_columns', 'custom_whatsapp_user_columns');
function custom_whatsapp_user_columns($columns)
{
    $columns['phone'] = __('Phone');
    $columns['loyalty_points'] = __('Loyalty Points');
    return $columns;
}

// Populate custom columns with data
add_action('manage_whatsapp_user_posts_custom_column', 'custom_whatsapp_user_custom_columns', 10, 2);
function custom_whatsapp_user_custom_columns($column, $post_id)
{
    switch ($column) {
        case 'phone':
            $phone = get_post_meta($post_id, 'phone', true);
            echo esc_html($phone);
            break;

        case 'loyalty_points':
            $loyalty_points = get_post_meta($post_id, 'loyalty_points', true);
            echo esc_html($loyalty_points);
            break;
    }
}

// Make custom columns sortable
add_filter('manage_edit-whatsapp_user_sortable_columns', 'custom_whatsapp_user_sortable_columns');
function custom_whatsapp_user_sortable_columns($columns)
{
    $columns['phone'] = 'phone';
    $columns['loyalty_points'] = 'loyalty_points';
    return $columns;
}

// Handle sorting of custom columns
add_action('pre_get_posts', 'custom_whatsapp_user_sort_order');
function custom_whatsapp_user_sort_order($query)
{
    if (!is_admin()) {
        return;
    }

    $orderby = $query->get('orderby');

    if ('phone' === $orderby) {
        $query->set('meta_key', 'phone');
        $query->set('orderby', 'meta_value');
    }

    if ('loyalty_points' === $orderby) {
        $query->set('meta_key', 'loyalty_points');
        $query->set('orderby', 'meta_value_num'); // Use meta_value_num for numerical sorting
    }
}

// Register REST route to get user profile data
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/user-profile', array(
        'methods' => 'GET',
        'callback' => 'get_user_profile_data',
        'permission_callback' => function () {
            return current_user_can('read'); // Adjust permissions as needed
        },
    ));
});

function get_user_profile_data(WP_REST_Request $request)
{
    $profile_id = $request->get_param('profile_id');

    if (!$profile_id) {
        return new WP_Error('no_profile_id', 'Profile ID is required', array('status' => 400));
    }

    $user_post = get_post($profile_id);

    if (!$user_post) {
        return new WP_Error('profile_not_found', 'Profile not found', array('status' => 404));
    }

    $phone = get_post_meta($profile_id, 'phone', true);
    $loyalty_points = get_post_meta($profile_id, 'loyalty_points', true);

    return array(
        'name' => $user_post->post_title,
        'phone' => $phone,
        'loyalty_points' => $loyalty_points,
    );
}


// Register custom REST route for fetching user receipts
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/receipts', array(
        'methods' => 'GET',
        'callback' => 'custom_get_user_receipts',
        'permission_callback' => function () {
            return current_user_can('read'); // Adjust permissions as needed
        },
    ));
});


// Handle fetching user receipts
function custom_get_user_receipts(WP_REST_Request $request)
{
    $profile_id = $request->get_param('profile_id');

    if (!$profile_id) {
        return new WP_Error('missing_profile_id', 'Profile ID is required.', array('status' => 400));
    }

    // Query for receipts associated with the given profile ID
    $args = array(
        'post_type'   => 'receipt',
        'meta_key'    => 'profile_id',
        'meta_value'  => $profile_id,
        'post_status' => 'publish',
        'posts_per_page' => -1, // Retrieve all receipts
    );

    $query = new WP_Query($args);

    if (!$query->have_posts()) {
        return array('message' => 'No receipts found for this profile.');
    }

    $receipts = array();

    foreach ($query->posts as $post) {
        $attachment_id = get_post_thumbnail_id($post->ID);
        $receipt = array(
            'id'             => $post->ID,
            'date_uploaded'  => get_the_date('Y-m-d', $post->ID),
            'receipt_image'  => $attachment_id ? wp_get_attachment_url($attachment_id) : '',
        );
        $receipts[] = $receipt;
    }

    return $receipts;
}

// ----------------------------------------- V2

// add_action('rest_api_init', function () {
//     register_rest_route('chatbot/v1', '/respond', [
//         'methods' => 'POST',
//         'callback' => 'chatbot_api_response',
//         'permission_callback' => '__return_true',
//     ]);
// });

function chatbot_api_response($request)
{
    global $wpdb;

    $params     = $request->get_json_params();
    $user_input = sanitize_text_field($params['message'] ?? '');
    $name       = sanitize_text_field($params['name'] ?? '');
    $phone      = sanitize_text_field($params['phone'] ?? '');

    if (!$user_input || !$phone || !$name) {
        return new WP_REST_Response(['error' => 'Missing required fields.'], 400);
    }

    // Check if user already welcomed
    $welcomed_table = $wpdb->prefix . 'bot_welcomed_users';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $welcomed_table WHERE phone = %s",
        $phone
    ));
    if (!$existing) {
        $wpdb->insert($welcomed_table, [
            'name'  => $name,
            'phone' => $phone,
        ]);

        $welcome_table = $wpdb->prefix . 'bot_welcome_settings';
        $welcome = $wpdb->get_row("SELECT * FROM $welcome_table LIMIT 1");

        if ($welcome) {
            $welcome_text = str_replace('{name}', $name, $welcome->welcome_text);
            return [
                'welcome_text'  => $welcome_text,
                'welcome_image' => $welcome->welcome_image,
            ];
        } else {
            return [
                'welcome_text'  => "Welcome, $name!",
                'welcome_image' => '',
            ];
        }
    }

    $response_table = $wpdb->prefix . 'bot_responses';
    $like = '%' . $wpdb->esc_like($user_input) . '%';
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $response_table WHERE keywords LIKE %s",
        $like
    ));

    $response_text = null;
    $response_id = null;

    foreach ($results as $row) {
        $keywords = array_map('trim', explode(',', $row->keywords));
        foreach ($keywords as $keyword) {
            if (stripos($user_input, $keyword) !== false) {
                $possible_responses = array_map('trim', explode(',', $row->response));
                $response_text = $possible_responses[array_rand($possible_responses)];
                $response_id = $row->id;
                break 2; // break both loops
            }
        }
    }

    // Log analytics
    $analytics_table = $wpdb->prefix . 'bot_analytics';
    $wpdb->insert($analytics_table, [
        'phone'       => $phone,
        'query'       => $user_input,
        'response'    => $response_text,
        'response_id' => $response_id,
        'created_at'  => current_time('mysql'),
    ]);

    if (!$response_text) {
        $response_text = 'Sorry, I did not understand that.';
    }

    return ['response' => $response_text, 'keywords' => $keywords];
}



add_action('rest_api_init', function () {
    register_rest_route('chatbot/v1', '/media', [
        'methods' => 'POST',
        'callback' => 'chatbot_media_upload_handler',
        'permission_callback' => '__return_true',
    ]);
});

function chatbot_media_upload_handler($request)
{
    global $wpdb;
    $table = $wpdb->prefix . 'chatbot_media';

    $params = $request->get_json_params();
    $sender = sanitize_text_field($params['sender'] ?? '');
    $media = $params['media'] ?? [];

    if (empty($sender) || empty($media)) {
        return new WP_REST_Response(['error' => 'Missing sender or media'], 400);
    }

    $uploaded_urls = [];

    foreach ($media as $base64) {
        $decoded = base64_decode($base64, true);
        if (!$decoded) continue;

        $filename = 'image-' . time() . '-' . wp_generate_password(4, false) . '.jpg';
        $upload = wp_upload_bits($filename, null, $decoded);

        if (!$upload['error']) {
            $filetype = wp_check_filetype($upload['file'], null);
            $attachment = [
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name($filename),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];
            $attach_id = wp_insert_attachment($attachment, $upload['file']);
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);

            $uploaded_urls[] = wp_get_attachment_url($attach_id);
        }
    }

    // Save to DB: convert media URLs array to JSON or serialized string
    if (!empty($uploaded_urls)) {
        $wpdb->insert(
            $table,
            [
                'sender' => $sender,                          // matches VARCHAR(100) sender column
                'media_urls' => maybe_serialize($uploaded_urls), // matches LONGTEXT media_urls column
                'uploaded_at' => current_time('mysql'),          // matches DATETIME uploaded_at column
            ]
        );
    }

    return new WP_REST_Response([
        'sender' => $sender,
        'uploaded' => $uploaded_urls,
    ]);
}


// --------------------------- ACF
add_action('rest_api_init', function () {
    register_rest_route('chatbot/v1', '/respond', [
        'methods' => 'POST',
        'callback' => 'chatbot_match_message',
    ]);
});

function chatbot_get_flow_from_acf()
{
    $steps = get_posts([
        'post_type' => 'chatbot_step',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ]);

    $flow = [];
    foreach ($steps as $step) {
        $state_name = $step->post_name; // slug

        $flow[$state_name] = [
            'question' => get_field('bot_question', $step->ID),
            'next_state' => get_field('next_state', $step->ID)->post_name ?? null,
            'save_key' => get_field('save_key', $step->ID),
            'response_image' => get_field('response_image', $step->ID),
            'confirmation' => get_field('is_confirmation', $step->ID),
        ];
    }

    return $flow;
}

function get_user_session_state($phone)
{
    return get_option("chatbot_session_" . sanitize_key($phone), []);
}

function update_user_session_state($phone, $state, $data = [])
{
    update_option("chatbot_session_" . sanitize_key($phone), [
        'state' => $state,
        'data' => $data,
    ]);
}

function chatbot_save_log($phone, $name, $state, $user_input, $session_data)
{
    global $wpdb;

    $result = $wpdb->insert(
        $wpdb->prefix . 'chatbot_logs',
        [
            'phone'        => $phone,
            'name'         => $name,
            'state'        => $state,
            'user_input'   => $user_input,
            'session_data' => maybe_serialize($session_data),
        ],
        [
            '%s',
            '%s',
            '%s',
            '%s',
            '%s'
        ]
    );

    if ($result === false) {
        error_log('❌ chatbot_save_log insert failed: ' . $wpdb->last_error);
    } else {
        error_log('✅ chatbot_save_log inserted row ID: ' . $wpdb->insert_id);
    }
}


function handle_dynamic_conversation($user_input, $phone, $name)
{
    $flow = chatbot_get_flow_from_acf();
    $session = get_user_session_state($phone);
    $state = $session['state'] ?? 'start';
    $data = $session['data'] ?? [];

    if (strtolower(trim($user_input)) === 'cancel') {
        update_user_session_state($phone, null, []);
        chatbot_save_log($phone, $name, 'cancelled', $user_input, []);
        return ['response' => "Your session has been cancelled. How can I help you next?"];
    }

    if (!isset($flow[$state])) {
        $state = 'start';
    }

    $step = $flow[$state];

    // Save input
    if (!empty($step['save_key'])) {
        $data[$step['save_key']] = $user_input;
    }

    chatbot_save_log($phone, $name, $state, $user_input, $data);

    // Reset if invalid or missing in flow
    if (!isset($flow[$state])) {
        $state = 'start';
    }

    $step = $flow[$state];

    // // If session state is confirmation step but user sends unrelated input, reset session
    // if (!empty($step['confirmation']) && strtolower($user_input) !== 'yes' && strtolower($user_input) !== 'no') {
    //     // Clear session, start fresh
    //     update_user_session_state($phone, null, []);
    //     // Optionally respond to user that previous confirmation cancelled automatically
    //     return ['response' => "Let's start again. How can I help you?"];
    // }

    // Continue
    $next = $step['next_state'];
    if ($next && isset($flow[$next])) {
        update_user_session_state($phone, $next, $data);
        $next_question = strtr($flow[$next]['question'], array_merge(['{name}' => $name], $data));

        if (!empty($data['__start']) && $next === $data['__start']) {
            $next_question .= "\n\n(Type 'cancel' anytime to stop.)";
        }

        if (empty($flow[$next]['next_state'])) {
            update_user_session_state($phone, null, []);
        }
        return ['response' => $next_question, 'response_image' => $flow[$next]['response_image']];
    }
}

function saveToAnalytics($user_input, $phone)
{
    global $wpdb;
    $response_table = $wpdb->prefix . 'bot_responses';
    $like = '%' . $wpdb->esc_like($user_input) . '%';
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $response_table WHERE keywords LIKE %s",
        $like
    ));

    $response_text = null;
    $response_id = null;

    foreach ($results as $row) {
        $keywords = array_map('trim', explode(',', $row->keywords));
        foreach ($keywords as $keyword) {
            if (stripos($user_input, $keyword) !== false) {
                $possible_responses = array_map('trim', explode(',', $row->response));
                $response_text = $possible_responses[array_rand($possible_responses)];
                $response_id = $row->id;
                break 2; // break both loops
            }
        }
    }

    // Log analytics
    $analytics_table = $wpdb->prefix . 'bot_analytics';
    $wpdb->insert($analytics_table, [
        'phone'       => $phone,
        'query'       => $user_input,
        'response'    => $response_text,
        'response_id' => $response_id,
        'created_at'  => current_time('mysql'),
    ]);
}

function chatbot_match_message($request)
{

    global $wpdb;

    $params     = $request->get_json_params();
    $user_input = sanitize_text_field($params['message'] ?? '');
    $name       = sanitize_text_field($params['name'] ?? '');
    $phone      = sanitize_text_field($params['phone'] ?? '');

    if (!$user_input || !$phone || !$name) {
        return new WP_REST_Response(['error' => 'Missing required fields.'], 400);
    }

    // Check if user already welcomed
    $welcomed_table = $wpdb->prefix . 'bot_welcomed_users';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $welcomed_table WHERE phone = %s",
        $phone
    ));


    if (!$existing) {
        $wpdb->insert($welcomed_table, [
            'name'  => $name,
            'phone' => $phone,
        ]);

        $welcome_table = $wpdb->prefix . 'bot_welcome_settings';
        $welcome = $wpdb->get_row("SELECT * FROM $welcome_table LIMIT 1");

        if ($welcome) {
            $welcome_text = str_replace('{name}', $name, $welcome->welcome_text);
            return [
                'welcome_text'  => $welcome_text,
                'welcome_image' => $welcome->welcome_image,
            ];
        } else {
            return [
                'welcome_text'  => "Welcome, $name!",
                'welcome_image' => '',
            ];
        }
    } else {
        $session = get_user_session_state($phone);
        if (isset($session['state']) && $session['state'] !== null && $session['state'] !== '') {
            saveToAnalytics($user_input, $phone);
            return handle_dynamic_conversation($user_input, $phone, $name);
        }

        // Find matching chatbot response
        $matched_post = chatbot_match_text($user_input);

        if ($matched_post) {
            $start_step = get_field('start_state', $matched_post->ID);

            if ($start_step && get_post_type($start_step) === 'chatbot_step') {
                $start_slug = is_object($start_step) ? $start_step->post_name : get_post_field('post_name', $start_step);
                update_user_session_state($phone, $start_slug, []);

                $flow = chatbot_get_flow_from_acf();
                $question = $flow[$start_slug]['question'] ?? "Let's begin!";
                saveToAnalytics($user_input, $phone);
                return [
                    'response' => strtr($question, ['{name}' => $name]) . "\n\n(Type 'cancel' anytime to stop.)",

                    'start_step ' => $flow,
                ];
            }

            $response_text = get_field('response_text', $matched_post->ID);
            $response_image = get_field('response_image', $matched_post->ID);
            saveToAnalytics($user_input, $phone);
            //
            return [
                'response' => $response_text,
                'response_image' => $response_image,
            ];
        }
        saveToAnalytics($user_input, $phone);
        return ['response' => "Sorry, I did not understand that."];
    }
}

function chatbot_match_text(string $message)
{
    $args = [
        'post_type' => 'chatbot_response',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ];
    $responses = get_posts($args);

    foreach ($responses as $post) {
        $match_type = get_field('match_type', $post->ID);
        $pattern = get_field('match_pattern', $post->ID);

        if (!$pattern) continue;

        switch ($match_type) {
            case 'exact':
                if (strtolower($message) === strtolower($pattern)) return $post;
                break;
            case 'contains':
                if (stripos($message, $pattern) !== false) return $post;
                break;
            case 'starts_with':
                if (stripos($message, $pattern) === 0) return $post;
                break;
            case 'regex':
                if (@preg_match("/$pattern/i", $message)) return $post;
                break;
        }
    }

    return null;
}

// --------------------------- End ACF