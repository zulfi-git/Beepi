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
            return [
                'success' => false,
                'error' => 'no_data',
                'message' => 'No data returned from API',
                'user_message' => 'Could not find vehicle information. Please check the registration number.'
            ];
        }
        
        // Format successful response
        return [
            'success' => true,
            'data' => $api_response,
        ];
    }
    
    /**
     * Format error response
     * 
     * @param WP_Error $error WordPress error object
     * @return array Formatted error response
     */
    private static function format_error($error) {
        $error_code = $error->get_error_code();
        $error_message = $error->get_error_message();
        
        // Map error codes to user-friendly messages
        $user_message = self::get_user_message($error_code, $error_message);
        
        return [
            'success' => false,
            'error' => $error_code,
            'message' => $error_message,
            'user_message' => $user_message
        ];
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
            '422' => 'Query limit exceeded. Please try again later.'
        ];
        
        if (isset($error_messages[$error_code])) {
            return $error_messages[$error_code];
        }
        
        // Default error message
        return 'An error occurred while retrieving vehicle information. Please try again later.';
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
}
