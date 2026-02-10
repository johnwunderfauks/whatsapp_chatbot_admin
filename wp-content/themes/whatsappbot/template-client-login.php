<?php
/**
 * Template Name: Client Login
 */
get_header();
?>

<div class="login-container">
    <div class="login-card">
        <div class="logo">
            <h1>📊 Client Dashboard</h1>
            <p>Analytics Portal</p>
        </div>

        <div id="alert-container"></div>

        <form id="client-login-form">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            
            <button type="submit" class="btn btn-primary" id="login-btn">
                🔐 Login
            </button>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#client-login-form').on('submit', function(e) {
        e.preventDefault();
        
        const username = $('#username').val();
        const password = $('#password').val();
        const $btn = $('#login-btn');
        const $alert = $('#alert-container');
        
        $btn.prop('disabled', true).text('Logging in...');
        $alert.html('');
        
        $.ajax({
            url: wpApiSettings.root + 'custom/v1/client-login',
            method: 'POST',
            data: JSON.stringify({ username, password }),
            contentType: 'application/json',
            success: function(response) {
                $alert.html('<div class="alert alert-success">✅ Login successful! Redirecting...</div>');
                setTimeout(() => {
                    window.location.href = '/client-dashboard/';
                }, 1000);
            },
            error: function(xhr) {
                const error = xhr.responseJSON;
                $alert.html(`<div class="alert alert-error">❌ ${error.message}</div>`);
                $btn.prop('disabled', false).text('🔐 Login');
            }
        });
    });
});
</script>

<?php get_footer(); ?>