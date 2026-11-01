<?php
/**
 * Storage and REST endpoint for customer designs.
 *
 * @package Lecouz_Product_Designer
 */

namespace Lecouz\ProductDesigner;

defined( 'ABSPATH' ) || exit;

/**
 * A design is a folder of PNG files named after a random 32-character ID:
 *
 *   uploads/lecouz-product-designer/pending/<id>/   saved but not ordered yet
 *   uploads/lecouz-product-designer/orders/<id>/    attached to an order
 *
 * Each folder holds preview.png (the product with the design on it) and one
 * area-<area id>.png print file per customised area.
 */
final class Designs {

	const REST_NAMESPACE = 'lecouzpd/v1';
	const ROOT           = 'lecouz-product-designer';
	const RATE_LIMIT     = 40;
	const PENDING_DAYS   = 3;
	const MAX_PREVIEW    = 4194304;  // 4 MB.
	const MAX_PRINT      = 12582912; // 12 MB.
	const MAX_PREVIEW_PX = 2000;
	const MAX_PRINT_PX   = 5000;

	/**
	 * Registers the REST route.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Adds the route used by the editor to save a design.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/designs',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_create' ),
				// Guests can customise products, so the route is public. Input is validated
				// strictly and the number of saves per visitor is limited.
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'preview'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'areas'      => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);
	}

	/**
	 * Saves a design sent by the editor.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_create( $request ) {
		$limited = self::check_rate_limit();

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$config = Config::get( (int) $request->get_param( 'product_id' ) );

		if ( ! $config ) {
			return new \WP_Error( 'lecouzpd_no_designer', __( 'This product cannot be customised.', 'lecouz-product-designer' ), array( 'status' => 404 ) );
		}

		$preview = self::decode_png( (string) $request->get_param( 'preview' ), self::MAX_PREVIEW, self::MAX_PREVIEW_PX );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$submitted = $request->get_param( 'areas' );
		$known     = wp_list_pluck( $config['areas'], 'id' );
		$prints    = array();

		if ( is_array( $submitted ) ) {
			foreach ( $submitted as $area_id => $data_url ) {
				if ( ! in_array( (string) $area_id, $known, true ) || ! is_string( $data_url ) ) {
					continue;
				}

				$print = self::decode_png( $data_url, self::MAX_PRINT, self::MAX_PRINT_PX );

				if ( is_wp_error( $print ) ) {
					return $print;
				}

				$prints[ (string) $area_id ] = $print;
			}
		}

		if ( empty( $prints ) ) {
			return new \WP_Error( 'lecouzpd_empty', __( 'The design is empty.', 'lecouz-product-designer' ), array( 'status' => 400 ) );
		}

		$id = bin2hex( random_bytes( 16 ) );

		$written = self::write( $id, 'preview.png', $preview );

		foreach ( $prints as $area_id => $bytes ) {
			$written = $written && self::write( $id, 'area-' . $area_id . '.png', $bytes );
		}

		if ( ! $written ) {
			return new \WP_Error( 'lecouzpd_write', __( 'The design could not be saved on the server.', 'lecouz-product-designer' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'id'      => $id,
				'preview' => self::url( $id, 'preview.png' ),
			)
		);
	}

	/**
	 * Tells whether a design ID exists and fits a product configuration.
	 *
	 * @param string $id     Design ID.
	 * @param array  $config Result of Config::get().
	 * @return bool
	 */
	public static function is_valid_for( $id, $config ) {
		if ( ! self::is_valid_id( $id ) || ! self::locate( $id ) ) {
			return false;
		}

		$files = self::get_files( $id );

		if ( empty( $files['areas'] ) ) {
			return false;
		}

		$known = wp_list_pluck( $config['areas'], 'id' );

		return empty( array_diff( array_keys( $files['areas'] ), $known ) );
	}

	/**
	 * Lists the files of a design.
	 *
	 * @param string $id Design ID.
	 * @return array{preview:?string,areas:array<string,string>} URLs, areas keyed by area ID.
	 */
	public static function get_files( $id ) {
		$result = array(
			'preview' => null,
			'areas'   => array(),
		);

		$group = self::locate( $id );

		if ( ! $group ) {
			return $result;
		}

		$names = scandir( self::dir( $id, $group ) );

		foreach ( (array) $names as $name ) {
			if ( 'preview.png' === $name ) {
				$result['preview'] = self::url( $id, $name, $group );
			} elseif ( preg_match( '/^area-([a-z0-9]{1,12})\.png$/', $name, $matches ) ) {
				$result['areas'][ $matches[1] ] = self::url( $id, $name, $group );
			}
		}

		return $result;
	}

	/**
	 * URL of one file of a design.
	 *
	 * @param string      $id    Design ID.
	 * @param string      $file  File name.
	 * @param string|null $group "pending" or "orders"; looked up when omitted.
	 * @return string
	 */
	public static function url( $id, $file, $group = null ) {
		$group = $group ? $group : self::locate( $id );
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::ROOT . '/' . $group . '/' . $id . '/' . $file;
	}

	/**
	 * Moves a design to the orders folder so the cleanup keeps it.
	 *
	 * @param string $id Design ID.
	 * @return bool
	 */
	public static function attach( $id ) {
		$group = self::locate( $id );

		if ( 'orders' === $group ) {
			return true;
		}

		$filesystem = self::filesystem();

		if ( 'pending' !== $group || ! $filesystem ) {
			return false;
		}

		$target = self::dir( $id, 'orders' );
		wp_mkdir_p( dirname( $target ) );

		return $filesystem->move( self::dir( $id, 'pending' ), $target );
	}

