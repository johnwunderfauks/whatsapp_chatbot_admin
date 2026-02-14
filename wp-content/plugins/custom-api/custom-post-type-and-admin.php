<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Register a custom post type for storing receipts
 */
function custom_register_receipt_post_type()
{
    $labels = array(
        'name'                  => _x('Receipts', 'Post type general name', 'textdomain'),
        'singular_name'         => _x('Receipt', 'Post type singular name', 'textdomain'),
        'menu_name'             => _x('Receipts', 'Admin Menu text', 'textdomain'),
        'name_admin_bar'        => _x('Receipt', 'Add New on Toolbar', 'textdomain'),
        'add_new'               => __('Add New', 'textdomain'),
        'add_new_item'          => __('Add New Receipt', 'textdomain'),
        'new_item'              => __('New Receipt', 'textdomain'),
        'edit_item'             => __('Edit Receipt', 'textdomain'),
        'view_item'             => __('View Receipt', 'textdomain'),
        'all_items'             => __('All Receipts', 'textdomain'),
        'search_items'          => __('Search Receipts', 'textdomain'),
        'not_found'             => __('No receipts found.', 'textdomain'),
        'not_found_in_trash'    => __('No receipts found in Trash.', 'textdomain'),
        'featured_image'        => _x('Receipt Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'textdomain'),
        'set_featured_image'    => _x('Set receipt image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'textdomain'),
        'remove_featured_image' => _x('Remove receipt image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'textdomain'),
        'use_featured_image'    => _x('Use as receipt image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'textdomain'),
        'archives'              => _x('Receipt archives', 'The post type archive label used in nav menus. Added in 4.4', 'textdomain'),
        'insert_into_item'      => _x('Insert into receipt', 'Overrides the “Insert into post” phrase for this post type. Added in 4.4', 'textdomain'),
        'uploaded_to_this_item' => _x('Uploaded to this receipt', 'Overrides the “Uploaded to this post” phrase for this post type. Added in 4.4', 'textdomain'),
        'filter_items_list'     => _x('Filter receipts list', 'Screen reader text. Added in 4.4', 'textdomain'),
        'items_list_navigation' => _x('Receipts list navigation', 'Screen reader text. Added in 4.4', 'textdomain'),
        'items_list'            => _x('Receipts list', 'Screen reader text. Added in 4.4', 'textdomain'),
    );

    $args = array(
        'labels'                => $labels,
        'public'                => true,
        'publicly_queryable'    => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'query_var'             => true,
        'rewrite'               => array('slug' => 'receipt'),
        'capability_type'       => 'post',
        'has_archive'           => true,
        'hierarchical'          => false,
        'menu_position'         => null,
        'supports'              => array('title', 'editor', 'thumbnail'),
    );

    register_post_type('receipt', $args);
}
add_action('init', 'custom_register_receipt_post_type');

/**
 * Add meta boxes for receipt custom fields
 */
add_action('add_meta_boxes', 'custom_add_receipt_meta_boxes');

function custom_add_receipt_meta_boxes()
{
    add_meta_box(
        'receipt_details',
        'Receipt Details',
        'custom_receipt_details_callback',
        'receipt',
        'normal',
        'high'
    );
}

function custom_receipt_details_callback($post)
{
    // Add nonce for security
    wp_nonce_field('receipt_details_nonce', 'receipt_details_nonce_field');

    // Get existing values
    $receipt_id_on_receipt = get_post_meta($post->ID, 'receipt_id_on_receipt', true);
    $store_name = get_post_meta($post->ID, 'store_name', true);
    $purchase_date = get_post_meta($post->ID, 'purchase_date', true);
    $total_amount = get_post_meta($post->ID, 'total_amount', true);
    $currency = get_post_meta($post->ID, 'currency', true);
    $profile_id = get_post_meta($post->ID, 'profile_id', true);
    $raw_ocr_text = get_post_meta($post->ID, 'raw_ocr_text', true);
    $items = get_post_meta($post->ID, 'receipt_items', true);
    $processed_at = get_post_meta($post->ID, 'processed_at', true);

    // Get WhatsApp user info
    $user_post = get_post($profile_id);
    $username = $user_post ? $user_post->post_title : 'N/A';
    $phone = get_post_meta($profile_id, 'phone', true);

    $fraud_score = get_post_meta($post->ID, 'fraud_score', true);
    $fraud_decision = get_post_meta($post->ID, 'fraud_decision', true);
    $fraud_reasons = get_post_meta($post->ID, 'fraud_reasons', true);

    $additional_images = get_post_meta($post->ID, 'additional_images', true);
    $total_images = get_post_meta($post->ID, 'total_images', true);

    ?>
    <style>
        .receipt-meta-row {
            margin-bottom: 15px;
        }
        .receipt-meta-row label {
            display: inline-block;
            width: 150px;
            font-weight: bold;
        }
        .receipt-meta-row input[type="text"],
        .receipt-meta-row input[type="date"] {
            width: 60%;
            padding: 5px;
        }
        .receipt-items {
            width: 100%;
            min-height: 150px;
            font-family: monospace;
        }
        .receipt-raw-text {
            width: 100%;
            min-height: 200px;
            font-family: monospace;
            background: #f5f5f5;
        }
        .receipt-meta-info {
            background: #e8f4f8;
            padding: 10px;
            border-left: 4px solid #0073aa;
            margin-bottom: 20px;
        }
        .notify-user-btn {
            background: #00a32a;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 14px;
            cursor: pointer;
            border-radius: 3px;
            margin-top: 10px;
        }
        .notify-user-btn:hover {
            background: #008a20;
        }
        .notify-user-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        #notify-status {
            margin-top: 10px;
            padding: 10px;
            display: none;
        }
        #notify-status.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            display: block;
        }
        #notify-status.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            display: block;
        }
    </style>

    <?php 
        if ($fraud_score) {
        $color = $fraud_score >= 70 ? '#dc3232' : ($fraud_score >= 40 ? '#f0b429' : '#46b450');
        ?>
        <div style="background: #f8f9fa; padding: 15px; border-left: 4px solid <?php echo $color; ?>; margin-bottom: 20px;">
            <h4 style="margin: 0 0 10px;">🛡️ Fraud Detection</h4>
            <p><strong>Risk Score:</strong> <span style="color: <?php echo $color; ?>; font-size: 20px; font-weight: bold;"><?php echo $fraud_score; ?>/100</span></p>
            <p><strong>Decision:</strong> 
                <span style="padding: 3px 8px; background: <?php echo $color; ?>; color: white; border-radius: 3px;">
                    <?php echo strtoupper($fraud_decision); ?>
                </span>
            </p>
            <?php if (is_array($fraud_reasons) && !empty($fraud_reasons)): ?>
                <p><strong>Flags:</strong></p>
                <ul style="margin: 5px 0; padding-left: 20px;">
                    <?php foreach ($fraud_reasons as $reason): ?>
                        <li><?php echo esc_html($reason); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }
    ?>

    <div class="receipt-meta-info">
        <p><strong>WhatsApp User:</strong> <?php echo esc_html($username); ?></p>
        <p><strong>Phone:</strong> <?php echo esc_html($phone); ?></p>
        <p><strong>Profile ID:</strong> <?php echo esc_html($profile_id); ?></p>
        <?php if ($processed_at): ?>
            <p><strong>Processed At:</strong> <?php echo esc_html($processed_at); ?></p>
        <?php endif; ?>
        
        <!-- Notify Button -->
        <!-- <button type="button" id="notify-user-btn" class="notify-user-btn" 
                data-receipt-id="<?php echo esc_attr($post->ID); ?>"
                data-phone="<?php echo esc_attr($phone); ?>">
            📱 Notify User via WhatsApp
        </button> -->
        <div id="notify-status"></div>
    </div>

    <div class="receipt-meta-row">
        <label for="receipt_id_on_receipt">Receipt ID:</label>
        <input type="text" id="receipt_id_on_receipt" name="receipt_id_on_receipt" value="<?php echo esc_attr($receipt_id_on_receipt); ?>" />
    </div>
    <div class="receipt-meta-row">
        <label for="store_name">Store Name:</label>
        <input type="text" id="store_name" name="store_name" value="<?php echo esc_attr($store_name); ?>" />
    </div>

    <div class="receipt-meta-row">
        <label for="purchase_date">Purchase Date:</label>
        <input type="date" id="purchase_date" name="purchase_date" value="<?php echo esc_attr($purchase_date); ?>" />
    </div>

    <div class="receipt-meta-row">
        <label for="total_amount">Total Amount:</label>
        <input type="text" id="total_amount" name="total_amount" value="<?php echo esc_attr($total_amount); ?>" />
    </div>

    <div class="receipt-meta-row">
        <label for="currency">Currency:</label>
        <input type="text" id="currency" name="currency" value="<?php echo esc_attr($currency); ?>" placeholder="USD, THB, etc." />
    </div>

    <div class="receipt-meta-row">
        <label for="receipt_items">Items (one per line):</label><br>
        
        <textarea id="receipt_items" name="receipt_items" class="receipt-items">
        <?php 
            if (!empty($items)) {
                echo esc_textarea(json_encode($items, JSON_PRETTY_PRINT));
            }
        ?>
        </textarea>
        <p class="description">Edit JSON carefully. Must remain valid format.</p>
    </div>

    <div class="receipt-meta-row">
        <label for="raw_ocr_text">Raw OCR Text:</label><br>
        <textarea id="raw_ocr_text" name="raw_ocr_text" class="receipt-raw-text" readonly><?php echo esc_textarea($raw_ocr_text); ?></textarea>
        <p class="description">This is read-only (original OCR output)</p>
    </div>

     <script>
    // jQuery(document).ready(function($) {
        // $('#notify-user-btn').on('click', function() {
        //     var btn = $(this);
        //     var receiptId = btn.data('receipt-id');
        //     var phone = btn.data('phone');
        //     var statusDiv = $('#notify-status');
            
        //     if (!phone) {
        //         statusDiv.removeClass('success').addClass('error').text('❌ No phone number found for this user').show();
        //         return;
        //     }
            
        //     btn.prop('disabled', true).text('Sending...');
        //     statusDiv.hide();
            
        //     $.ajax({
        //         url: '<?php echo admin_url('admin-ajax.php'); ?>',
        //         type: 'POST',
        //         data: {
        //             action: 'notify_receipt_user',
        //             receipt_id: receiptId,
        //             nonce: '<?php echo wp_create_nonce('notify_receipt_nonce'); ?>'
        //         },
        //         success: function(response) {
        //             if (response.success) {
        //                 statusDiv.removeClass('error').addClass('success').text('✅ ' + response.data.message).show();
        //             } else {
        //                 statusDiv.removeClass('success').addClass('error').text('❌ ' + response.data.message).show();
        //             }
        //         },
        //         error: function() {
        //             statusDiv.removeClass('success').addClass('error').text('❌ Failed to send notification').show();
        //         },
        //         complete: function() {
        //             btn.prop('disabled', false).text('📱 Notify User via WhatsApp');
        //         }
        //     });
        // });
    // }); 
    </script>
    <?php
}

/**
 * AJAX handler to notify user via WhatsApp
 */
add_action('wp_ajax_notify_receipt_user', 'custom_notify_receipt_user');

function custom_notify_receipt_user()
{
    // Verify nonce
    check_ajax_referer('notify_receipt_nonce', 'nonce');
    
    // Check permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    $receipt_id = isset($_POST['receipt_id']) ? intval($_POST['receipt_id']) : 0;
    
    if (!$receipt_id) {
        wp_send_json_error(array('message' => 'Invalid receipt ID'));
    }
    
    // Get receipt data
    $profile_id = get_post_meta($receipt_id, 'profile_id', true);
    $store_name = get_post_meta($receipt_id, 'store_name', true);
    $purchase_date = get_post_meta($receipt_id, 'purchase_date', true);
    $total_amount = get_post_meta($receipt_id, 'total_amount', true);
    $currency = get_post_meta($receipt_id, 'currency', true);
    
    // Get phone number
    $phone = get_post_meta($profile_id, 'phone', true);
    
    if (!$phone) {
        wp_send_json_error(array('message' => 'No phone number found'));
    }
    
    // Call your Node.js webhook to send WhatsApp message
    $webhook_url = get_option('whatsapp_webhook_url', 'YOUR_NODEJS_WEBHOOK_URL');
    
    // $response = wp_remote_post($webhook_url, array(
    //     'headers' => array('Content-Type' => 'application/json'),
    //     'body' => json_encode(array(
    //         'phone' => $phone,
    //         'message' => "✅ Your receipt has been processed!\n\n" .
    //                     "Store: $store_name\n" .
    //                     "Date: $purchase_date\n" .
    //                     "Total: $currency $total_amount\n\n" .
    //                     "Thank you for uploading your receipt!",
    //         'receipt_id' => $receipt_id
    //     )),
    //     'timeout' => 15
    // ));

    $response = wp_remote_post($webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'phone' => $phone,
                'use_template' => true,
                'template_name' => 'receipt_processed', // Template name
                'template_params' => array(
                    $store_name,
                    $purchase_date,
                    $currency . ' ' . $total_amount
                ),
                'receipt_id' => $receipt_id,
                'message' => "✅ Your receipt has been processed!\n\n" .
                "Store: $store_name\n" .
                "Date: $purchase_date\n" .
                "Total: $currency $total_amount\n\n" .
                "Thank you for uploading your receipt!",
            )),
            'timeout' => 30
        ));
    
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Failed to send notification: ' . $response->get_error_message()));
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['success']) && $data['success']) {
        // Log notification
        update_post_meta($receipt_id, 'notification_sent', current_time('mysql'));
        
        wp_send_json_success(array('message' => 'Notification sent successfully to ' . $phone));
    } else {
        wp_send_json_error(array('message' => 'Failed to send notification'));
    }
}

