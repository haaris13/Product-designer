<?php
/**
 * Plugin Name:       Lecouz Product Designer
 * Plugin URI:        https://lecouz.ch/
 * Description:       Let customers personalise WooCommerce products with their own text and images, and get print-ready files with every order.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Lecouz
 * Author URI:        https://lecouz.ch/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lecouz-product-designer
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package Lecouz_Product_Designer
 */

defined( 'ABSPATH' ) || exit;

define( 'LECOUZPD_VERSION', '0.1.0' );
define( 'LECOUZPD_FILE', __FILE__ );
define( 'LECOUZPD_PATH', plugin_dir_path( __FILE__ ) );
define( 'LECOUZPD_URL', plugin_dir_url( __FILE__ ) );

require_once LECOUZPD_PATH . 'includes/class-config.php';
require_once LECOUZPD_PATH . 'includes/class-designs.php';
require_once LECOUZPD_PATH . 'includes/class-admin.php';
require_once LECOUZPD_PATH . 'includes/class-frontend.php';
require_once LECOUZPD_PATH . 'includes/class-cart.php';
require_once LECOUZPD_PATH . 'includes/class-plugin.php';

/**
 * Declares compatibility with WooCommerce High-Performance Order Storage.
 *
 * @return void
 */
function lecouzpd_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LECOUZPD_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'lecouzpd_declare_compatibility' );

register_activation_hook( __FILE__, array( '\Lecouz\ProductDesigner\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Lecouz\ProductDesigner\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\Lecouz\ProductDesigner\Plugin', 'init' ) );
