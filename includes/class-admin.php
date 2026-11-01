<?php
/**
 * Product edit screen: the "Product designer" tab.
 *
 * @package Lecouz_Product_Designer
 */

namespace Lecouz\ProductDesigner;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the shop owner pick a base image and draw the print areas.
 */
final class Admin {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Adds the tab to the product data box.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs['lecouzpd'] = array(
			'label'    => __( 'Product designer', 'lecouz-product-designer' ),
			'target'   => 'lecouzpd_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 75,
		);

		return $tabs;
	}

	/**
	 * Prints the tab content.
	 *
	 * @return void
	 */
	public static function render_panel() {
		global $product_object;

		if ( ! $product_object instanceof \WC_Product ) {
			return;
		}

		$stored   = Config::normalize( json_decode( (string) $product_object->get_meta( Config::META_CONFIG ), true ) );
		$image_id = $stored['image_id'] ? $stored['image_id'] : $product_object->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_src( $image_id, 'large' ) : false;
		?>
		<div id="lecouzpd_data" class="panel woocommerce_options_panel hidden">
			<?php wp_nonce_field( 'lecouzpd_save_product', 'lecouzpd_nonce' ); ?>

			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => Config::META_ENABLED,
						'label'       => __( 'Enable designer', 'lecouz-product-designer' ),
						'description' => __( 'Show a "Customize" button on the product page.', 'lecouz-product-designer' ),
						'value'       => $product_object->get_meta( Config::META_ENABLED ),
					)
				);
				woocommerce_wp_checkbox(
					array(
						'id'          => Config::META_REQUIRED,
						'label'       => __( 'Design required', 'lecouz-product-designer' ),
						'description' => __( 'Customers must create a design before they can add the product to the cart.', 'lecouz-product-designer' ),
						'value'       => $product_object->get_meta( Config::META_REQUIRED ),
					)
				);
				?>
			</div>

			<div class="options_group lecouzpd-admin"
				data-image-url="<?php echo esc_url( $image ? $image[0] : '' ); ?>"
				data-image-id="<?php echo esc_attr( (string) $stored['image_id'] ); ?>">
				<p class="form-field">
					<label><?php esc_html_e( 'Base image', 'lecouz-product-designer' ); ?></label>
					<button type="button" class="button lecouzpd-pick"><?php esc_html_e( 'Choose image', 'lecouz-product-designer' ); ?></button>
					<span class="description"><?php esc_html_e( 'The photo customers design on. Defaults to the product image.', 'lecouz-product-designer' ); ?></span>
				</p>

				<div class="lecouzpd-admin__editor">
					<div class="lecouzpd-admin__stage"></div>
					<ul class="lecouzpd-admin__areas"></ul>
				</div>

				<p class="form-field">
					<button type="button" class="button lecouzpd-add"><?php esc_html_e( 'Add print area', 'lecouz-product-designer' ); ?></button>
					<span class="description"><?php esc_html_e( 'Drag a rectangle to move it, drag its corner to resize it. Enter the real width of each area in millimetres to get print files at 300 dpi and low-resolution warnings.', 'lecouz-product-designer' ); ?></span>
				</p>

				<input type="hidden" name="lecouzpd_config" value="<?php echo esc_attr( wp_json_encode( $stored ) ); ?>">
			</div>
		</div>
		<?php
	}

	/**
	 * Saves the settings with the product.
	 *
	 * @param \WC_Product $product Product being saved.
	 * @return void
	 */
	public static function save( $product ) {
		if ( ! isset( $_POST['lecouzpd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lecouzpd_nonce'] ) ), 'lecouzpd_save_product' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		$product->update_meta_data( Config::META_ENABLED, isset( $_POST[ Config::META_ENABLED ] ) ? 'yes' : 'no' );
		$product->update_meta_data( Config::META_REQUIRED, isset( $_POST[ Config::META_REQUIRED ] ) ? 'yes' : 'no' );

		// JSON document: decoded here and rebuilt field by field by Config::normalize().
		$raw    = isset( $_POST['lecouzpd_config'] ) ? wp_unslash( $_POST['lecouzpd_config'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$config = Config::normalize( json_decode( is_string( $raw ) ? $raw : '', true ) );

		$product->update_meta_data( Config::META_CONFIG, wp_json_encode( $config ) );
	}

	/**
	 * Loads the editor script on the product screen.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'lecouzpd-admin', LECOUZPD_URL . 'assets/css/admin.css', array(), LECOUZPD_VERSION );
		wp_enqueue_script( 'lecouzpd-admin', LECOUZPD_URL . 'assets/js/admin.js', array(), LECOUZPD_VERSION, true );
		wp_localize_script(
			'lecouzpd-admin',
			'lecouzpdAdmin',
			array(
				'maxAreas' => Config::MAX_AREAS,
				'i18n'     => array(
					'chooseImage' => __( 'Choose the base image', 'lecouz-product-designer' ),
					'useImage'    => __( 'Use this image', 'lecouz-product-designer' ),
					'noImage'     => __( 'Choose an image, or set a product image, to place print areas.', 'lecouz-product-designer' ),
					'name'        => __( 'Name', 'lecouz-product-designer' ),
					'width'       => __( 'Width (mm)', 'lecouz-product-designer' ),
					'remove'      => __( 'Remove', 'lecouz-product-designer' ),
					/* translators: %d: print area number */
					'areaName'    => __( 'Area %d', 'lecouz-product-designer' ),
				),
			)
		);
	}
}