/**
 * Update receipt status
 */
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/receipt-status', array(
        'methods' => 'POST',
        'callback' => 'custom_update_receipt_status',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
    ));
});

function custom_update_receipt_status(WP_REST_Request $request) {
    $receipt_id = $request->get_param('receipt_id');
    $status = $request->get_param('status'); // accepted, pending, rejected
    $rejection_reason = $request->get_param('rejection_reason');
    
    if (!$receipt_id || !$status) {
        return new WP_Error('missing_data', 'Receipt ID and status are required', array('status' => 400));
    }
    
    // Validate status
    $valid_statuses = array('accepted', 'pending', 'rejected');
    if (!in_array($status, $valid_statuses)) {
        return new WP_Error('invalid_status', 'Status must be: accepted, pending, or rejected', array('status' => 400));
    }
    
    $receipt = get_post($receipt_id);
    if (!$receipt || $receipt->post_type !== 'receipt') {
        return new WP_Error('invalid_receipt', 'Receipt not found', array('status' => 404));
    }
    
    // Update receipt status
    update_post_meta($receipt_id, 'receipt_status', $status);
    
    if ($status === 'rejected' && !empty($rejection_reason)) {
        update_post_meta($receipt_id, 'rejection_reason', sanitize_text_field($rejection_reason));
    }
    
    // If accepted, award points (if not already awarded)
    if ($status === 'accepted') {
        $profile_id = get_post_meta($receipt_id, 'profile_id', true);
        $points = intval(get_post_meta($receipt_id, 'loyalty_points', true));
        
        if ($profile_id && $points > 0) {
            $current_points = intval(get_post_meta($profile_id, 'loyalty_points', true));
            $new_points = $current_points + $points;
            update_post_meta($profile_id, 'loyalty_points', $new_points);
        }
    }
    
    return array(
        'success' => true,
        'receipt_id' => $receipt_id,
        'status' => $status,
        'message' => "Receipt status updated to {$status}"
    );
}

/**
 * Add custom columns to receipts admin
 */
add_filter('manage_receipt_posts_columns', 'add_receipt_custom_columns');
function add_receipt_custom_columns($columns) {
    $new_columns = array();
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        if ($key === 'title') {
            $new_columns['receipt_status'] = 'Status';
            $new_columns['fraud_score'] = 'Fraud Score';
        }
    }
    return $new_columns;
}

/**
 * Display custom column content
 */
