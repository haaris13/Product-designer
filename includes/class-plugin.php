<?php
/**
 * Plugin bootstrap.
 *
 * @package Lecouz_Product_Designer
 */

namespace Lecouz\ProductDesigner;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together and handles activation.
 */
final class Plugin {

	/**
	 * Registers the hooks of every component.
	 *
	 * @return void
	 */
	public static function init() {
		// Old unsaved designs are removed even if WooCommerce was deactivated in the meantime.
		add_action( 'lecouzpd_cleanup', array( Designs::class, 'cleanup_pending' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}

		Designs::init();
		Admin::init();
		Frontend::init();
		Cart::init();
	}

	/**
	 * Creates the storage folders and schedules the daily cleanup.
	 *
	 * @return void
	 */
	public static function activate() {
		Designs::prepare_directories();

		if ( ! wp_next_scheduled( 'lecouzpd_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lecouzpd_cleanup' );
		}
	}

	/**
	 * Stops the daily cleanup.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'lecouzpd_cleanup' );
	}

	/**
	 * Tells the administrator that WooCommerce is required.
	 *
	 * @return void
	 */
	public static function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Lecouz Product Designer needs WooCommerce to be installed and active.', 'lecouz-product-designer' )
		);
	}
}
