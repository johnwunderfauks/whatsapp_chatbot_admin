<?php
/*
Plugin Name: Chatbot Analytics
Description: Chatbot Flow system with Analytics
Version: 1.0
Author: Wunderfauks
*/

// Create bot_analytics table on plugin activation
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'bot_analytics';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        phone VARCHAR(50) NOT NULL,
        query TEXT NOT NULL,
        response TEXT DEFAULT NULL,
        response_id BIGINT(20) UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX phone_idx (phone),
        INDEX created_at_idx (created_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});


// Add admin menu for chatbot analytics
add_action('admin_menu', function () {
    add_menu_page(
        'Chatbot Analytics',      // Page title
        'Chatbot Analytics',      // Menu title
        'manage_options',         // Capability
        'chatbot-analytics',      // Menu slug
        'chatbot_analytics_page', // Callback function
        'dashicons-chart-bar',    // Icon
        26                        // Position (optional)
    );
});

// Render analytics page
function chatbot_analytics_page()
{
    global $wpdb;

    $analytics_table = $wpdb->prefix . 'bot_analytics';

    // Total unique users
    $total_users = $wpdb->get_var("SELECT COUNT(DISTINCT phone) FROM $analytics_table");

    // Count unmatched queries (where response is NULL or empty)
    $unmatched_count = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM $analytics_table WHERE response IS NULL OR response = %s", '')
    );

    // Top queries by count (group by query, order desc)
    $top_queries = $wpdb->get_results(
        "SELECT query, COUNT(*) as total FROM $analytics_table GROUP BY query ORDER BY total DESC LIMIT 20"
    );

?>
   <div class="wrap">
    <h1 style="margin-bottom: 20px; color: #0073aa;">Chatbot Analytics</h1>

    <table class="widefat fixed striped" style="max-width:600px; margin-bottom: 30px; border: 1px solid #ddd;">
        <tbody>
            <tr style="background-color: #f1f1f1;">
                <th style="padding: 12px 15px; text-align: left; color: #333;">Total Unique Users</th>
                <td style="padding: 12px 15px; font-weight: bold; color: #0073aa;"><?php echo intval($total_users); ?></td>
            </tr>
            <tr>
                <th style="padding: 12px 15px; text-align: left; color: #333;">Unmatched Queries</th>
                <td style="padding: 12px 15px; font-weight: bold; color: #0073aa;"><?php echo intval($unmatched_count); ?></td>
            </tr>
        </tbody>
    </table>

    <h2 style="margin-bottom: 15px; color: #0073aa;">Top 20 Queries</h2>
    <table class="widefat fixed striped" style="max-width:800px; border: 1px solid #ddd;">
        <thead style="background-color: #f1f1f1; color: #333;">
            <tr>
                <th style="padding: 10px 15px; text-align: left;">Query</th>
                <th style="padding: 10px 15px; text-align: left; width: 80px;">Count</th>
                <th style="padding: 10px 15px; text-align: left;">Matched Response?</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($top_queries) {

                // Fetch all responses and keywords
                $response_table = $wpdb->prefix . 'bot_responses';
                $response_data = $wpdb->get_results("SELECT keywords FROM $response_table");

                // Create a flat array of all keywords
                $all_keywords = [];
                foreach ($response_data as $row) {
                    $keywords = array_filter(array_map('trim', explode(',', $row->keywords)));
                    $all_keywords = array_merge($all_keywords, $keywords);
                }


                foreach ($top_queries as $row) {
                    $query = $row->query;
                    $is_matched = false;

                    foreach ($all_keywords as $keyword) {
                        if (stripos($query, $keyword) !== false) {
                            $is_matched = true;
                            break;
                        }
                    }
                    echo '<tr>';
                    echo '<td style="padding: 10px 15px; color: #333;">' . esc_html($query) . '</td>';
                    echo '<td style="padding: 10px 15px; font-weight: bold; color: #0073aa;">' . intval($row->total) . '</td>';
                    echo '<td style="padding: 10px 15px; color: ' . ($is_matched ? '#0a0' : '#d63638') . ';">' . ($is_matched ? 'Yes' : 'No') . '</td>';

                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="2" style="padding: 10px 15px; color: #777; text-align: center;">No analytics data found.</td></tr>';
            }
            ?>
        </tbody>
    </table>
</div>

<?php
}