	/**
	 * Deletes designs that were saved but never ordered. Runs daily.
	 *
	 * @return void
	 */
	public static function cleanup_pending() {
		$filesystem = self::filesystem();
		$root       = self::root_dir() . '/pending';

		if ( ! $filesystem || ! is_dir( $root ) ) {
			return;
		}

		$limit = time() - ( self::PENDING_DAYS * DAY_IN_SECONDS );

		foreach ( (array) $filesystem->dirlist( $root ) as $name => $details ) {
			if ( ! self::is_valid_id( (string) $name ) || 'd' !== $details['type'] ) {
				continue;
			}

			if ( ! empty( $details['lastmodunix'] ) && $details['lastmodunix'] < $limit ) {
				$filesystem->delete( $root . '/' . $name, true );
			}
		}
	}

	/**
	 * Creates the storage folders and puts an empty index file in each.
	 *
	 * @return void
	 */
	public static function prepare_directories() {
		$filesystem = self::filesystem();
		$root       = self::root_dir();

		foreach ( array( $root, $root . '/pending', $root . '/orders' ) as $dir ) {
			wp_mkdir_p( $dir );

			if ( $filesystem && ! $filesystem->exists( $dir . '/index.php' ) ) {
				$filesystem->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			}
		}
	}

	/**
	 * Checks the format of a design ID.
	 *
	 * @param string $id Value to test.
	 * @return bool
	 */
	public static function is_valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $id );
	}

	/**
	 * Finds the folder group of a design.
	 *
	 * @param string $id Design ID.
	 * @return string|null "orders", "pending" or null when it does not exist.
	 */
	private static function locate( $id ) {
		if ( ! self::is_valid_id( $id ) ) {
			return null;
		}

		foreach ( array( 'orders', 'pending' ) as $group ) {
			if ( is_dir( self::dir( $id, $group ) ) ) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Absolute path of the storage root.
	 *
	 * @return string
	 */
	private static function root_dir() {
		$uploads = wp_upload_dir();

		return untrailingslashit( $uploads['basedir'] ) . '/' . self::ROOT;
	}

	/**
	 * Absolute path of a design folder.
	 *
	 * @param string $id    Design ID.
	 * @param string $group "pending" or "orders".
	 * @return string
	 */
	private static function dir( $id, $group ) {
		return self::root_dir() . '/' . $group . '/' . $id;
	}

	/**
	 * Writes a pending design file with the WordPress upload API.
	 *
	 * @param string $id    Design ID.
	 * @param string $name  File name.
	 * @param string $bytes PNG data.
	 * @return bool
	 */
	private static function write( $id, $name, $bytes ) {
		$redirect = static function ( $dirs ) use ( $id ) {
			$subdir = '/' . Designs::ROOT . '/pending/' . $id;

			$dirs['subdir'] = $subdir;
			$dirs['path']   = $dirs['basedir'] . $subdir;
			$dirs['url']    = $dirs['baseurl'] . $subdir;

			return $dirs;
		};

		add_filter( 'upload_dir', $redirect );
		$result = wp_upload_bits( $name, null, $bytes );
		remove_filter( 'upload_dir', $redirect );

		return empty( $result['error'] );
	}

	/**
	 * Decodes and checks a PNG data URL.
	 *
	 * @param string $data_url  Value sent by the browser.
	 * @param int    $max_bytes Largest accepted file size.
	 * @param int    $max_side  Largest accepted width or height in pixels.
	 * @return string|\WP_Error Raw PNG data.
	 */
	private static function decode_png( $data_url, $max_bytes, $max_side ) {
		$prefix  = 'data:image/png;base64,';
		$invalid = new \WP_Error( 'lecouzpd_invalid_image', __( 'The design contains an invalid image.', 'lecouz-product-designer' ), array( 'status' => 400 ) );

		if ( 0 !== strpos( $data_url, $prefix ) ) {
			return $invalid;
		}

		$encoded = substr( $data_url, strlen( $prefix ) );

		if ( strlen( $encoded ) > ( $max_bytes * 4 / 3 ) + 4 ) {
			return new \WP_Error( 'lecouzpd_too_large', __( 'The design is too large.', 'lecouz-product-designer' ), array( 'status' => 413 ) );
		}

		// Decoding an image the visitor's browser drew, not hiding code.
		$bytes = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $bytes || '' === $bytes ) {
			return $invalid;
		}

		$info = getimagesizefromstring( $bytes );

		if ( ! $info || IMAGETYPE_PNG !== $info[2] || $info[0] < 1 || $info[1] < 1 || max( $info[0], $info[1] ) > $max_side ) {
			return $invalid;
		}

		return $bytes;
	}

	/**
	 * Limits how many designs one visitor can save per hour.
	 *
	 * @return true|\WP_Error
	 */
	private static function check_rate_limit() {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key     = 'lecouzpd_rl_' . md5( $address );
		$count   = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return new \WP_Error( 'lecouzpd_rate_limited', __( 'Too many designs saved. Please try again later.', 'lecouz-product-designer' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Returns the WordPress filesystem when it writes directly to disk.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		if ( empty( $wp_filesystem ) || 'direct' !== $wp_filesystem->method ) {
			return null;
		}

		return $wp_filesystem;
	}
}
