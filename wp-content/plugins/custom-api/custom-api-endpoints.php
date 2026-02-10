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

/**
 * Allow image uploads via API
 */
add_filter('upload_mimes', 'custom_allow_image_uploads');

function custom_allow_image_uploads($mimes) {
    $mimes['jpg']  = 'image/jpeg';
    $mimes['jpeg'] = 'image/jpeg';
    $mimes['png']  = 'image/png';
    $mimes['gif']  = 'image/gif';
    return $mimes;
}

/**
 * Bypass file extension check for API uploads
 */
add_filter('wp_check_filetype_and_ext', 'custom_fix_mime_type_for_api', 10, 5);

function custom_fix_mime_type_for_api($data, $file, $filename, $mimes, $real_mime = null) {
    // Only apply for API requests
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return $data;
    }
    
    // Get file extension
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    
    // For images, explicitly set proper mime type
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
        $data['ext'] = $ext;
        $data['type'] = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
        $data['proper_filename'] = $filename;
    }
    
    return $data;
}

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
    $files        = $request->get_file_params();
    $profile_id   = $request->get_param('profile_id');
    $receipt_id   = $request->get_param('receipt_id');
    $total_images = intval($request->get_param('total_images'));

    if (empty($files['file'])) {
        return new WP_Error('no_file', 'No file uploaded', ['status' => 400]);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // ✅ FIX MIME TYPE DETECTION
    $file = $files['file'];
    
    if (file_exists($file['tmp_name'])) {
        $actual_mime = mime_content_type($file['tmp_name']);
        error_log('File type (actual): ' . $actual_mime);
    }
    
    // Detect actual MIME type
    if (file_exists($file['tmp_name'])) {
        $detected_type = mime_content_type($file['tmp_name']);
        
        // Override the type if it's an image
        if (strpos($detected_type, 'image/') === 0) {
            $_FILES['file']['type'] = $detected_type;
            $file['type'] = $detected_type;
        }
    }
    
    // Ensure proper file extension
    $file_name = $file['name'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // If no extension or wrong extension, add .jpg
    if (!in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
        $file_name = $file_name . '.jpg';
        $_FILES['file']['name'] = $file_name;
        $file['name'] = $file_name;
    }

    // ✅ USE wp_handle_upload INSTEAD of media_handle_upload
    $upload_overrides = array(
        'test_form' => false,
        'test_type' => true,
        'mimes' => array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif'
        )
    );

    $movefile = wp_handle_upload($file, $upload_overrides);

    if (isset($movefile['error'])) {
        return new WP_Error(
            'upload_error',
            $movefile['error'],
            ['status' => 500]
        );
    }

    // ✅ CREATE ATTACHMENT
    $wp_upload_dir = wp_upload_dir();
    $attachment = array(
        'guid'           => $movefile['url'],
        'post_mime_type' => $movefile['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($movefile['file'])),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );

    $attach_id = wp_insert_attachment($attachment, $movefile['file']);
    
    if (is_wp_error($attach_id)) {
        return new WP_Error(
            'attachment_error',
            $attach_id->get_error_message(),
            ['status' => 500]
        );
    }

    // Generate attachment metadata
    $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);

    // ============================
    // PRIMARY IMAGE
    // ============================
    if (!$receipt_id) {

        $user_post = get_post($profile_id);
        $username  = $user_post ? $user_post->post_title : 'Unknown User';
        $phone     = get_post_meta($profile_id, 'phone', true);

        $receipt_id = wp_insert_post([
            'post_title'  => "Receipt - {$username} - {$phone}",
            'post_type'   => 'receipt',
            'post_status' => 'publish'
        ]);

        update_post_meta($receipt_id, '_thumbnail_id', $attach_id);
        update_post_meta($receipt_id, 'profile_id', $profile_id);
        update_post_meta($receipt_id, 'total_images', max(1, $total_images));
        update_post_meta($receipt_id, 'additional_images', []);

        return [
            'success'          => true,
            'receipt_id'       => $receipt_id,
            'primary_image_id' => $attach_id
        ];
    }

    // ============================
    // ADDITIONAL IMAGE
    // ============================
    $additional = get_post_meta($receipt_id, 'additional_images', true);
    if (!is_array($additional)) {
        $additional = [];
    }

    $additional[] = $attach_id;
    update_post_meta($receipt_id, 'additional_images', $additional);

    return [
        'success'       => true,
        'receipt_id'    => $receipt_id,
        'attachment_id' => $attach_id
    ];
}


// Register REST route for updating receipt details after OCR
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/receipt/(?P<id>\d+)', array(
        'methods' => 'POST',
        'callback' => 'custom_update_receipt_details',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args' => array(
            'id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
});

