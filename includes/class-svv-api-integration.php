<?php
/**
 * Handles integration with Maskinporten for authentication and Statens Vegvesen API for vehicle data
 */
class SVV_API_Integration {
    private $integration_id;
    private $client_id;
    private $kid;
    private $org_no;
    private $certificate_path;
    private $certificate_password;
    private $scope;
    private $maskinporten_token_url;
    private $svv_api_base_url;
    private $token_cache_key = 'svv_access_token';
    private $token_cache_expiry = 3500; // Slightly less than 1 hour to ensure we don't use expired tokens
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load configuration
        $this->integration_id = '2d5adb28-0e61-46aa-9fc0-8772b5206c7c';
        $this->client_id = $this->integration_id; // In Maskinporten, these are the same
        $this->kid = '1423203a-dc67-4ae1-9a96-63d8bb71e169';
        $this->org_no = '998453240';
        $this->scope = 'svv:kjoretoy/kjoretoyopplysninger';
        
        // Use PEM file instead of P12
        $this->certificate_path = '/customers/f/6/e/cdi58sx9l/webroots/af2cfe37/cert/private.pem';
        
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
        
        error_log("🔧 SVV API Integration initialized - Environment: $environment");
        error_log("🔧 SVV API Base URL: {$this->svv_api_base_url}");
    }

    /**
     * Get access token from Maskinporten
     * 
     * @return string|WP_Error Access token or error
     */
    public function get_access_token() {
        // Check if we have a cached token
        $cached_token = SVV_API_Cache::get($this->token_cache_key);
        if ($cached_token !== false) {
            error_log("🔑 Using cached Maskinporten token");
            return $cached_token;
        }
        
        error_log("🔑 No cached token found, requesting new one from Maskinporten");
        
        // Create JWT grant
        $jwt = $this->create_jwt_grant();
        if (is_wp_error($jwt)) {
            return $jwt;
        }

        // Send request to Maskinporten
        error_log("🔄 Sending token request to: {$this->maskinporten_token_url}");
        
        $response = wp_remote_post($this->maskinporten_token_url, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log("❌ Maskinporten connection error: " . $response->get_error_message());
            return new WP_Error('token_request_failed', 'Could not connect to Maskinporten: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);
        
        error_log("🔄 Maskinporten response status: $status_code");

        if ($status_code !== 200 || !isset($body['access_token'])) {
            $error_message = isset($body['error_description']) ? $body['error_description'] : 'Unknown error';
            $error_code = isset($body['error']) ? $body['error'] : 'token_error';
            
            error_log("❌ Maskinporten error: HTTP Status $status_code - $error_message");
            error_log("Response body: " . wp_remote_retrieve_body($response));
            
            return new WP_Error($error_code, "Maskinporten error: $error_message");
        }

        // Cache token
        $token = $body['access_token'];
        SVV_API_Cache::set($this->token_cache_key, $token);
        
        error_log("✅ Successfully obtained new Maskinporten token");

        return $token;
    }

    /**
     * Create JWT grant for Maskinporten
     * 
     * @return string|WP_Error JWT string or error
     */
    private function create_jwt_grant() {
        if (!file_exists($this->certificate_path)) {
            error_log("❌ Certificate file not found at: {$this->certificate_path}");
            return new WP_Error('cert_not_found', 'Certificate file not found');
        }

        // Load private key from PEM file
        $private_key = file_get_contents($this->certificate_path);
        if (!$private_key) {
            error_log("❌ Could not read private key from PEM file");
            return new WP_Error('cert_read_error', 'Could not read private key from PEM file');
        }

        // Get the certificate data to include in x5c header
        $cert_data = $this->extract_cert_data($private_key);
        if (is_wp_error($cert_data)) {
            return $cert_data;
        }

        // Create JWT header with kid AND x5c - Maskinporten requires one of these
        $header = [
            'alg' => 'RS256',
            'kid' => $this->kid,
        ];
        
        // Add x5c if we have certificate data
        if (!empty($cert_data)) {
            $header['x5c'] = [$cert_data];
        }

        // Create JWT payload
        $now = time();
        $exp = $now + 120; // Max 120 seconds allowed by Maskinporten

        // Set the correct audience based on environment
        $aud = defined('SVV_API_ENVIRONMENT') && SVV_API_ENVIRONMENT === 'test' 
            ? 'https://test.maskinporten.no/' 
            : 'https://maskinporten.no/';

        $payload = [
            'aud' => $aud,
            'scope' => $this->scope,
            'iss' => $this->client_id,
            'exp' => $exp,
            'iat' => $now,
            'jti' => $this->generate_uuid()
        ];

        // Base64-url encode header and payload
        $encoded_header = $this->base64url_encode(json_encode($header));
        $encoded_payload = $this->base64url_encode(json_encode($payload));

        $signing_input = $encoded_header . '.' . $encoded_payload;

        // Sign JWT with private key
        $signature = '';
        $key_resource = openssl_pkey_get_private($private_key, $this->certificate_password);
        
        if (!$key_resource) {
            error_log("❌ Could not load private key - Check password");
            return new WP_Error('cert_load_failed', 'Could not load private key. Check the password.');
        }
        
        $is_signed = openssl_sign($signing_input, $signature, $key_resource, OPENSSL_ALGO_SHA256);

        if (!$is_signed) {
            error_log("❌ Failed to sign JWT");
            return new WP_Error('jwt_signing_failed', 'Failed to sign JWT');
        }

        // Encode signature and complete JWT
        $encoded_signature = $this->base64url_encode($signature);
        $jwt = $encoded_header . '.' . $encoded_payload . '.' . $encoded_signature;

        // Log JWT parts for debugging
        error_log("🛠 Debug JWT Header: " . json_encode($header));
        error_log("🛠 Debug JWT Payload: " . json_encode($payload));

        return $jwt;
    }

    /**
     * Extract certificate data from PEM file for use in x5c header
     * 
     * @param string $private_key PEM formatted private key
     * @return string|WP_Error Base64 encoded certificate data or error
     */
    private function extract_cert_data($private_key) {
        // Try to extract certificate from the PEM file
        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $private_key, $matches)) {
            // Clean up the certificate data (remove new lines and whitespace)
            return str_replace(["\r", "\n", " "], '', $matches[1]);
        }
        
        // If we can't find a certificate in the PEM file, we'll use kid instead
        // This is not an error since we can authenticate with kid only if necessary
        error_log("ℹ️ No certificate found in PEM file, will use kid for authentication");
        return '';
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
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
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
        
        // Log the token being used (first 20 chars only for security)
        error_log("🔑 Token being used (first 20 chars): " . substr($token, 0, 20));
        
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
            $direct_endpoint = $this->svv_api_base_url . '/kjoretoyoppslag/kjennemerke/' . urlencode($registration_number);
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
     * Prepare vehicle data for display
     * 
     * @param array $raw_data Raw data from API
     * @return array Processed data
     */
    private function prepare_vehicle_data($raw_data) {
        // Log the raw data structure for debugging
        error_log("🔍 Raw data structure: " . json_encode(array_keys($raw_data)));
        
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
            $data['protected']['status'] = $raw_data['registrering']['registreringsstatus'];
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
}