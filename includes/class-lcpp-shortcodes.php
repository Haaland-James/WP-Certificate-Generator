<?php
/**
 * Shortcodes Class
 * 
 * Registers and handles plugin shortcodes
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCPP_Shortcodes {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_shortcode('lcpp_share_button', array($this, 'render_share_button'));
        add_shortcode('lcpp_user_certificates', array($this, 'render_user_certificates'));
        add_shortcode('lcpp_attendee_dashboard', array($this, 'render_attendee_dashboard'));
        add_shortcode('lcpp_download_button', array($this, 'render_download_button'));
        add_shortcode('lcpp_linkedin_button', array($this, 'render_linkedin_button'));
        add_shortcode('lcpp_linkedin_profile_button', array($this, 'render_linkedin_profile_button'));
    }
    
    /**
     * Render LinkedIn share button
     * 
     * Usage: [lcpp_share_button id="123"] or [lcpp_share_button] (auto-detects on certificate page)
     */
    public function render_share_button($atts) {
        $atts = shortcode_atts(array(
            'id' => null,
            'text' => __('Share to LinkedIn', 'linkedin-certificate-publisher'),
            'class' => '',
        ), $atts);
        
        // Get certificate ID
        $certificate_id = $atts['id'];
        
        if (!$certificate_id) {
            global $post;
            if ($post && $post->post_type === 'lcpp_certificate') {
                $certificate_id = $post->ID;
            }
        }
        
        if (!$certificate_id) {
            return '<!-- LCPP: No certificate ID specified -->';
        }
        
        $oauth = LCPP_OAuth::get_instance();
        $is_authenticated = $oauth->is_authenticated();
        $auth_url = $oauth->get_auth_url($certificate_id);
        
        $button_class = 'lcpp-share-button ' . esc_attr($atts['class']);
        
        ob_start();
        ?>
        <div class="lcpp-share-wrapper" data-certificate-id="<?php echo esc_attr($certificate_id); ?>">
            <button type="button" 
                    class="<?php echo esc_attr($button_class); ?>"
                    data-authenticated="<?php echo $is_authenticated ? 'true' : 'false'; ?>"
                    data-auth-url="<?php echo esc_url($auth_url); ?>">
                <svg class="lcpp-linkedin-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                </svg>
                <span class="lcpp-button-text"><?php echo esc_html($atts['text']); ?></span>
            </button>
            <div class="lcpp-share-status" style="display: none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render user certificates list
     * 
     * Usage: [lcpp_user_certificates user_id="123"] or [lcpp_user_certificates] (current user)
     */
    public function render_user_certificates($atts) {
        $atts = shortcode_atts(array(
            'user_id' => get_current_user_id(),
            'show_share_button' => 'true',
            'columns' => 2,
        ), $atts);
        
        $user_id = intval($atts['user_id']);
        
        if (!$user_id) {
            return '<p>' . __('Please log in to view your certificates.', 'linkedin-certificate-publisher') . '</p>';
        }
        
        $certificates = LCPP_Certificates::get_user_certificates($user_id);
        
        if (empty($certificates)) {
            return '<p>' . __('No certificates found.', 'linkedin-certificate-publisher') . '</p>';
        }
        
        $columns = intval($atts['columns']);
        $show_button = $atts['show_share_button'] === 'true';
        
        ob_start();
        ?>
        <div class="lcpp-certificates-grid lcpp-columns-<?php echo esc_attr($columns); ?>">
            <?php foreach ($certificates as $certificate): ?>
                <?php
                $issuer = get_post_meta($certificate->ID, '_lcpp_issuer', true);
                $issue_date = get_post_meta($certificate->ID, '_lcpp_issue_date', true);
                $thumbnail = get_the_post_thumbnail($certificate->ID, 'medium');
                ?>
                <div class="lcpp-certificate-card">
                    <?php if ($thumbnail): ?>
                        <div class="lcpp-certificate-image">
                            <a href="<?php echo get_permalink($certificate->ID); ?>">
                                <?php echo $thumbnail; ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="lcpp-certificate-content">
                        <h3 class="lcpp-certificate-title">
                            <a href="<?php echo get_permalink($certificate->ID); ?>">
                                <?php echo esc_html($certificate->post_title); ?>
                            </a>
                        </h3>
                        <?php if ($issuer): ?>
                            <p class="lcpp-certificate-issuer"><?php echo esc_html($issuer); ?></p>
                        <?php endif; ?>
                        <?php if ($issue_date): ?>
                            <p class="lcpp-certificate-date">
                                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($issue_date))); ?>
                            </p>
                        <?php endif; ?>
                        <div class="lcpp-certificate-actions">
                            <a href="<?php echo get_permalink($certificate->ID); ?>" class="lcpp-view-button">
                                <?php _e('View', 'linkedin-certificate-publisher'); ?>
                            </a>
                            <?php if ($show_button): ?>
                                <?php echo do_shortcode('[lcpp_share_button id="' . $certificate->ID . '"]'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render attendee dashboard
     * 
     * Usage: [lcpp_attendee_dashboard]
     * Shows certificate download and LinkedIn share for event attendees
     */
    public function render_attendee_dashboard($atts) {
        $atts = shortcode_atts(array(
            'event' => 'ai-masterclass',
            'login_text' => __('Please log in to view your certificate.', 'linkedin-certificate-publisher'),
            'not_attendee_text' => __('You have not attended any events yet. If you recently registered, please try again later.', 'linkedin-certificate-publisher'),
        ), $atts);
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            ob_start();
            ?>
            <div class="lcpp-dashboard lcpp-dashboard-login">
                <div class="lcpp-dashboard-message">
                    <p><?php echo esc_html($atts['login_text']); ?></p>
                    <a href="<?php echo wp_login_url(get_permalink()); ?>" class="lcpp-login-button">
                        <?php _e('Log In', 'linkedin-certificate-publisher'); ?>
                    </a>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        // Get current user
        $user = wp_get_current_user();
        
        // Check if user is an attendee
        $attendees = LCPP_Attendees::get_instance();
        $attendee = $attendees->get_attendee_by_email($user->user_email, $atts['event']);
        
        if (!$attendee) {
            ob_start();
            ?>
            <div class="lcpp-dashboard lcpp-dashboard-no-events">
                <div class="lcpp-dashboard-message">
                    <div class="lcpp-no-events-icon">📋</div>
                    <p><?php echo esc_html($atts['not_attendee_text']); ?></p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        // User is an attendee - show certificate
        $generator = LCPP_Generator::get_instance();
        $certificate_url = $generator->get_certificate_url($attendee->name);
        
        if (is_wp_error($certificate_url)) {
            ob_start();
            ?>
            <div class="lcpp-dashboard lcpp-dashboard-error">
                <div class="lcpp-dashboard-message">
                    <p><?php _e('Unable to generate certificate. Please contact support.', 'linkedin-certificate-publisher'); ?></p>
                    <p><small><?php echo esc_html($certificate_url->get_error_message()); ?></small></p>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        // Build LinkedIn intent URLs (no API tokens needed - opens in user's own browser)
        $verify_url = home_url('/certificate/verify/' . $attendee->verification_code);
        
        $linkedin_share_url = 'https://www.linkedin.com/sharing/share-offsite/?' . http_build_query(array(
            'url' => $verify_url,
        ));
        
        $linkedin_profile_url = 'https://www.linkedin.com/profile/add?' . http_build_query(array(
            'startTask' => 'CERTIFICATION_NAME',
            'name' => 'AI For Funding Masterclass',
            'organizationName' => 'GoFunditNow',
            'issueYear' => date('Y', strtotime($attendee->registered_at)),
            'issueMonth' => date('n', strtotime($attendee->registered_at)),
            'certUrl' => $verify_url,
            'certId' => $attendee->verification_code,
        ));
        
        ob_start();
        ?>
        <div class="lcpp-dashboard lcpp-dashboard-certificate">
            <div class="lcpp-dashboard-header">
                <h2><?php _e('Your Certificate', 'linkedin-certificate-publisher'); ?></h2>
                <p class="lcpp-attendee-name"><?php echo esc_html($attendee->name); ?></p>
            </div>
            
            <div class="lcpp-certificate-preview">
                <img src="<?php echo esc_url($certificate_url); ?>" 
                     alt="<?php echo esc_attr($attendee->name); ?> - Certificate"
                     class="lcpp-certificate-image">
            </div>
            
            <div class="lcpp-dashboard-actions">
                <a href="<?php echo esc_url($linkedin_share_url); ?>" target="_blank" rel="noopener" class="lcpp-intent-link lcpp-dashboard-share">
                    <svg class="lcpp-linkedin-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                    </svg>
                    <?php _e('Share Post on LinkedIn', 'linkedin-certificate-publisher'); ?>
                </a>
                
                <a href="<?php echo esc_url($linkedin_profile_url); ?>" target="_blank" rel="noopener" class="lcpp-intent-link lcpp-dashboard-profile">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <?php _e('Add to LinkedIn Certifications', 'linkedin-certificate-publisher'); ?>
                </a>
                
                <a href="<?php echo esc_url($certificate_url); ?>" download="certificate-<?php echo esc_attr(sanitize_file_name($attendee->name)); ?>.png" class="lcpp-download-button">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    <?php _e('Download Certificate', 'linkedin-certificate-publisher'); ?>
                </a>
            </div>
            
            <div class="lcpp-dashboard-verification" style="margin-top: 20px; padding: 15px; background: #fff; border-radius: 8px; border: 1px solid #eee;">
                <h4><?php _e('Verification Link', 'linkedin-certificate-publisher'); ?></h4>
                <p style="margin-bottom: 10px; font-size: 14px; color: #666;"><?php _e('Share this link to verify your certificate:', 'linkedin-certificate-publisher'); ?></p>
                <div style="display: flex; gap: 10px;">
                    <input type="text" value="<?php echo esc_url($verify_url); ?>" readonly id="lcpp-dash-verify-url" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('lcpp-dash-verify-url').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);"><?php _e('Copy', 'linkedin-certificate-publisher'); ?></button>
                </div>
                <p style="margin-top: 10px; font-size: 13px; color: #888;">
                    <strong><?php _e('Credential ID:', 'linkedin-certificate-publisher'); ?></strong> <?php echo esc_html($attendee->verification_code); ?>
                </p>
            </div>
        </div>
        
        <script>
        /* No JavaScript needed for LinkedIn sharing - it's just links now! */
        jQuery(document).ready(function($) {});
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render standalone download button
     * 
     * Usage: [lcpp_download_button event="ai-masterclass" text="Download Certificate" class="my-button"]
     */
    public function render_download_button($atts) {
        $atts = shortcode_atts(array(
            'event' => 'ai-masterclass',
            'text' => __('Download Certificate', 'linkedin-certificate-publisher'),
            'class' => '',
            'login_message' => __('Please log in to download your certificate.', 'linkedin-certificate-publisher'),
            'not_attendee_message' => __('Certificate not available - you have not attended this event.', 'linkedin-certificate-publisher'),
        ), $atts);
        
        // Check if user is logged in - show nothing if not logged in
        if (!is_user_logged_in()) {
            return ''; // Silent - non-logged-in users don't see anything
        }
        
        // Get current user
        $user = wp_get_current_user();
        
        // Check if user is an attendee
        $attendees = LCPP_Attendees::get_instance();
        $attendee = $attendees->get_attendee_by_email($user->user_email, $atts['event']);
        
        if (!$attendee) {
            // Silent for regular users, only show comment for admins for debugging
            return current_user_can('manage_options') ? '<!-- LCPP: User not an attendee for event: ' . esc_attr($atts['event']) . ' -->' : '';
        }
        
        $button_class = 'lcpp-download-button ' . esc_attr($atts['class']);
        
        ob_start();
        ?>
        <div class="lcpp-button-wrapper lcpp-download-wrapper">
            <button type="button" class="<?php echo esc_attr($button_class); ?>" id="lcpp-download-btn-<?php echo esc_attr($attendee->id); ?>" data-attendee-id="<?php echo esc_attr($attendee->id); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <span><?php echo esc_html($atts['text']); ?></span>
            </button>
            <div class="lcpp-button-status" style="display: none;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#lcpp-download-btn-<?php echo esc_js($attendee->id); ?>').on('click', function() {
                var $btn = $(this);
                var originalHtml = $btn.html();
                $btn.prop('disabled', true).html('<span>Generating...</span>');
                
                $.ajax({
                    url: lcpp_ajax.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'lcpp_download_certificate',
                        nonce: lcpp_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var a = document.createElement('a');
                            a.href = response.data.download_url;
                            a.download = response.data.filename;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            $btn.html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M20 6L9 17l-5-5"/></svg><span>Downloaded!</span>');
                            setTimeout(function() { $btn.html(originalHtml).prop('disabled', false); }, 3000);
                        } else {
                            $btn.next('.lcpp-button-status').text(response.data.message || 'Download failed').fadeIn();
                            $btn.prop('disabled', false).html(originalHtml);
                        }
                    },
                    error: function() {
                        $btn.next('.lcpp-button-status').text('Network error. Please try again.').fadeIn();
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render standalone LinkedIn share button
     * 
     * Usage: [lcpp_linkedin_button event="ai-masterclass" text="Share to LinkedIn" class="my-button"]
     */
    public function render_linkedin_button($atts) {
        $atts = shortcode_atts(array(
            'event' => 'ai-masterclass',
            'text' => __('Share to LinkedIn', 'linkedin-certificate-publisher'),
            'class' => '',
            'login_message' => __('Please log in to share your certificate.', 'linkedin-certificate-publisher'),
            'not_attendee_message' => __('Certificate not available - you have not attended this event.', 'linkedin-certificate-publisher'),
        ), $atts);
        
        // Check if user is logged in - show nothing if not logged in
        if (!is_user_logged_in()) {
            return ''; // Silent - non-logged-in users don't see anything
        }
        
        // Get current user
        $user = wp_get_current_user();
        
        // Check if user is an attendee
        $attendees = LCPP_Attendees::get_instance();
        $attendee = $attendees->get_attendee_by_email($user->user_email, $atts['event']);
        
        if (!$attendee) {
            // Silent for regular users, only show comment for admins for debugging
            return current_user_can('manage_options') ? '<!-- LCPP: User not an attendee for event: ' . esc_attr($atts['event']) . ' -->' : '';
        }
        
        // Build LinkedIn share intent URL (opens in user's own LinkedIn)
        $verify_url = home_url('/certificate/verify/' . $attendee->verification_code);
        $linkedin_share_url = 'https://www.linkedin.com/sharing/share-offsite/?' . http_build_query(array(
            'url' => $verify_url,
        ));
        
        $button_class = 'lcpp-intent-link lcpp-linkedin-share ' . esc_attr($atts['class']);
        
        ob_start();
        ?>
        <div class="lcpp-button-wrapper lcpp-linkedin-wrapper">
            <a href="<?php echo esc_url($linkedin_share_url); ?>" 
               target="_blank" 
               rel="noopener"
               class="<?php echo esc_attr($button_class); ?>">
                <svg class="lcpp-linkedin-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                </svg>
                <span><?php echo esc_html($atts['text']); ?></span>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render LinkedIn Profile / Add to Certifications button
     * 
     * Usage: [lcpp_linkedin_profile_button text="Add to LinkedIn Certifications" class=""]
     */
    public function render_linkedin_profile_button($atts) {
        $atts = shortcode_atts(array(
            'text' => __('Add to LinkedIn Certifications', 'linkedin-certificate-publisher'),
            'class' => '',
            'cert_name' => 'AI For Funding Masterclass',
            'org_name' => 'GoFunditNow',
        ), $atts);
        
        // Get current user's attendee record
        if (!is_user_logged_in()) {
            return '<!-- LCPP: User not logged in -->';
        }
        
        $user = wp_get_current_user();
        $attendees = LCPP_Attendees::get_instance();
        $attendee = $attendees->get_attendee_by_email($user->user_email);
        
        if (!$attendee) {
            return '<!-- LCPP: No attendee record found -->';
        }
        
        // Build LinkedIn profile add URL
        $verify_url = home_url('/certificate/verify/' . $attendee->verification_code);
        
        $linkedin_profile_url = 'https://www.linkedin.com/profile/add?' . http_build_query(array(
            'startTask' => 'CERTIFICATION_NAME',
            'name' => $atts['cert_name'],
            'organizationName' => $atts['org_name'],
            'issueYear' => date('Y', strtotime($attendee->registered_at)),
            'issueMonth' => date('n', strtotime($attendee->registered_at)),
            'certUrl' => $verify_url,
            'certId' => $attendee->verification_code,
        ));
        
        $button_class = 'lcpp-intent-link lcpp-linkedin-profile ' . esc_attr($atts['class']);
        
        ob_start();
        ?>
        <div class="lcpp-button-wrapper lcpp-profile-wrapper">
            <a href="<?php echo esc_url($linkedin_profile_url); ?>" 
               target="_blank" 
               rel="noopener"
               class="<?php echo esc_attr($button_class); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
                <span><?php echo esc_html($atts['text']); ?></span>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
}


