jQuery(document).ready(function($) {
    $('#beepi-test-token').on('click', function(e) {
        e.preventDefault();
        
        const resultBox = $('#beepi-test-result');
        resultBox.html('<div class="loading">Testing authentication...</div>').show();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'beepi_test_token',
                nonce: beepiAdmin.nonce
            },
            success: function(response) {
                let html = '<div class="test-results">';
                
                if (response.data && response.data.messages) {
                    // Process detailed messages
                    response.data.messages.forEach(function(msg) {
                        const iconClass = msg.type === 'success' ? 'dashicons-yes' : 'dashicons-no';
                        const messageClass = msg.type === 'success' ? 'success' : 'error';
                        
                        html += `<div class="test-message ${messageClass}">
                            <span class="dashicons ${iconClass}"></span>
                            <span class="message-text">${msg.message}</span>
                        </div>`;
                    });
                    
                    // Final status message
                    if (response.success) {
                        html += '<div class="status-message success">Authentication is working correctly!</div>';
                    } else {
                        html += '<div class="status-message error">Authentication failed! See errors above.</div>';
                    }
                } else {
                    // Fallback for older response format
                    if (response.success) {
                        html += '<div class="status-message success">Success! Authentication is working correctly.</div>';
                    } else {
                        html += `<div class="status-message error">Error: ${response.data.message || 'Unknown error'}</div>`;
                    }
                }
                
                html += '</div>';
                resultBox.html(html);
            },
            error: function() {
                resultBox.html('<div class="status-message error">Connection error. Please try again.</div>');
            }
        });
    });
});