add_action('manage_receipt_posts_custom_column', 'show_receipt_custom_columns', 10, 2);
function show_receipt_custom_columns($column, $post_id) {
    if ($column === 'receipt_status') {
        $status = get_post_meta($post_id, 'receipt_status', true) ?: 'pending';
        
        $colors = array(
            'accepted' => '#22c55e',
            'pending' => '#f59e0b',
            'rejected' => '#ef4444'
        );
        
        $color = $colors[$status] ?? '#6b7280';
        
        echo '<span style="background: ' . $color . '; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">' 
            . ucfirst($status) 
            . '</span>';
    }
    
    if ($column === 'fraud_score') {
        $fraud_score = floatval(get_post_meta($post_id, 'fraud_score', true));
        
        // Determine color based on score
        if ($fraud_score >= 80) {
            $color = '#ef4444'; // Red - High risk
            $emoji = '🚨';
        } elseif ($fraud_score >= 60) {
            $color = '#f97316'; // Orange - Medium-high risk
            $emoji = '⚠️';
        } elseif ($fraud_score >= 40) {
            $color = '#f59e0b'; // Yellow - Medium risk
            $emoji = '⚡';
        } elseif ($fraud_score >= 20) {
            $color = '#84cc16'; // Light green - Low-medium risk
            $emoji = '✓';
        } else {
            $color = '#22c55e'; // Green - Low risk
            $emoji = '✅';
        }
        
        if ($fraud_score > 0) {
            echo '<span style="background: ' . $color . '; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">' 
                . $emoji . ' ' . number_format($fraud_score, 1) 
                . '</span>';
        } else {
            echo '<span style="color: #999;">Not scored</span>';
        }
    }
}

/**
 * Make custom columns sortable
 */
add_filter('manage_edit-receipt_sortable_columns', 'receipt_sortable_columns');
function receipt_sortable_columns($columns) {
    $columns['receipt_status'] = 'receipt_status';
    $columns['fraud_score'] = 'fraud_score';
    return $columns;
}

/**
 * Handle sorting logic
 */
add_action('pre_get_posts', 'receipt_custom_orderby');
function receipt_custom_orderby($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    $orderby = $query->get('orderby');
    
    if ($orderby === 'receipt_status') {
        $query->set('meta_key', 'receipt_status');
        $query->set('orderby', 'meta_value');
    }
    
    if ($orderby === 'fraud_score') {
        $query->set('meta_key', 'fraud_score');
        $query->set('orderby', 'meta_value_num');
    }
}

/**
 * Add filter dropdowns to receipts list
 */
add_action('restrict_manage_posts', 'add_receipt_filters');
function add_receipt_filters($post_type) {
    if ($post_type !== 'receipt') {
        return;
    }
    
    // Status filter
    $current_status = isset($_GET['receipt_status_filter']) ? $_GET['receipt_status_filter'] : '';
    ?>
    <select name="receipt_status_filter">
        <option value="">All Statuses</option>
        <option value="pending" <?php selected($current_status, 'pending'); ?>>⏳ Pending</option>
        <option value="accepted" <?php selected($current_status, 'accepted'); ?>>✅ Accepted</option>
        <option value="rejected" <?php selected($current_status, 'rejected'); ?>>❌ Rejected</option>
    </select>
    <?php
    
    // Fraud score filter
    $current_fraud = isset($_GET['fraud_score_filter']) ? $_GET['fraud_score_filter'] : '';
    ?>
    <select name="fraud_score_filter">
        <option value="">All Fraud Scores</option>
        <option value="0-20" <?php selected($current_fraud, '0-20'); ?>>✅ Low (0-20)</option>
        <option value="21-40" <?php selected($current_fraud, '21-40'); ?>>✓ Low-Medium (21-40)</option>
        <option value="41-60" <?php selected($current_fraud, '41-60'); ?>>⚡ Medium (41-60)</option>
        <option value="61-80" <?php selected($current_fraud, '61-80'); ?>>⚠️ High (61-80)</option>
        <option value="81-100" <?php selected($current_fraud, '81-100'); ?>>🚨 Very High (81-100)</option>
        <option value="not_scored" <?php selected($current_fraud, 'not_scored'); ?>>Not Scored</option>
    </select>
    <?php
}

/**
 * Filter receipts based on selected filters
 */
add_filter('parse_query', 'filter_receipts_by_custom_fields');
function filter_receipts_by_custom_fields($query) {
    global $pagenow, $typenow;
    
    if (!is_admin() || $pagenow !== 'edit.php' || $typenow !== 'receipt') {
        return;
    }
    
    $meta_query = array();
    
    // Filter by status
    if (isset($_GET['receipt_status_filter']) && !empty($_GET['receipt_status_filter'])) {
        $meta_query[] = array(
            'key' => 'receipt_status',
            'value' => sanitize_text_field($_GET['receipt_status_filter']),
            'compare' => '='
        );
    }
    
    // Filter by fraud score
    if (isset($_GET['fraud_score_filter']) && !empty($_GET['fraud_score_filter'])) {
        $fraud_filter = sanitize_text_field($_GET['fraud_score_filter']);
        
        if ($fraud_filter === 'not_scored') {
            // Show receipts with no fraud score or score = 0
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key' => 'fraud_score',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => 'fraud_score',
                    'value' => 0,
                    'compare' => '=',
                    'type' => 'NUMERIC'
                )
            );
        } else {
            // Parse range (e.g., "0-20", "81-100")
            $range = explode('-', $fraud_filter);
            if (count($range) === 2) {
                $min = intval($range[0]);
                $max = intval($range[1]);
                
                $meta_query[] = array(
                    'key' => 'fraud_score',
                    'value' => array($min, $max),
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                );
            }
        }
    }
    
    // Apply meta query if filters are set
    if (!empty($meta_query)) {
        if (count($meta_query) > 1) {
            $meta_query['relation'] = 'AND';
        }
        $query->set('meta_query', $meta_query);
    }
}

/**
 * Add settings page for WhatsApp webhook
 */
add_action('admin_menu', 'custom_add_whatsapp_settings_page');

function custom_add_whatsapp_settings_page()
{
    add_options_page(
        'WhatsApp Settings',
        'WhatsApp Settings',
        'manage_options',
        'whatsapp-settings',
        'custom_whatsapp_settings_page'
    );
}

