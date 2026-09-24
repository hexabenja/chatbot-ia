<?php
/**
 * Plugin Name: Limatco AI Chat
 * Description: Popup de chat con modelo de IA que responde preguntas basándose únicamente en el catálogo/información de Limatco.
 * Version: 1.3
 * Author: Limatco
 * Text Domain: limatco-ai-chat
 * Notas de última versión, se modificó para que usase datos del wc_get_products e implementar API de DeepsSeek usando el SDK de Anthropic
 *  * 0.3.0: Se corrigió una mala práctica y temas de endpoint post
 * 0.3.1: Pequeños arreglos
 * 0.4.0: Arreglos menores con el endpoint
 * 0.5.0: se removió el wp-nonce para diagnosticos
 * 0.6.0: tarjetas de producto (imagen, precio, stock, oferta) debajo de la respuesta
 * 0.6.1: el clasificador ahora usa el historial reciente (respuestas de seguimiento ya no quedan sin resultados)
 * 0.6.2: precio decodificado (&#36; -> $), tarjeta rediseñada con recuadro de oferta y % de descuento, clasificador ignora respuestas vagas ("todas las alternativas", "tonos neutros") y reusa el tema ya buscado
 * 0.6.3: el texto de la respuesta ya no repite el listado de productos (las tarjetas se bastan solas); orden de resultados aleatorizado con shuffle() en PHP (no ORDER BY RAND()) para no favorecer siempre la misma marca
 * 0.6.4: búsqueda por término individual en OR (antes exigía la frase completa en AND) + fallback plural->singular; el clasificador mapea dormitorio/living/cocina/baño->interior y terraza/patio->exterior, que sí están en las descripciones
 * 0.6.5: fix: saludos ("hola") ya no traían productos al azar (faltaba recortar a MAX_PRODUCTS en el camino de keywords vacías); el clasificador ahora decide needs_search y se salta la búsqueda por completo si el mensaje no es sobre productos
 * 0.6.6: la tarjeta muestra la marca (taxonomía product_brand de WooCommerce) arriba del nombre, 9px, mayúscula
 * 0.6.7: respuestas fijas (sin pasar por la IA) para contacto telefónico y listado de sucursales; el badge "Disponible" ya no se muestra en la tarjeta (solo "Sin stock" cuando corresponde)
 * 0.6.8: orderby=rand en la query de cada término de búsqueda (no solo shuffle() en PHP después): el pool de candidatos ahora es una muestra al azar de TODOS los productos que calzan, no siempre el mismo top-30 por fecha
 * 0.6.9: la respuesta de sucursales ya no es un texto fijo; ahora es contexto (dirección, teléfonos, horarios) que se inyecta solo en preguntas de sucursales, y la IA responde específicamente a lo preguntado
 * 0.7.0: detección de "oferta/rebaja/descuento/remate" (solo productos en oferta) y "económico/barato" (orden de menor a mayor precio, unidad base m² o precio regular); toggle admin "no buscar productos con stock menor a 20"
 * 0.7.1: "restringir a una sola página" ahora es una lista de rutas/URLs editable (ej. /producto, / , /carrito) en vez de un dropdown de una sola página; /producto calza también con /producto/nombre-del-producto/
 * 0.7.2: la tarjeta del chat sigue mostrando el precio m² como referencia, pero el carro ya no se sobrescribe con ese precio: se desactivó el hook woocommerce_before_calculate_totals que igualaba el precio del carro al de m², así que al agregar al carro se ve el precio normal (caja)
 * 0.7.3: nuevo panel "Registro de errores" en los ajustes del plugin (errores de API/Gemini, clasificador, nonce, rate limit, WooCommerce y agregar al carrito), rotativo de 100 entradas, con agrupación de repetidos y botón para vaciarlo
*/


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salida directa no permitida.
}

define( 'LAC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LAC_VERSION', '0.5.3' );

require_once LAC_PLUGIN_DIR . 'includes/class-limatco-chat-admin.php';
require_once LAC_PLUGIN_DIR . 'includes/class-limatco-chat-api.php';
require_once LAC_PLUGIN_DIR . 'includes/class-limatco-chat-context.php';

/**
 * Lee "lac_allowed_paths" (una ruta por línea, ej. /producto, /, /carrito) y la
 * normaliza: slash inicial, sin slash final (salvo la raíz "/"), minúsculas.
 * Vacío = sin restricción (se muestra en todo el sitio, comportamiento anterior).
 *
 * @return string[] Lista de rutas normalizadas.
 */