function custom_update_receipt_details(WP_REST_Request $request)
{
    $receipt_id = intval($request->get_param('id'));

    $receipt = get_post($receipt_id);
    if (!$receipt || $receipt->post_type !== 'receipt') {
        return new WP_Error('invalid_receipt', 'Receipt not found', ['status' => 404]);
    }

    // Core receipt data
    $profile_id    = $request->get_param('profile_id');
    $receipt_id_on_receipt    = $request->get_param('receipt_id');
    $store_name    = $request->get_param('store_name');
    $purchase_date = $request->get_param('purchase_date');
    $total_amount  = $request->get_param('total_amount');
    $currency      = $request->get_param('currency') ?: 'THB';
    $items         = $request->get_param('items');
    $raw_text      = $request->get_param('raw_text');

    // Fraud data
    $fraud_score         = $request->get_param('fraud_score');
    $fraud_decision      = $request->get_param('fraud_decision');
    $fraud_reasons       = $request->get_param('fraud_reasons');
    $image_fraud_summary = $request->get_param('image_fraud_summary');
    $per_image_analysis  = $request->get_param('per_image_analysis');

    // Update post title + content
    $user_post = get_post($profile_id);
    $username  = $user_post ? $user_post->post_title : 'Unknown User';
    $phone     = get_post_meta($profile_id, 'phone', true);

    wp_update_post([
        'ID'           => $receipt_id,
        'post_title'   => "Receipt - {$receipt_id_on_receipt} - {$store_name} - {$username} - {$phone}",
        'post_content' => $raw_text
    ]);

    // Structured meta
    update_post_meta($receipt_id, 'receipt_id_on_receipt', sanitize_text_field($receipt_id_on_receipt));
    update_post_meta($receipt_id, 'store_name', sanitize_text_field($store_name));
    update_post_meta($receipt_id, 'purchase_date', sanitize_text_field($purchase_date));
    update_post_meta($receipt_id, 'total_amount', sanitize_text_field($total_amount));
    update_post_meta($receipt_id, 'currency', sanitize_text_field($currency));
    update_post_meta($receipt_id, 'raw_ocr_text', $raw_text);

    if (is_array($items)) {
        update_post_meta($receipt_id, 'receipt_items', $items);
    }

    // Fraud metadata
    update_post_meta($receipt_id, 'fraud_score', floatval($fraud_score));
    update_post_meta($receipt_id, 'fraud_decision', sanitize_text_field($fraud_decision));

    if (is_array($fraud_reasons)) {
        update_post_meta($receipt_id, 'fraud_reasons', $fraud_reasons);
    }

    if (is_array($image_fraud_summary)) {
        update_post_meta($receipt_id, 'image_fraud_summary', $image_fraud_summary);
    }

    if (is_array($per_image_analysis)) {
        update_post_meta($receipt_id, 'per_image_analysis', $per_image_analysis);
    }

    update_post_meta($receipt_id, 'processed_at', current_time('mysql'));

    return [
        'success'    => true,
        'receipt_id' => $receipt_id,
        'message'    => 'Receipt updated successfully'
    ];
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
    $additional_images = get_post_meta($post->ID, 'additional_images', true);
    $total_images = get_post_meta($post->ID, 'total_images', true);
    
    // Get user's total loyalty points
    $user_total_points = get_post_meta($profile_id, 'loyalty_points', true);

    echo "<p><strong>User:</strong> $username</p>";
    echo "<p><strong>Phone:</strong> $phone</p>";
    echo "<p><strong>User's Total Points:</strong> " . intval($user_total_points) . "</p>";
    
    if ($attachment_id) {
        $img_url = wp_get_attachment_url($attachment_id);
        echo '<img src="' . esc_url($img_url) . '" 
            style="max-width:300px; cursor: zoom-in;" 
            class="receipt-click-image" />';
    }   

    if ($total_images && $total_images > 1) {
    ?>
    <div style="background: #f0f8ff; padding: 15px; border-left: 4px solid #0073aa; margin-bottom: 20px;">
        <p><strong>Total Images:</strong> <?php echo intval($total_images); ?></p>
        
        <?php if (is_array($additional_images) && !empty($additional_images)): ?>
            <p><strong>Additional Images:</strong></p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
               <?php foreach ($additional_images as $img_id): 
                    $img_url = wp_get_attachment_url($img_id);
                ?>
                    <img 
                        src="<?php echo esc_url($img_url); ?>" 
                        style="width:150px; cursor: zoom-in;" 
                        class="receipt-click-image"
                    />
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

    // Loyalty points input with button
    ?>
    <style>
        .loyalty-points-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
        }
        .loyalty-points-row label {
            font-weight: bold;
        }
        .loyalty-points-row input {
            width: 100px;
            padding: 5px;
        }
        .update-points-btn {
            background: #2271b1;
            color: white;
            border: none;
            padding: 8px 15px;
            cursor: pointer;
            border-radius: 3px;
            font-size: 13px;
        }
        .update-points-btn:hover {
            background: #135e96;
        }
        .update-points-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        #loyalty-status {
            margin-left: 10px;
            padding: 5px 10px;
            display: none;
        }
        #loyalty-status.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            display: inline-block;
        }
        #loyalty-status.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            display: inline-block;
        }
    </style>
    
    <div class="loyalty-points-row">
        <label for="loyalty_points">Loyalty Points to Add:</label>
        <input type="number" id="loyalty_points" name="loyalty_points" value="<?php echo esc_attr($loyalty_points); ?>" min="0" />
        <button type="button" id="update-points-btn" class="update-points-btn" 
                data-receipt-id="<?php echo esc_attr($post->ID); ?>"
                data-profile-id="<?php echo esc_attr($profile_id); ?>"
                data-phone="<?php echo esc_attr($phone); ?>"
                data-username="<?php echo esc_attr($username); ?>">
            💎 Update & Notify
        </button>
        <span id="loyalty-status"></span>
    </div>
    <p class="description">Enter points to add to user's account. Click "Update & Notify" to save and send WhatsApp notification.</p>
    
    <div id="receipt-image-modal" style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,0.9);
        z-index:999999;
        align-items:center;
        justify-content:center;
    ">
        <img id="receipt-modal-img" style="
            max-width:95%;
            max-height:95%;
            box-shadow:0 0 20px #000;
        ">
    </div>
    <script>
    jQuery(document).ready(function($) {
    const modal = document.getElementById('receipt-image-modal');
    const modalImg = document.getElementById('receipt-modal-img');

    document.querySelectorAll('.receipt-click-image').forEach(img => {
        img.addEventListener('click', function () {
            modalImg.src = this.src;
            modal.style.display = 'flex';
        });
    });

    modal.addEventListener('click', function () {
        modal.style.display = 'none';
        modalImg.src = '';
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            modal.style.display = 'none';
            modalImg.src = '';
        }
    });
        $('#update-points-btn').on('click', function() {
            var btn = $(this);
            var receiptId = btn.data('receipt-id');
            var profileId = btn.data('profile-id');
            var phone = btn.data('phone');
            var username = btn.data('username');
            var points = $('#loyalty_points').val();
            var statusDiv = $('#loyalty-status');
            
            if (!points || points <= 0) {
                statusDiv.removeClass('success').addClass('error').text('❌ Please enter valid points').show();
                return;
            }
            
            btn.prop('disabled', true).text('Processing...');
            statusDiv.hide();
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'update_loyalty_points',
                    receipt_id: receiptId,
                    profile_id: profileId,
                    points: points,
                    nonce: '<?php echo wp_create_nonce('update_loyalty_points_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        statusDiv.removeClass('error').addClass('success')
                            .text('✅ ' + response.data.message + ' (Total: ' + response.data.total_points + ')').show();
                        
                        // Update the total points display
                        $('p:contains("User\'s Total Points:")').text('User\'s Total Points: ' + response.data.total_points);
                    } else {
                        statusDiv.removeClass('success').addClass('error').text('❌ ' + response.data.message).show();
                    }
                },
                error: function() {
                    statusDiv.removeClass('success').addClass('error').text('❌ Failed to update points').show();
                },
                complete: function() {
                    btn.prop('disabled', false).text('💎 Update & Notify');
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * AJAX handler to update loyalty points and notify user
 */
add_action('wp_ajax_update_loyalty_points', 'custom_update_loyalty_points');

function custom_update_loyalty_points()
{
    // Verify nonce
    check_ajax_referer('update_loyalty_points_nonce', 'nonce');
    
    // Check permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    $receipt_id = isset($_POST['receipt_id']) ? intval($_POST['receipt_id']) : 0;
    $profile_id = isset($_POST['profile_id']) ? intval($_POST['profile_id']) : 0;
    $points = isset($_POST['points']) ? intval($_POST['points']) : 0;
    
    if (!$receipt_id || !$profile_id || $points <= 0) {
        wp_send_json_error(array('message' => 'Invalid data'));
    }
    
    // Update receipt meta
    update_post_meta($receipt_id, 'loyalty_points', $points);
    
    // Get current user points
    $current_points = intval(get_post_meta($profile_id, 'loyalty_points', true));
    $new_total = $current_points + $points;
    
    // Update user's total loyalty points
    update_post_meta($profile_id, 'loyalty_points', $new_total);
    
    // Get user info for notification
    $user_post = get_post($profile_id);
    $username = $user_post ? $user_post->post_title : 'User';
    $phone = get_post_meta($profile_id, 'phone', true);
    
    // Get receipt details
    $store_name = get_post_meta($receipt_id, 'store_name', true);
    $total_amount = get_post_meta($receipt_id, 'total_amount', true);
    $currency = get_post_meta($receipt_id, 'currency', true);
    
    // Send WhatsApp notification
    $webhook_url = get_option('whatsapp_webhook_url', '');
    
    if ($webhook_url && $phone) {
        $message = "🎉 Congratulations {$username}!\n\n" .
                   "You've earned {$points} loyalty points!\n\n" .
                   "Receipt Details:\n" .
                   "Store: {$store_name}\n" .
                   "Amount: {$currency} {$total_amount}\n\n" .
                   "Your Total Points: {$new_total} 💎\n\n" .
                   "Keep collecting points for exciting rewards!";
        
        // $response = wp_remote_post($webhook_url, array(
        //     'headers' => array('Content-Type' => 'application/json'),
        //     'body' => json_encode(array(
        //         'phone' => $phone,
        //         'message' => $message,
        //         'receipt_id' => $receipt_id
        //     )),
        //     'timeout' => 30
        // ));

        // ✅ Template message
        $response=wp_remote_post($webhook_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'phone' => $phone,
                'use_template' => true,
                'template_name' => 'loyalty_points_earned',
                'template_params' => array(
                    $username,
                    $receipt_id,
                    $store_name,
                    $currency . ' ' . $total_amount,
                    $points,
                    $new_total
                ),
                'receipt_id' => $receipt_id,
                'message' => $message,
            )),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('WhatsApp notification failed: ' . $response->get_error_message());
        }
    }
    
    // Log the update
    update_post_meta($receipt_id, 'loyalty_points_awarded_at', current_time('mysql'));
    
    wp_send_json_success(array(
        'message' => "Added {$points} points",
        'total_points' => $new_total
    ));
}

