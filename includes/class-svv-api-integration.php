<?php
/**
 * Handles integration with Statens Vegvesen API
 */
class SVV_API_Integration {
    private $integration_id;
    private $client_id;
    private $org_no;
    private $certificate_path;
    private $certificate_password;
    private $scope;
    private $maskinporten_token_url;
    private $svv_api_base_url;
    private $token_cache_key = 'svv_access_token';
    private $token_cache_expiry = 3500; // Slightly less than 1 hour to ensure we don't use expired tokens
    private $debug_mode;
    private $cache_enabled;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load configuration
        $this->integration_id = '2d5adb28-0e61-46aa-9fc0-8772b5206c7c';
        $this->client_id = $this->integration_id; // In Maskinporten, these are the same
        $this->org_no = '998453240';
        $this->scope = 'svv:kjoretoy/kjoretoyopplysninger';
        
        // Get certificate path from wp-config
        $this->certificate_path = SVV_CERT_PATH;
        
        // Get certificate password from wp-config
        $this->certificate_password = defined('SVV_CERT_PASSWORD') ? SVV_CERT_PASSWORD : '';

        // Environment URLs - get from wp-config if defined
        $environment = defined('SVV_API_ENVIRONMENT') ? SVV_API_ENVIRONMENT : 'prod';
        if ($environment === 'test') {
            $this->maskinporten_token_url = 'https://test.maskinporten.no/token';
            $this->svv_api_base_url = 'https://akfell-datautlevering-sisdinky.utv.atlas.vegvesen.no';
        } else {
            $this->maskinporten_token_url = 'https://maskinporten.no/token';
            $this->svv_api_base_url = 'https://akfell-datautlevering.atlas.vegvesen.no';
        }
        
        // Get debug mode from wp-config
        $this->debug_mode = defined('SVV_API_DEBUG') ? SVV_API_DEBUG : true;
        
        // Get cache setting from wp-config
        $this->cache_enabled = defined('SVV_API_CACHE_ENABLED') ? SVV_API_CACHE_ENABLED : true;
        SVV_API_Cache::set_cache_enabled($this->cache_enabled);
        
