<?php
/**
 * Handles API responses and errors
 */
class SVV_API_Response {
    /**
     * Format the API response for frontend display
     * 
     * @param array|WP_Error $api_response API response or error
     * @return array Formatted response with success/error flags
     */
    public static function format_response($api_response) {
        // Handle WP_Error objects
        if (is_wp_error($api_response)) {
            return self::format_error($api_response);
        }
        
        // Handle empty responses
        if (empty($api_response)) {
            return self::format_error(new WP_Error('no_data', 'No data returned from API'));
        }

        // Check rate limits and quotas
        self::check_rate_limits($api_response);
        
        // Check for API-specific error responses
        if (isset($api_response['feilmelding'])) {
            return self::handle_api_error_response($api_response);
        }

        // Validate and categorize response data
        $validation_result = self::validate_response_data($api_response);
        
        // Return appropriate response based on validation
        return [
            'success' => $validation_result['is_valid'],
            'data' => $api_response,
            'partial_success' => $validation_result['is_partial'],
            'missing_fields' => $validation_result['missing_fields'],
            'timestamp' => current_time('mysql'),
            'debug_info' => self::get_debug_info('success', [
                'validation' => $validation_result,
                'response_meta' => self::extract_response_metadata($api_response)
            ])
        ];
    }

    /**
     * Format error response
     * 
     * @param WP_Error $error WordPress error object
     * @return array Formatted error response
     */
    private static function format_error($error, $context = []) {
        $error_code = $error->get_error_code();
        $error_message = $error->get_error_message();
        
        // Map error codes to user-friendly messages
        $user_message = self::get_user_message($error_code, $error_message);
        
        $response = [
            'success' => false,
            'error' => $error_code,
            'message' => $error_message,
            'user_message' => $user_message,
            'timestamp' => current_time('mysql')
        ];
        
        // Add debug information in development
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $response['debug_info'] = self::get_debug_info($error_code, $context);
        }
        