function lac_get_allowed_paths() {
	$raw = trim( (string) get_option( 'lac_allowed_paths', '' ) );
	if ( '' === $raw ) {
		return array();
	}

	$paths = array();
	foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$path = '/' . ltrim( $line, '/' );
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		$paths[] = strtolower( $path );
	}

	return array_unique( $paths );
}

/**
 * true si la URL actual calza con alguna ruta de "lac_allowed_paths" (o si la
 * lista está vacía, sin restricción). "/producto" calza con "/producto" y con
 * cualquier ruta hija ("/producto/nombre-del-producto/"); "/" solo calza con
 * la raíz exacta del sitio.
 */
function lac_current_path_is_allowed() {
	$allowed = lac_get_allowed_paths();
	if ( empty( $allowed ) ) {
		return true;
	}

	$current = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '/';
	$current = '/' . ltrim( (string) $current, '/' );
	if ( '/' !== $current ) {
		$current = rtrim( $current, '/' );
	}
	$current = strtolower( $current );

	foreach ( $allowed as $path ) {
		if ( '/' === $path ) {
			if ( '/' === $current ) {
				return true;
			}
			continue;
		}
		if ( $current === $path || 0 === strpos( $current, $path . '/' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Inicializa el plugin.
 */
function lac_init() {
	new Limatco_Chat_Admin();
	new Limatco_Chat_Api();
}
add_action( 'plugins_loaded', 'lac_init' );

/**
 * Encola CSS/JS del widget solo en el frontend.
 */
function lac_enqueue_assets() {
	// No cargar si falta la API key (evita mostrar un chat que no funciona).
	$api_key = get_option( 'lac_api_key', '' );
	if ( empty( $api_key ) ) {
		return;
	}
	
	// Gate global: chatbot desactivado desde el panel.
	if ( ! get_option( 'lac_enabled', 1 ) ) {
		return;
	}

	// Gate de rutas: si hay rutas específicas configuradas, solo cargar ahí.
	if ( ! lac_current_path_is_allowed() ) {
		return;
	}

	wp_enqueue_style(
		'lac-chat-widget',
		LAC_PLUGIN_URL . 'assets/css/chat-widget.css',
		array(),
		LAC_VERSION
	);

	wp_enqueue_script(
		'lac-chat-widget',
		LAC_PLUGIN_URL . 'assets/js/chat-widget.js',
		array(),
		LAC_VERSION,
		true
	);

	wp_localize_script(
		'lac-chat-widget',
		'lacChatConfig',
		array(
			'restUrl'        => esc_url_raw( rest_url( 'limatco-chat/v1/message' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'welcomeText'    => get_option( 'lac_welcome_text', '¡Hola! ¿En qué te puedo ayudar?' ),
			'placeholder'    => get_option( 'lac_placeholder_text', 'Escribe tu pregunta…' ),
			'buttonLabel'    => get_option( 'lac_button_label', 'Chat' ),
			'userLoggedIn'   => is_user_logged_in(),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'lac_enqueue_assets' );

/**
 * Inyecta el HTML del popup en el footer.
 */
function lac_render_widget_markup() {
	$api_key = get_option( 'lac_api_key', '' );
	if ( empty( $api_key ) ) {
		return;
	}
	if ( ! get_option( 'lac_enabled', 1 ) ) {
		return;
	}
	if ( ! lac_current_path_is_allowed() ) {
		return;
	}
	include LAC_PLUGIN_DIR . 'includes/widget-markup.php';
}
add_action( 'wp_footer', 'lac_render_widget_markup' );

/**
 * si unidad_stock = "M2", usa get_m2_price_info()
 * (precio unidad base, o precio de oferta = precio_oferta_caja / rendimiento_m2_por_caja
 * Si es "C/U" no se toca nada — queda el $product->get_price()
 *
 * DESACTIVADO: lac_apply_before_calculate_totals haciendo que aparezca precio de venta de la caja, 
 * por si se necesita reactivar está la función de precio caja/m2. AUNQUE NO SE DEBA.
 */
function lac_apply_m2_price_to_cart( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		$m2_info = Limatco_Chat_Context::get_m2_price_info( $cart_item['data'] );
		if ( null !== $m2_info ) {
			$cart_item['data']->set_price( $m2_info['price'] );
		}
	}
}
