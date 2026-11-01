<?php
/**
 * Per-product designer settings.
 *
 * @package Lecouz_Product_Designer
 */

namespace Lecouz\ProductDesigner;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and stores the designer settings of a product.
 *
 * The settings are kept in three product meta values: an on/off flag, a
 * "design required" flag and a JSON document holding the base image and the
 * print areas. Area positions are fractions (0 to 1) of the base image.
 */
final class Config {

	const META_ENABLED  = '_lecouzpd_enabled';
	const META_REQUIRED = '_lecouzpd_required';
	const META_CONFIG   = '_lecouzpd_config';
	const MAX_AREAS     = 6;

	/**
	 * Returns the usable settings of a product, or null when it has no designer.
	 *
	 * @param int $product_id Product ID (a variation resolves to its parent).
	 * @return array|null
	 */
	public static function get( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( $product && $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}

		if ( ! $product || 'yes' !== $product->get_meta( self::META_ENABLED ) ) {
			return null;
		}

		$stored = json_decode( (string) $product->get_meta( self::META_CONFIG ), true );
		$stored = self::normalize( $stored );

		if ( empty( $stored['areas'] ) ) {
			return null;
		}

		$image_id = $stored['image_id'] ? $stored['image_id'] : $product->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_src( $image_id, 'large' ) : false;

		if ( ! $image ) {
			return null;
		}

		return array(
			'product_id' => $product->get_id(),
			'required'   => 'yes' === $product->get_meta( self::META_REQUIRED ),
			'image'      => array(
				'id'     => (int) $image_id,
				'url'    => $image[0],
				'width'  => (int) $image[1],
				'height' => (int) $image[2],
			),
			'areas'      => $stored['areas'],
		);
	}

	/**
	 * Cleans a decoded settings document.
	 *
	 * @param mixed $raw Decoded JSON, possibly from an untrusted source.
	 * @return array{image_id:int,areas:array}
	 */
	public static function normalize( $raw ) {
		$clean = array(
			'image_id' => 0,
			'areas'    => array(),
		);

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		if ( isset( $raw['image_id'] ) ) {
			$image_id = absint( $raw['image_id'] );

			if ( $image_id && wp_attachment_is_image( $image_id ) ) {
				$clean['image_id'] = $image_id;
			}
		}

		if ( empty( $raw['areas'] ) || ! is_array( $raw['areas'] ) ) {
			return $clean;
		}

		$seen = array();

		foreach ( $raw['areas'] as $area ) {
			if ( ! is_array( $area ) || count( $clean['areas'] ) >= self::MAX_AREAS ) {
				continue;
			}

			$id = isset( $area['id'] ) ? strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $area['id'] ) ) : '';
			$id = substr( $id, 0, 12 );

			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			$w = self::fraction( isset( $area['w'] ) ? $area['w'] : 0.4, 0.02 );
			$h = self::fraction( isset( $area['h'] ) ? $area['h'] : 0.4, 0.02 );
			$x = min( self::fraction( isset( $area['x'] ) ? $area['x'] : 0.3, 0 ), 1 - $w );
			$y = min( self::fraction( isset( $area['y'] ) ? $area['y'] : 0.3, 0 ), 1 - $h );

			$name = isset( $area['name'] ) ? sanitize_text_field( (string) $area['name'] ) : '';
			$name = mb_substr( $name, 0, 40 );

			$clean['areas'][] = array(
				'id'   => $id,
				'name' => '' !== $name ? $name : sprintf(
					/* translators: %d: print area number */
					__( 'Area %d', 'lecouz-product-designer' ),
					count( $clean['areas'] ) + 1
				),
				'x'    => $x,
				'y'    => $y,
				'w'    => $w,
				'h'    => $h,
				'w_mm' => self::millimetres( isset( $area['w_mm'] ) ? $area['w_mm'] : 0 ),
			);
		}

		return $clean;
	}

	/**
	 * Clamps a value to the 0-1 range.
	 *
	 * @param mixed $value Raw value.
	 * @param float $min   Smallest allowed value.
	 * @return float
	 */
	private static function fraction( $value, $min ) {
		return round( min( 1, max( $min, (float) $value ) ), 4 );
	}

	/**
	 * Keeps a physical size between 0 (unknown) and 5000 mm.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private static function millimetres( $value ) {
		return round( min( 5000, max( 0, (float) $value ) ), 1 );
	}
}
