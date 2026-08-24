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

	const MAX_PRODUCTS = 5;

	// Tamaño del pool de candidatos que se trae por cada término buscado, antes de
	// combinar/mezclar en PHP y recortar a MAX_PRODUCTS. COSIDERAR REMOVER EN POSTERIORES VERSIONES DEBIDO A QUE COMO YA ESTÁ ORDERY BY SE PODRÍA OPTIMIZAR MÁS EL TIEMPO DE RESPONSE.
	const SHUFFLE_POOL_SIZE = 30;

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
	 * Ejecuta la búsqueda en WooCommerce y arma el texto de contexto a partir de la categoría/keywords detectados en el mensaje del usuario.
	 * Búsqueda en cascada: categoría + keywords juntos no encuentran nada (combinación muy específica), reintenta solo con las keywords de la consulta del usuario y si tampoco encuentra nada, reintenta solo con la categoría, antes de no dar respuesta con algun producto.
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

	/**
	 * Busca en WooCommerce con la categoría/keywords dados
	 * Las keywords se parten en términos individuales y se buscan en OR (no como frase completa en AND): 
	 * WordPress exige que TODAS las palabras de '?s' aparezcan para que un producto califique, así que una frase
	 * de 2-3 palabras (ej. "interior alto tránsito") casi nunca calza completa aunque
	 * el producto sí cumpla con cada término por separado. Se prioriza a los productos
	 * que calzan con más términos, y se mezcla el resto para variar marcas.
	 */
	private static function search_products( $category_slug, $keywords ) {
		$keywords = trim( (string) $keywords );

		if ( '' === $keywords ) {
			$products = self::run_single_term_query( $category_slug, '' );
			if ( empty( $products ) ) {
				return $products;
			}
			// Mismo tratamiento que el resto: mezclar (variedad de marca) y recortar
			// a MAX_PRODUCTS. Sin esto, una categoría/keywords vacías devolvía hasta
			// SHUFFLE_POOL_SIZE productos sin filtrar (ej. el bug de "hola" -> 30+ productos).
			shuffle( $products );
			return array_slice( $products, 0, self::MAX_PRODUCTS );
		}

		$terms = self::expand_search_terms( $keywords );

		if ( empty( $terms ) ) {
			return self::run_single_term_query( $category_slug, $keywords );
		}

		$scored = array(); // id => array('product' => WC_Product, 'score' => int)

		foreach ( $terms as $term ) {
			$found = self::run_single_term_query( $category_slug, $term );
			foreach ( $found as $product ) {
				$id = $product->get_id();
				if ( ! isset( $scored[ $id ] ) ) {
					$scored[ $id ] = array(
						'product' => $product,
						'score'   => 0,
					);
				}
				$scored[ $id ]['score']++;
			}
		}

		if ( empty( $scored ) ) {
			return array();
		}

		$entries = array_values( $scored );
		// Se mezcla ANTES de ordenar por score para que los empates queden en orden
		// aleatorio (variedad de marca) en vez de siempre en el mismo orden.
		shuffle( $entries );
		usort(
			$entries,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$products = array_map(
			function ( $entry ) {
				return $entry['product'];
			},
			$entries
		);

		return array_slice( $products, 0, self::MAX_PRODUCTS );
	}

	/**
	 * Parte "interior alto tránsito" en ["interior", "alto", "tránsito"] y agrega,
	 * para cada palabra que termine en "s" (plural simple en español), la versión
	 * sin esa "s" como término adicional — así "cerámicas" (lo que suele escribir el
	 * usuario) también encuentra productos nombrados en singular ("Cerámica ...").
	 */
	private static function expand_search_terms( $keywords ) {
		$words = preg_split( '/\s+/', $keywords );
		$words = array_filter(
			$words,
			function ( $word ) {
				return mb_strlen( $word ) >= 2;
			}
		);

		$terms = array();
		foreach ( $words as $word ) {
			$terms[] = $word;
			if ( mb_strtolower( mb_substr( $word, -1 ) ) === 's' && mb_strlen( $word ) > 3 ) {
				$terms[] = mb_substr( $word, 0, -1 );
			}
		}

		return array_values( array_unique( $terms ) );
	}

	/** Ejecuta una única consulta a WooCommerce con la categoría/término dados (cualquiera de los dos puede venir vacío). */
	private static function run_single_term_query( $category_slug, $term ) {
		$args = array(
			'status'  => 'publish',
			'limit'   => self::SHUFFLE_POOL_SIZE,
			// 'orderby' => 'rand' aquí (no solo shuffle() después) es lo que de verdad varía
			// qué productos entran al pool: sin esto, WooCommerce siempre trae el mismo
			// top-N por fecha para un mismo término, y el shuffle() posterior solo mezcla
			// el ORDEN de ese mismo grupo fijo — por eso seguían saliendo los mismos productos.
			// Con ~300 productos en el catálogo, el costo de ORDER BY RAND() es despreciable;
			// si el catálogo crece a varios miles, esto habría que revisitarlo.
			'orderby' => 'rand',
		);

		if ( ! empty( $category_slug ) ) {
			$args['category'] = array( $category_slug );
		}

		if ( ! empty( $term ) ) {
			$args['s'] = $term;
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
			'brand'             => self::get_product_brand( $product ),
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

	/**
	 * Lee la marca desde la taxonomía de marca de WooCommerce (la "casilla de Marca"
	 * del producto), no desde la descripción. Se prueban varios slugs de taxonomía
	 * porque varía según cómo esté configurado el sitio:
	 * 'product_brand' es el feature nativo de WooCommerce (desde WC 8.3); 'pwb-brand'
	 * y 'yith_product_brand' son de plugins de marca comunes. Si en tu sitio la marca
	 * no aparece en la tarjeta, revisa en wp-admin → Productos → Marcas cuál es el slug
	 * real (aparece en la URL de esa pantalla) y avísame para ajustarlo.
	 */
	private static function get_product_brand( $product ) {
		$brand_taxonomies = array( 'product_brand', 'pwb-brand', 'yith_product_brand' );

		foreach ( $brand_taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $product->get_id(), $taxonomy );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				return $terms[0]->name;
			}
		}

		return '';
	}
}
