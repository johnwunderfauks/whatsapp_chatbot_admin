<?php
/**
 * Template Name: Login Page
 */
get_header();
?>

<div class="login-container">
    <div class="login-card" id="login-form">
        <div class="logo">
            <h1>🛍️ Customer Portal</h1>
            <p>Login with your WhatsApp number</p>
        </div>

        <div id="alert-container"></div>

        <form id="phone-form">
            <div class="form-group">
                <label for="phone">WhatsApp Number</label>
                <input type="tel" id="phone" name="phone" placeholder="+66812345678" required>
                <small>Include country code (e.g., +66 for Thailand)</small>
            </div>
            <button type="submit" class="btn btn-primary" id="send-otp-btn">
                📱 Send OTP
            </button>
        </form>
    </div>

    <div class="login-card" id="otp-form" style="display: none;">
        <div class="logo">
            <h1>🔐 Enter OTP</h1>
            <p>We've sent a code to your WhatsApp</p>
        </div>

        <div id="otp-alert-container"></div>

        <form id="verify-otp-form">
            <div class="form-group">
                <label for="otp">6-Digit Code</label>
                <input type="text" id="otp" name="otp" placeholder="123456" maxlength="6" required>
            </div>
            <button type="submit" class="btn btn-primary" id="verify-otp-btn">
                ✅ Verify & Login
            </button>
            <button type="button" class="btn btn-secondary" id="back-btn">
                ← Back
            </button>
        </form>
    </div>
</div>

<?php get_footer(); ?>