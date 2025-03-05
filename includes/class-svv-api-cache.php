<?php
/**
 * Handles caching for API responses
 */
class SVV_API_Cache {
    // Cache group
    const CACHE_GROUP = 'svv_vehicle_lookup';
    
    // Cache durations
    const TOKEN_CACHE_TIME = 3500; // Just under 1 hour
    const VEHICLE_CACHE_TIME = 21600; // 6 hours
    const ERROR_CACHE_TIME = 300; // 5 minutes for errors (shorter time)
    
    // Cache control
    private static $cache_enabled = true;
    
    /**
     * Enable or disable caching
     * 
     * @param bool $enabled Whether caching should be enabled
     */
    public static function set_cache_enabled($enabled) {
        self::$cache_enabled = (bool) $enabled;
        error_log("🔧 Cache " . ($enabled ? "enabled" : "disabled"));
    }
    
    /**
     * Check if caching is enabled
     * 
     * @return bool Whether caching is enabled
     */
    public static function is_cache_enabled() {
        return self::$cache_enabled;
    }
    
    /**
     * Get cached item
     * 
     * @param string $key Cache key
     * @return mixed|false Cached value or false
     */
    public static function get($key) {
        if (!self::$cache_enabled) {
            error_log("🔧 Cache disabled - skipping get for key: $key");
            return false;
        }
        
        $full_key = self::get_full_key($key);
        return get_transient($full_key);
    }
    
    /**
     * Set cache item
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expiration Expiration time in seconds
     * @return bool Success
     */
    public static function set($key, $value, $expiration = null) {
        if (!self::$cache_enabled) {
            error_log("🔧 Cache disabled - skipping set for key: $key");
            return false;
        }
        
        $full_key = self::get_full_key($key);
        
        // If expiration not specified, determine based on content
        if ($expiration === null) {
            $expiration = self::determine_expiration($key, $value);
        }
        
        return set_transient($full_key, $value, $expiration);
    }
    
    /**
     * Delete cached item
     * 
     * @param string $key Cache key
     * @return bool Success
     */
    public static function delete($key) {
        $full_key = self::get_full_key($key);
        return delete_transient($full_key);
    }
    
    /**
     * Get full cache key
     * 
     * @param string $key Base key
     * @return string Full key
     */
    private static function get_full_key($key) {
        return self::CACHE_GROUP . '_' . $key;
    }
    
    /**
     * Determine appropriate cache expiration
     * 
     * @param string $key Cache key
     * @param mixed $value Cached value
     * @return int Expiration time in seconds
     */
    private static function determine_expiration($key, $value) {
        // For access tokens
        if (strpos($key, 'token') !== false) {
            return self::TOKEN_CACHE_TIME;
        }
        
        // For error responses
        if (is_array($value) && isset($value['success']) && $value['success'] === false) {
            return self::ERROR_CACHE_TIME;
        }
        
        // Default for vehicle data
        return self::VEHICLE_CACHE_TIME;
    }
    
    /**
     * Clear all plugin cache items
     * 
     * @return bool Success
     */
    public static function clear_all_cache() {
        global $wpdb;
        
        $prefix = '_transient_' . self::CACHE_GROUP . '_';
        $timeout_prefix = '_transient_timeout_' . self::CACHE_GROUP . '_';
        
        // Find all our transients
        $transients = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT REPLACE(option_name, '_transient_', '') 
                 FROM $wpdb->options 
                 WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );
        
        // Delete each transient
        $result = true;
        foreach ($transients as $transient) {
            $result = $result && delete_transient($transient);
        }
        
        return $result;
    }
    
    /**
     * Handle cache cleanup on plugin deactivation
     */
    public static function cleanup() {
        self::clear_all_cache();
    }
    
    /**
     * Register cleanup hooks
     */
    public static function register_hooks() {
        register_deactivation_hook(__FILE__, [__CLASS__, 'cleanup']);
        
        // Optional: Add a hook to clear cache on specific events
        add_action('svv_refresh_cache', [__CLASS__, 'clear_all_cache']);
    }
}
