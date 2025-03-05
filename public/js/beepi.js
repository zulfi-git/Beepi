/**
 * Frontend JavaScript for Beepi plugin
 */
(function($) {
    'use strict';

    // Enhanced registration patterns with descriptions
    const REGISTRATION_PATTERNS = {
        NO_STANDARD: {
            pattern: /^[A-Z]{2}[0-9]{4,5}$/,
            description: 'Standard Norwegian format (e.g., AB12345)'
        },
        NO_ELECTRIC: {
            pattern: /^E[A-Z][0-9]{4,5}$/,
            description: 'Norwegian electric vehicle format (e.g., EK12345)'
        },
        NO_HISTORIC: {
            pattern: /^[A-Z]{1,2}[0-9]{4,5}$/,
            description: 'Historic Norwegian format (e.g., A12345)'
        },
        NO_DIPLOMATIC: {
            pattern: /^CD[0-9]{4}$/,
            description: 'Diplomatic vehicle format (e.g., CD1234)'
        }
    };

    // Error tracking
    const errorTracking = {
        errors: [],
        maxErrors: 50,
        
        add(error) {
            this.errors.unshift({
                timestamp: new Date(),
                error: error,
                url: window.location.href
            });
            
            // Keep only recent errors
            if (this.errors.length > this.maxErrors) {
                this.errors.pop();
            }
            
            // Send to analytics if available
            if (typeof gtag !== 'undefined') {
                gtag('event', 'api_error', {
                    error_type: error.type,
                    error_message: error.message
                });
            }
        },
        
        getRecent() {
            return this.errors;
        }
    };

    // Retry configuration
    const retryConfig = {
        maxRetries: 3,
        retryDelay: 1000,
        retryableErrors: ['timeout', 'rate_limited', 'network_error']
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
            const validationResults = validateRegistration(registration);
            if (!validationResults.isValid) {
                const suggestion = validationResults.suggestion ? ` Did you mean ${validationResults.suggestion.corrected} (${validationResults.suggestion.description})?` : '';
                showError(resultContainer, 'Invalid registration number format. Please check and try again.' + suggestion);
                highlightField($('#vehicle-registration'));
                return;
            }
            
            showLoading(resultContainer);
            
            performLookup(resultContainer);
        });
    });

    function validateRegistration(reg) {
        const results = {
            isValid: false,
            matchedFormat: null,
            suggestion: null
        };

        // Try all patterns
        for (const [key, format] of Object.entries(REGISTRATION_PATTERNS)) {
            if (format.pattern.test(reg)) {
                results.isValid = true;
                results.matchedFormat = key;
                break;
            }
        }

        // If invalid, try to suggest corrections
        if (!results.isValid) {
            results.suggestion = suggestCorrection(reg);
        }

        return results;
    }

    function suggestCorrection(reg) {
        // Remove common mistakes
        const cleaned = reg.replace(/[^A-Z0-9]/g, '');
        
        // Check if cleaned version matches any pattern
        for (const [key, format] of Object.entries(REGISTRATION_PATTERNS)) {
            if (format.pattern.test(cleaned)) {
                return {
                    corrected: cleaned,
                    format: key,
                    description: format.description
                };
            }
        }
        
        return null;
    }

    function showLoading(container) {
        container.html(`
            <div class="beepi-loading">
                <div class="loading-spinner"></div>
                <p>${beepi.loading_text}</p>
            </div>
        `);
    }

    function showError(container, message, details = '', isRetryable = false) {
        const errorId = 'error-' + Date.now();
        
        container.html(`
            <div class="beepi-error" id="${errorId}">
                <div class="error-icon">⚠️</div>
                <div class="error-content">
                    <p class="error-message">${message}</p>
                    ${details ? `<p class="error-details">${details}</p>` : ''}
                    <div class="error-actions">
                        ${isRetryable ? `
                            <button class="retry-button" data-error-id="${errorId}">
                                Try Again
                            </button>
                        ` : ''}
                        <button class="new-search-button">
                            New Search
                        </button>
                    </div>
                </div>
            </div>
        `);

        // Track error
        errorTracking.add({
            type: isRetryable ? 'retryable' : 'permanent',
            message: message,
            details: details
        });
    }

    async function handleLookupResponse(response, container, retryCount = 0) {
        if (!response || typeof response !== 'object') {
            return handleResponseError('invalid_response', container, retryCount);
        }

        if (response.success) {
            if (response.data.partial_success) {
                // Handle partial success
                renderPartialResults(response.data, container);
            } else if (validateResponseData(response.data)) {
                renderTeaserResults(response.data, container);
            } else {
                return handleResponseError('invalid_data', container, retryCount);
            }
        } else {
            const errorType = response.data?.error_type || 'unknown';
            return handleResponseError(errorType, container, retryCount);
        }
    }

    async function handleResponseError(errorType, container, retryCount) {
        const isRetryable = retryConfig.retryableErrors.includes(errorType);
        
        if (isRetryable && retryCount < retryConfig.maxRetries) {
            await new Promise(resolve => setTimeout(resolve, retryConfig.retryDelay));
            return performLookup(container, retryCount + 1);
        }

        const errorMessage = getErrorMessage(errorType);
        showError(container, errorMessage.message, errorMessage.details, isRetryable);
    }

    function renderPartialResults(data, container) {
        const html = renderTeaserResults(data, container);
        
        // Add warning about partial data
        container.append(`
            <div class="partial-data-warning">
                <p>⚠️ Some vehicle information is currently unavailable.</p>
                <ul class="missing-data-list">
                    ${data.missing_fields.map(field => `
                        <li>${formatFieldName(field)}</li>
                    `).join('')}
                </ul>
            </div>
        `);
    }

    function formatFieldName(field) {
        const fieldNames = {
            'engine': 'Engine details',
            'inspection': 'Inspection history',
            'registration': 'Registration details'
            // Add more field mappings as needed
        };
        
        return fieldNames[field] || field;
    }

    function getErrorMessage(errorType) {
        const errorMessages = {
            'invalid_response': {
                message: 'Could not process the server response',
                details: 'Please try again. If the problem persists, contact support.'
            },
            'timeout': {
                message: 'The request timed out',
                details: 'The server is taking too long to respond. Please try again.'
            },
            'rate_limited': {
                message: 'Too many requests',
                details: 'Please wait a moment before trying again.'
            },
            // Add more error types as needed
        };
        
        return errorMessages[errorType] || {
            message: 'An unexpected error occurred',
            details: 'Please try again later.'
        };
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