function custom_whatsapp_settings_page()
{
    if (isset($_POST['whatsapp_webhook_url'])) {
        check_admin_referer('whatsapp_settings_nonce');
        update_option('whatsapp_webhook_url', sanitize_text_field($_POST['whatsapp_webhook_url']));
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    $webhook_url = get_option('whatsapp_webhook_url', '');
    ?>
    <div class="wrap">
        <h1>WhatsApp Settings</h1>
        <form method="post">
            <?php wp_nonce_field('whatsapp_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Webhook URL</th>
                    <td>
                        <input type="text" name="whatsapp_webhook_url" value="<?php echo esc_attr($webhook_url); ?>" class="regular-text" />
                        <p class="description">Enter your Node.js webhook URL for sending WhatsApp notifications</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Save receipt meta box data
 */
add_action('save_post_receipt', 'custom_save_receipt_meta');

function custom_save_receipt_meta($post_id)
{
    // Check nonce
    if (!isset($_POST['receipt_details_nonce_field']) || 
        !wp_verify_nonce($_POST['receipt_details_nonce_field'], 'receipt_details_nonce')) {
        return;
    }

    // Check autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save store name
    if (isset($_POST['store_name'])) {
        update_post_meta($post_id, 'store_name', sanitize_text_field($_POST['store_name']));
    }

    // Save receipt id
    if (isset($_POST['receipt_id_on_receipt'])) {
        update_post_meta($post_id, 'receipt_id_on_receipt', sanitize_text_field($_POST['receipt_id_on_receipt']));
    }

    // Save purchase date
    if (isset($_POST['purchase_date'])) {
        update_post_meta($post_id, 'purchase_date', sanitize_text_field($_POST['purchase_date']));
    }

    // Save total amount
    if (isset($_POST['total_amount'])) {
        update_post_meta($post_id, 'total_amount', sanitize_text_field($_POST['total_amount']));
    }

    // Save currency
    if (isset($_POST['currency'])) {
        update_post_meta($post_id, 'currency', sanitize_text_field($_POST['currency']));
    }

    // Save items (convert textarea lines to array)
    if (isset($_POST['receipt_items'])) {

        $raw_json = wp_unslash($_POST['receipt_items']);

        $decoded = json_decode($raw_json, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            update_post_meta($post_id, 'receipt_items', $decoded);
        } else {
            // Optional: show admin error
            error_log('Invalid receipt_items JSON for post ' . $post_id);
        }
    }

    // Note: raw_ocr_text is not saved here because it's read-only
}

/**
 * Register WhatsApp User custom post type
 */
function custom_register_whatsapp_user_post_type()
{
    $labels = array(
        'name'                  => _x('WhatsApp Users', 'Post type general name', 'textdomain'),
        'singular_name'         => _x('WhatsApp User', 'Post type singular name', 'textdomain'),
        'menu_name'             => _x('WhatsApp Users', 'Admin Menu text', 'textdomain'),
        'name_admin_bar'        => _x('WhatsApp User', 'Add New on Toolbar', 'textdomain'),
        'add_new'               => __('Add New', 'textdomain'),
        'add_new_item'          => __('Add New WhatsApp User', 'textdomain'),
        'new_item'              => __('New WhatsApp User', 'textdomain'),
        'edit_item'             => __('Edit WhatsApp User', 'textdomain'),
        'view_item'             => __('View WhatsApp User', 'textdomain'),
        'all_items'             => __('All WhatsApp Users', 'textdomain'),
        'search_items'          => __('Search WhatsApp Users', 'textdomain'),
        'not_found'             => __('No WhatsApp users found.', 'textdomain'),
        'not_found_in_trash'    => __('No WhatsApp users found in Trash.', 'textdomain'),
    );

    $args = array(
        'labels'                => $labels,
        'public'                => true,
        'publicly_queryable'    => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'query_var'             => true,
        'rewrite'               => array('slug' => 'whatsapp_user'),
        'capability_type'       => 'post',
        'has_archive'           => true,
        'hierarchical'          => false,
        'menu_position'         => null,
        'supports'              => array('title', 'editor'),
    );

    register_post_type('whatsapp_user', $args);
}
add_action('init', 'custom_register_whatsapp_user_post_type');


/**
 * Register Rewards custom post type
 */
function custom_register_rewards_post_type()
{
    $labels = array(
        'name'                  => _x('Rewards', 'Post type general name', 'textdomain'),
        'singular_name'         => _x('Reward', 'Post type singular name', 'textdomain'),
        'menu_name'             => _x('Rewards', 'Admin Menu text', 'textdomain'),
        'name_admin_bar'        => _x('Reward', 'Add New on Toolbar', 'textdomain'),
        'add_new'               => __('Add New', 'textdomain'),
        'add_new_item'          => __('Add New Reward', 'textdomain'),
        'new_item'              => __('New Reward', 'textdomain'),
        'edit_item'             => __('Edit Reward', 'textdomain'),
        'view_item'             => __('View Reward', 'textdomain'),
        'all_items'             => __('All Rewards', 'textdomain'),
        'search_items'          => __('Search Rewards', 'textdomain'),
        'not_found'             => __('No rewards found.', 'textdomain'),
        'not_found_in_trash'    => __('No rewards found in Trash.', 'textdomain'),
    );

    $args = array(
        'labels'                => $labels,
        'public'                => true,
        'publicly_queryable'    => true,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'query_var'             => true,
        'rewrite'               => array('slug' => 'reward'),
        'capability_type'       => 'post',
        'has_archive'           => true,
        'hierarchical'          => false,
        'menu_position'         => null,
        'menu_icon'             => 'dashicons-awards',
        'supports'              => array('title', 'editor', 'thumbnail'),
    );

    register_post_type('reward', $args);
}
add_action('init', 'custom_register_rewards_post_type');

/**
 * Add meta boxes for reward custom fields
 */
add_action('add_meta_boxes', 'custom_add_reward_meta_boxes');

function custom_add_reward_meta_boxes()
{
    add_meta_box(
        'reward_details',
        'Reward Details',
        'custom_reward_details_callback',
        'reward',
        'normal',
        'high'
    );
}

function custom_reward_details_callback($post)
{
    wp_nonce_field('reward_details_nonce', 'reward_details_nonce_field');

    $points_cost = get_post_meta($post->ID, 'points_cost', true);
    $max_quantity = get_post_meta($post->ID, 'max_quantity', true);
    $current_quantity = get_post_meta($post->ID, 'current_quantity', true);
    $is_active = get_post_meta($post->ID, 'is_active', true);
    $reward_type = get_post_meta($post->ID, 'reward_type', true);

    ?>
    <style>
        .reward-meta-row {
            margin-bottom: 15px;
        }
        .reward-meta-row label {
            display: inline-block;
            width: 150px;
            font-weight: bold;
        }
        .reward-meta-row input[type="number"],
        .reward-meta-row input[type="text"],
        .reward-meta-row select {
            width: 300px;
            padding: 5px;
        }
    </style>

    <div class="reward-meta-row">
        <label for="points_cost">Points Cost:</label>
        <input type="number" id="points_cost" name="points_cost" value="<?php echo esc_attr($points_cost); ?>" min="0" required />
    </div>

    <div class="reward-meta-row">
        <label for="reward_type">Reward Type:</label>
        <select id="reward_type" name="reward_type">
            <option value="physical" <?php selected($reward_type, 'physical'); ?>>Physical Item</option>
            <option value="voucher" <?php selected($reward_type, 'voucher'); ?>>Voucher</option>
        </select>
    </div>

    <div class="reward-meta-row">
        <label for="max_quantity">Maximum Quantity:</label>
        <input type="number" id="max_quantity" name="max_quantity" value="<?php echo esc_attr($max_quantity); ?>" min="0" />
        <small>Leave 0 for unlimited</small>
    </div>

    <div class="reward-meta-row">
        <label for="current_quantity">Current Quantity:</label>
        <input type="number" id="current_quantity" name="current_quantity" value="<?php echo esc_attr($current_quantity ?: $max_quantity); ?>" min="0" />
        <small>Available stock</small>
    </div>

    <div class="reward-meta-row">
        <label for="is_active">Status:</label>
        <select id="is_active" name="is_active">
            <option value="1" <?php selected($is_active, '1'); ?>>Active</option>
            <option value="0" <?php selected($is_active, '0'); ?>>Inactive</option>
        </select>
    </div>
    <?php
}

/**
 * Save reward meta box data
 */
add_action('save_post_reward', 'custom_save_reward_meta');

function custom_save_reward_meta($post_id)
{
    if (!isset($_POST['reward_details_nonce_field']) || 
        !wp_verify_nonce($_POST['reward_details_nonce_field'], 'reward_details_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['points_cost'])) {
        update_post_meta($post_id, 'points_cost', intval($_POST['points_cost']));
    }

    if (isset($_POST['reward_type'])) {
        update_post_meta($post_id, 'reward_type', sanitize_text_field($_POST['reward_type']));
    }

    if (isset($_POST['max_quantity'])) {
        update_post_meta($post_id, 'max_quantity', intval($_POST['max_quantity']));
    }

    if (isset($_POST['current_quantity'])) {
        update_post_meta($post_id, 'current_quantity', intval($_POST['current_quantity']));
    }

    if (isset($_POST['is_active'])) {
        update_post_meta($post_id, 'is_active', sanitize_text_field($_POST['is_active']));
    }
}


/**
 * Create redemptions table on plugin/theme activation
 */
function custom_create_redemptions_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'reward_redemptions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        reward_id bigint(20) UNSIGNED NOT NULL,
        points_spent int(11) NOT NULL,
        redemption_code varchar(50) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        redeemed_at datetime NOT NULL,
        fulfilled_at datetime DEFAULT NULL,
        notes text,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY reward_id (reward_id),
        KEY redemption_code (redemption_code)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('after_setup_theme', 'custom_create_redemptions_table');

/**
 * Add Redemptions admin menu
 */
add_action('admin_menu', 'custom_add_redemptions_menu');

function custom_add_redemptions_menu()
{
    add_menu_page(
        'Redemptions',           // Page title
        'Redemptions',           // Menu title
        'manage_options',        // Capability
        'redemptions',           // Menu slug
        'custom_redemptions_page', // Callback function
        'dashicons-tickets-alt', // Icon
        26                       // Position (after Comments)
    );
    
    // Add submenu for stats
    add_submenu_page(
        'redemptions',
        'Redemption Stats',
        'Statistics',
        'manage_options',
        'redemptions-stats',
        'custom_redemptions_stats_page'
    );
}

/**
 * Display redemptions list page
 */
function custom_redemptions_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'reward_redemptions';
    
    // Handle status update
    if (isset($_POST['update_status']) && isset($_POST['redemption_id']) && isset($_POST['status'])) {
        check_admin_referer('update_redemption_status');
        
        $redemption_id = intval($_POST['redemption_id']);
        $new_status = sanitize_text_field($_POST['status']);
        $notes = sanitize_textarea_field($_POST['notes']);
        
        $updated = $wpdb->update(
            $table_name,
            array(
                'status' => $new_status,
                'notes' => $notes,
                'fulfilled_at' => ($new_status === 'fulfilled') ? current_time('mysql') : null
            ),
            array('id' => $redemption_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        if ($updated !== false) {
            // Send WhatsApp notification
            $redemption = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $redemption_id));
            if ($redemption) {
                notify_redemption_status_change($redemption, $new_status);
            }
            
            echo '<div class="notice notice-success"><p>Status updated successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to update status.</p></div>';
        }
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['redemption_ids'])) {
        check_admin_referer('bulk_update_redemptions');
        
        $action = sanitize_text_field($_POST['bulk_action']);
        $redemption_ids = array_map('intval', $_POST['redemption_ids']);
        
        if ($action && !empty($redemption_ids)) {
            foreach ($redemption_ids as $id) {
                $wpdb->update(
                    $table_name,
                    array(
                        'status' => $action,
                        'fulfilled_at' => ($action === 'fulfilled') ? current_time('mysql') : null
                    ),
                    array('id' => $id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
            echo '<div class="notice notice-success"><p>' . count($redemption_ids) . ' redemptions updated!</p></div>';
        }
    }
    
    // Get filter parameters
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'redeemed_at';
    $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
    
    // Build query
    $where = array('1=1');
    if ($status_filter) {
        $where[] = $wpdb->prepare("status = %s", $status_filter);
    }
    if ($search) {
        $where[] = $wpdb->prepare("redemption_code LIKE %s", '%' . $wpdb->esc_like($search) . '%');
    }
    
    $where_sql = implode(' AND ', $where);
    
    // Pagination
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Get total count
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE $where_sql");
    $total_pages = ceil($total_items / $per_page);
    
    // Get redemptions
    $redemptions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));
    
    // Get stats
    $stats = $wpdb->get_row("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'fulfilled' THEN 1 ELSE 0 END) as fulfilled,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(points_spent) as total_points
        FROM $table_name
    ");
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Redemptions</h1>
        <a href="<?php echo admin_url('edit.php?post_type=reward'); ?>" class="page-title-action">Manage Rewards</a>
        <hr class="wp-header-end">
        
        <!-- Stats Cards -->
        <div class="redemption-stats-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            <div class="stats-card" style="background: white; padding: 20px; border-left: 4px solid #0073aa; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px; color: #666; font-size: 14px;">Total Redemptions</h3>
                <p style="margin: 0; font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo number_format($stats->total); ?></p>
            </div>
            <div class="stats-card" style="background: white; padding: 20px; border-left: 4px solid #f0b429; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px; color: #666; font-size: 14px;">Pending</h3>
                <p style="margin: 0; font-size: 32px; font-weight: bold; color: #f0b429;"><?php echo number_format($stats->pending); ?></p>
            </div>
            <div class="stats-card" style="background: white; padding: 20px; border-left: 4px solid #46b450; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px; color: #666; font-size: 14px;">Fulfilled</h3>
                <p style="margin: 0; font-size: 32px; font-weight: bold; color: #46b450;"><?php echo number_format($stats->fulfilled); ?></p>
            </div>
            <div class="stats-card" style="background: white; padding: 20px; border-left: 4px solid #dc3232; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px; color: #666; font-size: 14px;">Total Points Spent</h3>
                <p style="margin: 0; font-size: 32px; font-weight: bold; color: #dc3232;"><?php echo number_format($stats->total_points); ?> 💎</p>
            </div>
        </div>
        
        <!-- Filters -->
        <form method="get" style="background: white; padding: 15px; margin: 20px 0; border: 1px solid #ccd0d4;">
            <input type="hidden" name="page" value="redemptions">
            
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <select name="status" style="min-width: 150px;">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                    <option value="fulfilled" <?php selected($status_filter, 'fulfilled'); ?>>Fulfilled</option>
                    <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>>Cancelled</option>
                </select>
                
                <input type="text" name="s" placeholder="Search by code..." value="<?php echo esc_attr($search); ?>" style="min-width: 200px;">
                
                <button type="submit" class="button">Filter</button>
                
                <?php if ($status_filter || $search): ?>
                    <a href="<?php echo admin_url('admin.php?page=redemptions'); ?>" class="button">Clear Filters</a>
                <?php endif; ?>
            </div>
        </form>
        
        <!-- Bulk Actions -->
        <form method="post">
            <?php wp_nonce_field('bulk_update_redemptions'); ?>
            
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="fulfilled">Mark as Fulfilled</option>
                        <option value="pending">Mark as Pending</option>
                        <option value="cancelled">Mark as Cancelled</option>
                    </select>
                    <button type="submit" class="button action">Apply</button>
                </div>
                
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo number_format($total_items); ?> items</span>
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $total_pages,
                        'current' => $current_page
                    ));
                    ?>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" id="cb-select-all">
                        </td>
                        <th>ID</th>
                        <th>User</th>
                        <th>Reward</th>
                        <th>Code</th>
                        <th>Points</th>
                        <th>Status</th>
                        <th>Redeemed</th>
                        <th>Fulfilled</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($redemptions)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                <p style="color: #999; font-size: 16px;">No redemptions found.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($redemptions as $redemption): 
                            $user = get_post($redemption->user_id);
                            $reward = get_post($redemption->reward_id);
                            $phone = get_post_meta($redemption->user_id, 'phone', true);
                            
                            $status_colors = array(
                                'pending' => '#f0b429',
                                'fulfilled' => '#46b450',
                                'cancelled' => '#dc3232'
                            );
                            $status_color = isset($status_colors[$redemption->status]) ? $status_colors[$redemption->status] : '#999';
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="redemption_ids[]" value="<?php echo $redemption->id; ?>">
                            </th>
                            <td><strong><?php echo $redemption->id; ?></strong></td>
                            <td>
                                <?php if ($user): ?>
                                    <strong><?php echo esc_html($user->post_title); ?></strong><br>
                                    <small><?php echo esc_html($phone); ?></small>
                                <?php else: ?>
                                    <em>Deleted User</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($reward): ?>
                                    <a href="<?php echo get_edit_post_link($redemption->reward_id); ?>">
                                        <?php echo esc_html($reward->post_title); ?>
                                    </a>
                                <?php else: ?>
                                    <em>Deleted Reward</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code style="background: #f0f0f0; padding: 4px 8px; border-radius: 3px; font-weight: bold;">
                                    <?php echo esc_html($redemption->redemption_code); ?>
                                </code>
                            </td>
                            <td><strong><?php echo number_format($redemption->points_spent); ?></strong> 💎</td>
                            <td>
                                <span style="display: inline-block; padding: 4px 10px; border-radius: 3px; background: <?php echo $status_color; ?>; color: white; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                    <?php echo esc_html($redemption->status); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($redemption->redeemed_at)); ?></td>
                            <td>
                                <?php if ($redemption->fulfilled_at): ?>
                                    <?php echo date('Y-m-d H:i', strtotime($redemption->fulfilled_at)); ?>
                                <?php else: ?>
                                    <em>—</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small view-details" data-id="<?php echo $redemption->id; ?>">
                                    View/Edit
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>
    </div>
    
    <!-- Details Modal -->
    <div id="redemption-modal" style="display: none;">
        <div class="redemption-modal-content">
            <!-- Populated by JavaScript -->
        </div>
    </div>
    
    <style>
        #redemption-modal {
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .redemption-modal-content {
            background: white;
            padding: 30px;
            border-radius: 5px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .modal-close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
        }
        
        .modal-close:hover {
            color: #333;
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Select all checkbox
        $('#cb-select-all').on('change', function() {
            $('input[name="redemption_ids[]"]').prop('checked', this.checked);
        });
        
        // View details modal
        $('.view-details').on('click', function() {
            var redemptionId = $(this).data('id');
            
            // Fetch redemption details via AJAX
            $.post(ajaxurl, {
                action: 'get_redemption_details',
                redemption_id: redemptionId,
                nonce: '<?php echo wp_create_nonce('get_redemption_details'); ?>'
            }, function(response) {
                if (response.success) {
                    showRedemptionModal(response.data);
                } else {
                    alert('Failed to load redemption details');
                }
            });
        });
        
        function showRedemptionModal(data) {
            var html = `
                <span class="modal-close">&times;</span>
                <h2>Redemption Details</h2>
                
                <table class="form-table">
                    <tr>
                        <th>ID:</th>
                        <td><strong>${data.id}</strong></td>
                    </tr>
                    <tr>
                        <th>User:</th>
                        <td>${data.user_name}<br><small>${data.user_phone}</small></td>
                    </tr>
                    <tr>
                        <th>Reward:</th>
                        <td>${data.reward_title}</td>
                    </tr>
                    <tr>
                        <th>Redemption Code:</th>
                        <td><code style="background: #f0f0f0; padding: 5px 10px; font-size: 16px; font-weight: bold;">${data.redemption_code}</code></td>
                    </tr>
                    <tr>
                        <th>Points Spent:</th>
                        <td><strong>${data.points_spent}</strong> 💎</td>
                    </tr>
                    <tr>
                        <th>Redeemed At:</th>
                        <td>${data.redeemed_at}</td>
                    </tr>
                    <tr>
                        <th>Fulfilled At:</th>
                        <td>${data.fulfilled_at || '—'}</td>
                    </tr>
                </table>
                
                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field('update_redemption_status'); ?>
                    <input type="hidden" name="redemption_id" value="${data.id}">
                    
                    <p>
                        <label><strong>Status:</strong></label><br>
                        <select name="status" style="width: 100%; margin-top: 5px;">
                            <option value="pending" ${data.status === 'pending' ? 'selected' : ''}>Pending</option>
                            <option value="fulfilled" ${data.status === 'fulfilled' ? 'selected' : ''}>Fulfilled</option>
                            <option value="cancelled" ${data.status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                        </select>
                    </p>
                    
                    <p>
                        <label><strong>Notes:</strong></label><br>
                        <textarea name="notes" rows="4" style="width: 100%; margin-top: 5px;">${data.notes || ''}</textarea>
                    </p>
                    
                    <p>
                        <button type="submit" name="update_status" class="button button-primary">Update Status</button>
                        <button type="button" class="button modal-close">Cancel</button>
                    </p>
                </form>
            `;
            
            $('.redemption-modal-content').html(html);
            $('#redemption-modal').show();
        }
        
        // Close modal
        $(document).on('click', '.modal-close', function() {
            $('#redemption-modal').hide();
        });
        
        $(document).on('click', '#redemption-modal', function(e) {
            if (e.target.id === 'redemption-modal') {
                $('#redemption-modal').hide();
            }
        });
    });
    </script>
    <?php
}

/**
 * AJAX handler to get redemption details
 */
add_action('wp_ajax_get_redemption_details', 'custom_get_redemption_details_ajax');

function custom_get_redemption_details_ajax()
{
    check_ajax_referer('get_redemption_details', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'reward_redemptions';
    $redemption_id = intval($_POST['redemption_id']);
    
    $redemption = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $redemption_id));
    
    if (!$redemption) {
        wp_send_json_error('Redemption not found');
    }
    
    $user = get_post($redemption->user_id);
    $reward = get_post($redemption->reward_id);
    $phone = get_post_meta($redemption->user_id, 'phone', true);
    
    wp_send_json_success(array(
        'id' => $redemption->id,
        'user_name' => $user ? $user->post_title : 'Deleted User',
        'user_phone' => $phone,
        'reward_title' => $reward ? $reward->post_title : 'Deleted Reward',
        'redemption_code' => $redemption->redemption_code,
        'points_spent' => number_format($redemption->points_spent),
        'status' => $redemption->status,
        'redeemed_at' => date('Y-m-d H:i:s', strtotime($redemption->redeemed_at)),
        'fulfilled_at' => $redemption->fulfilled_at ? date('Y-m-d H:i:s', strtotime($redemption->fulfilled_at)) : null,
        'notes' => $redemption->notes
    ));
}