// Save the loyalty points
// add_action('save_post', 'save_receipt_meta');
// function save_receipt_meta($post_id)
// {
//     if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
//         return;
//     }

//     if (wp_is_post_revision($post_id)) {
//         return;
//     }

//     if (get_post_type($post_id) !== 'receipt') {
//         return;
//     }

//     if (isset($_POST['loyalty_points'])) {
//         $loyalty_points = intval($_POST['loyalty_points']);
//         update_post_meta($post_id, 'loyalty_points', $loyalty_points);

//         // Update the WhatsApp user's loyalty points
//         $profile_id = get_post_meta($post_id, 'profile_id', true);
//         if ($profile_id) {
//             $user_post = get_post($profile_id);
//             if ($user_post) {
//                 // Get current points and cast to integer
//                 $current_points = intval(get_post_meta($user_post->ID, 'loyalty_points', true));
//                 $new_points = $current_points + $loyalty_points;
//                 update_post_meta($user_post->ID, 'loyalty_points', $new_points);
//             }
//         }
//     }
// }

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
        $next_state_obj = get_field('next_state', $step->ID);
        $next_state_slug = null;

        if ($next_state_obj) {
            if (is_numeric($next_state_obj)) {
                $next_state_slug = get_post_field('post_name', $next_state_obj);
            } elseif (is_object($next_state_obj)) {
                $next_state_slug = $next_state_obj->post_name;
            }
        }

        $flow[$state_name] = [
            'question' => get_field('bot_question', $step->ID),
            'next_state' => $next_state_slug,
            'save_key' => get_field('save_key', $step->ID),
            'response_image' => get_field('response_image', $step->ID),
            'confirmation' => get_field('is_confirmation', $step->ID),
            'match_type'      => get_field('step_match_type', $step->ID),
            'match_pattern'   => get_field('step_match_pattern', $step->ID),
            'validation_error_message'   => get_field('validation_error_message', $step->ID),
            'ID'              => $step->ID 
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

    // Validate input against step's pattern
    $match_type = $step['match_type'] ?? get_field('step_match_type', $step['ID']);
    $pattern_string = $step['match_pattern'] ?? get_field('step_match_pattern', $step['ID']);
    $validation_error_message = $step['validation_error_message'] ?? null;

    $is_valid = true;
    if (!empty($match_type) && !empty($pattern_string)) {
        $patterns = array_map('trim', explode(',', $pattern_string));
        $match = false;

        foreach ($patterns as $pattern) {
            switch ($match_type) {
                case 'exact':
                    if (strtolower(trim($user_input)) === strtolower($pattern)) {
                        $match = true;
                    }
                    break;

                case 'contains':
                    if (stripos($user_input, $pattern) !== false) {
                        $match = true;
                    }
                    break;

                case 'starts_with':
                    if (stripos($user_input, $pattern) === 0) {
                        $match = true;
                    }
                    break;

                case 'regex':
                    if (@preg_match("/$pattern/i", $user_input)) {
                        $match = true;
                    }
                    break;
            }

                if ($match) break; // no need to continue once matched
            }

        if (!$match) {
            return [
                'response' => "❌ Invalid input. " . $validation_error_message
            ];
        }
    }

    // Save input
    if (!empty($step['save_key'])) {
        $save_key = $step['save_key'];

        // Fix: normalize if it's a post object
        if (is_object($save_key)) {
            $save_key = $save_key->post_name ?? (string) $save_key->ID;
        } elseif (is_numeric($save_key)) {
            $save_key = get_post_field('post_name', $save_key); // fallback
        }

        $data[$save_key] = $user_input;
    }

    chatbot_save_log($phone, $name, $state, $user_input, $data);

    // Reset if invalid or missing in flow
    if (!isset($flow[$state])) {
        $state = 'start';
    }

    $step = $flow[$state];

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

        $temp_user_session = get_user_session_state($phone);
        return [
            'response' => $next_question,
            'response_image' => $flow[$next]['response_image'],
            'user_session' => $temp_user_session
        ];
    }
}


