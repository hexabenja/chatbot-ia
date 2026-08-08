<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Construye el bloque de contexto a partir de una búsqueda dinámica en
 * WooCommerce (no de un catálogo estático). Se llama en cada mensaje,
 * con la categoría/keywords ya detectados con Limatco_Chat_Api::classify_query().
 */
class Limatco_Chat_Context {

	const MAX_PRODUCTS = 15;

	/**
	 * Devuelve la lista de categorías de producto disponibles (slug => nombre),
	 * usada para que el paso de clasificación elija entre categorías reales.
	 */
	public static function get_available_categories() {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}

		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[ $term->slug ] = $term->name;
		}
		return $categories;
	}

	/**
	 * Ejecuta la búsqueda en WooCommerce y arma el texto de contexto
	 * a partir de la categoría/keywords detectados en el mensaje del usuario.
	 *
	 * @param string $category_slug Slug de categoría (puede venir vacío).
	 * @param string $keywords      Texto libre de búsqueda (puede venir vacío).
	 * @return string Texto formateado listo para inyectar en el prompt.
	 */
	public static function get_context_for_query( $category_slug, $keywords ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 'WooCommerce no está activo en este sitio.';
		}

		$args = array(
			'status' => 'publish',
			'limit'  => self::MAX_PRODUCTS,
		);

		if ( ! empty( $category_slug ) ) {
			// 'category' acepta un array de slugs de categorías en wc_get_products
			$args['category'] = array( sanitize_text_field( $category_slug ) );
		}

		if ( ! empty( $keywords ) ) {
			$args['s'] = sanitize_text_field( $keywords );
		}

		$products = wc_get_products( $args );

		if ( empty( $products ) ) {
			return 'No se encontraron productos que calcen con esa búsqueda en el catálogo.';
		}

		$lines = array();
		foreach ( $products as $product ) {
			if ( is_a( $product, 'WC_Product' ) ) {
				$lines[] = self::format_product_line( $product );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Formatea un producto de WooCommerce como una línea de contexto.
	 */
	private static function format_product_line( $product ) {
		$name        = $product->get_name();
		$price       = wp_strip_all_tags( wc_price( $product->get_price() ) );
		$stock       = $product->is_in_stock() ? 'Disponible' : 'Sin stock';
		$short_desc  = wp_strip_all_tags( $product->get_short_description() );
		$sku         = $product->get_sku();
		$url         = get_permalink( $product->get_id() );

		$parts = array(
			'- ' . $name,
			'Precio: ' . $price,
			$stock,
		);

		if ( ! empty( $sku ) ) {
			$parts[] = 'SKU: ' . $sku;
		} 
		// Gran parte de los productos  no tienen shortdescription asi que en Plan B que la IA sustiya short por description
		if ( ! empty( $short_desc ) ) {
			$parts[] = 'Descripción: ' . $short_desc; 
		}
		// Le agrega enlace al Link del producto
		$parts[] = 'Link: ' . $url;

		return implode( ' | ', $parts );
	}
}