/**
 * Send WhatsApp notification when status changes
 */
function notify_redemption_status_change($redemption, $new_status)
{
    $user = get_post($redemption->user_id);
    $reward = get_post($redemption->reward_id);
    $phone = get_post_meta($redemption->user_id, 'phone', true);
    
    if (!$phone || !$user || !$reward) {
        return;
    }

    $template_map = array(
        'fulfilled' => array(
            'template' => 'reward_request_confirmation',
            'params' => array(
                $reward->post_title,
                $redemption->redemption_code
            )
        ),
        'cancelled' => array(
            'template' => 'reward_cancelled',
            'params' => array(
                $reward->post_title,
                $redemption->redemption_code,
                $redemption->points_spent
            )
        ),
        'pending' => array(
            'template' => 'reward_pending',
            'params' => array(
                $reward->post_title,
                $redemption->redemption_code
            )
        )
    );
    
    $messages = array(
        'fulfilled' => "✅ Your reward has been fulfilled!\n\nReward: {$reward->post_title}\nCode: {$redemption->redemption_code}\n\nThank you for being a valued customer!",
        'cancelled' => "❌ Your reward redemption has been cancelled.\n\nReward: {$reward->post_title}\nCode: {$redemption->redemption_code}\nPoints Refunded: {$redemption->points_spent} 💎\n\nIf you have questions, please contact us.",
        'pending' => "⏳ Your reward redemption is being processed.\n\nReward: {$reward->post_title}\nCode: {$redemption->redemption_code}\n\nWe'll notify you when it's ready!"
    );
    
    $message = isset($messages[$new_status]) ? $messages[$new_status] : '';
    
    if ($message && isset($template_map[$new_status])) {
        $payload = $template_map[$new_status];
        $webhook_url = get_option('whatsapp_webhook_url', '');
        if ($webhook_url) {
            wp_remote_post($webhook_url, array(
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode(array(
                    'phone' => $phone,
                    'message' => $message,
                    'use_template' => true,
                    'template_name' => $payload['template'],
                    'template_params' => $payload['params'],
                )),
                'timeout' => 30
            ));
        }
    }
    
    // Refund points if cancelled
    if ($new_status === 'cancelled') {
        $current_points = intval(get_post_meta($redemption->user_id, 'loyalty_points', true));
        update_post_meta($redemption->user_id, 'loyalty_points', $current_points + $redemption->points_spent);
    }
}

