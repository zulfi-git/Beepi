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
                resultBox.html(handleTestResponse(response));
            },
            error: function(xhr, status, error) {
                let errorMessage = 'Connection error. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMessage = xhr.responseJSON.message;
                }
                
                resultBox.html(`
                    <div class="test-results">
                        <div class="status-message error">
                            <strong>❌ Request Failed:</strong><br>
                            ${errorMessage}<br>
                            <em>Status: ${status}</em>
                        </div>
                    </div>
                `);
            }
        });
    });
    
    // Helper function to format individual messages
    function formatMessage(msg, type) {
        const icons = {
            error: '❌',
            warning: '⚠️',
            success: '✅'
        };

        const className = type === 'error' ? 'error-message' : 
                         type === 'warning' ? 'warning-message' : 
                         'success-message';
        
        let html = `
            <div class="message ${className}">
                <div class="message-header">
                    <span class="message-icon">${icons[type]}</span>
                    <span class="message-text">${msg.text}</span>
                    ${msg.timestamp ? `<span class="message-time">${msg.timestamp}</span>` : ''}
                </div>`;
        
        if (msg.details || msg.raw_message || msg.response_code) {
            html += '<div class="message-details">';
            if (msg.details) {
                html += `<div class="detail-item"><strong>Details:</strong> ${msg.details}</div>`;
            }
            if (msg.raw_message) {
                html += `<div class="detail-item"><strong>Raw Response:</strong> ${msg.raw_message}</div>`;
            }
            if (msg.response_code) {
                html += `<div class="detail-item"><strong>Response Code:</strong> ${msg.response_code}</div>`;
            }
            html += '</div>';
        }
        
        html += '</div>';
        return html;
    }

    function handleTestResponse(response) {
        let html = '<div class="test-results">';
        
        if (response.data && response.data.messages) {
            // Group messages by type
            const groups = {
                error: [],
                warning: [],
                success: []
            };
            
            response.data.messages.forEach(msg => {
                const type = msg.type || 'warning';
                groups[type].push(msg);
            });
            
            // Display error messages first
            if (groups.error.length > 0) {
                html += `
                    <div class="message-group error-group">
                        <h4 class="group-header">❌ Errors Found (${groups.error.length})</h4>
                        ${groups.error.map(msg => formatMessage(msg, 'error')).join('')}
                    </div>`;
            }
            
            // Display warnings
            if (groups.warning.length > 0) {
                html += `
                    <div class="message-group warning-group">
                        <h4 class="group-header">⚠️ Warnings (${groups.warning.length})</h4>
                        ${groups.warning.map(msg => formatMessage(msg, 'warning')).join('')}
                    </div>`;
            }
            
            // Display success messages
            if (groups.success.length > 0) {
                html += `
                    <div class="message-group success-group">
                        <h4 class="group-header">✅ Successful Steps (${groups.success.length})</h4>
                        ${groups.success.map(msg => formatMessage(msg, 'success')).join('')}
                    </div>`;
            }
            
            // Add debug information if available
            if (response.data.debug_info) {
                html += `
                    <div class="debug-info">
                        <h4 class="group-header">🔍 Debug Information</h4>
                        <pre>${JSON.stringify(response.data.debug_info, null, 2)}</pre>
                    </div>`;
            }
            
            // Final status message
            html += `
                <div class="status-message ${response.success ? 'success' : 'error'}">
                    <strong>${response.success ? '✅ Authentication Successful' : '❌ Authentication Failed'}</strong>
                    <p>${response.success ? 
                        'All tests completed successfully.' : 
                        'Please review the errors above and check your configuration.'}
                    </p>
                </div>`;
        } else {
            // Fallback for unexpected response format
            html += `
                <div class="status-message error">
                    <strong>❌ Unexpected Response Format</strong>
                    <p>The server response was not in the expected format.</p>
                    <pre>${JSON.stringify(response, null, 2)}</pre>
                </div>`;
        }
        
        html += '</div>';
        return html;
    }
});