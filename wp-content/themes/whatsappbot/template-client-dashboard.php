<?php
/**
 * Template Name: Client Dashboard
 */

// Check auth
$token = isset($_COOKIE['client_token']) ? $_COOKIE['client_token'] : '';
$client_id = get_transient('client_session_' . $token);

if (!$client_id) {
    wp_redirect('/client-login/');
    exit;
}

$client = get_post($client_id);
$brand_title = get_post_meta($client_id, 'brand_title', true);
$company_name = get_post_meta($client_id, 'company_name', true);

$display_name = $brand_title ?: $company_name ?: $client->post_title;

get_header();
?>

<style>
/* Previous styles remain the same */
.dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #eee;
}

.user-info {
    display: flex;
    gap: 15px;
    align-items: center;
}

.date-filter {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.date-filter-inputs{
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.date-filter label {
    font-weight: 600;
}

.date-filter input[type="date"] {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.date-filter select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: white;
}

.date-filter .btn{
    width: fit-content;
    margin-bottom: 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.stat-card h3 {
    font-size: 14px;
    color: #666;
    margin: 0 0 10px 0;
    font-weight: 500;
}

.stat-number {
    font-size: 32px;
    font-weight: bold;
    margin: 0;
    color: #333;
}

.stat-meta {
    font-size: 13px;
    color: #999;
    margin-top: 8px;
}

.stat-positive {
    color: #22c55e;
}

.stat-negative {
    color: #ef4444;
}

.charts-section {
    margin-bottom: 30px;
}

.section-title {
    font-size: 24px;
    font-weight: bold;
    margin-bottom: 20px;
    color: #333;
}

.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(600px, 1fr));
    gap: 20px;
}

.chart-card {
    background: white;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.chart-card h3 {
    margin-top: 0;
    margin-bottom: 20px;
    font-size: 18px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.data-table th {
    background: #f9fafb;
    font-weight: 600;
    font-size: 13px;
    color: #666;
}

.data-table tr:hover {
    background: #f9fafb;
}

.loading {
    text-align: center;
    padding: 40px;
    color: #999;
}

.alert {
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.alert-error {
    background: #fee;
    color: #c33;
    border: 1px solid #fcc;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.funnel-chart {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.funnel-step {
    display: flex;
    align-items: center;
    gap: 15px;
}

.funnel-bar {
    flex: 1;
    height: 40px;
    background: #e5e7eb;
    border-radius: 4px;
    position: relative;
    overflow: hidden;
}

.funnel-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #2563eb);
    display: flex;
    align-items: center;
    padding: 0 15px;
    color: white;
    font-weight: 600;
}

.funnel-label {
    min-width: 150px;
    font-size: 14px;
    font-weight: 500;
}

.heatmap-grid {
    display: grid;
    gap: 4px;
    margin-top: 15px;
}

.heatmap-container {
    overflow-x: auto;
    width: 100%;
}

.heatmap-grid.by-hour {
    grid-template-columns: repeat(24, minmax(30px, 1fr));
    min-width: 720px; /* 24 * 30px */
}

.heatmap-labels.by-hour {
    grid-template-columns: repeat(24, minmax(30px, 1fr));
    min-width: 720px;
}

.heatmap-grid.by-day {
    grid-template-columns: repeat(7, 1fr);
}

.heatmap-labels.by-day {
    grid-template-columns: repeat(7, 1fr);
}

.heatmap-grid.by-hour {
    grid-template-columns: repeat(24, 1fr);
}

.heatmap-grid.by-day {
    grid-template-columns: repeat(7, 1fr);
}

.heatmap-cell {
    aspect-ratio: 1;
    background: #e5e7eb;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    cursor: pointer;
    flex-direction: column;
    width: 66px;
    height: 66px;
}

.heatmap-cell strong {
    font-size: 14px;
    margin-bottom: 2px;
}

.heatmap-labels {
    display: grid;
    gap: 4px;
    margin-bottom: 5px;
    font-size: 11px;
    color: #666;
    text-align: center;
    font-weight: 500;
}

.heatmap-labels.by-hour {
    grid-template-columns: repeat(24, 1fr);
}

.heatmap-labels.by-day {
    grid-template-columns: repeat(7, 1fr);
}

.heatmap-labels.by-day div, .heatmap-labels.by-hour div {
    width: 66px;
}
</style>

<div class="dashboard-container">
    <div class="dashboard-header">
        <div>
            <h1>📊 <?php echo esc_html($display_name); ?></h1>
            <p style="color: #666; margin: 5px 0 0 0;">Analytics Dashboard</p>
        </div>
        <div class="user-info">
            <p style="font-weight: 500;"><?php echo esc_html($company_name ?: $client->post_title); ?></p>
            <button id="logout-btn" class="btn btn-secondary">Logout</button>
        </div>
    </div>

    <div class="date-filter">
        <div class="date-filter-inputs">

        
            <label>📅 Date Range:</label>
            
            <select id="date-preset">
                <option value="">Custom Range</option>
                <option value="7">Last 7 Days</option>
                <option value="30">Last 30 Days</option>
                <option value="60">Last 60 Days</option>
                <option value="90">Last 90 Days</option>
                <option value="180">Last 6 Months</option>
                <option value="365">Last Year</option>
                <option value="mtd" selected>Month to Date</option>
                <option value="all">All Time</option>
            </select>
            
            <input type="date" id="start-date" value="<?php echo date('Y-m-01'); ?>">
            <span>to</span>
            <input type="date" id="end-date" value="<?php echo date('Y-m-d'); ?>">

        </div>
        
        <button id="apply-filter" class="btn btn-primary">Apply Filter</button>
    </div>

    <div id="analytics-container">
        <div class="loading">🔄 Loading analytics data...</div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Handle date preset selection
    $('#date-preset').on('change', function() {
        const preset = $(this).val();
        const today = new Date();
        let startDate, endDate;
        
        endDate = today.toISOString().split('T')[0];
        
        switch(preset) {
            case '7':
                startDate = new Date(today.setDate(today.getDate() - 7)).toISOString().split('T')[0];
                break;
            case '30':
                startDate = new Date(today.setDate(today.getDate() - 30)).toISOString().split('T')[0];
                break;
            case '60':
                startDate = new Date(today.setDate(today.getDate() - 60)).toISOString().split('T')[0];
                break;
            case '90':
                startDate = new Date(today.setDate(today.getDate() - 90)).toISOString().split('T')[0];
                break;
            case '180':
                startDate = new Date(today.setDate(today.getDate() - 180)).toISOString().split('T')[0];
                break;
            case '365':
                startDate = new Date(today.setDate(today.getDate() - 365)).toISOString().split('T')[0];
                break;
            case 'mtd':
                const now = new Date();
                startDate = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
                endDate = new Date().toISOString().split('T')[0];
                break;
            case 'all':
                startDate = '';
                endDate = '';
                break;
            default:
                return; // Custom range - don't auto-fill
        }
        
        $('#start-date').val(startDate);
        $('#end-date').val(endDate);
        
        if (preset) {
            loadAnalytics();
        }
    });
    
    // Update preset to "Custom Range" when dates manually changed
    $('#start-date, #end-date').on('change', function() {
        $('#date-preset').val('');
    });
    
    function loadAnalytics() {
        const startDate = $('#start-date').val();
        const endDate = $('#end-date').val();
        
        $('#analytics-container').html('<div class="loading">🔄 Loading analytics data...</div>');
        
        // Build query params
        let params = {};
        if (startDate) params.start_date = startDate;
        if (endDate) params.end_date = endDate;
        
        $.ajax({
            url: wpApiSettings.root + 'custom/v1/analytics',
            method: 'GET',
            data: params,
            success: function(response) {
                console.log(response)
                renderAnalytics(response);
            },
            error: function(xhr) {
                if (xhr.status === 401) {
                    window.location.href = '/client-login/';
                } else {
                    $('#analytics-container').html('<div class="alert alert-error">❌ Failed to load analytics. Please try again.</div>');
                }
            }
        });
    }
    
    function renderAnalytics(data) {
        const exec = data.executive_overview;
        const charts = data.charts;
        const fraud = data.fraud_analytics;
        const redemption = data.redemption_analytics;
        const engagement = data.user_engagement;
        
        // Show date range info
        const dateRangeText = data.date_range.is_filtered 
            ? `${data.date_range.start} to ${data.date_range.end}`
            : 'All Time';
        
        const html = `
            <div style="background: #f0f9ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                <strong>Showing data for:</strong> ${dateRangeText}
            </div>

            <!-- Value Framing -->
            <div class="stats-grid" style="margin-top: 20px;">
                <div class="stat-card" style="grid-column: span 2; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h3 style="color: white; opacity: 0.9;">💡 Key Insight</h3>
                    <p style="font-size: 20px; font-weight: 600; margin: 10px 0;">
                        ${exec.conversion_rate}% of submissions successfully converted to rewards
                    </p>
                    <p style="opacity: 0.9; margin: 0;">
                        Out of ${exec.total_receipts.toLocaleString()} submissions, ${exec.receipts_with_points.toLocaleString()} were approved and earned points
                    </p>
                </div>
                <div class="stat-card" style="grid-column: span 2; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <h3 style="color: white; opacity: 0.9;">🛡️ Prevented</h3>
                    <p style="font-size: 20px; font-weight: 600; margin: 10px 0;">
                        ${fraud.total_blocked} fraudulent submissions blocked
                    </p>
                    <p style="opacity: 0.9; margin: 0;">
                        Prevented ${fraud.duplicate_attempts} duplicate attempts • Average Risk score: ${fraud.avg_fraud_score}/100
                    </p>
                </div>
            </div>
            
            <!-- EXECUTIVE OVERVIEW -->
            <div class="section-title">📊 Executive Overview</div>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Receipts</h3>
                    <p class="stat-number">${exec.total_receipts.toLocaleString()}</p>
                    <p class="stat-meta">${exec.valid_receipts} valid • ${exec.rejected_receipts} rejected</p>
                </div>
                <div class="stat-card">
                    <h3>Approval Rate</h3>
                    <p class="stat-number stat-positive">${exec.approval_rate}%</p>       
                </div>
                <div class="stat-card">
                    <h3>Approved submissions</h3>
                    <p class="stat-number stat-positive">${exec.valid_receipts.toLocaleString()}</p>   
                </div>
                <div class="stat-card">
                    <h3>Rejection Rate</h3>
                    <p class="stat-number">${fraud.fraud_rate}%</p>
                   
                </div>
                <div class="stat-card">
                    <h3>Submissions rejected</h3>
                    <p class="stat-number">${fraud.total_blocked}</p>
                    
                </div>
                <div class="stat-card">
                    <h3>Active Users</h3>
                    <p class="stat-number">${exec.active_users.toLocaleString()}</p>
                    <p class="stat-meta">out of ${exec.total_users.toLocaleString()} total users</p>
                </div>
                <div class="stat-card">
                    <h3>Total Revenue</h3>
                    <p class="stat-number">S$${parseFloat(exec.total_revenue).toLocaleString()}</p>
                    <p class="stat-meta">From approved receipts</p>
                </div>
                <div class="stat-card">
                    <h3>Points Issued</h3>
                    <p class="stat-number">${exec.points_issued.toLocaleString()} 💎</p>
                    <p class="stat-meta">${exec.points_redeemed.toLocaleString()} redeemed</p>
                </div>
                <div class="stat-card">
                    <h3>Total Redemptions</h3>
                    <p class="stat-number">${exec.total_redemptions.toLocaleString()}</p>
                    <p class="stat-meta">${redemption.fulfilled} fulfilled • ${redemption.pending} pending</p>
                </div>
            </div>

            <div class="chart-card" style="grid-column: span 2;">
                <h3>Receipt Submission Flow Funnel</h3>
                <div class="funnel-chart">
                    <div class="funnel-step">
                        <div class="funnel-label">Submitted Receipt</div>
                        <div class="funnel-bar">
                            <div class="funnel-fill" style="width: 100%">
                                ${engagement.funnel.submitted_receipt} users (100%)
                            </div>
                        </div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-label">Approved Receipt</div>
                        <div class="funnel-bar">
                            <div class="funnel-fill" style="width: ${(engagement.funnel.approved_receipt / engagement.funnel.submitted_receipt * 100).toFixed(1)}%">
                                ${engagement.funnel.approved_receipt} users (${engagement.funnel.submitted_to_approved_rate}%)
                            </div>
                        </div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-label">Earned Points</div>
                        <div class="funnel-bar">
                            <div class="funnel-fill" style="width: ${(engagement.funnel.earned_points / engagement.funnel.submitted_receipt * 100).toFixed(1)}%">
                                ${engagement.funnel.earned_points} users (${engagement.funnel.approved_to_points_rate}% of approved)
                            </div>
                        </div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-label">Redeemed Reward</div>
                        <div class="funnel-bar">
                            <div class="funnel-fill" style="width: ${(engagement.funnel.redeemed_reward / engagement.funnel.submitted_receipt * 100).toFixed(1)}%">
                                ${engagement.funnel.redeemed_reward} users (${engagement.funnel.points_to_redeemed_rate}% of point holders)
                            </div>
                        </div>
                    </div>
                </div>
                <p style="margin-top: 15px; font-size: 13px; color: #666;">
                    Drop-off: ${engagement.funnel.submitted_receipt - engagement.funnel.approved_receipt} users didn't get approved, 
                    ${engagement.funnel.approved_receipt - engagement.funnel.redeemed_reward} users haven't redeemed yet
                </p>
            </div>

            <!-- FRAUD & RISK ANALYTICS -->
            <div class="section-title" style="margin-top: 40px;">🛡️ Risk Analytics</div>
            <div class="stats-grid">
                
                <div class="stat-card">
                    <h3>Average Risk Score</h3>
                    <p class="stat-number">${fraud.avg_fraud_score}</p>
                    <p class="stat-meta">Out of 100 (${fraud.total_scored} receipts scored)</p>
                </div>
                <div class="stat-card">
                    <h3>Duplicate Attempts</h3>
                    <p class="stat-number">${fraud.duplicate_attempts}</p>
                    <p class="stat-meta">Automatically prevented</p>
                </div>
                <div class="stat-card">
                    <h3>Risk Decisions</h3>
                    <p class="stat-number">${fraud.fraud_decisions.approve + fraud.fraud_decisions.review + fraud.fraud_decisions.reject}</p>
                    <p class="stat-meta">
                        ✅ ${fraud.fraud_decisions.approve} approved • 
                        ⚠️ ${fraud.fraud_decisions.review} review • 
                        ❌ ${fraud.fraud_decisions.reject} rejected
                    </p>
                </div>
            </div>

            <!-- Fraud Details Grid -->
            <div class="charts-grid" style="margin-top: 20px;">
                <div class="chart-card">
                    <h3>🚩 Top Risk Flags</h3>
                    ${Object.keys(fraud.fraud_reasons).length > 0 ? `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Flag</th>
                                    <th>Count</th>
                                    <th>% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${Object.entries(fraud.fraud_reasons).slice(0, 10).map(([reason, count]) => `
                                    <tr>
                                        <td>${reason}</td>
                                        <td><strong>${count}</strong></td>
                                        <td>${fraud.total_scored > 0 ? ((count / fraud.total_scored) * 100).toFixed(1) : 0}%</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    ` : '<p style="color: #999; text-align: center; padding: 20px;">No flags recorded</p>'}
                </div>
                
                <div class="chart-card">
                    <h3>📊 Risk Score Distribution</h3>
                    <div style="margin-top: 20px;">
                        ${Object.entries(fraud.fraud_score_distribution).map(([range, count]) => {
                            const max = Math.max(...Object.values(fraud.fraud_score_distribution));
                            const percentage = max > 0 ? (count / max) * 100 : 0;
                            const color = range.startsWith('81') ? '#ef4444' : 
                                         range.startsWith('61') ? '#f97316' : 
                                         range.startsWith('41') ? '#f59e0b' : 
                                         range.startsWith('21') ? '#84cc16' : '#22c55e';
                            return `
                                <div style="margin-bottom: 15px;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 13px;">
                                        <span style="font-weight: 500;">Score ${range}</span>
                                        <span>${count} receipts</span>
                                    </div>
                                    <div style="background: #e5e7eb; height: 24px; border-radius: 4px; overflow: hidden;">
                                        <div style="background: ${color}; height: 100%; width: ${percentage}%; transition: width 0.3s;"></div>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
            </div>


            <!-- USER ENGAGEMENT -->
            <div class="section-title" style="margin-top: 40px;">👥 User Engagement</div>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>New vs Returning</h3>
                    <p class="stat-number">${engagement.new_users}</p>
                    <p class="stat-meta">${engagement.returning_users} returning users</p>
                </div>
                <div class="stat-card">
                    <h3>Avg Receipts/User</h3>
                    <p class="stat-number">${engagement.avg_receipts_per_user}</p>
                    <p class="stat-meta">Per active user</p>
                </div>
            </div>

            <!-- New vs Returning Graph -->
            <div class="charts-grid" style="margin-top: 20px;">
                <div class="chart-card" style="grid-column: span 2;">
                    <h3>📈 New vs Returning Users Over Time</h3>
                    <canvas id="newVsReturningChart" style="max-height: 300px;"></canvas>
                </div>
                
                
</div>
                <!-- ITEM & BASKET ANALYTICS -->
<div class="section-title" style="margin-top: 40px;">🛒 Item & Basket Analytics</div>
<div class="stats-grid">
    <div class="stat-card">
        <h3>General Avg Basket Size</h3>
        <p class="stat-number">${data.item_analytics.avg_basket_size}</p>
        <p class="stat-meta">Items per receipt (all receipts)</p>
    </div>
    <div class="stat-card">
        <h3>Approved Basket Size</h3>
        <p class="stat-number">${data.item_analytics.avg_approved_basket_size}</p>
        <p class="stat-meta">Items per receipt (approved only)</p>
    </div>
    <div class="stat-card">
        <h3>General Avg Basket Total</h3>
        <p class="stat-number">S$${data.item_analytics.avg_basket_total}</p>
        <p class="stat-meta">All receipts</p>
    </div>
    <div class="stat-card">
        <h3>Approved Basket Total</h3>
        <p class="stat-number">S$${data.item_analytics.avg_approved_basket_total}</p>
        <p class="stat-meta">Approved receipts only</p>
    </div>
    <div class="stat-card">
        <h3>Avg Item Price</h3>
        <p class="stat-number">S$${data.item_analytics.avg_item_price}</p>
        <p class="stat-meta">${data.item_analytics.item_to_basket_ratio}% of basket total</p>
    </div>
    <div class="stat-card">
        <h3>Total Items Analyzed</h3>
        <p class="stat-number">${data.item_analytics.total_items_analyzed.toLocaleString()}</p>
        <p class="stat-meta">From ${data.item_analytics.total_approved_baskets} approved receipts</p>
    </div>
</div>

<!-- Merchant & Brand Analytics -->
<div class="charts-grid" style="margin-top: 20px;">
    <div class="chart-card">
        <h3>🏪 Top Merchants by Basket Size</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Merchant</th>
                    <th>Avg Basket Size</th>
                    <th>Avg Basket Total</th>
                    <th>Receipts</th>
                </tr>
            </thead>
            <tbody>
                ${data.merchant_basket_analytics.top_merchants_by_basket_size.map(merchant => `
                    <tr>
                        <td><strong>${merchant.merchant}</strong></td>
                        <td>${merchant.avg_basket_size} items</td>
                        <td>S$${merchant.avg_basket_total.toLocaleString()}</td>
                        <td>${merchant.approved_receipts}/${merchant.total_receipts}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    </div>

    <div class="chart-card">
        <h3>🏷️ Top Brands by Purchase Amount</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Brand</th>
                    <th>Purchases</th>
                    <th>Total Amount</th>
                    <th>Avg Cost</th>
                </tr>
            </thead>
            <tbody>
                ${data.brand_analytics.top_brands.map(brand => `
                    <tr>
                        <td><strong>${brand.brand}</strong></td>
                        <td>${brand.total_purchases}</td>
                        <td>S$${brand.total_amount.toLocaleString()}</td>
                        <td>S$${brand.avg_purchase_cost.toFixed(2)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    </div>
    
    <div class="chart-card">
        <h3>📦 Most Purchased Items</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Times Purchased</th>
                    <th>Total Amount</th>
                </tr>
            </thead>
            <tbody>
                ${data.item_insights.most_purchased.slice(0, 10).map(item => `
                    <tr>
                        <td>${item.name}</td>
                        <td><strong>${item.count}</strong></td>
                        <td>S$${item.total_amount.toFixed(2)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    </div>
    
    <div class="chart-card">
        <h3>💎 Most Expensive Items</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Brand</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody>
                ${data.item_insights.most_expensive.slice(0, 10).map(item => `
                    <tr>
                        <td>${item.name}</td>
                        <td>${item.brand}</td>
                        <td><strong>S$${item.price.toFixed(2)}</strong></td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    </div>
</div>
        <!-- CHARTS -->
        <div class="section-title" style="margin-top: 40px;">📈 Trends & Insights</div>
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Top Merchants</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Merchant</th>
                            <th>Receipts</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${Object.entries(charts.top_merchants).map(([merchant, count]) => `
                            <tr>
                                <td>${merchant}</td>
                                <td><strong>${count}</strong></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            
            <div class="chart-card">
                <h3>Top Rewards Redeemed</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reward</th>
                            <th>Type</th>
                            <th>Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${charts.top_rewards.map(reward => `
                            <tr>
                                <td>${reward.name}</td>
                                <td><span style="background: ${reward.type === 'voucher' ? '#dbeafe' : '#fef3c7'}; padding: 2px 8px; border-radius: 4px; font-size: 11px;">${reward.type || 'physical'}</span></td>
                                <td><strong>${reward.redemptions}</strong></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>

            <div class="chart-card">
                <h3>🕐 Submission Heatmap by Hour</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
                    Peak submission time: <strong>${charts.peak_submission_hour}:00 - ${charts.peak_submission_hour + 1}:00</strong>
                </p>
                <div class="heatmap-container">
                    <div class="heatmap-labels by-hour">
                        ${charts.submissions_by_hour.map((_, i) => `<div>${i}</div>`).join('')}
                    </div>
                    <div class="heatmap-grid by-hour">
                        ${charts.submissions_by_hour.map((count, hour) => {
                            const max = Math.max(...charts.submissions_by_hour);
                            const intensity = max > 0 ? (count / max) : 0;
                            const color = intensity > 0.7 ? '#1e40af' : 
                                        intensity > 0.4 ? '#3b82f6' : 
                                        intensity > 0.2 ? '#60a5fa' : '#e5e7eb';
                            return `<div class="heatmap-cell" style="background: ${color}" title="${hour}:00 - ${count} submissions">${count > 0 ? count : ''}</div>`;
                        }).join('')}
                    </div>
                </div>
            </div>

            <div class="chart-card">
                <h3>📅 Submission Heatmap by Day of Week</h3>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
                    Most active day: <strong>${['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][charts.submissions_by_day.indexOf(Math.max(...charts.submissions_by_day))]}</strong>
                </p>
                <div class="heatmap-container">
                    <div class="heatmap-labels by-day">
                        <div>Sun</div>
                        <div>Mon</div>
                        <div>Tue</div>
                        <div>Wed</div>
                        <div>Thu</div>
                        <div>Fri</div>
                        <div>Sat</div>
                    </div>
                    <div class="heatmap-grid by-day">
                        ${charts.submissions_by_day.map((count, day) => {
                            const max = Math.max(...charts.submissions_by_day);
                            const intensity = max > 0 ? (count / max) : 0;
                            const color = intensity > 0.7 ? '#1e40af' : 
                                        intensity > 0.4 ? '#3b82f6' : 
                                        intensity > 0.2 ? '#60a5fa' : '#e5e7eb';
                            const textColor = intensity > 0.7 ? '#fff' : 
                                        intensity > 0.4 ? '#333' : '#333';
                            const dayName = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][day];
                            return `<div class="heatmap-cell" style="background: ${color}" title="${dayName} - ${count} submissions"><strong style="color:${textColor}">${count}</strong><br><span style="color:${textColor}">${dayName}</span></div>`;
                        }).join('')}
                    </div>
                </div>
            </div>
        </div>
    `;
    
    $('#analytics-container').html(html);
    
    // Render new vs returning chart if Chart.js is available
    renderNewVsReturningChart(engagement.new_users_by_date, engagement.returning_users_by_date);
}

function renderNewVsReturningChart(newUsersByDate, returningUsersByDate) {
    // Get all dates
    const allDates = new Set([
        ...Object.keys(newUsersByDate),
        ...Object.keys(returningUsersByDate)
    ]);
    
    const sortedDates = Array.from(allDates).sort();
    
    const newData = sortedDates.map(date => newUsersByDate[date] || 0);
    const returningData = sortedDates.map(date => returningUsersByDate[date] || 0);
    
    // Simple text-based visualization if Chart.js not loaded
    let chartHTML = '<div style="overflow-x: auto;"><table class="data-table"><thead><tr><th>Date</th><th>New Users</th><th>Returning Users</th></tr></thead><tbody>';
    
    sortedDates.forEach((date, i) => {
        chartHTML += `
            <tr>
                <td>${date}</td>
                <td>${newData[i]}</td>
                <td>${returningData[i]}</td>
            </tr>
        `;
    });
    
    chartHTML += '</tbody></table></div>';
    
    $('#newVsReturningChart').parent().html(chartHTML);
}

$('#apply-filter').on('click', loadAnalytics);

$('#logout-btn').on('click', function() {
    $.post(wpApiSettings.root + 'custom/v1/client-logout', function() {
        window.location.href = '/client-login/';
    });
});

// Load initial data (month to date by default)
loadAnalytics();
});
</script>
<?php get_footer(); ?>