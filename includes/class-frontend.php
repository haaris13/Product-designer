<?php
/**
 * Product page: the "Customize" button and editor assets.
 *
 * @package Lecouz_Product_Designer
 */

namespace Lecouz\ProductDesigner;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the designer to single product pages.
 */
final class Frontend {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_button' ), 5 );

		// Products that need a design cannot be added to the cart from a shop listing.
		add_filter( 'woocommerce_product_add_to_cart_url', array( __CLASS__, 'loop_url' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'loop_text' ), 10, 2 );
		add_filter( 'woocommerce_product_supports', array( __CLASS__, 'loop_supports' ), 10, 3 );
	}

	/**
	 * Loads the editor on products that have a designer.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$config = Config::get( get_queried_object_id() );

		if ( $config ) {
			self::enqueue_assets( $config );
		}
	}

	/**
	 * Registers the editor styles, scripts and data once per page.
	 *
	 * @param array $config Result of Config::get().
	 * @return void
	 */
	private static function enqueue_assets( $config ) {
		if ( wp_script_is( 'lecouzpd-editor', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style( 'lecouzpd-editor', LECOUZPD_URL . 'assets/css/editor.css', array(), LECOUZPD_VERSION );
		wp_enqueue_script( 'lecouzpd-fabric', LECOUZPD_URL . 'assets/js/vendor/fabric.min.js', array(), '5.3.0', true );
		wp_enqueue_script( 'lecouzpd-editor', LECOUZPD_URL . 'assets/js/editor.js', array( 'lecouzpd-fabric' ), LECOUZPD_VERSION, true );

		wp_localize_script(
			'lecouzpd-editor',
			'lecouzpdData',
			array(
				'restUrl'   => esc_url_raw( rest_url( Designs::REST_NAMESPACE . '/designs' ) ),
				'productId' => $config['product_id'],
				'required'  => $config['required'],
				'image'     => $config['image'],
				'areas'     => $config['areas'],
				'fonts'     => self::fonts(),
				'i18n'      => self::strings(),
			)
		);
	}

	/**
	 * Prints the button and the field that carries the saved design to the cart.
	 *
	 * @return void
	 */
	public static function render_button() {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$config = Config::get( $product->get_id() );

		if ( ! $config ) {
			return;
		}

		// Block themes print the product form before wp_enqueue_scripts runs, so load the assets here too.
		self::enqueue_assets( $config );

		$label = apply_filters( 'lecouzpd_button_label', __( 'Customize', 'lecouz-product-designer' ) );
		?>
		<div class="lecouzpd-field">
			<button type="button" class="button alt wp-element-button lecouzpd-open" aria-haspopup="dialog"><?php echo esc_html( $label ); ?></button>
			<img class="lecouzpd-thumb" src="" alt="" hidden>
			<span class="lecouzpd-status" role="status" aria-live="polite"></span>
			<input type="hidden" name="lecouzpd_design_id" value="">
		</div>
		<?php
	}

	/**
	 * Sends shoppers to the product page for products that require a design.
	 *
	 * @param string      $url     Add to cart URL.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function loop_url( $url, $product ) {
		return self::requires_design( $product ) ? $product->get_permalink() : $url;
	}

	/**
	 * Button text in shop listings.
	 *
	 * @param string      $text    Button text.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function loop_text( $text, $product ) {
		return self::requires_design( $product ) ? __( 'Customize', 'lecouz-product-designer' ) : $text;
	}

	/**
	 * Turns off AJAX add to cart for products that require a design.
	 *
	 * @param bool        $supports Whether the feature is supported.
	 * @param string      $feature  Feature name.
	 * @param \WC_Product $product  Product.
	 * @return bool
	 */
	public static function loop_supports( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature && self::requires_design( $product ) ) {
			return false;
		}

		return $supports;
	}

	/**
	 * Tells whether a product needs a design before it can be bought.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	private static function requires_design( $product ) {
		return $product instanceof \WC_Product
			&& 'yes' === $product->get_meta( Config::META_ENABLED )
			&& 'yes' === $product->get_meta( Config::META_REQUIRED );
	}

	/**
	 * Fonts offered to customers. They are installed on the visitor's device,
	 * so nothing is loaded from a third-party server.
	 *
	 * @return string[]
	 */
	private static function fonts() {
		$fonts = apply_filters(
			'lecouzpd_fonts',
			array( 'Arial', 'Verdana', 'Trebuchet MS', 'Georgia', 'Times New Roman', 'Courier New', 'Impact' )
		);

		return array_values( array_filter( array_map( 'sanitize_text_field', (array) $fonts ) ) );
	}

	/**
	 * Texts used by the editor script.
	 *
	 * @return array<string,string>
	 */
	private static function strings() {
		return array(
			'title'        => __( 'Customize your product', 'lecouz-product-designer' ),
			'close'        => __( 'Close', 'lecouz-product-designer' ),
			'edit'         => __( 'Edit design', 'lecouz-product-designer' ),
			'addText'      => __( 'Add text', 'lecouz-product-designer' ),
			'addImage'     => __( 'Add image', 'lecouz-product-designer' ),
			'defaultText'  => __( 'Your text', 'lecouz-product-designer' ),
			'hint'         => __( 'Add text or an image, then move and resize it inside the dashed area.', 'lecouz-product-designer' ),
			'area'         => __( 'Print area', 'lecouz-product-designer' ),
			'text'         => __( 'Text', 'lecouz-product-designer' ),
			'font'         => __( 'Font', 'lecouz-product-designer' ),
			'color'        => __( 'Colour', 'lecouz-product-designer' ),
			'size'         => __( 'Size', 'lecouz-product-designer' ),
			'bold'         => __( 'Bold', 'lecouz-product-designer' ),
			'italic'       => __( 'Italic', 'lecouz-product-designer' ),
			'alignLeft'    => __( 'Align left', 'lecouz-product-designer' ),
			'alignCenter'  => __( 'Align centre', 'lecouz-product-designer' ),
			'alignRight'   => __( 'Align right', 'lecouz-product-designer' ),
			'center'       => __( 'Center in area', 'lecouz-product-designer' ),
			'forward'      => __( 'Bring forward', 'lecouz-product-designer' ),
			'backward'     => __( 'Send backward', 'lecouz-product-designer' ),
			'remove'       => __( 'Delete', 'lecouz-product-designer' ),
			'cancel'       => __( 'Cancel', 'lecouz-product-designer' ),
			'save'         => __( 'Save design', 'lecouz-product-designer' ),
			'saving'       => __( 'Saving…', 'lecouz-product-designer' ),
			'saved'        => __( 'Design saved.', 'lecouz-product-designer' ),
			'empty'        => __( 'Add some text or an image first.', 'lecouz-product-designer' ),
			'needDesign'   => __( 'Please customize this product before adding it to your cart.', 'lecouz-product-designer' ),
			'badFile'      => __( 'Please choose a PNG, JPEG, WebP or GIF image.', 'lecouz-product-designer' ),
			'fileTooLarge' => __( 'This image is too large. The limit is 15 MB.', 'lecouz-product-designer' ),
			'saveError'    => __( 'The design could not be saved. Please try again.', 'lecouz-product-designer' ),
			/* translators: %d: image resolution in dots per inch */
			'lowRes'       => __( 'Low resolution: about %d dpi. The print may look blurry.', 'lecouz-product-designer' ),
		);
	}
}
