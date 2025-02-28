<?php
/**
 * Template for displaying teaser vehicle data
 *
 * @var array $vehicle_data The vehicle data
 */

// Get teaser data
$teaser = $vehicle_data['teaser'];
?>

<div class="vehicle-teaser">
    <div class="vehicle-teaser-header">
        <h2><?php echo esc_html($teaser['brand'] . ' ' . $teaser['model']); ?></h2>
        <div class="vehicle-reg-number"><?php echo esc_html($teaser['reg_number']); ?></div>
    </div>
    
    <div class="vehicle-teaser-body">
        <div class="vehicle-teaser-section">
            <h3><?php _e('Vehicle Information', 'beepi'); ?></h3>
            <div class="vehicle-info-grid">
                <div class="vehicle-info-item">
                    <span class="info-label"><?php _e('Type', 'beepi'); ?>:</span>
                    <span class="info-value"><?php echo esc_html($teaser['vehicle_type']); ?></span>
                </div>
                
                <div class="vehicle-info-item">
                    <span class="info-label"><?php _e('First Registration', 'beepi'); ?>:</span>
                    <span class="info-value">
                        <?php 
                        $date = !empty($teaser['first_registration']) ? date_i18n(get_option('date_format'), strtotime($teaser['first_registration'])) : '-';
                        echo esc_html($date);
                        ?>
                    </span>
                </div>
                
                <?php if (!empty($teaser['engine'])) : ?>
                    <div class="vehicle-info-item">
                        <span class="info-label"><?php _e('Fuel Type', 'beepi'); ?>:</span>
                        <span class="info-value"><?php echo esc_html($teaser['engine']['fuel_type']); ?></span>
                    </div>
                    
                    <?php if (!empty($teaser['engine']['displacement'])) : ?>
                        <div class="vehicle-info-item">
                            <span class="info-label"><?php _e('Engine Size', 'beepi'); ?>:</span>
                            <span class="info-value"><?php echo esc_html($teaser['engine']['displacement']); ?> cc</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($teaser['engine']['power'])) : ?>
                        <div class="vehicle-info-item">
                            <span class="info-label"><?php _e('Engine Power', 'beepi'); ?>:</span>
                            <span class="info-value"><?php echo esc_html($teaser['engine']['power']); ?> kW</span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (!empty($teaser['last_inspection'])) : ?>
                    <div class="vehicle-info-item">
                        <span class="info-label"><?php _e('Last Inspection', 'beepi'); ?>:</span>
                        <span class="info-value">
                            <?php 
                            $date = !empty($teaser['last_inspection']['last_date']) ? date_i18n(get_option('date_format'), strtotime($teaser['last_inspection']['last_date'])) : '-';
                            echo esc_html($date);
                            ?>
                        </span>
                    </div>
                    
                    <div class="vehicle-info-item">
                        <span class="info-label"><?php _e('Next Inspection Due', 'beepi'); ?>:</span>
                        <span class="info-value">
                            <?php 
                            $date = !empty($teaser['last_inspection']['next_date']) ? date_i18n(get_option('date_format'), strtotime($teaser['last_inspection']['next_date'])) : '-';
                            echo esc_html($date);
                            ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="vehicle-owner-locked">
            <div class="locked-content">
                <div class="lock-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <h3><?php _e('Vehicle Owner Information', 'beepi'); ?></h3>
                <p><?php _e('Get access to the owner information and complete vehicle history.', 'beepi'); ?></p>
                
                <?php 
                // Get product info for the button
                $product_id = get_option('beepi_product_id');
                $product = wc_get_product($product_id);
                
                // Check if partner ID is provided
                $partner_id = isset($atts['partner_id']) ? $atts['partner_id'] : '';
                
                // Build checkout URL
                $args = array(
                    'add-to-cart' => $product_id,
                    'registration' => $teaser['reg_number'],
                );
                
                // Add partner ID if provided
                if (!empty($partner_id)) {
                    $args['partner_id'] = $partner_id;
                }
                
                $checkout_url = add_query_arg($args, wc_get_checkout_url());
                ?>
                
                <a href="<?php echo esc_url($checkout_url); ?>" class="view-owner-button">
                    <?php 
                    if ($product) {
                        printf(
                            __('View Owner Info (%s)', 'beepi'),
                            wc_price($product->get_price())
                        );
                    } else {
                        _e('View Owner Info', 'beepi');
                    }
                    ?>
                </a>
            </div>
        </div>
    </div>
</div>