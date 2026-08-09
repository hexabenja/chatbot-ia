<?php
/**
 * Plugin Name: Limatco AI Chat
 * Description: Popup de chat con modelo de IA que responde preguntas basándose únicamente en el catálogo/información de Limatco.
 * Version: 1.2
 * Author: Limatco
 * Text Domain: limatco-ai-chat
 * Notas de última versión, se modificó para que usase datos del wc_get_products e implementar API de DeepsSeek usando el SDK de Anthropic
 *  * 0.3.0: Se corrigió una mala práctica y temas de endpoint post
 * 0.3.1: Pequeños arreglos
 * 0.4.0: Arreglos menores con el endpoint
 * 0.5.0: se removió el wp-nonce para diagnosticos
*/


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salida directa no permitida.
}

define( 'LAC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LAC_VERSION', '0.2.0' );

require_once LAC_PLUGIN_DIR . 'includes/class-limatco-chat-admin.php';
require_once LAC_PLUGIN_DIR . 'includes/class-limatco-chat-api.php';
require_once LAC_PLUGIN_DIR . 'includes/class-limatco-chat-context.php';

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
			'restUrl'      => esc_url_raw( rest_url( 'limatco-chat/v1/message' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'welcomeText'  => get_option( 'lac_welcome_text', '¡Hola! ¿En qué te puedo ayudar?' ),
			'placeholder'  => get_option( 'lac_placeholder_text', 'Escribe tu pregunta…' ),
			'buttonLabel'  => get_option( 'lac_button_label', 'Chat' ),
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
	include LAC_PLUGIN_DIR . 'includes/widget-markup.php';
}
add_action( 'wp_footer', 'lac_render_widget_markup' );
