<?php
/**
 * Cart and order integration.
 *
 * @package Lecouz_Product_Designer
 */

namespace Lecouz\ProductDesigner;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a saved design from the product page to the cart and the order.
 */
final class Cart {

	const ITEM_KEY = 'lecouzpd_design_id';
	const META_KEY = '_lecouzpd_design_id';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'cart_thumbnail' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_meta' ) );
		add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'store_api_images' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_to_order_item' ), 10, 3 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'admin_order_files' ), 10, 2 );
		add_action( 'woocommerce_order_item_meta_end', array( __CLASS__, 'order_preview' ), 10, 4 );
	}

	/**
	 * Blocks the add to cart when a required design is missing or unknown.
	 *
	 * @param bool $passed     Whether the product can be added.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public static function validate( $passed, $product_id ) {
		$config = Config::get( $product_id );

		if ( ! $config ) {
			return $passed;
		}

		$design_id = self::posted_design_id();

		if ( '' === $design_id ) {
			if ( $config['required'] ) {
				wc_add_notice( __( 'Please customize this product before adding it to your cart.', 'lecouz-product-designer' ), 'error' );
				return false;
			}

			return $passed;
		}

		if ( ! Designs::is_valid_for( $design_id, $config ) ) {
			wc_add_notice( __( 'Your design could not be found. Please open the editor and save it again.', 'lecouz-product-designer' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Stores the design ID in the cart item. It also keeps two different
	 * designs of the same product on separate cart lines.
	 *
	 * @param array $data       Cart item data.
	 * @param int   $product_id Product ID.
	 * @return array
	 */
	public static function add_item_data( $data, $product_id ) {
		$config    = Config::get( $product_id );
		$design_id = self::posted_design_id();

		if ( $config && '' !== $design_id && Designs::is_valid_for( $design_id, $config ) ) {
			$data[ self::ITEM_KEY ] = $design_id;
		}

		return $data;
	}

	/**
	 * Shows a line under the product name in the cart and at checkout.
	 *
	 * @param array $item_data Displayed data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function display_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item[ self::ITEM_KEY ] ) ) {
			$item_data[] = array(
				'key'   => __( 'Design', 'lecouz-product-designer' ),
				'value' => __( 'Customised', 'lecouz-product-designer' ),
			);
		}

		return $item_data;
	}

	/**
	 * Replaces the cart thumbnail with the customer's preview.
	 *
	 * @param string $thumbnail Thumbnail HTML.
	 * @param array  $cart_item Cart item.
	 * @return string
	 */
	public static function cart_thumbnail( $thumbnail, $cart_item ) {
		if ( empty( $cart_item[ self::ITEM_KEY ] ) ) {
			return $thumbnail;
		}

		$files = Designs::get_files( $cart_item[ self::ITEM_KEY ] );

		if ( empty( $files['preview'] ) ) {
			return $thumbnail;
		}

		return sprintf(
			'<img src="%s" class="attachment-woocommerce_thumbnail lecouzpd-cart-thumb" alt="%s" loading="lazy">',
			esc_url( $files['preview'] ),
			esc_attr__( 'Your design', 'lecouz-product-designer' )
		);
	}

	/**
	 * Hides the design ID from the order item meta list in the admin.
	 *
	 * @param string[] $keys Hidden meta keys.
	 * @return string[]
	 */
	public static function hide_meta( $keys ) {
		$keys[] = self::META_KEY;

		return $keys;
	}

	/**
	 * Shows the customer's preview as the product image in the block-based cart and checkout.
	 *
	 * @param array $images    Product images.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function store_api_images( $images, $cart_item ) {
		if ( empty( $cart_item[ self::ITEM_KEY ] ) ) {
			return $images;
		}

		$files = Designs::get_files( $cart_item[ self::ITEM_KEY ] );

		if ( empty( $files['preview'] ) ) {
			return $images;
		}

		return array(
			(object) array(
				'id'        => 0,
				'src'       => $files['preview'],
				'thumbnail' => $files['preview'],
				'srcset'    => '',
				'sizes'     => '',
				'name'      => 'lecouz-design-preview',
				'alt'       => __( 'Your design', 'lecouz-product-designer' ),
			),
		);
	}

	/**
	 * Keeps the design on the order line and moves its files out of the cleanup.
	 *
	 * @param \WC_Order_Item_Product $item          Order line.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item.
	 * @return void
	 */
	public static function save_to_order_item( $item, $cart_item_key, $values ) {
		if ( empty( $values[ self::ITEM_KEY ] ) || ! Designs::is_valid_id( $values[ self::ITEM_KEY ] ) ) {
			return;
		}

		Designs::attach( $values[ self::ITEM_KEY ] );

		// The leading underscore hides the value from the default item display.
		$item->add_meta_data( self::META_KEY, $values[ self::ITEM_KEY ], true );
	}

	/**
	 * Lists preview and print files under the line in the order edit screen.
	 *
	 * @param int                    $item_id Order item ID.
	 * @param \WC_Order_Item_Product $item    Order line.
	 * @return void
	 */
	public static function admin_order_files( $item_id, $item ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$design_id = (string) $item->get_meta( self::META_KEY );
		$files     = Designs::get_files( $design_id );

		if ( empty( $files['preview'] ) && empty( $files['areas'] ) ) {
			return;
		}

		$config = Config::get( $item->get_product_id() );
		$names  = $config ? wp_list_pluck( $config['areas'], 'name', 'id' ) : array();
		?>
		<div class="lecouzpd-order-files" style="margin-top:8px">
			<?php if ( $files['preview'] ) : ?>
				<a href="<?php echo esc_url( $files['preview'] ); ?>" target="_blank" rel="noopener">
					<img src="<?php echo esc_url( $files['preview'] ); ?>" alt="" style="display:block;max-width:96px;height:auto;border:1px solid #c3c4c7;margin-bottom:6px">
				</a>
			<?php endif; ?>
			<?php foreach ( $files['areas'] as $area_id => $url ) : ?>
				<a class="button button-small" href="<?php echo esc_url( $url ); ?>" download>
					<?php
					printf(
						/* translators: %s: print area name */
						esc_html__( 'Print file: %s', 'lecouz-product-designer' ),
						esc_html( isset( $names[ $area_id ] ) ? $names[ $area_id ] : $area_id )
					);
					?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Shows the customer's preview in order details and emails.
	 *
	 * @param int                    $item_id    Order item ID.
	 * @param \WC_Order_Item_Product $item       Order line.
	 * @param \WC_Order              $order      Order.
	 * @param bool                   $plain_text Whether the output is plain text.
	 * @return void
	 */
	public static function order_preview( $item_id, $item, $order, $plain_text = false ) {
		if ( $plain_text || ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$files = Designs::get_files( (string) $item->get_meta( self::META_KEY ) );

		if ( empty( $files['preview'] ) ) {
			return;
		}

		printf(
			'<div class="lecouzpd-order-preview"><img src="%s" alt="%s" width="96" style="max-width:96px;height:auto"></div>',
			esc_url( $files['preview'] ),
			esc_attr__( 'Your design', 'lecouz-product-designer' )
		);
	}

	/**
	 * Reads the design ID sent with the add to cart form.
	 *
	 * @return string Empty when nothing valid was sent.
	 */
	private static function posted_design_id() {
		// WooCommerce add to cart forms carry no nonce. The ID is random, unguessable and checked against files on disk.
		if ( ! isset( $_POST[ self::ITEM_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}

		$id = sanitize_text_field( wp_unslash( $_POST[ self::ITEM_KEY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return Designs::is_valid_id( $id ) ? $id : '';
	}
}