/**
 * Statistics page
 */
function custom_redemptions_stats_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'reward_redemptions';
    
    // Get date range
    $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
    $date_from = date('Y-m-d', strtotime("-{$days} days"));
    
    // Top users
    $top_users = $wpdb->get_results($wpdb->prepare("
        SELECT user_id, COUNT(*) as redemption_count, SUM(points_spent) as total_points
        FROM $table_name
        WHERE redeemed_at >= %s
        GROUP BY user_id
        ORDER BY redemption_count DESC
        LIMIT 10
    ", $date_from));
    
    // Top rewards
    $top_rewards = $wpdb->get_results($wpdb->prepare("
        SELECT reward_id, COUNT(*) as redemption_count
        FROM $table_name
        WHERE redeemed_at >= %s
        GROUP BY reward_id
        ORDER BY redemption_count DESC
        LIMIT 10
    ", $date_from));
    
    ?>
    <div class="wrap">
        <h1>Redemption Statistics</h1>
        
        <form method="get">
            <input type="hidden" name="page" value="redemptions-stats">
            <select name="days" onchange="this.form.submit()">
                <option value="7" <?php selected($days, 7); ?>>Last 7 days</option>
                <option value="30" <?php selected($days, 30); ?>>Last 30 days</option>
                <option value="90" <?php selected($days, 90); ?>>Last 90 days</option>
                <option value="365" <?php selected($days, 365); ?>>Last year</option>
            </select>
        </form>
        
        <h2>Top Users (Last <?php echo $days; ?> days)</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>User</th>
                    <th>Redemptions</th>
                    <th>Points Spent</th>
                </tr>
            </thead>
            <tbody>
                <?php $rank = 1; foreach ($top_users as $user_data): 
                    $user = get_post($user_data->user_id);
                ?>
                <tr>
                    <td><?php echo $rank++; ?></td>
                    <td><?php echo $user ? esc_html($user->post_title) : 'Deleted User'; ?></td>
                    <td><?php echo number_format($user_data->redemption_count); ?></td>
                    <td><?php echo number_format($user_data->total_points); ?> 💎</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h2>Top Rewards (Last <?php echo $days; ?> days)</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Reward</th>
                    <th>Redemptions</th>
                </tr>
            </thead>
            <tbody>
                <?php $rank = 1; foreach ($top_rewards as $reward_data): 
                    $reward = get_post($reward_data->reward_id);
                ?>
                <tr>
                    <td><?php echo $rank++; ?></td>
                    <td><?php echo $reward ? esc_html($reward->post_title) : 'Deleted Reward'; ?></td>
                    <td><?php echo number_format($reward_data->redemption_count); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Register a custom post type for Promotions
 */
function custom_register_promotion_post_type() {

    $labels = array(
        'name'                  => _x('Promotions', 'Post type general name', 'textdomain'),
        'singular_name'         => _x('Promotion', 'Post type singular name', 'textdomain'),
        'menu_name'             => _x('Promotions', 'Admin Menu text', 'textdomain'),
        'name_admin_bar'        => _x('Promotion', 'Add New on Toolbar', 'textdomain'),
        'add_new'               => __('Add New', 'textdomain'),
        'add_new_item'          => __('Add New Promotion', 'textdomain'),
        'new_item'              => __('New Promotion', 'textdomain'),
        'edit_item'             => __('Edit Promotion', 'textdomain'),
        'view_item'             => __('View Promotion', 'textdomain'),
        'all_items'             => __('All Promotions', 'textdomain'),
        'search_items'          => __('Search Promotions', 'textdomain'),
        'not_found'             => __('No promotions found.', 'textdomain'),
        'not_found_in_trash'    => __('No promotions found in Trash.', 'textdomain'),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array('slug' => 'promotion'),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-megaphone',
        'supports'           => array('title', 'editor', 'thumbnail'),
        'show_in_rest'       => true, // Enables Gutenberg + API
    );

    register_post_type('promotion', $args);
}
add_action('init', 'custom_register_promotion_post_type');

add_action('add_meta_boxes', 'custom_add_promotion_meta_boxes');

function custom_add_promotion_meta_boxes() {
    add_meta_box(
        'promotion_details',
        'Promotion Details',
        'custom_promotion_details_callback',
        'promotion',
        'normal',
        'high'
    );
}

function custom_promotion_details_callback($post) {

    wp_nonce_field('promotion_details_nonce', 'promotion_details_nonce_field');

    $expiry_date = get_post_meta($post->ID, 'expiry_date', true);
    $promo_link  = get_post_meta($post->ID, 'promo_link', true);
    $is_active   = get_post_meta($post->ID, 'is_active', true);

    $media_id  = get_post_meta($post->ID, 'promotion_media_id', true);
    $media_url = $media_id ? wp_get_attachment_url($media_id) : '';
    $mime      = $media_id ? get_post_mime_type($media_id) : '';

    ?>
    <div style="margin-bottom:15px;">
        <label><strong>Expiry Date:</strong></label><br>
        <input type="date" name="expiry_date" value="<?php echo esc_attr($expiry_date); ?>">
    </div>

    <div style="margin-bottom:15px;">
        <label><strong>Promotion Link (Optional):</strong></label><br>
        <input type="url" name="promo_link" style="width:60%;" value="<?php echo esc_attr($promo_link); ?>" placeholder="https://example.com">
    </div>

    <div class="promotion-meta-row" style="margin-top:20px;">
        <label><strong>Promotion Media (Image / GIF / Video)</strong></label><br>

        <input type="hidden" id="promotion_media_id" name="promotion_media_id" value="<?php echo esc_attr($media_id); ?>" />

        <button type="button" class="button" id="upload_promotion_media">
            Upload / Select Media
        </button>

        <div id="promotion_media_preview" style="margin-top:15px;">
            <?php if ($media_url): ?>
                <?php if (strpos($mime, 'image/') === 0): ?>
                    <img src="<?php echo esc_url($media_url); ?>" style="max-width:300px;" />
                <?php elseif (strpos($mime, 'video/') === 0): ?>
                    <video controls style="max-width:300px;">
                        <source src="<?php echo esc_url($media_url); ?>" type="<?php echo esc_attr($mime); ?>">
                    </video>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div style="margin-bottom:15px;">
        <label>
            <input type="checkbox" name="is_active" value="1" <?php checked($is_active, '1'); ?>>
            Active Promotion
        </label>
    </div>
    <script>
    jQuery(document).ready(function($){

        var frame;

        $('#upload_promotion_media').on('click', function(e){
            e.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Select Promotion Media',
                button: { text: 'Use this media' },
                multiple: false
            });

            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();

                $('#promotion_media_id').val(attachment.id);

                let previewHTML = '';

                if (attachment.type === 'image') {
                    previewHTML = `<img src="${attachment.url}" style="max-width:300px;" />`;
                }

                if (attachment.type === 'video') {
                    previewHTML = `
                        <video controls style="max-width:300px;">
                            <source src="${attachment.url}" type="${attachment.mime}">
                        </video>`;
                }

                $('#promotion_media_preview').html(previewHTML);
            });

            frame.open();
        });

    });
    </script>
    <?php
}

