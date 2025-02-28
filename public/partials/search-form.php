<?php
/**
 * Template for the vehicle search form
 *
 * @var array $atts Shortcode attributes
 */
?>
<div class="beepi-container">
    <div class="beepi-form">
        <form id="vehicle-search-form">
            <div class="form-group">
                <input 
                    type="text" 
                    id="vehicle-registration" 
                    name="registration" 
                    placeholder="<?php echo esc_attr($atts['placeholder']); ?>" 
                    pattern="[A-Za-z0-9]+" 
                    required
                />
                <?php if (!empty($atts['partner_id'])) : ?>
                    <input type="hidden" name="partner_id" value="<?php echo esc_attr($atts['partner_id']); ?>" />
                <?php endif; ?>
                <button type="submit" class="vehicle-search-button">
                    <?php echo esc_html($atts['button_text']); ?>
                </button>
            </div>
        </form>
    </div>
    
    <div id="vehicle-lookup-results" class="beepi-results">
        <!-- Results will be loaded here via AJAX -->
    </div>
</div>
