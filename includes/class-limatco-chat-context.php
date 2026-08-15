<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Construye el bloque de contexto a partir de una búsqueda dinámica en
 * WooCommerce (no de un catálogo estático). Se llama en cada mensaje,
 * con la categoría/keywords ya detectados por Limatco_Chat_Api::classify_query().
 */
class Limatco_Chat_Context {

	const MAX_PRODUCTS = 7;

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
	 * Ejecuta la búsqueda en WooCommerce y arma el texto de contexto a
	 * partir de la categoría/keywords detectados en el mensaje del usuario.
	 *
	 * Se agrega la búsqueda en cascada: categoría + keywords juntos no encuentran nada (combinación muy específica), reintenta solo con las keywords de la consulta del usuario y si tampoco encuentra nada, reintenta solo con la categoría, antes de no dar respuesta con algun producto.
	 *
	 * @param string $category_slug Slug de categoría (puede venir vacío).
	 * @param string $keywords      Texto libre de búsqueda (puede venir vacío).
	 * @return array{text:string,products:array} 'text' va al prompt de la IA;
	 *         'products' es la data (imagen/precio/stock/oferta) para las
	 *         tarjetas que el widget pinta debajo de la respuesta.
	 */
	public static function get_context_for_query( $category_slug, $keywords ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array(
				'text'     => 'WooCommerce no está activo en este sitio.',
				'products' => array(),
			);
		}

		// Cascada 1: categoría + keywords (específico)
		$products = self::search_products( $category_slug, $keywords );

		// Cascada 2: Reintenta solo con keywords.
		if ( empty( $products ) && ! empty( $category_slug ) && ! empty( $keywords ) ) {
			$products = self::search_products( '', $keywords );
		}

		// Cascada 3: Reintenta solo con la categoría (no keywords)
		if ( empty( $products ) && ! empty( $category_slug ) ) {
			$products = self::search_products( $category_slug, '' );
		}
		// Cascada 4: Búsqueda fallida
		if ( empty( $products ) ) {
			return array(
				'text'     => 'No se encontraron productos que calcen con esa búsqueda en nuestro catálogo, intenta detallando tu búsqueda.',
				'products' => array(),
			);
		}

		$lines          = array();
		$product_cards = array();
		foreach ( $products as $product ) {
			$lines[]         = self::format_product_line( $product );
			$product_cards[] = self::build_product_card_data( $product );
		}

		return array(
			'text'     => implode( "\n", $lines ),
			'products' => $product_cards,
		);
	}

	/** Ejecuta una única consulta a WooCommerce con la categoría/keywords dados (cualquiera de los dos puede venir vacío). */
	private static function search_products( $category_slug, $keywords ) {
		$args = array(
			'status' => 'publish',
			'limit'  => self::MAX_PRODUCTS,
		);

		if ( ! empty( $category_slug ) ) {
			$args['category'] = array( $category_slug );
		}

		if ( ! empty( $keywords ) ) {
			$args['s'] = $keywords;
		}

		return wc_get_products( $args );
	}

	/**
	 * Formatea un producto de WooCommerce como una línea de contexto.
	 */
	private static function format_product_line( $product ) {
		$name        = $product->get_name();
		$price       = html_entity_decode( wp_strip_all_tags( wc_price( $product->get_price() ) ), ENT_QUOTES, 'UTF-8' );
		$stock       = $product->is_in_stock() ? 'Disponible' : 'Sin stock';
		$long_desc   = wp_strip_all_tags( $product->get_description() );
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
		if ( ! empty( $long_desc ) ) {
			$parts[] = 'Descripción: ' . $long_desc;
		}
		$parts[] = 'Link: ' . $url;

		return implode( ' | ', $parts );
	}

	/**
	 * Arma la data (imagen, precio, stock, oferta) que el widget usa para
	 * pintar la tarjeta visual de cada producto debajo de la respuesta.
	 */
	private static function build_product_card_data( $product ) {
		$image_id  = $product->get_image_id();
		$image_url = $image_id
			? wp_get_attachment_image_url( $image_id, 'medium' )
			: wc_placeholder_img_src( 'medium' );

		$on_sale = $product->is_on_sale();

		$discount_percent = 0;
		if ( $on_sale ) {
			$regular = (float) $product->get_regular_price();
			$current = (float) $product->get_price();
			if ( $regular > 0 ) {
				$discount_percent = (int) round( ( ( $regular - $current ) / $regular ) * 100 );
			}
		}

		return array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'image'             => $image_url,
			'url'               => get_permalink( $product->get_id() ),
			// wc_price() devuelve el símbolo de moneda como entidad HTML (&#36;);
			// hay que decodificarla además de quitar las etiquetas, o queda "&#36;24.225" en pantalla.
			'price'             => html_entity_decode( wp_strip_all_tags( wc_price( $product->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
			'regular_price'     => $on_sale ? html_entity_decode( wp_strip_all_tags( wc_price( $product->get_regular_price() ) ), ENT_QUOTES, 'UTF-8' ) : '',
			'on_sale'           => $on_sale,
			'discount_percent'  => $discount_percent,
			'in_stock'          => $product->is_in_stock(),
			'stock_text'        => $product->is_in_stock() ? 'Disponible' : 'Sin stock',
		);
	}
}
