<?php
/**
 * Template Name: Dashboard Page
 */
get_header();
?>

<div class="dashboard-container" id="dashboard" style="display: none;">
    <!-- Header -->
    <div class="dashboard-header">
        <div class="header-content">
            <div class="user-info">
                <h2 id="user-name">Loading...</h2>
                <p id="user-phone"></p>
            </div>
            <button class="btn-logout" id="logout-btn">🚪 Logout</button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Loyalty Points</h3>
            <div class="value" id="loyalty-points">0</div>
            <p class="stat-label">💎 Total Points</p>
        </div>
        <div class="stat-card">
            <h3>Total Receipts</h3>
            <div class="value" id="total-receipts">0</div>
            <p class="stat-label">📄 Receipts</p>
        </div>
        <div class="stat-card">
            <h3>Total Spent</h3>
            <div class="value" id="total-spent">฿0</div>
            <p class="stat-label">💰 Amount</p>
        </div>
        <div class="stat-card">
            <h3>Member Since</h3>
            <div class="value-small" id="member-since">-</div>
            <p class="stat-label">📅 Join Date</p>
        </div>

        <div class="stat-card" style="cursor: pointer;" onclick="window.location.href='/rewards'">
            <h3>Rewards Available</h3>
            <div class="value" id="rewards-available">-</div>
            <p class="stat-label">🎁 Redeem Now</p>
        </div>
    </div>

    <!-- Receipts Section -->
    <div class="receipts-section">
        <h2>📋 Purchase History</h2>
        <div id="receipts-list">
            <div class="loading">Loading your receipts...</div>
        </div>
    </div>
</div>

<div class="not-logged-in" id="not-logged-in">
    <div class="message-card">
        <h2>🔒 Access Denied</h2>
        <p>Please login to view your dashboard</p>
        <a href="<?php echo home_url('/login'); ?>" class="btn btn-primary">Go to Login</a>
    </div>
</div>

<?php get_footer(); ?>