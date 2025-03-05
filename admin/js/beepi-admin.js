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

    $('#beepi-run-diagnostics').on('click', function() {
        const $button = $(this);
        const $results = $('#beepi-diagnostic-results');
        const $content = $results.find('.diagnostic-content');
        
        $button.prop('disabled', true).text(beepiAdmin.diagnostic_labels.running);
        $content.html('');
        $results.show();
        
        $.ajax({
            url: beepiAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'svv_run_diagnostics',
                nonce: beepiAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    displayDiagnosticResults(response.data);
                } else {
                    $content.html('<div class="notice notice-error"><p>Diagnostic failed: ' + 
                        (response.data || 'Unknown error') + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $content.html('<div class="notice notice-error"><p>Ajax request failed: ' + error + '</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Run Diagnostics');
            }
        });
    });
    
    function displayDiagnosticResults(data) {
        const $content = $('#beepi-diagnostic-results .diagnostic-content');
        let html = '<div class="diagnostic-summary">';
        
        // Authentication section
        html += '<div class="diagnostic-section">';
        html += '<h4>Authentication Status</h4>';
        html += formatAuthenticationResults(data.authentication_debug);
        html += '</div>';
        
        // Endpoint section
        html += '<div class="diagnostic-section">';
        html += '<h4>Endpoint Status</h4>';
        html += formatEndpointResults(data.endpoint_diagnosis);
        html += '</div>';
        
        html += '</div>';
        
        $content.html(html);
    }
    
    function formatAuthenticationResults(auth) {
        let html = '<table class="widefat">';
        
        for (let key in auth) {
            const status = auth[key].status;
            const statusClass = getStatusClass(status);
            
            html += `<tr>
                <td><strong>${key}</strong></td>
                <td><span class="status-badge ${statusClass}">${status}</span></td>
                <td>${formatDetails(auth[key].details)}</td>
            </tr>`;
        }
        
        html += '</table>';
        return html;
    }
    
    function formatEndpointResults(endpoint) {
        let html = '<table class="widefat">';
        
        // Connectivity status
        html += `<tr>
            <td><strong>Connectivity</strong></td>
            <td><span class="status-badge ${getStatusClass(endpoint.connectivity.status)}">
                ${endpoint.connectivity.status}</span></td>
            <td>${formatDetails(endpoint.connectivity.details)}</td>
        </tr>`;
        
        // Response time
        if (endpoint.response_time) {
            html += `<tr>
                <td><strong>Response Time</strong></td>
                <td colspan="2">${endpoint.response_time}ms</td>
            </tr>`;
        }
        
        html += '</table>';
        return html;
    }
    
    function getStatusClass(status) {
        switch (status) {
            case 'ok': return 'status-success';
            case 'error': return 'status-error';
            default: return 'status-unknown';
        }
    }
    
    function formatDetails(details) {
        if (typeof details !== 'object') return '';
        
        let html = '<ul class="diagnostic-details">';
        for (let key in details) {
            if (details[key] !== null && details[key] !== undefined) {
                html += `<li><strong>${key}:</strong> ${details[key]}</li>`;
            }
        }
        html += '</ul>';
        return html;
    }
});