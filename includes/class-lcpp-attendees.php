<?php
/**
 * Attendee Management Class
 * 
 * Handles attendee registration, storage, and lookup
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCPP_Attendees {
    
    private static $instance = null;
    
    const TABLE_NAME = 'lcpp_attendees';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Ensure table exists (auto-create if missing)
        $this->maybe_create_table();
        
        // One-time migration: backfill verification codes for existing attendees
        if (get_option('lcpp_verification_codes_migrated') !== 'yes') {
            self::backfill_verification_codes();
            update_option('lcpp_verification_codes_migrated', 'yes');
        }
    }
    
    /**
     * Check if table exists and create if not
     */
    private function maybe_create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            self::create_table();
        }
    }
    
    /**
     * Create attendees table on plugin activation
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            name varchar(255) NOT NULL,
            event_id varchar(100) NOT NULL DEFAULT 'ai-masterclass',
            verification_code varchar(32) NOT NULL DEFAULT '',
            registered_at datetime DEFAULT CURRENT_TIMESTAMP,
            certificate_generated tinyint(1) DEFAULT 0,
            certificate_downloaded_at datetime DEFAULT NULL,
            linkedin_shared_at datetime DEFAULT NULL,
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email_event (email, event_id),
            KEY verification_code (verification_code),
            KEY event_id (event_id),
            KEY wp_user_id (wp_user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Backfill verification codes for existing attendees
        self::backfill_verification_codes();
    }
    
    /**
     * Backfill verification codes for existing attendees who don't have one
     */
    public static function backfill_verification_codes() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Check if table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        if (!$table_exists) {
            return;
        }
        
        // Check if column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'verification_code'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN verification_code varchar(32) NOT NULL DEFAULT '' AFTER event_id");
        }
        
        // Find attendees without verification codes
        $attendees = $wpdb->get_results(
            "SELECT id FROM $table_name WHERE verification_code = '' OR verification_code IS NULL"
        );
        
        if (empty($attendees)) {
            return;
        }
        
        // Generate codes inline (avoid calling get_instance() which triggers constructor recursion)
        foreach ($attendees as $attendee) {
            do {
                $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 12));
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE verification_code = %s",
                    $code
                ));
            } while ($exists > 0);
            
            $wpdb->update(
                $table_name,
                array('verification_code' => $code),
                array('id' => $attendee->id),
                array('%s'),
                array('%d')
            );
        }
        
        error_log('LCPP: Backfilled verification codes for ' . count($attendees) . ' attendees');
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('lcpp/v1', '/register-attendee', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_register_attendee'),
            'permission_callback' => array($this, 'verify_api_request'),
        ));
        
        register_rest_route('lcpp/v1', '/attendees', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_attendees'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
    }
    
    /**
     * Verify API request (simple API key or allow all for now)
     */
    public function verify_api_request($request) {
        // Get API key from settings
        $settings = get_option('lcpp_settings', array());
        $api_key = $settings['webhook_api_key'] ?? '';
        
        // If no API key is set, allow all requests (for initial setup)
        if (empty($api_key)) {
            return true;
        }
        
        // Check Authorization header
        $auth_header = $request->get_header('Authorization');
        if ($auth_header && $auth_header === 'Bearer ' . $api_key) {
            return true;
        }
        
        // Check X-API-Key header
        $api_key_header = $request->get_header('X-API-Key');
        if ($api_key_header && $api_key_header === $api_key) {
            return true;
        }
        
        return new WP_Error('unauthorized', 'Invalid API key', array('status' => 401));
    }
    
    /**
     * Handle attendee registration from webhook
     */
    public function handle_register_attendee($request) {
        $params = $request->get_json_params();
        
        // Support various field name formats from Elementor/n8n
        $email = sanitize_email($params['email'] ?? $params['Email'] ?? $params['user_email'] ?? '');
        $name = sanitize_text_field($params['name'] ?? $params['Name'] ?? $params['full_name'] ?? $params['fullName'] ?? '');
        $event_id = sanitize_text_field($params['event'] ?? $params['event_id'] ?? $params['eventId'] ?? 'ai-masterclass');
        
        if (empty($email)) {
            return new WP_Error('missing_email', 'Email is required', array('status' => 400));
        }
        
        if (empty($name)) {
            return new WP_Error('missing_name', 'Name is required', array('status' => 400));
        }
        
        $result = $this->register_attendee($email, $name, $event_id);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Get claim URL for the attendee
        $claim_url = $this->get_certificate_claim_url($result);
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Attendee registered successfully',
            'attendee_id' => $result,
            'claim_url' => $claim_url,
        ), 201);
    }
    
    /**
     * Register an attendee
     */
    public function register_attendee($email, $name, $event_id = 'ai-masterclass') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Check if already registered
        $existing = $this->get_attendee_by_email($email, $event_id);
        
        if ($existing) {
            // Update name if different
            if ($existing->name !== $name) {
                $wpdb->update(
                    $table_name,
                    array('name' => $name),
                    array('id' => $existing->id),
                    array('%s'),
                    array('%d')
                );
            }
            return $existing->id;
        }
        
        // Generate unique verification code
        $verification_code = $this->generate_verification_code();
        
        // Insert new attendee
        $result = $wpdb->insert(
            $table_name,
            array(
                'email' => $email,
                'name' => $name,
                'event_id' => $event_id,
                'verification_code' => $verification_code,
                'registered_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to register attendee');
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Generate a unique verification code
     */
    public function generate_verification_code() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        do {
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 12));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE verification_code = %s",
                $code
            ));
        } while ($exists > 0);
        
        return $code;
    }
    
    /**
     * Get attendee by verification code
     */
    public function get_attendee_by_code($code) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE verification_code = %s",
            $code
        ));
    }
    
    /**
     * Link attendee to WordPress user
     */
    public function link_to_wp_user($attendee_id, $wp_user_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        return $wpdb->update(
            $table_name,
            array('wp_user_id' => $wp_user_id),
            array('id' => $attendee_id),
            array('%d'),
            array('%d')
        );
    }
    
    /**
     * Get certificate claim URL for an attendee
     */
    public function get_certificate_claim_url($attendee_id) {
        $attendee = $this->get_attendee_by_id($attendee_id);
        if (!$attendee || empty($attendee->verification_code)) {
            return null;
        }
        return home_url('/certificate/claim/' . $attendee->verification_code);
    }
    
    /**
     * Get attendee by ID
     */
    public function get_attendee_by_id($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Get attendee by email
     */
    public function get_attendee_by_email($email, $event_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        if ($event_id) {
            $attendee = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE email = %s AND event_id = %s",
                $email,
                $event_id
            ));
        } else {
            $attendee = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE email = %s ORDER BY registered_at DESC",
                $email
            ));
        }
        
        // Runtime fallback: generate verification code if missing
        if ($attendee && empty($attendee->verification_code)) {
            $code = $this->generate_verification_code();
            $wpdb->update(
                $table_name,
                array('verification_code' => $code),
                array('id' => $attendee->id),
                array('%s'),
                array('%d')
            );
            $attendee->verification_code = $code;
        }
        
        return $attendee;
    }
    
    /**
     * Check if email is an attendee
     */
    public function is_attendee($email, $event_id = null) {
        return $this->get_attendee_by_email($email, $event_id) !== null;
    }
    
    /**
     * Get all attendees for an event
     */
    public function get_attendees($event_id = null, $limit = 100, $offset = 0) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        if ($event_id) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE event_id = %s ORDER BY registered_at DESC LIMIT %d OFFSET %d",
                $event_id,
                $limit,
                $offset
            ));
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY registered_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }
    
    /**
     * Handle get attendees API
     */
    public function handle_get_attendees($request) {
        $event_id = $request->get_param('event_id');
        $attendees = $this->get_attendees($event_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'count' => count($attendees),
            'attendees' => $attendees,
        ), 200);
    }
    
    /**
     * Mark certificate as downloaded
     */
    public function mark_downloaded($attendee_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        return $wpdb->update(
            $table_name,
            array(
                'certificate_generated' => 1,
                'certificate_downloaded_at' => current_time('mysql'),
            ),
            array('id' => $attendee_id),
            array('%d', '%s'),
            array('%d')
        );
    }
    
    /**
     * Mark certificate as shared to LinkedIn
     */
    public function mark_linkedin_shared($attendee_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        return $wpdb->update(
            $table_name,
            array('linkedin_shared_at' => current_time('mysql')),
            array('id' => $attendee_id),
            array('%s'),
            array('%d')
        );
    }
    
    /**
     * Add admin menu for attendees list
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=lcpp_certificate',
            __('Attendees', 'linkedin-certificate-publisher'),
            __('Attendees', 'linkedin-certificate-publisher'),
            'manage_options',
            'lcpp-attendees',
            array($this, 'render_attendees_page')
        );
    }
    
    /**
     * Render attendees admin page
     */
    public function render_attendees_page() {
        // Handle CSV import
        $import_message = '';
        if (isset($_POST['lcpp_import_csv']) && check_admin_referer('lcpp_import_csv_nonce')) {
            $import_message = $this->handle_csv_import();
        }
        
        $attendees = $this->get_attendees(null, 500);
        ?>
        <div class="wrap">
            <h1><?php _e('Event Attendees', 'linkedin-certificate-publisher'); ?></h1>
            
            <?php if (!empty($import_message)): ?>
                <div class="notice <?php echo strpos($import_message, 'Error') !== false ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                    <p><?php echo wp_kses_post($import_message); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- CSV Import Section -->
            <div class="lcpp-csv-import" style="background: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #2ecc71;">
                <h3 style="margin-top: 0;"><?php _e('Import Attendees from CSV', 'linkedin-certificate-publisher'); ?></h3>
                <p><?php _e('Upload a CSV file with columns: <strong>name</strong>, <strong>email</strong>, and optionally <strong>event</strong> (defaults to "ai-masterclass").', 'linkedin-certificate-publisher'); ?></p>
                <p><?php _e('First row should be headers. Example:', 'linkedin-certificate-publisher'); ?></p>
                <pre style="background: #f0f0f1; padding: 10px; overflow-x: auto;">name,email,event
John Doe,john@example.com,ai-masterclass
Jane Smith,jane@example.com,ai-masterclass</pre>
                
                <form method="post" enctype="multipart/form-data" style="margin-top: 15px;">
                    <?php wp_nonce_field('lcpp_import_csv_nonce'); ?>
                    <input type="file" name="lcpp_csv_file" accept=".csv" required />
                    <input type="text" name="lcpp_default_event" value="ai-masterclass" placeholder="Default Event ID" style="width: 200px;" />
                    <input type="submit" name="lcpp_import_csv" class="button button-primary" value="<?php _e('Import CSV', 'linkedin-certificate-publisher'); ?>" />
                </form>
            </div>
            
            <div class="lcpp-webhook-info" style="background: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #0a66c2;">
                <h3 style="margin-top: 0;"><?php _e('n8n Webhook Endpoint', 'linkedin-certificate-publisher'); ?></h3>
                <p><?php _e('Use this endpoint in your n8n HTTP Request node:', 'linkedin-certificate-publisher'); ?></p>
                <code style="display: block; padding: 10px; background: #f0f0f1; margin: 10px 0;">
                    POST <?php echo rest_url('lcpp/v1/register-attendee'); ?>
                </code>
                <p><strong><?php _e('JSON Body:', 'linkedin-certificate-publisher'); ?></strong></p>
                <pre style="background: #f0f0f1; padding: 10px; overflow-x: auto;">{
  "name": "{{ $json.name }}",
  "email": "{{ $json.email }}",
  "event": "ai-masterclass"
}</pre>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Name', 'linkedin-certificate-publisher'); ?></th>
                        <th><?php _e('Email', 'linkedin-certificate-publisher'); ?></th>
                        <th><?php _e('Event', 'linkedin-certificate-publisher'); ?></th>
                        <th><?php _e('Registered', 'linkedin-certificate-publisher'); ?></th>
                        <th><?php _e('Downloaded', 'linkedin-certificate-publisher'); ?></th>
                        <th><?php _e('LinkedIn Shared', 'linkedin-certificate-publisher'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendees)): ?>
                        <tr>
                            <td colspan="6"><?php _e('No attendees registered yet.', 'linkedin-certificate-publisher'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attendees as $attendee): ?>
                            <tr>
                                <td><strong><?php echo esc_html($attendee->name); ?></strong></td>
                                <td><?php echo esc_html($attendee->email); ?></td>
                                <td><?php echo esc_html($attendee->event_id); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($attendee->registered_at))); ?></td>
                                <td>
                                    <?php if ($attendee->certificate_downloaded_at): ?>
                                        <span style="color: green;">✓</span> <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($attendee->certificate_downloaded_at))); ?>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($attendee->linkedin_shared_at): ?>
                                        <span style="color: green;">✓</span> <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($attendee->linkedin_shared_at))); ?>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <p style="margin-top: 20px;">
                <strong><?php _e('Total Attendees:', 'linkedin-certificate-publisher'); ?></strong> <?php echo count($attendees); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Handle CSV file import
     */
    private function handle_csv_import() {
        if (!isset($_FILES['lcpp_csv_file']) || $_FILES['lcpp_csv_file']['error'] !== UPLOAD_ERR_OK) {
            return __('Error: Please upload a valid CSV file.', 'linkedin-certificate-publisher');
        }
        
        $file = $_FILES['lcpp_csv_file']['tmp_name'];
        $default_event = sanitize_text_field($_POST['lcpp_default_event'] ?? 'ai-masterclass');
        
        $handle = fopen($file, 'r');
        if (!$handle) {
            return __('Error: Could not read the CSV file.', 'linkedin-certificate-publisher');
        }
        
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return __('Error: CSV file is empty or invalid.', 'linkedin-certificate-publisher');
        }
        
        // Normalize headers to lowercase
        $headers = array_map('strtolower', array_map('trim', $headers));
        
        // Find column indices
        $name_col = array_search('name', $headers);
        if ($name_col === false) $name_col = array_search('full_name', $headers);
        if ($name_col === false) $name_col = array_search('fullname', $headers);
        
        $email_col = array_search('email', $headers);
        if ($email_col === false) $email_col = array_search('user_email', $headers);
        
        $event_col = array_search('event', $headers);
        if ($event_col === false) $event_col = array_search('event_id', $headers);
        
        if ($name_col === false || $email_col === false) {
            fclose($handle);
            return __('Error: CSV must have "name" and "email" columns.', 'linkedin-certificate-publisher');
        }
        
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            $name = isset($row[$name_col]) ? sanitize_text_field(trim($row[$name_col])) : '';
            $email = isset($row[$email_col]) ? sanitize_email(trim($row[$email_col])) : '';
            $event = ($event_col !== false && isset($row[$event_col]) && !empty(trim($row[$event_col]))) 
                     ? sanitize_text_field(trim($row[$event_col])) 
                     : $default_event;
            
            if (empty($name) || empty($email)) {
                $skipped++;
                continue;
            }
            
            $result = $this->register_attendee($email, $name, $event);
            
            if (is_wp_error($result)) {
                $errors++;
            } else {
                $imported++;
            }
        }
        
        fclose($handle);
        
        return sprintf(
            __('Import complete: %d imported, %d skipped (empty), %d errors.', 'linkedin-certificate-publisher'),
            $imported, $skipped, $errors
        );
    }
}
