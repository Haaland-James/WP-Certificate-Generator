<?php
/**
 * Single Certificate Template
 * 
 * Template for displaying a single certificate
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()): the_post();
    $certificate_id = get_the_ID();
    $issuer = get_post_meta($certificate_id, '_lcpp_issuer', true);
    $issue_date = get_post_meta($certificate_id, '_lcpp_issue_date', true);
    $expiry_date = get_post_meta($certificate_id, '_lcpp_expiry_date', true);
    $credential_id = get_post_meta($certificate_id, '_lcpp_credential_id', true);
    $recipient_user_id = get_post_meta($certificate_id, '_lcpp_recipient_user_id', true);
    $recipient = $recipient_user_id ? get_user_by('ID', $recipient_user_id) : null;
?>

<div class="lcpp-certificate-page">
    <div class="lcpp-certificate-container">
        
        <!-- Certificate Header -->
        <header class="lcpp-certificate-header">
            <div class="lcpp-certificate-badge">
                <svg viewBox="0 0 100 100" class="lcpp-badge-icon">
                    <circle cx="50" cy="50" r="45" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M30 50 L45 65 L70 35" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h1 class="lcpp-certificate-title"><?php the_title(); ?></h1>
            <?php if ($issuer): ?>
                <p class="lcpp-certificate-issuer">Issued by <strong><?php echo esc_html($issuer); ?></strong></p>
            <?php endif; ?>
        </header>
        
        <!-- Certificate Image -->
        <?php if (has_post_thumbnail()): ?>
            <div class="lcpp-certificate-image-wrap">
                <?php the_post_thumbnail('large', array('class' => 'lcpp-certificate-image')); ?>
            </div>
        <?php endif; ?>
        
        <!-- Certificate Details -->
        <div class="lcpp-certificate-details">
            
            <?php if ($recipient): ?>
                <div class="lcpp-detail-item lcpp-recipient">
                    <span class="lcpp-detail-label"><?php _e('Awarded To', 'linkedin-certificate-publisher'); ?></span>
                    <span class="lcpp-detail-value"><?php echo esc_html($recipient->display_name); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($issue_date): ?>
                <div class="lcpp-detail-item">
                    <span class="lcpp-detail-label"><?php _e('Issue Date', 'linkedin-certificate-publisher'); ?></span>
                    <span class="lcpp-detail-value"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($issue_date))); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($expiry_date): ?>
                <div class="lcpp-detail-item">
                    <span class="lcpp-detail-label"><?php _e('Expiry Date', 'linkedin-certificate-publisher'); ?></span>
                    <span class="lcpp-detail-value"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($expiry_date))); ?></span>
                </div>
            <?php else: ?>
                <div class="lcpp-detail-item">
                    <span class="lcpp-detail-label"><?php _e('Validity', 'linkedin-certificate-publisher'); ?></span>
                    <span class="lcpp-detail-value"><?php _e('No Expiration', 'linkedin-certificate-publisher'); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($credential_id): ?>
                <div class="lcpp-detail-item">
                    <span class="lcpp-detail-label"><?php _e('Credential ID', 'linkedin-certificate-publisher'); ?></span>
                    <span class="lcpp-detail-value lcpp-credential-id"><?php echo esc_html($credential_id); ?></span>
                </div>
            <?php endif; ?>
            
        </div>
        
        <!-- Verification Section -->
        <div class="lcpp-verification">
            <div class="lcpp-verified-badge">
                <svg class="lcpp-check-icon" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-.997-6l7.07-7.071-1.414-1.414-5.656 5.657-2.829-2.829-1.414 1.414L11.003 16z"/>
                </svg>
                <span><?php _e('Verified Certificate', 'linkedin-certificate-publisher'); ?></span>
            </div>
            <p class="lcpp-verification-text">
                <?php _e('This certificate has been verified and is authentic.', 'linkedin-certificate-publisher'); ?>
            </p>
        </div>
        
        <!-- Share Section -->
        <div class="lcpp-share-section">
            <h3><?php _e('Share this achievement', 'linkedin-certificate-publisher'); ?></h3>
            <?php echo do_shortcode('[lcpp_share_button]'); ?>
        </div>
        
        <!-- Certificate URL for copying -->
        <div class="lcpp-url-section">
            <label><?php _e('Certificate URL:', 'linkedin-certificate-publisher'); ?></label>
            <div class="lcpp-url-copy">
                <input type="text" value="<?php echo esc_url(get_permalink()); ?>" readonly id="lcpp-certificate-url">
                <button type="button" class="lcpp-copy-button" onclick="navigator.clipboard.writeText(document.getElementById('lcpp-certificate-url').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">
                    <?php _e('Copy', 'linkedin-certificate-publisher'); ?>
                </button>
            </div>
        </div>
        
    </div>
</div>

<?php
endwhile;

get_footer();
