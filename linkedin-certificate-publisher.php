<?php
/**
 * Plugin Name: LinkedIn Certificate Publisher
 * Plugin URI: https://www.deeplearningintelligence.com/linkedin-certificate-publisher
 * Description: One-click sharing of certifications to LinkedIn with customizable announcements and unique certificate URLs
 * Version: 1.3.0
 * Author: Deep Learning Intelligence
 * Author URI: https://www.deeplearningintelligence.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: linkedin-certificate-publisher
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LCPP_VERSION', '1.3.0');
define('LCPP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LCPP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LCPP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class LinkedIn_Certificate_Publisher {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once LCPP_PLUGIN_DIR . 'includes/class-lcpp-admin.php';
        require_once LCPP_PLUGIN_DIR . 'includes/class-lcpp-oauth.php';
        require_once LCPP_PLUGIN_DIR . 'includes/class-lcpp-linkedin-api.php';
        require_once LCPP_PLUGIN_DIR . 'includes/class-lcpp-certificates.php';
        require_once LCPP_PLUGIN_DIR . 'includes/class-lcpp-shortcodes.php';
        require_once LCPP_PLUGIN_DIR . 'includes/class-lcpp-attendees.php';
        require_once LCPP_PLUGIN_DIR . 'includes/class-lcpp-generator.php';
        require_once LCPP_PLUGIN_DIR . 'includes/class-lcpp-elementor.php';
        require_once LCPP_PLUGIN_DIR . 'includes/class-lcpp-claim-handler.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Initialize certificates CPT EARLY (before init hook fires)
        LCPP_Certificates::get_instance();
        
        // Initialize other components on init
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Initialize certificates CPT
        LCPP_Certificates::get_instance()->register_post_type();
        
        // Create attendees table
        LCPP_Attendees::create_table();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Set default options
        $default_options = array(
            'client_id' => '',
            'client_secret' => '',
            'webhook_api_key' => '', // Empty = no authentication required (set in settings to enable)
            'default_announcement' => "I'm excited to share that I've earned the {certificate_name} certification from {issuer}! 🎉\n\nView my credential: {credential_url}\n\n#certification #achievement #learning",
        );
        
        if (!get_option('lcpp_settings')) {
            add_option('lcpp_settings', $default_options);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * Initialize plugin components
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('linkedin-certificate-publisher', false, dirname(LCPP_PLUGIN_BASENAME) . '/languages');
        
        // Initialize components (Certificates already initialized early in init_hooks)
        LCPP_Admin::get_instance();
        LCPP_OAuth::get_instance();
        LCPP_LinkedIn_API::get_instance();
        LCPP_Shortcodes::get_instance();
        LCPP_Attendees::get_instance();
        LCPP_Generator::get_instance();
        LCPP_Elementor_Integration::get_instance();
        LCPP_Claim_Handler::get_instance();
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style(
            'lcpp-style',
            LCPP_PLUGIN_URL . 'assets/css/lcpp-style.css',
            array(),
            LCPP_VERSION
        );
        
        wp_enqueue_script(
            'lcpp-share',
            LCPP_PLUGIN_URL . 'assets/js/lcpp-share.js',
            array('jquery'),
            LCPP_VERSION,
            true
        );
        
        wp_localize_script('lcpp-share', 'lcpp_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lcpp_nonce'),
            'oauth_url' => LCPP_OAuth::get_instance()->get_auth_url(),
        ));
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'lcpp') !== false || get_post_type() === 'lcpp_certificate') {
            wp_enqueue_style(
                'lcpp-admin-style',
                LCPP_PLUGIN_URL . 'assets/css/lcpp-admin.css',
                array(),
                LCPP_VERSION
            );
        }
    }
}

// Initialize plugin
function lcpp_init() {
    return LinkedIn_Certificate_Publisher::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'lcpp_init');