add_action('save_post_promotion', 'custom_save_promotion_meta');

function custom_save_promotion_meta($post_id) {

    if (!isset($_POST['promotion_details_nonce_field']) ||
        !wp_verify_nonce($_POST['promotion_details_nonce_field'], 'promotion_details_nonce')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    update_post_meta($post_id, 'expiry_date', sanitize_text_field($_POST['expiry_date'] ?? ''));
    update_post_meta($post_id, 'promo_link', esc_url_raw($_POST['promo_link'] ?? ''));
    update_post_meta($post_id, 'is_active', isset($_POST['is_active']) ? '1' : '0');
    if (isset($_POST['promotion_media_id'])) {
        update_post_meta($post_id, 'promotion_media_id', intval($_POST['promotion_media_id']));
    }
}

/**
 * Create points audit table
 */
function create_points_audit_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'points_audit';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        receipt_id bigint(20) UNSIGNED DEFAULT NULL,
        points_change int(11) NOT NULL,
        points_before int(11) NOT NULL,
        points_after int(11) NOT NULL,
        action_type varchar(50) NOT NULL,
        reason varchar(255) DEFAULT NULL,
        admin_id bigint(20) UNSIGNED DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY receipt_id (receipt_id),
        KEY admin_id (admin_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action('after_setup_theme', 'create_points_audit_table');

/**
 * Add Points Audit admin menu
 */
add_action('admin_menu', 'add_points_audit_menu');
function add_points_audit_menu() {
    add_menu_page(
        'Points Audit',
        'Points Audit',
        'manage_options',
        'points-audit',
        'render_points_audit_page',
        'dashicons-list-view',
        25
    );
}

/**
 * Render Points Audit page
 */
function render_points_audit_page() {
    global $wpdb;
    
    // Handle point adjustment
    if (isset($_POST['adjust_points']) && check_admin_referer('adjust_points_nonce')) {
        $audit_id = intval($_POST['audit_id']);
        $new_points = intval($_POST['new_points']);
        $adjustment_reason = sanitize_textarea_field($_POST['adjustment_reason']);
        
        if (empty($adjustment_reason)) {
            echo '<div class="notice notice-error"><p>❌ Error: Reason for adjustment is required.</p></div>';
        } elseif (strlen($adjustment_reason) < 10) {
            echo '<div class="notice notice-error"><p>❌ Error: Please provide a detailed reason (at least 10 characters).</p></div>';
        } else {
            $result = adjust_audit_points($audit_id, $new_points, $adjustment_reason);
            
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p>✅ Points adjusted successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Failed to adjust points. Please try again.</p></div>';
            }
        }
    }
    
    $audit_table = $wpdb->prefix . 'points_audit';
    
    // Pagination
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Get total count
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $audit_table");
    $total_pages = ceil($total_items / $per_page);
    
    // Get audit records
    $audits = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $audit_table ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));
    
    ?>
    <div class="wrap">
        <h1>💎 Points Audit Log</h1>
        <p>Track and manage all loyalty points adjustments</p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Receipt</th>
                    <th>Points Change</th>
                    <th>Before</th>
                    <th>After</th>
                    <th>Action</th>
                    <th>Reason</th>
                    <th>Admin</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($audits)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 40px;">No audit records found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($audits as $audit): 
                        $user = get_post($audit->user_id);
                        $receipt = get_post($audit->receipt_id);
                        $admin = get_userdata($audit->admin_id);
                        
                        $points_color = $audit->points_change >= 0 ? '#22c55e' : '#ef4444';
                        $points_sign = $audit->points_change >= 0 ? '+' : '';
                    ?>
                        <tr>
                            <td><strong>#<?php echo $audit->id; ?></strong></td>
                            <td>
                                <?php if ($user): ?>
                                    <a href="<?php echo get_edit_post_link($audit->user_id); ?>">
                                        <?php echo esc_html($user->post_title); ?>
                                    </a>
                                <?php else: ?>
                                    User #<?php echo $audit->user_id; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($receipt): ?>
                                    <a href="<?php echo get_edit_post_link($audit->receipt_id); ?>">
                                        Receipt #<?php echo $audit->receipt_id; ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color: <?php echo $points_color; ?>; font-weight: 600;">
                                    <?php echo $points_sign . $audit->points_change; ?> 💎
                                </span>
                            </td>
                            <td><?php echo $audit->points_before; ?></td>
                            <td><?php echo $audit->points_after; ?></td>
                            <td>
                                <span style="background: #f0f0f0; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                                    <?php echo esc_html($audit->action_type); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($audit->reason); ?></td>
                            <td>
                                <?php echo $admin ? esc_html($admin->display_name) : 'System'; ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($audit->created_at)); ?></td>
                            <td>
                                <?php if ($audit->receipt_id && $audit->action_type === 'receipt_approved'): ?>
                                    <button class="button button-small edit-points-btn" 
                                            data-audit-id="<?php echo $audit->id; ?>"
                                            data-receipt-id="<?php echo $audit->receipt_id; ?>"
                                            data-current-points="<?php echo $audit->points_change; ?>"
                                            data-user-id="<?php echo $audit->user_id; ?>">
                                        ✏️ Adjust
                                    </button>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $current_page
                    ));
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Edit Points Modal -->
    <div id="edit-points-modal" style="display:none;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; margin: 100px auto; box-shadow: 0 4px 20px rgba(0,0,0,0.2);">
            <h2>✏️ Adjust Points</h2>
            <form method="POST" id="adjust-points-form">
                <?php wp_nonce_field('adjust_points_nonce'); ?>
                <input type="hidden" name="adjust_points" value="1">
                <input type="hidden" name="audit_id" id="edit-audit-id">
                <input type="hidden" name="user_id" id="edit-user-id">
                
                <table class="form-table">
                    <tr>
                        <th>Receipt ID</th>
                        <td><strong>#<span id="edit-receipt-id"></span></strong></td>
                    </tr>
                    <tr>
                        <th>Current Points</th>
                        <td><span id="edit-current-points" style="font-size: 18px; font-weight: 600; color: #2271b1;"></span> 💎</td>
                    </tr>
                    <tr>
                        <th><label for="edit-new-points">New Points *</label></th>
                        <td>
                            <input type="number" name="new_points" id="edit-new-points" min="0" required style="width: 150px; padding: 8px; font-size: 16px;">
                            💎
                            <p class="description" id="points-difference" style="margin-top: 8px; font-weight: 600;"></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="adjustment-reason">Reason for Adjustment *</label></th>
                        <td>
                            <textarea name="adjustment_reason" id="adjustment-reason" rows="4" required style="width: 100%; padding: 8px;" placeholder="e.g., Incorrect points calculation, Receipt reviewed again, Customer dispute resolved..."></textarea>
                            <p class="description">Explain why you're adjusting these points. This will be logged in the audit trail.</p>
                        </td>
                    </tr>
                </table>
                
                <div style="background: #f0f6fc; padding: 15px; border-left: 4px solid #2271b1; margin: 20px 0;">
                    <p style="margin: 0; font-weight: 600;">⚠️ Important:</p>
                    <p style="margin: 8px 0 0 0;">This will automatically adjust the user's total points and create a new audit entry.</p>
                </div>
                
                <p style="margin-top: 25px;">
                    <button type="submit" class="button button-primary" style="padding: 8px 20px; height: auto; font-size: 14px;">
                        💾 Save Adjustment
                    </button>
                    <button type="button" class="button" id="cancel-edit" style="margin-left: 10px;">
                        Cancel
                    </button>
                </p>
            </form>
        </div>
    </div>

    <style>
        #edit-points-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 999999;
            overflow-y: auto;
        }
        
        #edit-points-modal .form-table th {
            width: 180px;
            padding: 15px 10px 15px 0;
            font-weight: 600;
        }
        
        #edit-points-modal .form-table td {
            padding: 15px 10px;
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        $('.edit-points-btn').on('click', function() {
            var auditId = $(this).data('audit-id');
            var receiptId = $(this).data('receipt-id');
            var currentPoints = $(this).data('current-points');
            var userId = $(this).data('user-id');
            
            $('#edit-audit-id').val(auditId);
            $('#edit-user-id').val(userId);
            $('#edit-receipt-id').text(receiptId);
            $('#edit-current-points').text(currentPoints);
            $('#edit-new-points').val(currentPoints);
            $('#adjustment-reason').val('');
            $('#points-difference').text('');
            
            $('#edit-points-modal').show();
        });
        
        // Calculate and show difference
        $('#edit-new-points').on('input', function() {
            var currentPoints = parseInt($('#edit-current-points').text());
            var newPoints = parseInt($(this).val()) || 0;
            var difference = newPoints - currentPoints;
            
            if (difference === 0) {
                $('#points-difference').html('No change');
            } else if (difference > 0) {
                $('#points-difference').html('<span style="color: #22c55e;">+' + difference + ' points will be added</span>');
            } else {
                $('#points-difference').html('<span style="color: #ef4444;">' + difference + ' points will be deducted</span>');
            }
        });
        
        $('#cancel-edit').on('click', function() {
            $('#edit-points-modal').hide();
        });
        
        // Close modal on background click
        $('#edit-points-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });
        
        // Validate form before submit
        $('#adjust-points-form').on('submit', function(e) {
            var reason = $('#adjustment-reason').val().trim();
            if (reason.length < 10) {
                e.preventDefault();
                alert('Please provide a detailed reason (at least 10 characters).');
                return false;
            }
            
            var currentPoints = parseInt($('#edit-current-points').text());
            var newPoints = parseInt($('#edit-new-points').val());
            
            if (currentPoints === newPoints) {
                e.preventDefault();
                alert('New points must be different from current points.');
                return false;
            }
            
            if (!confirm('Are you sure you want to adjust these points? This action will be logged.')) {
                e.preventDefault();
                return false;
            }
        });
    });
    </script>
    <?php
}

