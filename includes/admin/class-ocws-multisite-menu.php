<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class OCWS_Multisite_Menu
 */
class OCWS_Multisite_Menu {

    public static function init(){
        self::hooks();
    }

    public static function hooks(){

        if( is_multisite() ){
            add_action( 'network_admin_menu', array( __CLASS__, 'add_menu_page' ), 100 );
        } else {
            // In non-multisite, add to regular admin menu
            add_action( 'admin_menu', array( __CLASS__, 'add_menu_page_single_site' ), 100 );
        }
    }

    /**
     * Network Admin Menu and Admin Menu
     */
    public static function add_menu_page(){

        if ( ! current_user_can( 'manage_sites' ) ) {
            return;
        }
        //add_menu_page( __( 'Original Concepts Shipping Status', 'ocws' ), __( 'OC Shipping', 'ocws' ), 'manage_sites', 'ocws-woocommerce-shipping', array( __CLASS__, 'sites_shipping_status' ), '', null );
        //add_submenu_page('ocws-network', __( 'Original Concepts Shipping Settings', 'ocws' ), __( 'Settings', 'ocws' ),'manage_sites','ocws-network-settings', array( __CLASS__, 'sites_shipping_settings' ),null );
        add_submenu_page('ocws-network', __( 'Original Concepts Network Settings', 'ocws' ), __( 'Settings', 'ocws' ),'manage_sites','ocws-network-settings', array( __CLASS__, 'sites_network_settings' ),null);
    }

    /**
     * Admin Menu for single site
     */
    public static function add_menu_page_single_site(){

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        add_submenu_page('ocws-network', __( 'Original Concepts Settings', 'ocws' ), __( 'Settings', 'ocws' ),'manage_options','ocws-network-settings', array( __CLASS__, 'sites_network_settings' ),null);
    }

    public static function sites_network_settings() {

        if (is_multisite() && ! current_user_can( 'manage_sites' )) {
            return;
        }
        if (!is_multisite() && ! current_user_can( 'manage_options' )) {
            return;
        }
        $options = array(
            'ocws_common_google_maps_api_key',
            'ocws_common_use_google_cities',
            'ocws_common_use_google_cities_and_polygons',
        );

        foreach ( $options as $option_name ) {
            if ( ! isset( $_POST[ $option_name ] ) ) {
                if (in_array($option_name, ['ocws_common_use_google_cities', 'ocws_common_use_google_cities_and_polygons',]) && isset( $_POST['submit'] )) {
                    if (is_multisite()) {
                        if (false === get_site_option($option_name)) {
                            add_site_option( $option_name, 0 );
                        }
                        else {
                            update_site_option( $option_name, 0 );
                        }
                    } else {
                        if (false === get_option($option_name)) {
                            add_option( $option_name, 0 );
                        }
                        else {
                            update_option( $option_name, 0 );
                        }
                    }
                }
            }
            else {
                $value = is_numeric($_POST[ $option_name ])? intval( $_POST[ $option_name ] ) : trim(wp_unslash( $_POST[ $option_name ] ));
                if (is_multisite()) {
                    update_site_option( $option_name, $value );
                } else {
                    update_option( $option_name, $value );
                }
            }
        }
        ?>
        <h1><?php echo esc_html(__('Original Concepts Network Settings', 'ocws')); ?></h1>
        <form method="post" action="" novalidate="novalidate">
            <?php wp_nonce_field( 'siteoptions' ); ?>
            <h2><?php _e( 'Google Maps API', 'ocws' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr valign="top">
                    <th scope="row"><?php echo __('Google Maps API key', 'ocws') ?></th><td>

                        <input type="text" name="ocws_common_google_maps_api_key" value="<?php echo esc_attr( is_multisite() ? get_site_option('ocws_common_google_maps_api_key') : get_option('ocws_common_google_maps_api_key') ); ?>" />
                    </td>
                </tr>

                <tr valign="top">
                    <th scope="row"><?php echo __('Use Google Cities', 'ocws') ?></th><td>

                        <label>
                            <input name="ocws_common_use_google_cities" data-value="<?php echo is_multisite() ? get_site_option('ocws_common_use_google_cities') : get_option('ocws_common_use_google_cities') ?>" type="checkbox" value="1" <?php if ((is_multisite() ? get_site_option('ocws_common_use_google_cities') : get_option('ocws_common_use_google_cities')) == 1) { ?> checked="checked"<?php } ?> >
                        </label>
                    </td>
                </tr>

                <tr valign="top">
                    <th scope="row"><?php echo __('Use Polygon Feature', 'ocws') ?></th><td>

                        <label>
                            <input name="ocws_common_use_google_cities_and_polygons" type="checkbox" value="1" <?php if ((is_multisite() ? get_site_option('ocws_common_use_google_cities_and_polygons') : get_option('ocws_common_use_google_cities_and_polygons')) == 1) { ?> checked="checked"<?php } ?> >
                        </label>
                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>
        </form>

        <?php

    }
}