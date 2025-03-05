
jQuery(document).ready(function($) {
    $('#run-svv-diagnostics').on('click', function() {
        const $button = $(this);
        const $results = $('#diagnostic-results');
        const $content = $results.find('.diagnostic-content');
        
        $button.prop('disabled', true).text(svvDiagnostics.diagnostic_labels.running);
        $content.text('');
        $results.show();
        
        $.ajax({
            url: svvDiagnostics.ajax_url,
            type: 'POST',
            data: {
                action: 'svv_run_diagnostics',
                nonce: svvDiagnostics.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Pretty print the JSON response
                    const formattedData = JSON.stringify(response.data, null, 2);
                    $content.text(formattedData);
                    $button.text(svvDiagnostics.diagnostic_labels.success);
                } else {
                    $content.text('Diagnostic failed: ' + (response.data || 'Unknown error'));
                    $button.text(svvDiagnostics.diagnostic_labels.error);
                }
            },
            error: function(xhr, status, error) {
                $content.text('Ajax request failed: ' + error);
                $button.text(svvDiagnostics.diagnostic_labels.error);
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
});
