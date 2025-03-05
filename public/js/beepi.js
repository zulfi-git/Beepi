/**
 * Frontend JavaScript for Beepi plugin
 */
(function($) {
    'use strict';

    // When the document is ready
    $(document).ready(function() {
        // Handle form submission
        $('#vehicle-search-form').on('submit', function(e) {
            e.preventDefault();
            
            const form = $(this);
            const resultContainer = $('#vehicle-lookup-results');
            const registration = $('#vehicle-registration').val().toUpperCase();
            
            // Validate registration number format
            if (!registration.match(/^[A-Z0-9]+$/)) {
                resultContainer.html('<div class="beepi-error"><p>Invalid registration number format.</p></div>');
                return;
            }
            
            // Show loading indicator
            resultContainer.html('<div class="beepi-loading">' + beepi.loading_text + '</div>');
            
            // Collect form data
            const formData = {
                action: 'vehicle_lookup',
                nonce: beepi.nonce,
                registration: registration
            };
            
            // Add partner ID if present
            const partnerIdField = form.find('input[name="partner_id"]');
            if (partnerIdField.length > 0) {
                formData.partner_id = partnerIdField.val();
            }
            
            // Send AJAX request
            $.ajax({
                url: beepi.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        // Render teaser results
                        renderTeaserResults(response.data, resultContainer);
                    } else {
                        // Show error message
                        const message = response.data && response.data.message ? response.data.message : beepi.error_text;
                        resultContainer.html('<div class="beepi-error"><p>' + message + '</p></div>');
                    }
                },
                error: function() {
                    // Show generic error message
                    resultContainer.html('<div class="beepi-error"><p>' + beepi.error_text + '</p></div>');
                }
            });
        });
    });
    
    /**
     * Render teaser results
     * 
     * @param {Object} data - The response data
     * @param {jQuery} container - The container to render into
     */
    function renderTeaserResults(data, container) {
        const teaser = data.teaser;
        const checkoutUrl = data.checkout_url;
        
        // Create HTML for the teaser
        let html = `
            <div class="vehicle-teaser">
                <div class="vehicle-teaser-header">
                    <h2>${teaser.brand} ${teaser.model}</h2>
                    <div class="vehicle-reg-number">${teaser.reg_number}</div>
                </div>
                
                <div class="vehicle-teaser-body">
                    <div class="vehicle-teaser-section">
                        <h3>Vehicle Information</h3>
                        <div class="vehicle-info-grid">
                            <div class="vehicle-info-item">
                                <span class="info-label">Type:</span>
                                <span class="info-value">${teaser.vehicle_type || '-'}</span>
                            </div>
                            
                            <div class="vehicle-info-item">
                                <span class="info-label">First Registration:</span>
                                <span class="info-value">${formatDate(teaser.first_registration) || '-'}</span>
                            </div>`;
        
        // Add engine info if available
        if (teaser.engine) {
            if (teaser.engine.fuel_type) {
                html += `
                    <div class="vehicle-info-item">
                        <span class="info-label">Fuel Type:</span>
                        <span class="info-value">${teaser.engine.fuel_type}</span>
                    </div>`;
            }
            
            if (teaser.engine.displacement) {
                html += `
                    <div class="vehicle-info-item">
                        <span class="info-label">Engine Size:</span>
                        <span class="info-value">${teaser.engine.displacement} cc</span>
                    </div>`;
            }
            
            if (teaser.engine.power) {
                html += `
                    <div class="vehicle-info-item">
                        <span class="info-label">Engine Power:</span>
                        <span class="info-value">${teaser.engine.power} kW</span>
                    </div>`;
            }
        }
        
        // Add inspection info if available
        if (teaser.last_inspection) {
            html += `
                <div class="vehicle-info-item">
                    <span class="info-label">Last Inspection:</span>
                    <span class="info-value">${formatDate(teaser.last_inspection.last_date) || '-'}</span>
                </div>
                <div class="vehicle-info-item">
                    <span class="info-label">Next Inspection Due:</span>
                    <span class="info-value">${formatDate(teaser.last_inspection.next_date) || '-'}</span>
                </div>`;
        }
        
        // Close the vehicle info section and add the locked owner section
        html += `
                        </div>
                    </div>
                    
                    <div class="vehicle-owner-locked">
                        <div class="locked-content">
                            <div class="lock-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                            </div>
                            <h3>Vehicle Owner Information</h3>
                            <p>Get access to the owner information and complete vehicle history.</p>
                            <a href="${checkoutUrl}" class="view-owner-button">View Owner Info</a>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Insert the HTML into the container
        container.html(html);
    }
    
    /**
     * Format a date string
     * 
     * @param {string} dateString - The date string to format
     * @return {string} The formatted date
     */
    function formatDate(dateString) {
        if (!dateString) {
            return '';
        }
        
        const date = new Date(dateString);
        if (isNaN(date.getTime())) {
            return dateString; // Return original if not a valid date
        }
        
        return date.toLocaleDateString();
    }

})(jQuery);
