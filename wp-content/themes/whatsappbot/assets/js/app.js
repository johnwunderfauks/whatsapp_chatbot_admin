jQuery(document).ready(function($) {
    const apiUrl = wpApiSettings.root + 'custom/v1';
    $('#not-logged-in').removeClass('active');
    // Check if user is logged in
    function checkAuth() {
        const token = getCookie('whatsapp_token');
        if (token && $('#dashboard').length) {
            console.log("logged in")
            loadDashboard();
        } else if (!token && $('#dashboard').length) {
            $('#not-logged-in').addClass('active');
        }
    }
    
    // Get cookie
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
    }
    
    // Show alert
    function showAlert(message, type, containerId) {
        const alertClass = type === 'error' ? 'alert-error' : 'alert-success';
        const alertHtml = `<div class="alert ${alertClass}">${message}</div>`;
        $(`#${containerId}`).html(alertHtml);
        
        setTimeout(() => {
            $(`#${containerId}`).html('');
        }, 5000);
    }
    
    // Send OTP
    $('#phone-form').on('submit', function(e) {
        e.preventDefault();
        
        const phone = $('#phone').val().trim();
        const btn = $('#send-otp-btn');
        
        btn.prop('disabled', true).text('Sending...');
        
        $.ajax({
            url: apiUrl + '/whatsapp-login',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ phone: phone }),
            success: function(response) {
                if (response.success) {
                    showAlert('✅ OTP sent to your WhatsApp!', 'success', 'alert-container');
                    setTimeout(() => {
                        $('#login-form').hide();
                        $('#otp-form').show();
                        $('#otp-form input[name="phone"]').val(phone);
                    }, 1000);
                }
            },
            error: function(xhr) {
                const error = xhr.responseJSON;
                showAlert('❌ ' + (error.message || 'Failed to send OTP'), 'error', 'alert-container');
                btn.prop('disabled', false).text('📱 Send OTP');
            }
        });
    });
    
    // Verify OTP
    $('#verify-otp-form').on('submit', function(e) {
        e.preventDefault();
        
        const phone = $('#phone').val().trim();
        const otp = $('#otp').val().trim();
        const btn = $('#verify-otp-btn');
        
        btn.prop('disabled', true).text('Verifying...');
        
        $.ajax({
            url: apiUrl + '/verify-otp',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ phone: phone, otp: otp }),
            success: function(response) {
                if (response.success) {
                    showAlert('✅ Login successful! Redirecting...', 'success', 'otp-alert-container');
                    setTimeout(() => {
                        window.location.href = '/dashboard';
                    }, 1000);
                }
            },
            error: function(xhr) {
                const error = xhr.responseJSON;
                showAlert('❌ ' + (error.message || 'Invalid OTP'), 'error', 'otp-alert-container');
                btn.prop('disabled', false).text('✅ Verify & Login');
            }
        });
    });
    
    // Back button
    $('#back-btn').on('click', function() {
        $('#otp-form').hide();
        $('#login-form').show();
        $('#otp').val('');
        $('#otp-alert-container').html('');
    });
    
    // Load Dashboard
    function loadDashboard() {
        $.ajax({
            url: apiUrl + '/user-data',
            type: 'GET',
            success: function(response) {
                if (response.success) {
                    $('#dashboard').show();
                    $('#not-logged-in').removeClass('active');
                    
                    // Update user info
                    $('#user-name').text(response.user.name);
                    $('#user-phone').text(response.user.phone);
                    $('#loyalty-points').text(response.user.loyalty_points);
                    $('#total-receipts').text(response.total_receipts);
                    $('#total-spent').text(parseFloat(response.total_spent || 0).toFixed(2));
                    $('#member-since').text(response.user.member_since);
                    $('#rewards-available').text(response.rewards_available);
                    
                    // Load receipts
                    if (response.receipts.length > 0) {
                        let receiptsHtml = '';
                        response.receipts.forEach(receipt => {
                            receiptsHtml += `
                                <div class="receipt-card">
                                    <div class="receipt-header">
                                        <h3>${receipt.store_name || 'Unknown Store'}</h3>
                                        <div class="receipt-amount">${receipt.currency || '฿'} ${receipt.total_amount}</div>
                                    </div>
                                    <div class="receipt-meta">
                                        <span>📅 ${new Date(receipt.date).toLocaleDateString()}</span>
                                        ${receipt.loyalty_points ? `<span class="receipt-points">💎 +${receipt.loyalty_points} points</span>` : ''}
                                    </div>
                                    ${receipt.image_url ? `<div class="receipt-image"><img src="${receipt.image_url}" alt="Receipt"></div>` : ''}
                                </div>
                            `;
                        });
                        $('#receipts-list').html(receiptsHtml);
                    } else {
                        $('#receipts-list').html('<p class="loading">No receipts found. Start uploading receipts via WhatsApp!</p>');
                    }
                }
            },
            error: function() {
                $('#dashboard').hide();
                $('#not-logged-in').addClass('active');
            }
        });
    }
    
    // Logout
    $('#logout-btn').on('click', function() {
        $.ajax({
            url: apiUrl + '/logout',
            type: 'POST',
            success: function() {
                window.location.href = '/login';
            }
        });
    });
    
    // Initialize
    checkAuth();

    // ==========================================
    // REWARDS PAGE FUNCTIONALITY
    // ==========================================

    let userPoints = 0;

    // Load rewards page
    if ($('.rewards-container').length) {
        loadRewardsPage();
    }

    function loadRewardsPage() {
        const token = getCookie('whatsapp_token') || localStorage.getItem('whatsapp_token');
        
        if (!token) {
            $('.rewards-container').hide();
            $('#rewards-not-logged-in').css('display', 'flex').show();
            return;
        }
        
        // Load user points first
        loadUserPoints();
        
        // Load rewards
        loadRewards();
    }

    function loadUserPoints() {
        return $.ajax({
            url: apiUrl + '/user-data',
            type: 'GET',
            xhrFields: { withCredentials: true }
        }).then(response => {
            if (response.success) {
                userPoints = response.user.loyalty_points;
                $('#user-points-display').text(userPoints);
            }
            return userPoints;
        });
    }

    function loadRewards() {
        return $.ajax({
            url: apiUrl + '/rewards',
            type: 'GET'
        }).then(response => {
            if (response.success) {
                return response.rewards;
            }
            return [];
        });
    }

    function displayRewards(rewards) {
        let html = '';
        
        rewards.forEach(reward => {
            const canAfford = userPoints >= reward.points_cost;
            console.log(userPoints, reward.points_cost)
            const isAvailable = reward.is_available;
            const lowStock = reward.max_quantity > 0 && reward.current_quantity <= 5;
            
            html += `
                <div class="reward-card" data-reward-id="${reward.id}">
                    <div class="reward-image">
                        ${reward.image_url ? `<img src="${reward.image_url}" alt="${reward.title}">` : '<div style="background: #116500; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 64px;">🎁</div>'}
                    </div>
                    <div class="reward-info">
                        <span class="reward-type-badge">${reward.reward_type || 'reward'}</span>
                        <h3 class="reward-title">${reward.title}</h3>
                        <p class="reward-description">${reward.description || 'Exciting reward awaits!'}</p>
                        <div class="reward-footer">
                            <div>
                                <div class="reward-points">${reward.points_cost} 💎</div>
                                ${reward.max_quantity > 0 ? `
                                    <div class="reward-quantity ${lowStock ? 'low-stock' : ''}">
                                        ${reward.current_quantity} left
                                    </div>
                                ` : ''}
                            </div>
                            ${isAvailable ? `
                                <button class="btn-redeem" 
                                        data-reward-id="${reward.id}"
                                        ${!canAfford ? 'disabled title="Not enough points"' : ''}>
                                    ${canAfford ? 'Redeem' : 'Not Enough'}
                                </button>
                            ` : `
                                <span class="out-of-stock-badge">Out of Stock</span>
                            `}
                        </div>
                    </div>
                </div>
            `;
        });
        
        $('#rewards-list').html(html);
    }

    Promise.all([
        loadUserPoints(),
        loadRewards()
    ]).then(([points, rewards]) => {
        displayRewards(rewards);
    }).catch(err => {
        console.error('Failed loading data', err);
    });

    // Redeem button click
    $(document).on('click', '.btn-redeem:not(:disabled)', function() {
        const rewardId = $(this).data('reward-id');
        showRedemptionModal(rewardId);
    });

    // Reward card click for details
    $(document).on('click', '.reward-card', function(e) {
        if (!$(e.target).hasClass('btn-redeem')) {
            const rewardId = $(this).data('reward-id');
            showRewardDetails(rewardId);
        }
    });

    function showRewardDetails(rewardId) {
        $.ajax({
            url: apiUrl + '/rewards',
            type: 'GET',
            success: function(response) {
                const reward = response.rewards.find(r => r.id == rewardId);
                if (reward) {
                    showRedemptionModal(rewardId, reward);
                }
            }
        });
    }

    function showRedemptionModal(rewardId, rewardData = null) {
        if (rewardData) {
            displayModal(rewardData);
        } else {
            // Fetch reward details
            $.ajax({
                url: apiUrl + '/rewards',
                type: 'GET',
                success: function(response) {
                    const reward = response.rewards.find(r => r.id == rewardId);
                    if (reward) {
                        displayModal(reward);
                    }
                }
            });
        }
        
        function displayModal(reward) {
            const canAfford = userPoints >= reward.points_cost;
            const newBalance = userPoints - reward.points_cost;
            
            const modalHtml = `
                ${reward.image_url ? `<img src="${reward.image_url}" class="modal-reward-image" alt="${reward.title}">` : ''}
                <h2 class="modal-reward-title">${reward.title}</h2>
                <p class="modal-reward-description">${reward.description || 'Redeem this exciting reward!'}</p>
                
                <div class="modal-points-display">
                    <p>
                        <span>Cost:</span>
                        <span class="highlight">${reward.points_cost} 💎</span>
                    </p>
                    <p>
                        <span>Your Points:</span>
                        <span>${userPoints} 💎</span>
                    </p>
                    <p>
                        <span>After Redemption:</span>
                        <span class="highlight">${newBalance} 💎</span>
                    </p>
                </div>
                
                ${!canAfford ? '<p style="color: #ff6b6b; text-align: center; font-weight: 600;">⚠️ Not enough points</p>' : ''}
                
                <div class="modal-actions">
                    <button class="btn btn-cancel modal-close-btn">Cancel</button>
                    <button class="btn btn-primary confirm-redeem-btn" 
                            data-reward-id="${reward.id}"
                            ${!canAfford ? 'disabled' : ''}>
                        ${canAfford ? '✅ Confirm Redemption' : '❌ Insufficient Points'}
                    </button>
                </div>
            `;
            
            $('#modal-body').html(modalHtml);
            $('#redemption-modal').addClass('active');
        }
    }

    // Confirm redemption
    $(document).on('click', '.confirm-redeem-btn:not(:disabled)', function() {
        const rewardId = $(this).data('reward-id');
        const btn = $(this);
        
        btn.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: apiUrl + '/redeem-reward',
            type: 'POST',
            contentType: 'application/json',
            xhrFields: { withCredentials: true },
            data: JSON.stringify({ reward_id: rewardId }),
            success: function(response) {
                if (response.success) {
                    // Update user points
                    userPoints = response.new_points_balance;
                    $('#user-points-display').text(userPoints);
                    
                    // Show success message
                    $('#modal-body').html(`
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 64px; margin-bottom: 20px;">🎉</div>
                            <h2 style="color: #333; margin-bottom: 15px;">Redemption Successful!</h2>
                            <p style="color: #666; margin-bottom: 20px;">Your reward has been redeemed successfully.</p>
                            
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 15px; margin-bottom: 20px;">
                                <p style="font-size: 14px; color: #666; margin-bottom: 10px;">Your Redemption Code:</p>
                                <div class="redemption-code" style="font-size: 24px;">${response.redemption_code}</div>
                                <p style="font-size: 12px; color: #999; margin-top: 10px;">Save this code to claim your reward</p>
                            </div>
                            
                            <p style="color: #667eea; font-weight: 600; margin-bottom: 20px;">
                                New Balance: ${response.new_points_balance} 💎
                            </p>
                            
                            <button class="btn btn-primary modal-close-btn">Close</button>
                        </div>
                    `);
                    
                    // Reload rewards to update quantities
                    setTimeout(() => {
                        loadRewards();
                    }, 2000);
                }
            },
            error: function(xhr) {
                const error = xhr.responseJSON;
                alert('❌ ' + (error.message || 'Failed to redeem reward'));
                btn.prop('disabled', false).text('✅ Confirm Redemption');
            }
        });
    });

    // Close modal
    $(document).on('click', '.modal-close, .modal-close-btn', function() {
        $('#redemption-modal').removeClass('active');
    });

    $(document).on('click', '#redemption-modal', function(e) {
        if (e.target.id === 'redemption-modal') {
            $('#redemption-modal').removeClass('active');
        }
    });

    // Tab switching
    $('.tab-btn').on('click', function() {
        const tab = $(this).data('tab');
        
        $('.tab-btn').removeClass('active');
        $(this).addClass('active');
        
        $('.tab-content').removeClass('active');
        
        if (tab === 'available') {
            $('#available-tab').addClass('active');
        } else if (tab === 'history') {
            $('#history-tab').addClass('active');
            loadRedemptionHistory();
        }
    });

    // Load redemption history
    function loadRedemptionHistory() {
        if ($('#redemptions-list').data('loaded')) return;
        
        $.ajax({
            url: apiUrl + '/my-redemptions',
            type: 'GET',
            xhrFields: { withCredentials: true },
            success: function(response) {
                if (response.success && response.redemptions.length > 0) {
                    displayRedemptionHistory(response.redemptions);
                } else {
                    $('#redemptions-list').html(`
                        <div class="empty-state">
                            <div class="empty-state-icon">📋</div>
                            <h3>No Redemptions Yet</h3>
                            <p>Start redeeming rewards to see your history here!</p>
                        </div>
                    `);
                }
                $('#redemptions-list').data('loaded', true);
            },
            error: function() {
                $('#redemptions-list').html('<p class="error">Failed to load redemption history</p>');
            }
        });
    }

    function displayRedemptionHistory(redemptions) {
        let html = '';
        
        redemptions.forEach(redemption => {
            const date = new Date(redemption.redeemed_at).toLocaleDateString();
            const statusClass = redemption.status.toLowerCase();
            
            html += `
                <div class="redemption-item">
                    ${redemption.reward_image ? `
                        <img src="${redemption.reward_image}" class="redemption-image" alt="${redemption.reward_title}">
                    ` : `
                        <div class="redemption-image" style="display: flex; align-items: center; justify-content: center; font-size: 32px; background: #116500;">🎁</div>
                    `}
                    <div class="redemption-details">
                        <h3 class="redemption-title">${redemption.reward_title}</h3>
                        <div class="redemption-meta">
                            <span>📅 ${date}</span>
                            <span>💎 ${redemption.points_spent} points</span>
                            <span class="status-badge ${statusClass}">${redemption.status}</span>
                        </div>
                        <div class="redemption-code">${redemption.redemption_code}</div>
                        ${redemption.notes ? `<p style="margin-top: 10px; color: #666; font-size: 14px;">${redemption.notes}</p>` : ''}
                    </div>
                </div>
            `;
        });
        
        $('#redemptions-list').html(html);
    }
});