/**
 * Frontend JavaScript for Beepi plugin
 */
(function($) {
    'use strict';

    // Validation patterns
    const REGISTRATION_PATTERNS = {
        NO: /^[A-Z]{2}[0-9]{4,5}$/, // Norwegian format
        NO_EL: /^E[A-Z][0-9]{4,5}$/, // Norwegian electric vehicles
        NO_OLD: /^[A-Z]{1,2}[0-9]{4,5}$/ // Older Norwegian format
    };

    // When the document is ready
    $(document).ready(function() {
        // Handle form submission
        $('#vehicle-search-form').on('submit', function(e) {
            e.preventDefault();
            
            const form = $(this);
            const resultContainer = $('#vehicle-lookup-results');
            const registration = $('#vehicle-registration').val().toUpperCase().replace(/\s+/g, '');
            
            // Enhanced validation
            if (!validateRegistration(registration)) {
                showError(resultContainer, 'Invalid registration number format. Please check and try again.');
                highlightField($('#vehicle-registration'));
                return;
            }
            
            showLoading(resultContainer);
            
            $.ajax({
                url: beepi.ajax_url,
                type: 'POST',
                data: {
                    action: 'vehicle_lookup',
                    nonce: beepi.nonce,
                    registration: registration,
                    partner_id: form.find('input[name="partner_id"]').val() || ''
                },
                success: function(response) {
                    handleLookupResponse(response, resultContainer);
                },
                error: function(xhr, status, error) {
                    handleAjaxError(xhr, status, error, resultContainer);
                }
            });
        });
    });

    function validateRegistration(reg) {
        return Object.values(REGISTRATION_PATTERNS).some(pattern => pattern.test(reg));
    }

    function showLoading(container) {
        container.html(`
            <div class="beepi-loading">
                <div class="loading-spinner"></div>
                <p>${beepi.loading_text}</p>
            </div>
        `);
    }

    function showError(container, message, details = '') {
        container.html(`
            <div class="beepi-error">
                <div class="error-icon">⚠️</div>
                <p class="error-message">${message}</p>
                ${details ? `<p class="error-details">${details}</p>` : ''}
                <button class="retry-button" onclick="window.location.reload()">Try Again</button>
            </div>
        `);
    }

    function handleLookupResponse(response, container) {
        if (!response || typeof response !== 'object') {
            showError(container, beepi.error_text, 'Invalid response from server');
            return;
        }

        if (response.success && response.data) {
            if (!validateResponseData(response.data)) {
                showError(container, 'Incomplete vehicle data received');
                return;
            }
            renderTeaserResults(response.data, container);
        } else {
            const message = response.data?.user_message || response.data?.message || beepi.error_text;
            showError(container, message);
        }
    }

    function validateResponseData(data) {
        return data.teaser && data.teaser.reg_number && 
               (data.teaser.brand || data.teaser.model);
    }

    function handleAjaxError(xhr, status, error, container) {
        let errorMessage = 'Could not complete the vehicle lookup';
        let details = '';

        if (xhr.responseJSON?.data?.user_message) {
            errorMessage = xhr.responseJSON.data.user_message;
            details = xhr.responseJSON.data.message || '';
        } else if (status === 'timeout') {
            errorMessage = 'Request timed out. Please try again.';
        } else if (status === 'parsererror') {
            errorMessage = 'Could not process the response from server.';
        }

        showError(container, errorMessage, details);
    }

    function highlightField($field) {
        $field.addClass('error')
              .one('input', function() {
                  $(this).removeClass('error');
              });
    }

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
        if (!dateString) return '-';
        
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;
            
            return new Intl.DateTimeFormat(beepi.locale || 'nb-NO', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).format(date);
        } catch (e) {
            return dateString;
        }
    }

})(jQuery);