function saveToAnalytics($user_input, $phone, $response)
{
    global $wpdb;
    $response_table = $wpdb->prefix . 'bot_responses';
    // Insert user input as a new response row every time (raw logging)
    $wpdb->insert($response_table, [
        'keywords' => $user_input,
        'response' => $response, // Leave blank or fill with default
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);
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
                // $response_text = $possible_responses[array_rand($possible_responses)];
                $response_text = $response;
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

    $blacklist_table = $wpdb->prefix . 'bot_blacklist';
    $is_blacklisted = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $blacklist_table WHERE phone = %s",
        $phone
    ));

    if ($is_blacklisted) {
        return ['response' => '🚫 You have been blocked due to repeated spamming.'];
    }

    if (is_spamming($phone)) {
        return [
            'response' => "⚠️ You're sending messages too quickly. Please slow down.",
        ];
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
            saveToAnalytics($user_input, $phone, "chatbot step");
            return handle_dynamic_conversation($user_input, $phone, $name);
        }

        // Find matching chatbot response
        $matched_post = chatbot_match_text($user_input);
        if ($matched_post) {
            $start_step = get_field('start_state', $matched_post->ID);

            // Normalize to post ID and slug
            $start_id = is_object($start_step) ? $start_step->ID : $start_step;
            $start_slug = is_object($start_step) ? $start_step->post_name : get_post_field('post_name', $start_id);

            if ($start_slug && get_post_type($start_id) === 'chatbot_step') {
                update_user_session_state($phone, $start_slug, []);

                $flow = chatbot_get_flow_from_acf();
                $question = $flow[$start_slug]['question'] ?? "Let's begin!";
                saveToAnalytics($user_input, $phone, "chatbot step");
                return [
                    'response' => strtr($question, ['{name}' => $name]) . "\n\n(Type 'cancel' anytime to stop.)",
                ];
            }

            $response_text = get_field('response_text', $matched_post->ID);
            $response_image = get_field('response_image', $matched_post->ID);
            $keywords = get_field('keywords', $matched_post->ID);
            saveToAnalytics($user_input, $phone, $response_text);

            return [
                'response' => $response_text,
                'response_image' => $response_image,
                'keywords' => $keywords,
            ];
        }
        saveToAnalytics($user_input, $phone, "");
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
    $matched_posts = [];

    foreach ($responses as $post) {
        $match_type = get_field('match_type', $post->ID);
        $pattern_string = get_field('match_pattern', $post->ID);

        if (!$pattern_string) continue;

        $patterns = array_map('trim', explode(',', $pattern_string));
        foreach ($patterns as $pattern) {
            $pattern = strtolower($pattern);
            $message_lower = strtolower($message);

            switch ($match_type) {
                case 'exact':
                    if ($message_lower === $pattern) {
                        $matched_posts[] = $post;
                        break 2; // no need to check more patterns
                    }
                    break;

                case 'contains':
                    if (stripos($message_lower, $pattern) !== false) {
                        $matched_posts[] = $post;
                        break 2;
                    }
                    break;

                case 'starts_with':
                    if (stripos($message_lower, $pattern) === 0) {
                        $matched_posts[] = $post;
                        break 2;
                    }
                    break;

                case 'regex':
                    if (@preg_match("/$pattern/i", $message)) {
                        $matched_posts[] = $post;
                        break 2;
                    }
                    break;
            }
        }
    }

    if (empty($matched_posts)) {
        return null;
    }

    // Return the post with highest response_priority
    usort($matched_posts, function($a, $b) {
        $priorityA = (int) get_field('response_priority', $a->ID);
        $priorityB = (int) get_field('response_priority', $b->ID);
        return $priorityB <=> $priorityA; // descending order
    });

    return $matched_posts[0];
}

