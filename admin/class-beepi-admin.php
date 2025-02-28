<?php
/**
 * The admin-specific functionality of the plugin.
 */
class Beepi_Admin {

// Constructor - add this to the class
public function __construct() {
    // Register AJAX handler for token testing
    add_action('wp_ajax_beepi_test_token', array($this, 'ajax_test_token'));
}

// AJAX handler for testing token authentication
public function ajax_test_token() {
    // Verify nonce
    check_ajax_referer('beepi_admin_nonce', 'nonce');
    
    // Test authentication
    $api = new SVV_API_Integration();
    $token = $api->get_access_token();
    
    if (is_wp_error($token)) {
        // Authentication failed
        wp_send_json_error(array(
            'message' => $token->get_error_message()
        ));
    } else {
        // Authentication successful
        wp_send_json_success(array(
            'token' => substr($token, 0, 10) . '...' // Show just beginning of token for security
        ));
    }
}


    /**
     * Add plugin admin menu
     */
    public function add_plugin_admin_menu() {
        add_menu_page(
            __('Beepi Settings', 'beepi'),
            'Beepi',
            'manage_options',
            'beepi',
            array($this, 'display_plugin_admin_page'),
            'dashicons-car',
            85
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'beepi_options',
            'beepi_product_id',
            array(
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'default' => 0,
            )
        );
    }

    /**
     * Render the settings page
     */
    public function display_plugin_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get current product ID
        $product_id = get_option('beepi_product_id');
        $product = $product_id ? wc_get_product($product_id) : null;
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('beepi_options');
                do_settings_sections('beepi_options');
                ?>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="beepi_product_id"><?php _e('Lookup Product', 'beepi'); ?></label>
                        </th>
                        <td>
                            <select name="beepi_product_id" id="beepi_product_id">
                                <option value=""><?php _e('-- Select a product --', 'beepi'); ?></option>
                                <?php
                                // Get virtual products
                                $args = array(
                                    'status' => 'publish',
                                    'type' => 'simple',
                                    'virtual' => true,
                                    'limit' => -1,
                                );
                                $products = wc_get_products($args);
                                
                                foreach ($products as $prod) {
                                    $selected = $product_id == $prod->get_id() ? 'selected' : '';
                                    echo '<option value="' . esc_attr($prod->get_id()) . '" ' . $selected . '>' . esc_html($prod->get_name()) . ' (' . $prod->get_price_html() . ')</option>';
                                }
                                ?>
                            </select>
                            <p class="description">
                                <?php _e('Select the WooCommerce product to use for vehicle lookups.', 'beepi'); ?>
                                <?php if ($product): ?>
                                    <br>
                                    <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>" target="_blank">
                                        <?php _e('Edit product', 'beepi'); ?>
                                    </a>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php _e('API Information', 'beepi'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php _e('Integration ID', 'beepi'); ?></th>
                    <td><code>2d5adb28-0e61-46aa-9fc0-8772b5206c7c</code></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Organization Number', 'beepi'); ?></th>
                    <td><code>998453240</code></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Scope', 'beepi'); ?></th>
                    <td><code>svv:kjoretoy/kjoretoyopplysninger</code></td>
                </tr>
            </table>
            
            <h3><?php _e('Test Token', 'beepi'); ?></h3>
            <p><?php _e('Click the button below to test your Maskinporten authentication.', 'beepi'); ?></p>
            <button class="button" id="beepi-test-token"><?php _e('Test Authentication', 'beepi'); ?></button>
            <div id="beepi-test-result" style="margin-top: 10px; padding: 10px; background: #f8f8f8; border: 1px solid #ddd; display: none;"></div>
            
            <script>
                jQuery(document).ready(function($) {
                    $('#beepi-test-token').on('click', function(e) {
                        e.preventDefault();
                        
                        const resultBox = $('#beepi-test-result');
                        resultBox.html('<?php _e('Testing authentication...', 'beepi'); ?>').show();
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'beepi_test_token',
                                nonce: '<?php echo wp_create_nonce('beepi_admin_nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    resultBox.html('<div style="color: green;"><?php _e('Success! Authentication is working correctly.', 'beepi'); ?></div>');
                                } else {
                                    resultBox.html('<div style="color: red;"><?php _e('Error: ', 'beepi'); ?>' + response.data.message + '</div>');
                                }
                            },
                            error: function() {
                                resultBox.html('<div style="color: red;"><?php _e('Connection error. Please try again.', 'beepi'); ?></div>');
                            }
                        });
                    });
                });
            </script>
        </div>
        <?php
    }
}