        error_log("🔧 SVV API Integration initialized - Environment: $environment");
        error_log("🔧 SVV API Base URL: {$this->svv_api_base_url}");
        error_log("🔧 Certificate Path: {$this->certificate_path}");
        error_log("🔧 Cache " . ($this->cache_enabled ? "enabled" : "disabled"));
    }

    /**
     * Enable or disable caching
     * 
     * @param bool $enabled Whether caching should be enabled
     */
    public function set_cache_enabled($enabled) {
        $this->cache_enabled = (bool) $enabled;
        SVV_API_Cache::set_cache_enabled($this->cache_enabled);
        error_log("🔧 Cache " . ($enabled ? "enabled" : "disabled"));
    }

    /**
     * Get access token from Maskinporten
     * 
     * @param bool $force_new Force generation of new token, bypassing cache
     * @return string|WP_Error Access token or error
     */
    public function get_access_token($force_new = false) {
        try {
            // Check if we have a cached token and not forcing new
            if (!$force_new) {
                $cached_token = SVV_API_Cache::get($this->token_cache_key);
                if ($cached_token !== false) {
                    error_log("🔑 Using valid cached Maskinporten token");
                    return $cached_token;
                }
            }
            
            error_log("🔑 Requesting new Maskinporten token");
            
            // Create JWT grant with enhanced error handling
            $jwt = $this->create_jwt_grant();
            if (is_wp_error($jwt)) {
                throw new Exception('JWT creation failed: ' . $jwt->get_error_message());
            }

            $response = wp_remote_post($this->maskinporten_token_url, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt
                ],
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                throw new Exception('Could not connect to Maskinporten: ' . $response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON response from Maskinporten: ' . json_last_error_msg());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            error_log("🔄 Maskinporten response status: $status_code");

            // Handle error responses from Maskinporten
            if ($status_code !== 200) {
                error_log("🔍 Maskinporten error response: " . wp_remote_retrieve_body($response));
                throw new Exception("Maskinporten error (HTTP $status_code): " . 
                    ($body['error_description'] ?? $body['error'] ?? 'Unknown error'));
            }

            // Check for token in response
            if (!isset($body['access_token'])) {
                throw new Exception('No access token in Maskinporten response');
            }

            // Get token - Do not validate the token from Maskinporten
            // Maskinporten already validates it, and trying to validate ourselves 
            // causes issues with certain claims
            $token = $body['access_token'];
            
            // Cache the token using the expires_in value from Maskinporten response
            $expires_in = isset($body['expires_in']) ? (int)$body['expires_in'] - 60 : $this->token_cache_expiry;
            SVV_API_Cache::set($this->token_cache_key, $token, $expires_in);
            
            error_log("✅ Successfully obtained Maskinporten token");
            return $token;

        } catch (Exception $e) {
            $error_message = "🚨 Token generation failed: " . $e->getMessage();
            error_log($error_message);
            
            // Clear token cache on error
            SVV_API_Cache::delete($this->token_cache_key);
            
            if ($this->debug_mode) {
                error_log("🔍 Debug stack trace: " . $e->getTraceAsString());
            }
            
            return new WP_Error(
                'token_generation_error',
                $error_message,
                [
                    'status' => 'error',
                    'debug_info' => $this->debug_mode ? [
                        'exception' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ] : null
                ]
            );
        }
    }

    /**
     * Create JWT grant for Maskinporten
     * 
     * @return string|WP_Error JWT string or error
     */
    private function create_jwt_grant() {
        try {
            // Load and verify certificate
            if (!file_exists($this->certificate_path)) {
                throw new Exception("Certificate file not found at: {$this->certificate_path}");
            }

            $private_key = file_get_contents($this->certificate_path);
            if (!$private_key) {
                throw new Exception('Could not read private key from PEM file');
            }

            // Get certificate data
            $cert_data = $this->extract_cert_data();
            if (is_wp_error($cert_data)) {
                throw new Exception($cert_data->get_error_message());
            }

            // Create JWT header with x5c claim
            $header = [
                'alg' => 'RS256',
                'x5c' => [$cert_data]
            ];

            // Add key ID if defined
            if (defined('SVV_API_KID') && SVV_API_KID) {
                $header['kid'] = SVV_API_KID;
            }

            // Set environment-specific values
            $is_test = defined('SVV_API_ENVIRONMENT') && SVV_API_ENVIRONMENT === 'test';
            $aud = $is_test ? 'https://test.maskinporten.no/' : 'https://maskinporten.no/';
            $resource = $is_test ? 'https://www.utv.vegvesen.no' : 'https://www.vegvesen.no';

            // Create JWT payload - Keep only the essential claims
            $now = time();
            $payload = [
                'aud' => $aud,
                'scope' => $this->scope,
                'iss' => $this->client_id,
                'exp' => $now + 120,
                'iat' => $now,
                'jti' => $this->generate_uuid(),
                'resource' => [$resource],
                'client_amr' => 'virksomhetssertifikat'
            ];

            // Only add client_org_no if needed (typically not required)
            if (defined('INCLUDE_ORG_NO') && INCLUDE_ORG_NO) {
                $payload['client_org_no'] = $this->org_no;
            }

            // Encode the JWT
            $encoded_header = $this->base64url_encode(json_encode($header));
            $encoded_payload = $this->base64url_encode(json_encode($payload));
            $signing_input = $encoded_header . '.' . $encoded_payload;

            // Sign JWT with private key
            $signature = '';
            $key_resource = openssl_pkey_get_private($private_key, $this->certificate_password);
            
            if (!$key_resource) {
                throw new Exception('Could not load private key. Check the password.');
            }
            
            $is_signed = openssl_sign($signing_input, $signature, $key_resource, OPENSSL_ALGO_SHA256);
            if (!$is_signed) {
                throw new Exception('Failed to sign JWT');
            }

            // Complete the JWT
            $encoded_signature = $this->base64url_encode($signature);
            $jwt = $encoded_header . '.' . $encoded_payload . '.' . $encoded_signature;

            // Log JWT parts for debugging if debug mode is enabled
            if ($this->debug_mode) {
                error_log("🛠 Debug JWT Header: " . json_encode($header));
                error_log("🛠 Debug JWT Payload: " . json_encode($payload));
            }

            return $jwt;

        } catch (Exception $e) {
            error_log("❌ JWT grant creation failed: " . $e->getMessage());
            return new WP_Error('jwt_creation_failed', $e->getMessage());
        }
    }

    /**
     * Extract certificate data from PEM file for use in x5c header
     * 
     * @return string|WP_Error Base64 encoded certificate data or error
     */
    private function extract_cert_data() {
        error_log("🔍 Looking for certificate data");
        
        // Define possible certificate locations
        $cert_file_paths = [
            dirname($this->certificate_path) . '/certificate.pem',
            $this->certificate_path,
            str_replace('private.pem', 'certificate.pem', $this->certificate_path),
            dirname($this->certificate_path) . '/public.pem',
            dirname($this->certificate_path) . '/cert.pem',
        ];
        
        // Try each location
        foreach ($cert_file_paths as $path) {
            if (!file_exists($path)) {
                continue;
            }
            
            error_log("✅ Found file at: $path");
            $cert_content = file_get_contents($path);
            
            if (empty($cert_content)) {
                continue;
            }
            
            // Try to extract certificate data between BEGIN/END markers
            if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $cert_content, $matches)) {
                $cert_data = str_replace(["\r", "\n", " "], '', $matches[1]);
                error_log("✅ Successfully extracted certificate data from: $path");
                return $cert_data;
            }
        }
        
        // If we get here, certificate data wasn't found
        error_log("❌ No valid certificate data found in any of the expected locations");
        return new WP_Error('cert_not_found', 'No valid certificate data found');
    }

    /**
     * Get vehicle data by registration number
     * 
     * @param string $registration_number Vehicle registration number
     * @return array|WP_Error Vehicle data or error
     */
    public function get_vehicle_by_registration($registration_number) {
        // Sanitize input
        $registration_number = strtoupper(trim($registration_number));
        
        error_log("🔍 Searching for vehicle with registration number: $registration_number");
        
        // Validate format (basic validation)
        if (!preg_match('/^[A-Z0-9]{1,8}$/', $registration_number)) {
            error_log("❌ Invalid registration number format: $registration_number");
            return new WP_Error('invalid_reg', 'Invalid registration number format');
        }
        
        // Check cache first
        $cache_key = 'vehicle_data_' . $registration_number;
        $cached_data = SVV_API_Cache::get($cache_key);
        if ($cached_data !== false) {
            error_log("🔄 Using cached data for: $registration_number");
            return $cached_data;
        }
        
        // Get access token
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            error_log("❌ Failed to get access token for vehicle lookup");
            return $token;
        }
        
        // Log the complete token in debug mode
        if ($this->debug_mode) {
            error_log("🔑 Full token being used: " . $token);
        } else {
            error_log("🔑 Token being used (first 20 chars): " . substr($token, 0, 20));
        }
        
        // Call SVV API - try with array of objects format first
        $endpoint = $this->svv_api_base_url . '/kjoretoyoppslag/bulk/kjennemerke';
        $request_body_1 = [['kjennemerke' => $registration_number]];
        
        error_log("🔄 Calling SVV API endpoint: $endpoint");
        error_log("🔄 Request body (format 1): " . json_encode($request_body_1));
        
        // Retry logic
        $max_retries = 3;
        $retry_count = 0;
        $response = null;

        while ($retry_count < $max_retries) {
            $response = wp_remote_post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
                'body' => json_encode($request_body_1),
                'timeout' => 15,
                'sslverify' => true,
            ]);

            if (!is_wp_error($response)) {
                break;
            }

            $retry_count++;
            error_log("🔄 Retry $retry_count/$max_retries for SVV API request");
        }

        if (is_wp_error($response)) {
            error_log("❌ SVV API error after $max_retries retries: " . $response->get_error_message());
            return $response;
        }
        
        // If we get 401, try alternate request format
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401) {
            $response_body = wp_remote_retrieve_body($response);
            error_log("🔄 First request format failed with 401 - Response: " . $response_body);
            
            // Add enhanced logging
            error_log("🔒 401 Unauthorized Details:");
            error_log("🔍 Response Headers: " . json_encode(wp_remote_retrieve_headers($response)));
            error_log("🔍 Full Response Body: " . $response_body);
            
            // Log token details
            $decoded_token = $this->decode_jwt_token($token);
            error_log("🔍 Token Payload Details: " . json_encode($decoded_token));
            
            // Try with simple object format
            $request_body_2 = ['kjennemerke' => $registration_number];
            error_log("🔄 Trying alternate request format");
            error_log("🔄 Request body (format 2): " . json_encode($request_body_2));
            
            $response = wp_remote_post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
                'body' => json_encode($request_body_2),
                'timeout' => 15,
                'sslverify' => true,
            ]);
        }
        
        // If still getting 401, try a direct endpoint
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401) {
            $response_body = wp_remote_retrieve_body($response);
            error_log("🔄 Second request format failed with 401 - Response: " . $response_body);
            
            // Add enhanced logging
            error_log("🔒 401 Unauthorized Details:");
            error_log("🔍 Response Headers: " . json_encode(wp_remote_retrieve_headers($response)));
            error_log("🔍 Full Response Body: " . $response_body);
            
            // Log token details
            $decoded_token = $this->decode_jwt_token($token);
            error_log("🔍 Token Payload Details: " . json_encode($decoded_token));
            
            // Clear token cache and get a new token
            SVV_API_Cache::delete($this->token_cache_key);
            error_log("🔄 Clearing token cache and getting new token");
            
            $token = $this->get_access_token();
            if (is_wp_error($token)) {
                return $token;
            }
            
            error_log("🔑 New token obtained (first 20 chars): " . substr($token, 0, 20));
            
            // Try with GET endpoint if available
            error_log("🔄 Trying direct endpoint with new token");
            $direct_endpoint = $this->svv_api_base_url . '/kjoretoyoppslag/bulk/kjennemerke' . urlencode($registration_number);
            error_log("🔄 Direct endpoint URL: " . $direct_endpoint);
            
            $response = wp_remote_get($direct_endpoint, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
                'timeout' => 15,
                'sslverify' => true,
            ]);
        }
        
        if (is_wp_error($response)) {
            error_log("❌ SVV API error: " . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log("🔄 Final API response status: $status_code");
        error_log("🔄 Response body (first 500 chars): " . substr($response_body, 0, 500));
        
        if ($status_code !== 200) {
            error_log("❌ API error: HTTP Status $status_code");
            return new WP_Error('api_error', "API error: HTTP Status $status_code");
        }
        
        // Try to decode JSON response
        $body = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("❌ JSON decode error: " . json_last_error_msg());
            return new WP_Error('json_decode_error', 'Failed to decode API response');
        }
        
        // Handle API error responses
        if (isset($body['gjenstaendeKvote'])) {
            // This is the response format for errors
            $error_message = isset($body['feilmelding']) ? $body['feilmelding'] : 'Unknown API error';
            error_log("❌ API error response: $error_message");
            return new WP_Error('api_error', $error_message);
        }
        
        // Handle empty response
        if (empty($body)) {
            error_log("❌ Empty response from API");
            return new WP_Error('empty_response', 'No data returned from API');
        }
        
        // Check for array response with error
        if (is_array($body) && !empty($body[0]) && isset($body[0]['feilmelding'])) {
            $error_message = $body[0]['feilmelding'];
            error_log("❌ Vehicle not found: $error_message");
            return new WP_Error('not_found', $error_message);
        }
        
        // Check for valid vehicle data
        $kjoretoydata = null;
        
        // Determine the structure of the response
        if (is_array($body) && !empty($body[0]) && isset($body[0]['kjoretoydata'])) {
            // Format 1: Array of results with kjoretoydata
            $kjoretoydata = $body[0]['kjoretoydata'];
        } elseif (isset($body['kjoretoydata'])) {
            // Format 2: Direct object with kjoretoydata
            $kjoretoydata = $body['kjoretoydata'];
        } elseif (is_array($body)) {
            // Format 3: Might be direct data
            $kjoretoydata = $body;
        }
        
        if (empty($kjoretoydata)) {
            error_log("❌ No vehicle data in response");
            return new WP_Error('no_vehicle_data', 'No vehicle data found in response');
        }
        
        // Process and prepare data for display
        error_log("✅ Vehicle data received for: $registration_number");
        $vehicle_data = $this->prepare_vehicle_data($kjoretoydata);
        
        // Cache for 6 hours
        SVV_API_Cache::set($cache_key, $vehicle_data, 6 * HOUR_IN_SECONDS);
        
        return $vehicle_data;
    }

    /**
     * Decode JWT token for debugging
     * 
     * @param string $token JWT token
     * @return array Decoded token payload or error
     */
    private function decode_jwt_token($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['error' => 'Invalid JWT format'];
        }
        
        try {
            // Only decode the payload part (index 1)
            $base64_payload = $parts[1];
            $base64_url_decoded = strtr($base64_payload, '-_', '+/');
            $padded = str_pad($base64_url_decoded, strlen($base64_url_decoded) % 4, '=', STR_PAD_RIGHT);
            $decoded = base64_decode($padded);
            
            if ($decoded === false) {
                return ['error' => 'Invalid base64 encoding'];
            }
            
            $payload = json_decode($decoded, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['error' => 'Invalid JSON in payload: ' . json_last_error_msg()];
            }
            
            return $payload;
        } catch (Exception $e) {
            return ['error' => 'Could not decode token: ' . $e->getMessage()];
        }
    }

    /**
     * Base64-url encoding function
     * 
     * @param string $data Data to encode
     * @return string Base64-url encoded string
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Generate UUID v4
     * 
     * @return string UUID
     */
    private function generate_uuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),   // First two random 16-bit segments
            mt_rand(0, 0xffff),                       // Third random 16-bit segment
            mt_rand(0, 0x0fff) | 0x4000,              // Fourth segment with version 4 bit set
            mt_rand(0, 0x3fff) | 0x8000,              // Fifth segment with variant bit set
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)  // Last three random segments
        );
    }
    
    /**
     * Test Maskinporten token generation without making API calls
     * 
     * @return array Test results with status and messages
     */
    public function test_token_generation() {
        $results = [
            'success' => false,
            'messages' => [],
        ];
        
        // Step 1: Check certificate
        if (!file_exists($this->certificate_path)) {
            $results['messages'][] = [
                'type' => 'error',
                'message' => 'Certificate file not found at: ' . $this->certificate_path
            ];
            return $results;
        }
        
        $results['messages'][] = [
            'type' => 'success',
            'message' => 'Certificate file found'
        ];
        
        // Step 2: Try to load private key
        $private_key = file_get_contents($this->certificate_path);
        if (!$private_key) {
            $results['messages'][] = [
                'type' => 'error',
                'message' => 'Could not read private key from certificate file'
            ];
            return $results;
        }
        
        $results['messages'][] = [
            'type' => 'success',
            'message' => 'Private key loaded from certificate file'
        ];
        
        // Step 3: Try to create JWT
        $jwt = $this->create_jwt_grant();
        if (is_wp_error($jwt)) {
            $results['messages'][] = [
                'type' => 'error',
                'message' => 'JWT creation failed: ' . $jwt->get_error_message()
            ];
            return $results;
        }
        
        $results['messages'][] = [
            'type' => 'success',
            'message' => 'JWT grant created successfully'
        ];
        
        // Step 4: Try to get token
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            $results['messages'][] = [
                'type' => 'error',
                'message' => 'Token request failed: ' . $token->get_error_message()
            ];
            return $results;
        }
        
        $results['success'] = true;
        $results['messages'][] = [
            'type' => 'success',
            'message' => 'Successfully obtained access token from Maskinporten'
        ];
        
        return $results;
    }
    
    /**
     * Prepare vehicle data for display
     * 
     * @param array $raw_data Raw data from API
     * @return array Processed data
     */
    private function prepare_vehicle_data($raw_data) {
        // Log the raw data structure for debugging if debug mode is enabled
        if ($this->debug_mode) {
            error_log("🔍 Raw data structure: " . json_encode(array_keys($raw_data)));
        }
        
        // Initialize with default values
        $data = [
            'teaser' => [
                'reg_number' => '',
                'brand' => '',
                'model' => '',
                'first_registration' => '',
                'vehicle_type' => '',
                'engine' => [],
                'last_inspection' => [],
            ],
            'protected' => [
                'owner' => [],
                'registration_history' => [],
                'status' => []
            ]
        ];
        
        // Extract data if available
        if (isset($raw_data['kjoretoyId']['kjennemerke'])) {
            $data['teaser']['reg_number'] = $raw_data['kjoretoyId']['kjennemerke'];
        }
        
        if (isset($raw_data['godkjenning']['tekniskGodkjenning']['fabrikat'])) {
            $data['teaser']['brand'] = $raw_data['godkjenning']['tekniskGodkjenning']['fabrikat'];
        }
        
        if (isset($raw_data['godkjenning']['tekniskGodkjenning']['handelsbetegnelse'])) {
            $data['teaser']['model'] = $raw_data['godkjenning']['tekniskGodkjenning']['handelsbetegnelse'];
        }
        
        if (isset($raw_data['forstegangsregistrering']['registrertForstegangNorgeDato'])) {
            $data['teaser']['first_registration'] = $raw_data['forstegangsregistrering']['registrertForstegangNorgeDato'];
        }
        
        if (isset($raw_data['godkjenning']['tekniskGodkjenning']['kjoretoyklasse']['kodeNavn'])) {
            $data['teaser']['vehicle_type'] = $raw_data['godkjenning']['tekniskGodkjenning']['kjoretoyklasse']['kodeNavn'];
        }
        
        if (isset($raw_data['godkjenning']['tekniskGodkjenning']['motor'])) {
            $data['teaser']['engine'] = $this->process_engine_data($raw_data['godkjenning']['tekniskGodkjenning']['motor']);
        }
        
        if (isset($raw_data['periodiskKjoretoyKontroll'])) {
            $data['teaser']['last_inspection'] = $this->process_inspection_data($raw_data['periodiskKjoretoyKontroll']);
        }
        
        if (isset($raw_data['registrering']['registrertEier'])) {
            $data['protected']['owner'] = $this->process_owner_data($raw_data['registrering']['registrertEier']);
        }
        
        if (isset($raw_data['registrering']['historikk'])) {
            $data['protected']['registration_history'] = $raw_data['registrering']['historikk'];
        }
        
        if (isset($raw_data['registrering']['registreringsstatus'])) {
            $data['protected']['status'] = $raw_data['registreringsstatus'];
        }
        
        // Include raw data for debugging if needed
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $data['raw_data'] = $raw_data;
        }
        
        return $data;
    }
    
    /**
     * Process engine data
     */
    private function process_engine_data($engine_data) {
        return [
            'fuel_type' => isset($engine_data['drivstoff']['kodeNavn']) ? 
                $engine_data['drivstoff']['kodeNavn'] : '',
            'displacement' => isset($engine_data['slagvolum']) ? $engine_data['slagvolum'] : '',
            'power' => isset($engine_data['motorytelse']) ? $engine_data['motorytelse'] : '',
        ];
    }
    
    /**
     * Process inspection data
     */
    private function process_inspection_data($inspection_data) {
        return [
            'last_date' => isset($inspection_data['sistGodkjent']) ? 
                $inspection_data['sistGodkjent'] : '',
            'next_date' => isset($inspection_data['kontrollfrist']) ? 
                $inspection_data['kontrollfrist'] : '',
        ];
    }
    
    /**
     * Process owner data
     */
    private function process_owner_data($owner_data) {
        $is_person = isset($owner_data['person']);
        $is_organization = isset($owner_data['eier']);
        
        if ($is_person) {
            return [
                'type' => 'person',
                'name' => isset($owner_data['person']['navn']) ? 
                    $owner_data['person']['navn'] : '',
                'address' => isset($owner_data['person']['adresse']) ? 
                    $this->format_address($owner_data['person']['adresse']) : '',
            ];
        } else if ($is_organization) {
            return [
                'type' => 'organization',
                'name' => isset($owner_data['eier']['navn']) ? 
                    $owner_data['eier']['navn'] : '',
                'org_number' => isset($owner_data['eier']['organisasjonsnummer']) ? 
                    $owner_data['eier']['organisasjonsnummer'] : '',
                'address' => isset($owner_data['eier']['adresse']) ? 
                    $this->format_address($owner_data['eier']['adresse']) : '',
            ];
        }
        
        return ['type' => 'unknown'];
    }
    
    /**
     * Format address data
     */
    private function format_address($address_data) {
        $address = [];
        
        if (isset($address_data['adresselinje1']) && !empty($address_data['adresselinje1'])) {
            $address[] = $address_data['adresselinje1'];
        }
        
        if (isset($address_data['postnummer']) && isset($address_data['poststed'])) {
            $address[] = $address_data['postnummer'] . ' ' . $address_data['poststed'];
        }
        
        return implode(', ', $address);
    }

    /**
     * Run comprehensive diagnostics on the API integration
     * 
     * @return array Diagnostic results
     */
    public function run_full_diagnostics() {
        error_log("🕵️ Starting Full Diagnostic Scan");
        
        $authentication_debug = $this->comprehensive_authentication_debug();
        $endpoint_diagnosis = $this->diagnose_api_endpoint();
        
        $diagnostic_report = [
            'authentication_debug' => $authentication_debug,
            'endpoint_diagnosis' => $endpoint_diagnosis,
            'timestamp' => current_time('mysql'),
            'environment' => defined('SVV_API_ENVIRONMENT') ? SVV_API_ENVIRONMENT : 'prod'
        ];
        
        error_log("🔍 Full Diagnostic Report:");
        error_log(print_r($diagnostic_report, true));
        
        return $diagnostic_report;
    }

    /**
     * Run comprehensive authentication debugging
     * 
     * @return array Debug results
     */
    private function comprehensive_authentication_debug() {
        error_log("🔐 Starting Authentication Diagnostics");
        
        $results = [
            'certificate' => [
                'status' => 'unknown',
                'details' => []
            ],
            'jwt' => [
                'status' => 'unknown',
                'details' => []
            ],
            'token' => [
                'status' => 'unknown',
                'details' => []
            ]
        ];

        // Check certificate
        try {
            if (!file_exists($this->certificate_path)) {
                throw new Exception("Certificate file not found");
            }
            
            $cert_info = openssl_x509_parse(file_get_contents($this->certificate_path));
            if ($cert_info) {
                $results['certificate'] = [
                    'status' => 'ok',
                    'details' => [
                        'valid_from' => date('Y-m-d H:i:s', $cert_info['validFrom_time_t']),
                        'valid_to' => date('Y-m-d H:i:s', $cert_info['validTo_time_t']),
                        'issuer' => $cert_info['issuer']['CN'],
                        'is_expired' => time() > $cert_info['validTo_time_t']
                    ]
                ];
            }
        } catch (Exception $e) {
            $results['certificate'] = [
                'status' => 'error',
                'details' => ['error' => $e->getMessage()]
            ];
        }

        // Test JWT creation
        try {
            $jwt = $this->create_jwt_grant();
            if (!is_wp_error($jwt)) {
                $results['jwt'] = [
                    'status' => 'ok',
                    'details' => [
                        'length' => strlen($jwt),
                        'parts' => count(explode('.', $jwt))
                    ]
                ];
            } else {
                throw new Exception($jwt->get_error_message());
            }
        } catch (Exception $e) {
            $results['jwt'] = [
                'status' => 'error',
                'details' => ['error' => $e->getMessage()]
            ];
        }

        // Test token acquisition
        try {
            $token = $this->get_access_token();
            if (!is_wp_error($token)) {
                $token_details = $this->decode_jwt_token($token);
                $results['token'] = [
                    'status' => 'ok',
                    'details' => [
                        'expires' => isset($token_details['exp']) ? 
                            date('Y-m-d H:i:s', $token_details['exp']) : 'unknown',
                        'scope' => $token_details['scope'] ?? 'unknown',
                        'cached' => SVV_API_Cache::get($this->token_cache_key) !== false
                    ]
                ];
            } else {
                throw new Exception($token->get_error_message());
            }
        } catch (Exception $e) {
            $results['token'] = [
                'status' => 'error',
                'details' => ['error' => $e->getMessage()]
            ];
        }

        return $results;
    }

    /**
     * Diagnose API endpoint connectivity and behavior
     * 
     * @return array Diagnostic results
     */
    private function diagnose_api_endpoint() {
        error_log("🔌 Starting API Endpoint Diagnostics");
        
        $results = [
            'connectivity' => [
                'status' => 'unknown',
                'details' => []
            ],
            'response_time' => null,
            'ssl_info' => [],
            'headers' => []
        ];

        try {
            $start_time = microtime(true);
            
            // Test with a known registration number
            $test_reg = 'TEST123';
            $endpoint = $this->svv_api_base_url . '/kjoretoyoppslag/bulk/kjennemerke';
            
            $token = $this->get_access_token();
            if (is_wp_error($token)) {
                throw new Exception($token->get_error_message());
            }

            $response = wp_remote_post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
                'body' => json_encode([['kjennemerke' => $test_reg]]),
                'timeout' => 15,
                'sslverify' => true
            ]);

            $end_time = microtime(true);
            
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            // Analyze response
            $status_code = wp_remote_retrieve_response_code($response);
            $headers = wp_remote_retrieve_headers($response);
            
            $results['connectivity'] = [
                'status' => 'ok',
                'details' => [
                    'status_code' => $status_code,
                    'endpoint' => $endpoint,
                    'curl_info' => $response['http_response']->get_info() ?? []
                ]
            ];
            
            $results['response_time'] = round(($end_time - $start_time) * 1000, 2); // in ms
            $results['headers'] = $headers;
            
            // Get SSL information
            $ssl_info = openssl_x509_parse(
                openssl_x509_read(
                    file_get_contents(ABSPATH . WPINC . '/certificates/ca-bundle.crt')
                )
            );
            
            $results['ssl_info'] = [
                'certificate_exists' => !empty($ssl_info),
                'valid_until' => $ssl_info ? date('Y-m-d H:i:s', $ssl_info['validTo_time_t']) : null
            ];

        } catch (Exception $e) {
            $results['connectivity'] = [
                'status' => 'error',
                'details' => ['error' => $e->getMessage()]
            ];
        }

        return $results;
    }
}