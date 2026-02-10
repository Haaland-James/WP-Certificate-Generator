<?php
/**
 * Certificate Claim Handler Class
 * 
 * Handles certificate claim/verification pages with login requirement
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCPP_Claim_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'register_rewrite_rules'));
        add_action('template_redirect', array($this, 'handle_claim_page'));
        add_filter('query_vars', array($this, 'add_query_vars'));
    }
    
    /**
     * Register rewrite rules for claim pages
     */
    public function register_rewrite_rules() {
        add_rewrite_rule(
            '^certificate/claim/([A-Za-z0-9]+)/?$',
            'index.php?lcpp_claim_code=$matches[1]',
            'top'
        );
        
        add_rewrite_rule(
            '^certificate/verify/([A-Za-z0-9]+)/?$',
            'index.php?lcpp_verify_code=$matches[1]',
            'top'
        );
        
        // Flush on version change
        if (get_option('lcpp_claim_rules_version') !== LCPP_VERSION) {
            flush_rewrite_rules();
            update_option('lcpp_claim_rules_version', LCPP_VERSION);
        }
    }
    
    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'lcpp_claim_code';
        $vars[] = 'lcpp_verify_code';
        return $vars;
    }
    
    /**
     * Handle claim/verify page requests
     */
    public function handle_claim_page() {
        $claim_code = get_query_var('lcpp_claim_code');
        $verify_code = get_query_var('lcpp_verify_code');
        
        // Fallback: parse URL path directly if rewrite rules haven't flushed
        if (empty($claim_code) && empty($verify_code)) {
            $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
            
            if (preg_match('#certificate/claim/([A-Za-z0-9]+)/?$#', $request_uri, $matches)) {
                $claim_code = $matches[1];
            } elseif (preg_match('#certificate/verify/([A-Za-z0-9]+)/?$#', $request_uri, $matches)) {
                $verify_code = $matches[1];
            }
        }
        
        if ($claim_code) {
            status_header(200);
            nocache_headers();
            $this->render_claim_page($claim_code);
            exit;
        }
        
        if ($verify_code) {
            status_header(200);
            nocache_headers();
            $this->render_verify_page($verify_code);
            exit;
        }
    }
    
    /**
     * Render claim page (requires login)
     */
    private function render_claim_page($code) {
        $attendees = LCPP_Attendees::get_instance();
        $attendee = $attendees->get_attendee_by_code($code);
        
        if (!$attendee) {
            $this->render_error_page('Certificate not found', 'This certificate link is invalid or has expired.');
            return;
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            // Store claim code in session for after login
            if (!session_id()) {
                @session_start();
            }
            $_SESSION['lcpp_pending_claim'] = $code;
            
            // Redirect to login/register page
            $this->render_login_required_page($attendee, $code);
            return;
        }
        
        $current_user = wp_get_current_user();
        
        // Check if email matches
        if (strtolower($current_user->user_email) !== strtolower($attendee->email)) {
            $this->render_error_page(
                'Email Mismatch',
                sprintf(
                    'This certificate belongs to <strong>%s</strong>. Please log in with that email address to claim your certificate.',
                    esc_html($attendee->email)
                )
            );
            return;
        }
        
        // Link attendee to WP user if not already linked
        if (empty($attendee->wp_user_id)) {
            $attendees->link_to_wp_user($attendee->id, $current_user->ID);
        }
        
        // Render the certificate dashboard
        $this->render_certificate_dashboard($attendee);
    }
    
    /**
     * Render public verify page (no login required)
     */
    private function render_verify_page($code) {
        $attendees = LCPP_Attendees::get_instance();
        $attendee = $attendees->get_attendee_by_code($code);
        
        if (!$attendee) {
            $this->render_error_page('Certificate not found', 'This certificate verification link is invalid.');
            return;
        }
        
        // Get certificate image
        $generator = LCPP_Generator::get_instance();
        $certificate_path = $generator->generate_certificate($attendee->name);
        $upload_dir = wp_upload_dir();
        $certificate_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $certificate_path);
        
        $this->render_public_verification($attendee, $certificate_url);
    }
    
    /**
     * Render login required page
     */
    private function render_login_required_page($attendee, $code) {
        $login_url = wp_login_url(home_url('/certificate/claim/' . $code));
        $register_url = wp_registration_url();
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php _e('Claim Your Certificate', 'linkedin-certificate-publisher'); ?> - <?php bloginfo('name'); ?></title>
            <?php wp_head(); ?>
            <style>
                .lcpp-claim-page { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; }
                .lcpp-claim-card { background: white; border-radius: 16px; padding: 40px; max-width: 500px; width: 100%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
                .lcpp-claim-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; }
                .lcpp-claim-icon svg { width: 40px; height: 40px; fill: white; }
                .lcpp-claim-title { font-size: 24px; font-weight: 700; color: #1a1a2e; margin: 0 0 8px; }
                .lcpp-claim-subtitle { color: #666; margin: 0 0 24px; }
                .lcpp-attendee-name { font-size: 20px; font-weight: 600; color: #667eea; margin: 0 0 24px; padding: 16px; background: #f8f9ff; border-radius: 8px; }
                .lcpp-btn { display: inline-block; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px; margin: 8px; transition: transform 0.2s, box-shadow 0.2s; }
                .lcpp-btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
                .lcpp-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4); }
                .lcpp-btn-secondary { background: #f0f0f0; color: #333; }
                .lcpp-btn-secondary:hover { background: #e0e0e0; }
                .lcpp-divider { display: flex; align-items: center; margin: 24px 0; }
                .lcpp-divider::before, .lcpp-divider::after { content: ''; flex: 1; height: 1px; background: #e0e0e0; }
                .lcpp-divider span { padding: 0 16px; color: #999; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="lcpp-claim-page">
                <div class="lcpp-claim-card">
                    <div class="lcpp-claim-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                    </div>
                    <h1 class="lcpp-claim-title"><?php _e('Your Certificate is Ready!', 'linkedin-certificate-publisher'); ?></h1>
                    <p class="lcpp-claim-subtitle"><?php _e('Log in to claim and share your achievement', 'linkedin-certificate-publisher'); ?></p>
                    
                    <div class="lcpp-attendee-name">
                        <?php echo esc_html($attendee->name); ?>
                    </div>
                    
                    <p><?php _e('Please log in with:', 'linkedin-certificate-publisher'); ?><br><strong><?php echo esc_html($attendee->email); ?></strong></p>
                    
                    <a href="<?php echo esc_url($login_url); ?>" class="lcpp-btn lcpp-btn-primary">
                        <?php _e('Log In to Claim', 'linkedin-certificate-publisher'); ?>
                    </a>
                    
                    <div class="lcpp-divider"><span><?php _e('or', 'linkedin-certificate-publisher'); ?></span></div>
                    
                    <p style="color: #666; font-size: 14px;"><?php _e("Don't have an account yet?", 'linkedin-certificate-publisher'); ?></p>
                    <a href="<?php echo esc_url(add_query_arg('redirect_to', home_url('/certificate/claim/' . $code), $register_url)); ?>" class="lcpp-btn lcpp-btn-secondary">
                        <?php _e('Create Account', 'linkedin-certificate-publisher'); ?>
                    </a>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
    
    /**
     * Render certificate dashboard for authenticated user
     */
    private function render_certificate_dashboard($attendee) {
        $generator = LCPP_Generator::get_instance();
        $certificate_path = $generator->generate_certificate($attendee->name);
        $upload_dir = wp_upload_dir();
        $certificate_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $certificate_path);
        
        // Verification URL (public link to certificate)
        $verify_url = home_url('/certificate/verify/' . $attendee->verification_code);
        
        // LinkedIn Share Intent URL - opens LinkedIn compose window in the USER'S account
        $share_text = sprintf(
            "I'm excited to share that I've earned the AI For Funding Masterclass certificate from GoFunditNow! 🎉\n\nVerify my credential: %s\n\n#AIForFunding #GoFunditNow #certification #achievement",
            $verify_url
        );
        $linkedin_share_url = 'https://www.linkedin.com/sharing/share-offsite/?' . http_build_query(array(
            'url' => $verify_url,
        ));
        
        // LinkedIn Add to Profile URL - opens LinkedIn certification editor pre-filled
        $linkedin_profile_url = 'https://www.linkedin.com/profile/add?' . http_build_query(array(
            'startTask' => 'CERTIFICATION_NAME',
            'name' => 'AI For Funding Masterclass',
            'organizationName' => 'GoFunditNow',
            'issueYear' => date('Y', strtotime($attendee->registered_at)),
            'issueMonth' => date('n', strtotime($attendee->registered_at)),
            'certUrl' => $verify_url,
            'certId' => $attendee->verification_code,
        ));
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php _e('Your Certificate', 'linkedin-certificate-publisher'); ?> - <?php bloginfo('name'); ?></title>
            <?php wp_head(); ?>
            <style>
                .lcpp-dashboard { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; min-height: 100vh; background: #f5f7fa; padding: 40px 20px; }
                .lcpp-dashboard-container { max-width: 900px; margin: 0 auto; }
                .lcpp-dashboard-header { text-align: center; margin-bottom: 32px; }
                .lcpp-dashboard-title { font-size: 28px; font-weight: 700; color: #1a1a2e; margin: 0 0 8px; }
                .lcpp-dashboard-subtitle { color: #666; margin: 0; }
                .lcpp-certificate-card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); margin-bottom: 24px; }
                .lcpp-certificate-image { width: 100%; display: block; }
                .lcpp-actions { display: flex; gap: 16px; padding: 24px; flex-wrap: wrap; justify-content: center; }
                .lcpp-btn { display: inline-flex; align-items: center; gap: 8px; padding: 14px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; border: none; cursor: pointer; transition: all 0.2s; color: white; }
                .lcpp-btn svg { width: 20px; height: 20px; }
                .lcpp-btn-linkedin { background: #0a66c2; }
                .lcpp-btn-linkedin:hover { background: #004182; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(10, 102, 194, 0.4); }
                .lcpp-btn-profile { background: #0073b1; }
                .lcpp-btn-profile:hover { background: #005f91; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0, 115, 177, 0.4); }
                .lcpp-btn-download { background: #28a745; }
                .lcpp-btn-download:hover { background: #218838; transform: translateY(-2px); }
                .lcpp-btn-copy { background: #6c757d; }
                .lcpp-btn-copy:hover { background: #5a6268; }
                .lcpp-share-url { background: #f8f9fa; padding: 16px 24px; display: flex; gap: 12px; align-items: center; border-top: 1px solid #e9ecef; }
                .lcpp-share-url input { flex: 1; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
                .lcpp-info-box { background: #e8f4fd; border: 1px solid #b8daff; border-radius: 8px; padding: 16px; margin: 0 24px 24px; font-size: 14px; color: #004085; }
                .lcpp-info-box strong { display: block; margin-bottom: 4px; }
                .lcpp-credential { text-align: center; padding: 12px 24px 24px; color: #888; font-size: 13px; }
            </style>
        </head>
        <body>
            <div class="lcpp-dashboard">
                <div class="lcpp-dashboard-container">
                    <div class="lcpp-dashboard-header">
                        <h1 class="lcpp-dashboard-title">🎉 <?php _e('Congratulations!', 'linkedin-certificate-publisher'); ?></h1>
                        <p class="lcpp-dashboard-subtitle"><?php echo esc_html($attendee->name); ?>, <?php _e('your certificate is ready to share!', 'linkedin-certificate-publisher'); ?></p>
                    </div>
                    
                    <div class="lcpp-certificate-card">
                        <img src="<?php echo esc_url($certificate_url); ?>" alt="Certificate" class="lcpp-certificate-image">
                        
                        <div class="lcpp-actions">
                            <a href="<?php echo esc_url($linkedin_share_url); ?>" target="_blank" rel="noopener" class="lcpp-btn lcpp-btn-linkedin">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.32 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.79M6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37h2.77z"/></svg>
                                <?php _e('Share Post on LinkedIn', 'linkedin-certificate-publisher'); ?>
                            </a>
                            
                            <a href="<?php echo esc_url($linkedin_profile_url); ?>" target="_blank" rel="noopener" class="lcpp-btn lcpp-btn-profile">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                                <?php _e('Add to LinkedIn Certifications', 'linkedin-certificate-publisher'); ?>
                            </a>
                            
                            <a href="<?php echo esc_url($certificate_url); ?>" download="certificate-<?php echo esc_attr(sanitize_file_name($attendee->name)); ?>.png" class="lcpp-btn lcpp-btn-download">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                                <?php _e('Download Certificate', 'linkedin-certificate-publisher'); ?>
                            </a>
                        </div>
                        
                        <div class="lcpp-info-box">
                            <strong>💡 <?php _e('How it works:', 'linkedin-certificate-publisher'); ?></strong>
                            <?php _e('"Share Post" creates a post on YOUR LinkedIn feed. "Add to Certifications" adds it to your LinkedIn profile\'s Licenses & Certifications section.', 'linkedin-certificate-publisher'); ?>
                        </div>
                        
                        <div class="lcpp-share-url">
                            <input type="text" value="<?php echo esc_url($verify_url); ?>" readonly id="lcpp-verify-url">
                            <button class="lcpp-btn lcpp-btn-copy" onclick="navigator.clipboard.writeText(document.getElementById('lcpp-verify-url').value); this.innerHTML='✓ Copied!'; setTimeout(() => this.innerHTML='Copy Link', 2000);">
                                <?php _e('Copy Link', 'linkedin-certificate-publisher'); ?>
                            </button>
                        </div>
                        
                        <div class="lcpp-credential">
                            <?php _e('Credential ID:', 'linkedin-certificate-publisher'); ?> <strong><?php echo esc_html($attendee->verification_code); ?></strong>
                        </div>
                    </div>
                    
                    <p style="text-align: center; color: #666; font-size: 14px;">
                        <?php _e('Share your verification link with employers or include it in your resume.', 'linkedin-certificate-publisher'); ?>
                    </p>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
    
    /**
     * Render public verification page
     */
    private function render_public_verification($attendee, $certificate_url) {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php printf(__('Certificate Verification - %s', 'linkedin-certificate-publisher'), esc_html($attendee->name)); ?></title>
            <meta name="description" content="<?php echo esc_attr(sprintf(__('Verified certificate for %s — AI For Funding Masterclass by %s.', 'linkedin-certificate-publisher'), $attendee->name, get_bloginfo('name'))); ?>">
            
            <?php
            // Remove OG tags from SEO plugins so ours take priority
            // Yoast SEO
            add_filter('wpseo_opengraph_title', '__return_false');
            add_filter('wpseo_opengraph_desc', '__return_false');
            add_filter('wpseo_opengraph_image', '__return_false');
            add_filter('wpseo_opengraph_url', '__return_false');
            add_filter('wpseo_opengraph_type', '__return_false');
            add_filter('wpseo_opengraph_site_name', '__return_false');
            // RankMath
            remove_all_actions('rank_math/opengraph/facebook');
            remove_all_actions('rank_math/opengraph/twitter');
            ?>
            
            <?php wp_head(); ?>
            
            <!-- Open Graph meta tags for LinkedIn / social sharing (placed after wp_head to override SEO plugins) -->
            <meta property="og:type" content="article">
            <meta property="og:title" content="<?php echo esc_attr(sprintf(__('%s - Verified Certificate', 'linkedin-certificate-publisher'), $attendee->name)); ?>">
            <meta property="og:description" content="<?php echo esc_attr(sprintf(__('Verified certificate for completing the AI For Funding Masterclass. Issued by %s.', 'linkedin-certificate-publisher'), get_bloginfo('name'))); ?>">
            <meta property="og:image" content="<?php echo esc_url($certificate_url); ?>">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="900">
            <meta property="og:url" content="<?php echo esc_url(home_url('/certificate/verify/' . $attendee->verification_code)); ?>">
            <meta property="og:site_name" content="<?php bloginfo('name'); ?>">
            
            <!-- Twitter Card -->
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="<?php echo esc_attr(sprintf(__('%s - Verified Certificate', 'linkedin-certificate-publisher'), $attendee->name)); ?>">
            <meta name="twitter:description" content="<?php echo esc_attr(sprintf(__('Verified certificate for completing the AI For Funding Masterclass. Issued by %s.', 'linkedin-certificate-publisher'), get_bloginfo('name'))); ?>">
            <meta name="twitter:image" content="<?php echo esc_url($certificate_url); ?>">
            
            <style>
                .lcpp-verify-page { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; min-height: 100vh; background: #f5f7fa; padding: 40px 20px; }
                .lcpp-verify-container { max-width: 800px; margin: 0 auto; }
                .lcpp-verified-banner { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 20px; border-radius: 12px 12px 0 0; text-align: center; }
                .lcpp-verified-banner svg { width: 48px; height: 48px; margin-bottom: 12px; }
                .lcpp-verified-banner h2 { margin: 0; font-size: 20px; }
                .lcpp-verify-card { background: white; border-radius: 0 0 12px 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); overflow: hidden; }
                .lcpp-verify-image { width: 100%; display: block; }
                .lcpp-verify-details { padding: 24px; }
                .lcpp-detail-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee; }
                .lcpp-detail-row:last-child { border-bottom: none; }
                .lcpp-detail-label { color: #666; }
                .lcpp-detail-value { font-weight: 600; color: #1a1a2e; }
            </style>
        </head>
        <body>
            <div class="lcpp-verify-page">
                <div class="lcpp-verify-container">
                    <div class="lcpp-verified-banner">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-.997-6l7.07-7.071-1.414-1.414-5.656 5.657-2.829-2.829-1.414 1.414L11.003 16z"/></svg>
                        <h2><?php _e('✓ Verified Certificate', 'linkedin-certificate-publisher'); ?></h2>
                    </div>
                    
                    <div class="lcpp-verify-card">
                        <img src="<?php echo esc_url($certificate_url); ?>" alt="Certificate" class="lcpp-verify-image">
                        
                        <div class="lcpp-verify-details">
                            <div class="lcpp-detail-row">
                                <span class="lcpp-detail-label"><?php _e('Recipient', 'linkedin-certificate-publisher'); ?></span>
                                <span class="lcpp-detail-value"><?php echo esc_html($attendee->name); ?></span>
                            </div>
                            <div class="lcpp-detail-row">
                                <span class="lcpp-detail-label"><?php _e('Event', 'linkedin-certificate-publisher'); ?></span>
                                <span class="lcpp-detail-value"><?php echo esc_html($attendee->event_id); ?></span>
                            </div>
                            <div class="lcpp-detail-row">
                                <span class="lcpp-detail-label"><?php _e('Issued', 'linkedin-certificate-publisher'); ?></span>
                                <span class="lcpp-detail-value"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($attendee->registered_at))); ?></span>
                            </div>
                            <div class="lcpp-detail-row">
                                <span class="lcpp-detail-label"><?php _e('Credential ID', 'linkedin-certificate-publisher'); ?></span>
                                <span class="lcpp-detail-value"><?php echo esc_html($attendee->verification_code); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <p style="text-align: center; color: #666; margin-top: 24px; font-size: 14px;">
                        <?php printf(__('Issued by %s', 'linkedin-certificate-publisher'), get_bloginfo('name')); ?>
                    </p>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
    
    /**
     * Render error page
     */
    private function render_error_page($title, $message) {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($title); ?></title>
            <?php wp_head(); ?>
            <style>
                .lcpp-error-page { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #fef2f2; padding: 20px; }
                .lcpp-error-card { background: white; border-radius: 12px; padding: 40px; max-width: 400px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                .lcpp-error-icon { font-size: 48px; margin-bottom: 16px; }
                .lcpp-error-title { color: #dc2626; margin: 0 0 12px; }
                .lcpp-error-message { color: #666; margin: 0; }
            </style>
        </head>
        <body>
            <div class="lcpp-error-page">
                <div class="lcpp-error-card">
                    <div class="lcpp-error-icon">❌</div>
                    <h1 class="lcpp-error-title"><?php echo esc_html($title); ?></h1>
                    <p class="lcpp-error-message"><?php echo wp_kses_post($message); ?></p>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
}
