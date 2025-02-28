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
        $cached_token = get_transient($this->token_cache_key);
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
            return new WP_Error('token_request_failed', 'Could not connect to Maskinporten');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);
        
        error_log("🔄 Maskinporten response status: $status_code");

        if ($status_code !== 200 || !isset($body['access_token'])) {
            $error_message = isset($body['error_description']) ? $body['error_description'] : 'Unknown error';
            error_log("❌ Maskinporten error: HTTP Status $status_code - $error_message");
            error_log("Response body: " . wp_remote_retrieve_body($response));
            return new WP_Error('token_error', "Maskinporten error: $error_message");
        }

        // Cache token
        $token = $body['access_token'];
        set_transient($this->token_cache_key, $token, $this->token_cache_expiry);
        
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

        $key_resource = openssl_pkey_get_private($private_key, $this->certificate_password);
        if (!$key_resource) {
            error_log("❌ Could not load private key - Check password");
            return new WP_Error('cert_load_failed', 'Could not load private key. Check the password.');
        }

        // Create JWT header with kid
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $this->kid  // Added key identifier
        ];

        // Create JWT payload with fixed audience
        $now = time();
        $exp = $now + 120; // Max 120 seconds

        // Fix for MP-110: Use explicit audience based on environment
        $aud = defined('SVV_API_ENVIRONMENT') && SVV_API_ENVIRONMENT === 'test' 
            ? 'https://test.maskinporten.no/' 
            : 'https://maskinporten.no/';

        $payload = [
            'aud' => $aud,  // Fixed audience claim
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
        $is_signed = openssl_sign($signing_input, $signature, $key_resource, OPENSSL_ALGO_SHA256);

        if (!$is_signed) {
            error_log("❌ Failed to sign JWT");
            return new WP_Error('jwt_signing_failed', 'Failed to sign JWT');
        }

        // Encode signature and complete JWT
        $encoded_signature = $this->base64url_encode($signature);
        $jwt = $encoded_header . '.' . $encoded_payload . '.' . $encoded_signature;

        // Log JWT for debugging
        error_log("🛠 Debug JWT Header: " . json_encode($header));
        error_log("🛠 Debug JWT Payload: " . json_encode($payload));
        error_log("🛠 Debug JWT Signature length: " . strlen($signature));
        error_log("🛠 Debug Full JWT length: " . strlen($jwt));

        return $jwt;
    }

    /**
     * Base64-url encoding function (fixes JWT encoding issue)
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
        $cached_data = get_transient($cache_key);
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
        
        // Call SVV API
        $endpoint = $this->svv_api_base_url . '/kjoretoyoppslag/bulk/kjennemerke';
        $request_body = [['kjennemerke' => $registration_number]];
        
        error_log("🔄 Calling SVV API endpoint: $endpoint");
        error_log("🔄 Request body: " . json_encode($request_body));
        
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ],
            'body' => json_encode($request_body),
            'timeout' => 15,
        ]);
        
        if (is_wp_error($response)) {
            error_log("❌ SVV API error: " . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log("🔄 SVV API response status: $status_code");
        error_log("🔄 SVV API response body: $response_body");
        
        $body = json_decode($response_body, true);
        
        if ($status_code !== 200) {
            $error_message = isset($body['message']) ? $body['message'] : 'Unknown API error';
            error_log("❌ SVV API error: HTTP Status $status_code - $error_message");
            return new WP_Error('api_error', $error_message);
        }
        
        // API might return empty array if vehicle not found
        if (empty($body) || empty($body[0]) || isset($body[0]['feilmelding'])) {
            $error_message = isset($body[0]['feilmelding']) ? $body[0]['feilmelding'] : 'Vehicle not found';
            error_log("❌ Vehicle not found: $error_message");
            return new WP_Error('not_found', $error_message);
        }
        
        // Process and prepare data for display
        error_log("✅ Vehicle data received for: $registration_number");
        $vehicle_data = $this->prepare_vehicle_data($body[0]);
        
        // Cache for 6 hours
        set_transient($cache_key, $vehicle_data, 6 * HOUR_IN_SECONDS);
        
        return $vehicle_data;
    }
    
    /**
     * Prepare vehicle data for display
     * 
     * @param array $raw_data Raw data from API
     * @return array Processed data
     */
    private function prepare_vehicle_data($raw_data) {
        // Check the structure of the raw data for debugging
        error_log("🔍 Raw data structure: " . json_encode(array_keys($raw_data)));
        
        // Split data into teaser (free) and protected (paid) parts
        $data = [
            'teaser' => [
                'reg_number' => isset($raw_data['kjoretoyId']['kjennemerke']) ? 
                    $raw_data['kjoretoyId']['kjennemerke'] : '',
                'brand' => isset($raw_data['godkjenning']['tekniskGodkjenning']['fabrikat']) ? 
                    $raw_data['godkjenning']['tekniskGodkjenning']['fabrikat'] : '',
                'model' => isset($raw_data['godkjenning']['tekniskGodkjenning']['handelsbetegnelse']) ? 
                    $raw_data['godkjenning']['tekniskGodkjenning']['handelsbetegnelse'] : '',
                'first_registration' => isset($raw_data['forstegangsregistrering']['registrertForstegangNorgeDato']) ? 
                    $raw_data['forstegangsregistrering']['registrertForstegangNorgeDato'] : '',
                'vehicle_type' => isset($raw_data['godkjenning']['tekniskGodkjenning']['kjoretoyklasse']['kodeNavn']) ? 
                    $raw_data['godkjenning']['tekniskGodkjenning']['kjoretoyklasse']['kodeNavn'] : '',
                'engine' => isset($raw_data['godkjenning']['tekniskGodkjenning']['motor']) ? 
                    $this->process_engine_data($raw_data['godkjenning']['tekniskGodkjenning']['motor']) : [],
                'last_inspection' => isset($raw_data['periodiskKjoretoyKontroll']) ? 
                    $this->process_inspection_data($raw_data['periodiskKjoretoyKontroll']) : [],
            ],
            'protected' => [
                'owner' => isset($raw_data['registrering']['registrertEier']) ?
                    $this->process_owner_data($raw_data['registrering']['registrertEier']) : [],
                'registration_history' => isset($raw_data['registrering']['historikk']) ?
                    $raw_data['registrering']['historikk'] : [],
                'status' => isset($raw_data['registrering']['registreringsstatus']) ?
                    $raw_data['registrering']['registreringsstatus'] : []
            ],
            'raw_data' => $raw_data // Store raw data for debugging
        ];
        
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