        return $response;
    }
    
    /**
     * Get user-friendly error message
     * 
     * @param string $error_code Error code
     * @param string $error_message Raw error message
     * @return string User-friendly message
     */
    private static function get_user_message($error_code, $error_message) {
        $error_messages = [
            // Certificate/JWT errors
            'cert_not_found' => 'System configuration error. Please contact support.',
            'cert_load_failed' => 'System security error. Please contact support.',
            'jwt_signing_failed' => 'Authentication error. Please try again later.',
            
            // Token errors
            'token_error' => 'Authentication failed. Please try again later.',
            
            // Input validation errors
            'invalid_reg' => 'Invalid registration number format. Please check and try again.',
            
            // API errors
            'api_error' => 'Vehicle information service is currently unavailable. Please try again later.',
            'not_found' => 'No vehicle found with this registration number. Please check and try again.',
            
            // Masked SVV API error responses
            'OPPLYSNINGER_UTILGJENGELIG' => 'This vehicle information is not available for public access.',
            'FNR_ETTERNAVN_UKJENT' => 'Owner information could not be found.',
            'UGYLDIG_DTG' => 'Invalid date parameter.',
            
            // Rate limit
            '422' => 'Query limit exceeded. Please try again later.',
            
            // Unknown API error
            'unknown_api_error' => 'An unknown error occurred while retrieving vehicle information. Please try again later.',
            
            // Additional API-specific error codes
            'KJENNEMERKE_IKKE_FUNNET' => 'Registration number not found in the database.',
            'KJORETOY_IKKE_REG' => 'Vehicle is not currently registered.',
            'TEKNISK_FEIL' => 'Technical error in the vehicle registration system.',
            'UGYLDIG_KJENNEMERKE' => 'Invalid registration number format.',
            'MANGLENDE_TILGANG' => 'Access denied to vehicle information.',
            
            // System errors
            'token_expired' => 'Your session has expired. Please try again.',
            'invalid_data' => 'Received invalid data from the vehicle registry.',
            'connection_error' => 'Could not connect to the vehicle registry system.',
            
            // Quota/rate limit errors
            'quota_exceeded' => 'Daily lookup quota exceeded. Please try again tomorrow.',
            'rate_limited' => 'Too many requests. Please wait a few minutes and try again.',
            
            // Default unknown error
            'unknown_api_error' => 'An unexpected error occurred. Our team has been notified.'
        ];
        
        if (isset($error_messages[$error_code])) {
            return $error_messages[$error_code];
        }
        
        // Default error message
        return 'An error occurred while retrieving vehicle information. Please try again later.';
    }
    
    /**
     * Get debug information for logging
     */
    private static function get_debug_info($error_code, $context = []) {
        return [
            'error_code' => $error_code,
            'timestamp' => current_time('mysql'),
            'environment' => defined('SVV_API_ENVIRONMENT') ? SVV_API_ENVIRONMENT : 'prod',
            'context' => $context,
            'request_id' => uniqid('svv_', true)
        ];
    }
    
    /**
     * Validate vehicle data structure
     */
    private static function validate_vehicle_data($data) {
        $required_fields = ['kjoretoyId', 'godkjenning'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Log API error for debugging
     * 
     * @param WP_Error|string $error Error to log
     * @param array $context Additional context
     */
    public static function log_error($error, $context = []) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        $error_message = is_wp_error($error) ? 
            $error->get_error_code() . ': ' . $error->get_error_message() : 
            $error;
        
        $log_message = '[' . date('Y-m-d H:i:s') . '] SVV API Error: ' . $error_message;
        
        if (!empty($context)) {
            $log_message .= ' | Context: ' . json_encode($context);
        }
        
        error_log($log_message);
    }

    /**
     * Validate and categorize response data
     */
    private static function validate_response_data($data) {
        $required_fields = [
            'kjoretoyId' => ['kjennemerke'],
            'godkjenning' => ['tekniskGodkjenning'],
            'forstegangsregistrering' => ['registrertForstegangNorgeDato']
        ];

        $optional_fields = [
            'periodiskKjoretoyKontroll' => ['sistGodkjent', 'kontrollfrist'],
            'registrering' => ['registrertEier', 'historikk']
        ];

        $missing_required = [];
        $missing_optional = [];
        
        // Check required fields
        foreach ($required_fields as $field => $subfields) {
            if (!isset($data[$field])) {
                $missing_required[] = $field;
                continue;
            }
            foreach ($subfields as $subfield) {
                if (!isset($data[$field][$subfield])) {
                    $missing_required[] = "$field.$subfield";
                }
            }
        }

        // Check optional fields
        foreach ($optional_fields as $field => $subfields) {
            if (!isset($data[$field])) {
                $missing_optional[] = $field;
                continue;
            }
            foreach ($subfields as $subfield) {
                if (!isset($data[$field][$subfield])) {
                    $missing_optional[] = "$field.$subfield";
                }
            }
        }

        return [
            'is_valid' => empty($missing_required),
            'is_partial' => !empty($missing_optional) && empty($missing_required),
            'missing_fields' => [
                'required' => $missing_required,
                'optional' => $missing_optional
            ]
        ];
    }

    /**
     * Handle API-specific error responses
     */
    private static function handle_api_error_response($response) {
        $error_code = isset($response['feilkode']) ? $response['feilkode'] : 'unknown_api_error';
        $error_message = isset($response['feilmelding']) ? $response['feilmelding'] : 'Unknown API error';
        
        // Map API-specific error codes
        $mapped_code = self::map_api_error_code($error_code);
        
        return self::format_error(
            new WP_Error($mapped_code, $error_message),
            ['raw_response' => $response]
        );
    }

    /**
     * Map API error codes to internal codes
     */
    private static function map_api_error_code($code) {
        $error_map = [
            'OPPLYSNINGER_UTILGJENGELIG' => 'data_unavailable',
            'FNR_ETTERNAVN_UKJENT' => 'owner_unknown',
            'KJENNEMERKE_IKKE_FUNNET' => 'reg_not_found',
            'KJORETOY_IKKE_REG' => 'vehicle_not_registered',
            'TEKNISK_FEIL' => 'technical_error',
            'UGYLDIG_KJENNEMERKE' => 'invalid_registration',
            'MANGLENDE_TILGANG' => 'access_denied'
        ];

        return isset($error_map[$code]) ? $error_map[$code] : 'api_error';
    }

    /**
     * Check and log rate limits
     */
    private static function check_rate_limits($response) {
        if (isset($response['gjenstaendeKvote'])) {
            $quota_info = [
                'remaining' => $response['gjenstaendeKvote'],
                'total' => isset($response['kvote']) ? $response['kvote'] : null,
                'timestamp' => current_time('mysql')
            ];

            // Store quota information
            update_option('svv_api_rate_limits', $quota_info);

            // Check if we're running low
            if ($quota_info['total'] && ($quota_info['remaining'] / $quota_info['total']) < 0.1) {
                do_action('svv_api_quota_low', $quota_info);
            }
        }
    }

    /**
     * Get enhanced debug information
     */
    private static function get_debug_info($type, $context = []) {
        $debug_info = [
            'type' => $type,
            'timestamp' => current_time('mysql'),
            'request_id' => uniqid('svv_', true),
            'environment' => defined('SVV_API_ENVIRONMENT') ? SVV_API_ENVIRONMENT : 'prod',
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'quota_info' => get_option('svv_api_rate_limits'),
            'context' => $context
        ];

        if (function_exists('wp_get_environment_type')) {
            $debug_info['wp_environment'] = wp_get_environment_type();
        }

        return $debug_info;
    }

    /**
     * Extract metadata from response
     */
    private static function extract_response_metadata($response) {
        return array_intersect_key($response, array_flip([
            'gjenstaendeKvote',
            'kvote',
            'timestamp',
            'version'
        ]));
    }
}
