<?php
/**
 * Template Name: Rewards Page
 */
get_header();
?>

<div class="rewards-container">
    <!-- Header -->
    <div class="rewards-header">
        <h1>🎁 Rewards Store</h1>
        <div class="user-points-badge">
            <span class="points-label">Your Points:</span>
            <span class="points-value" id="user-points-display">0</span> 💎
        </div>
    </div>

    <!-- Tabs -->
    <div class="rewards-tabs">
        <button class="tab-btn active" data-tab="available">Available Rewards</button>
        <button class="tab-btn" data-tab="history">My Redemptions</button>
    </div>

    <!-- Available Rewards Tab -->
    <div class="tab-content active" id="available-tab">
        <div class="rewards-grid" id="rewards-list">
            <div class="loading">Loading rewards...</div>
        </div>
    </div>

    <!-- Redemption History Tab -->
    <div class="tab-content" id="history-tab">
        <div class="redemptions-list" id="redemptions-list">
            <div class="loading">Loading your redemptions...</div>
        </div>
    </div>
</div>

<!-- Redemption Modal -->
<div class="modal" id="redemption-modal">
    <div class="modal-content">
        <span class="modal-close">&times;</span>
        <div id="modal-body">
            <!-- Dynamic content -->
        </div>
    </div>
</div>

<!-- Not Logged In Message -->
<div class="not-logged-in" id="rewards-not-logged-in">
    <div class="message-card">
        <h2>🔒 Login Required</h2>
        <p>Please login to view and redeem rewards</p>
        <a href="<?php echo home_url('/login'); ?>" class="btn btn-primary">Go to Login</a>
    </div>
</div>

<?php get_footer(); ?>