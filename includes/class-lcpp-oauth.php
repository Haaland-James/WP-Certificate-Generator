<?php
/**
 * OAuth Handler Class
 * 
 * Implements LinkedIn OAuth 2.0 3-legged flow
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCPP_OAuth {
    
    private static $instance = null;
    
    const AUTH_URL = 'https://www.linkedin.com/oauth/v2/authorization';
    const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';
    const SCOPES = 'openid profile email w_member_social';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'register_callback_endpoint'));
        add_action('template_redirect', array($this, 'handle_oauth_callback'));
        add_action('wp_ajax_lcpp_check_auth', array($this, 'ajax_check_auth'));
        add_action('wp_ajax_nopriv_lcpp_check_auth', array($this, 'ajax_check_auth'));
        
        // Fallback: catch callback request early via parse_request
        add_action('parse_request', array($this, 'maybe_handle_oauth_callback_early'));
    }
    
    /**
     * Register OAuth callback endpoint
     */
    public function register_callback_endpoint() {
        add_rewrite_rule('^lcpp-oauth-callback/?$', 'index.php?lcpp_oauth_callback=1', 'top');
        add_rewrite_tag('%lcpp_oauth_callback%', '1');
        
        // Auto-flush rewrite rules if needed (once)
        if (get_option('lcpp_oauth_rules_flushed') !== LCPP_VERSION) {
            flush_rewrite_rules();
            update_option('lcpp_oauth_rules_flushed', LCPP_VERSION);
        }
    }
    
    /**
     * Fallback handler - catch OAuth callback even if rewrite rules not flushed
     */
    public function maybe_handle_oauth_callback_early($wp) {
        // Check if this is our callback URL
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        
        if (strpos($request_uri, 'lcpp-oauth-callback') !== false && isset($_GET['code'])) {
            // This is our callback - handle it directly
            $this->process_oauth_callback();
            exit;
        }
    }
    
    /**
     * Process the OAuth callback
     */
    private function process_oauth_callback() {
        // Check for errors
        if (isset($_GET['error'])) {
            $this->render_oauth_result(false, sanitize_text_field($_GET['error_description'] ?? 'Authorization failed'));
            return;
        }
        
        // Verify state
        if (!session_id()) {
            @session_start();
        }
        
        $state = sanitize_text_field($_GET['state'] ?? '');
        $stored_state = $_SESSION['lcpp_oauth_state'] ?? '';
        
        // If no stored state, it might have been lost - try to proceed anyway
        if (!empty($stored_state) && $state !== $stored_state) {
            $this->render_oauth_result(false, 'Invalid state parameter. Please try again.');
            return;
        }
        
        // Exchange code for access token
        $code = sanitize_text_field($_GET['code'] ?? '');
        
        if (empty($code)) {
            $this->render_oauth_result(false, 'Authorization code missing');
            return;
        }
        
        $token_data = $this->exchange_code_for_token($code);
        
        if (is_wp_error($token_data)) {
            $this->render_oauth_result(false, $token_data->get_error_message());
            return;
        }
        
        // Store token in user meta or session
        $this->store_access_token($token_data);
        
        // Get certificate ID if set
        $certificate_id = $_SESSION['lcpp_certificate_id'] ?? null;
        unset($_SESSION['lcpp_oauth_state'], $_SESSION['lcpp_certificate_id']);
        
        $this->render_oauth_result(true, 'Successfully connected to LinkedIn!', $certificate_id);
    }
    
    /**
     * Get authorization URL
     */
    public function get_auth_url($certificate_id = null) {
        $settings = LCPP_Admin::get_settings();
        
        if (empty($settings['client_id'])) {
            return '#';
        }
        
        $state = wp_create_nonce('lcpp_oauth_state');
        
        // Store state and certificate ID in session
        if (!session_id()) {
            session_start();
        }
        $_SESSION['lcpp_oauth_state'] = $state;
        $_SESSION['lcpp_certificate_id'] = $certificate_id;
        
        $params = array(
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => home_url('/lcpp-oauth-callback'),
            'state' => $state,
            'scope' => self::SCOPES,
        );
        
        return self::AUTH_URL . '?' . http_build_query($params);
    }
    
    /**
     * Handle OAuth callback
     */
    public function handle_oauth_callback() {
        if (!get_query_var('lcpp_oauth_callback')) {
            return;
        }
        
        // Check for errors
        if (isset($_GET['error'])) {
            $this->render_oauth_result(false, sanitize_text_field($_GET['error_description'] ?? 'Authorization failed'));
            return;
        }
        
        // Verify state
        if (!session_id()) {
            session_start();
        }
        
        $state = sanitize_text_field($_GET['state'] ?? '');
        $stored_state = $_SESSION['lcpp_oauth_state'] ?? '';
        
        if (empty($state) || $state !== $stored_state) {
            $this->render_oauth_result(false, 'Invalid state parameter. Please try again.');
            return;
        }
        
        // Exchange code for access token
        $code = sanitize_text_field($_GET['code'] ?? '');
        
        if (empty($code)) {
            $this->render_oauth_result(false, 'Authorization code missing');
            return;
        }
        
        $token_data = $this->exchange_code_for_token($code);
        
        if (is_wp_error($token_data)) {
            $this->render_oauth_result(false, $token_data->get_error_message());
            return;
        }
        
        // Store token in user meta or session
        $this->store_access_token($token_data);
        
        // Get certificate ID if set
        $certificate_id = $_SESSION['lcpp_certificate_id'] ?? null;
        unset($_SESSION['lcpp_oauth_state'], $_SESSION['lcpp_certificate_id']);
        
        $this->render_oauth_result(true, 'Successfully connected to LinkedIn!', $certificate_id);
    }
    
    /**
     * Exchange authorization code for access token
     */
    private function exchange_code_for_token($code) {
        $settings = LCPP_Admin::get_settings();
        
        $response = wp_remote_post(self::TOKEN_URL, array(
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => home_url('/lcpp-oauth-callback'),
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
            ),
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('token_error', $body['error_description'] ?? $body['error']);
        }
        
        return $body;
    }
    
    /**
     * Store access token
     */
    private function store_access_token($token_data) {
        $user_id = get_current_user_id();
        
        $token_info = array(
            'access_token' => $token_data['access_token'],
            'expires_at' => time() + ($token_data['expires_in'] ?? 5184000), // Default 60 days
            'scope' => $token_data['scope'] ?? '',
        );
        
        if ($user_id > 0) {
            // Encrypt token before storing
            $encrypted = $this->encrypt_token($token_info);
            update_user_meta($user_id, 'lcpp_linkedin_token', $encrypted);
        } else {
            // Store in session for non-logged-in users
            if (!session_id()) {
                session_start();
            }
            $_SESSION['lcpp_linkedin_token'] = $token_info;
        }
    }
    
    /**
     * Get stored access token
     */
    public function get_access_token() {
        $user_id = get_current_user_id();
        
        if ($user_id > 0) {
            $encrypted = get_user_meta($user_id, 'lcpp_linkedin_token', true);
            if (empty($encrypted)) {
                return null;
            }
            $token_info = $this->decrypt_token($encrypted);
        } else {
            if (!session_id()) {
                session_start();
            }
            $token_info = $_SESSION['lcpp_linkedin_token'] ?? null;
        }
        
        if (empty($token_info)) {
            return null;
        }
        
        // Check if expired
        if (isset($token_info['expires_at']) && time() > $token_info['expires_at']) {
            return null;
        }
        
        return $token_info['access_token'] ?? null;
    }
    
    /**
     * Check if user is authenticated
     */
    public function is_authenticated() {
        return !empty($this->get_access_token());
    }
    
    /**
     * Encrypt token for storage
     */
    private function encrypt_token($data) {
        $key = wp_salt('auth');
        $serialized = serialize($data);
        return base64_encode(openssl_encrypt($serialized, 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16)));
    }
    
    /**
     * Decrypt stored token
     */
    private function decrypt_token($encrypted) {
        $key = wp_salt('auth');
        $decrypted = openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16));
        return unserialize($decrypted);
    }
    
    /**
     * Render OAuth result page
     */
    private function render_oauth_result($success, $message, $certificate_id = null) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo $success ? 'Success' : 'Error'; ?> - LinkedIn Connection</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    background: <?php echo $success ? '#f0fdf4' : '#fef2f2'; ?>;
                }
                .result-box {
                    text-align: center;
                    padding: 40px;
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                    max-width: 400px;
                }
                .icon {
                    font-size: 48px;
                    margin-bottom: 20px;
                }
                h1 {
                    margin: 0 0 10px;
                    color: <?php echo $success ? '#166534' : '#dc2626'; ?>;
                }
                p { color: #666; }
            </style>
        </head>
        <body>
            <div class="result-box">
                <div class="icon"><?php echo $success ? '✅' : '❌'; ?></div>
                <h1><?php echo $success ? 'Connected!' : 'Connection Failed'; ?></h1>
                <p><?php echo esc_html($message); ?></p>
                <p><small>This window will close automatically...</small></p>
            </div>
            <script>
                window.opener && window.opener.postMessage({
                    type: 'lcpp_oauth_result',
                    success: <?php echo $success ? 'true' : 'false'; ?>,
                    certificateId: <?php echo $certificate_id ? intval($certificate_id) : 'null'; ?>
                }, '*');
                setTimeout(function() { window.close(); }, 2000);
            </script>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * AJAX check authentication status
     */
    public function ajax_check_auth() {
        check_ajax_referer('lcpp_nonce', 'nonce');
        
        wp_send_json_success(array(
            'authenticated' => $this->is_authenticated(),
        ));
    }
    
    /**
     * Disconnect LinkedIn account
     */
    public function disconnect() {
        $user_id = get_current_user_id();
        
        if ($user_id > 0) {
            delete_user_meta($user_id, 'lcpp_linkedin_token');
        }
        
        if (session_id()) {
            unset($_SESSION['lcpp_linkedin_token']);
        }
    }
}