/**
 * Adjust points from audit record
 */
function adjust_audit_points($audit_id, $new_points, $adjustment_reason) {
    global $wpdb;
    $audit_table = $wpdb->prefix . 'points_audit';
    
    // Get original audit record
    $audit = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $audit_table WHERE id = %d",
        $audit_id
    ));
    
    if (!$audit) {
        return false;
    }
    
    $old_points = $audit->points_change;
    $points_difference = $new_points - $old_points;
    
    if ($points_difference == 0) {
        return false; // No change needed
    }
    
    // Update user's total points
    $user_id = $audit->user_id;
    $current_total = intval(get_post_meta($user_id, 'loyalty_points', true));
    $new_total = $current_total + $points_difference;
    
    // Don't allow negative points
    if ($new_total < 0) {
        return false;
    }
    
    update_post_meta($user_id, 'loyalty_points', $new_total);
    
    // Update receipt points
    if ($audit->receipt_id) {
        update_post_meta($audit->receipt_id, 'loyalty_points', $new_points);
    }
    
    // Get admin info
    $admin = wp_get_current_user();
    $admin_name = $admin->display_name;
    
    // Create detailed reason text
    $detailed_reason = sprintf(
        "Points adjusted from %d to %d by %s. Reason: %s (Original audit #%d)",
        $old_points,
        $new_points,
        $admin_name,
        $adjustment_reason,
        $audit_id
    );
    
    // Create new audit entry for the adjustment
    $inserted = $wpdb->insert(
        $audit_table,
        array(
            'user_id' => $user_id,
            'receipt_id' => $audit->receipt_id,
            'points_change' => $points_difference,
            'points_before' => $current_total,
            'points_after' => $new_total,
            'action_type' => 'points_adjusted',
            'reason' => $detailed_reason,
            'admin_id' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ),
        array('%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s')
    );
    
    return $inserted !== false;
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