function is_spamming($phone) {
    $key = 'chatbot_spam_' . md5($phone);
    $count = get_transient($key);

    if ($count === false) {
        set_transient($key, 1, 60);
        return false;
    }

    if ($count >= 5) {
        // Track spam strikes
        track_spam_strike($phone);
        return true;
    }

    set_transient($key, $count + 1, 60);
    return false;
}

function track_spam_strike($phone) {
    global $wpdb;
    $strike_key = 'chatbot_strikes_' . md5($phone);
    $strikes = get_transient($strike_key);
    $strikes = $strikes ? $strikes + 1 : 1;

    if ($strikes >= 3) {
        // Add to blacklist
        $blacklist_table = $wpdb->prefix . 'bot_blacklist';
        $wpdb->insert($blacklist_table, [
            'phone' => $phone,
        ]);
        // Clear strike counter
        delete_transient($strike_key);
    } else {
        // Save strike count for 5 minutes
        set_transient($strike_key, $strikes, 300);
    }
}
// --------------------------- End ACF

// get welcomed users start

add_action('rest_api_init', function () {
    register_rest_route('chatbot/v1', '/welcomed-users', [
        'methods' => 'GET',
        'callback' => function () {
            global $wpdb;
            $table = $wpdb->prefix . 'bot_welcomed_users';
            $users = $wpdb->get_results("SELECT name, phone FROM $table");
            return rest_ensure_response($users);
        },
        'permission_callback' => '__return_true',
    ]);
});

// get welcomed users end