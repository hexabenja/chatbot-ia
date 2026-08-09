<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Endpoint REST: classify_query() detecta categoría/keywords, Limatco_Chat_Context busca en WooCommerce, y call_gemini_api() responde usando solo esos productos como contexto. Se usa el formato "messages"/"choices". La API key nunca se expone al navegador. */
class Limatco_Chat_Api {

	const NAMESPACE_ROUTE = 'limatco-chat/v1';
	// Endpoint Gemini compatible con OpenAI: mismo formato de "messages" y respuesta en choices[0].message.content.
	const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';

	// Instrucciones de formato para la respuesta final (no para la clasificación). Se agregan al prompt de sistema junto con el contexto de productos.
	const RESPONSE_FORMAT_INSTRUCTIONS = "Cuando muestres varios productos, agrúpalos por marca usando un encabezado en negrita por marca (**Nombre marca**), y lista cada producto como viñeta con el formato: [Nombre del producto](link) — breve descripción de uso. Usa Markdown (encabezados, listas y links) para que la respuesta sea fácil de leer. No repitas mecánicamente todos los campos del producto (precio, SKU, stock) en cada viñeta; menciona precio o stock solo si el usuario los pidió o si es relevante para la recomendación. Si solo hay uno o dos productos relevantes, responde en prosa natural en vez de forzar una lista.";

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_message' ),
				'permission_callback' => '__return_true', // Chat público
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'history' => array(
						'required' => false,
						'type'     => 'array',
					),
				),
			)
		);

		// El HTML de la página al cachearse ya sea por navegador, plugin o Cloudafare queda con el mismo WP-nonce, este código entrega un nonce nuevo en cada request.
		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_nonce' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle_nonce() {
		$response = new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
		// Evita que Cloudflare y navegadores cacheen el WP-Nonce.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	public function handle_message( WP_REST_Request $request ) {

		if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			error_log( 'Revisar si hay algún plugin de Caché, Cloudfare o modo administrador de WP activo' );
			return new WP_REST_Response( array( 'error' => 'Nonce inválido o expirado, recargue la página' ), 403 );
		}

		$rate_limit_error = $this->check_rate_limit();
		if ( is_wp_error( $rate_limit_error ) ) {
			error_log( 'Error 429' );
			return new WP_REST_Response( array( 'error' => $rate_limit_error->get_error_message() ), 429 );
		}

		$user_message = trim( $request->get_param( 'message' ) );
		if ( empty( $user_message ) ) {
			error_log( 'Error 400' );
			return new WP_REST_Response( array( 'error' => 'Mensaje vacío, escriba su consulta para que le podamos ayudar' ), 400 );
		}

		$history = $request->get_param( 'history' );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$api_key = get_option( 'lac_api_key', '' );
		if ( empty( $api_key ) ) {
			error_log( 'No hay API configurada en el sistema' );
			return new WP_REST_Response( array( 'error' => 'Espere un momento y recargue la página' ), 500 );
		}

		$model = get_option( 'lac_model', 'gemini-2.0-flash' );

		// 1.- Clasifica la consulta (categoría + keywords).
		$classification = $this->classify_query( $api_key, $model, $user_message );
		if ( is_wp_error( $classification ) ) {
			// Si falla la clasificación, seguimos igual pero sin filtro de categoría.
			$classification = array( 'category' => '', 'keywords' => $user_message );
		}

		// 2.- Buscar en WooCommerce con esa categoría/keywords. IMPLEMENTAR POSTERIOR A BUSCADOR DE SITIO WEB (EJ: PORCELANATOS)
		$context = Limatco_Chat_Context::get_context_for_query(
			$classification['category'],
			$classification['keywords']
		);

		// 3.- Responder usando SOLO esos productos como contexto, con instrucciones de formato para que la respuesta sea legible (headers, listas, links) en vez de un volcado rígido de campos.
		$system_prompt = get_option( 'lac_system_prompt', '' );
		$full_system   = $system_prompt . "\n\n" . self::RESPONSE_FORMAT_INSTRUCTIONS . "\n\n--- Acorde a su consulta:\n" . $context;

		$messages = $this->build_messages( $history, $user_message );
		$response = $this->call_gemini_api( $api_key, $model, $full_system, $messages, 1200 );

		if ( is_wp_error( $response ) ) {
			error_log( 'Error 502 posterior a la consulta del usuario, revisar api, modelo y o mensaje' );
			return new WP_REST_Response( array( 'error' => 'Error al intentar crear una respuesta, vuelva a intentar en unos momentos,' ), 502 );
		}

		return new WP_REST_Response( array( 'reply' => $this->markdown_to_html( $response ) ), 200 );
	}

	/** Paso 1: llamada rápida y barata que le pide al modelo devolver SOLO un JSON con la categoría (de las categorías reales de WooCommerce) y las keywords de búsqueda a partir del mensaje. @return array{category:string,keywords:string}|WP_Error */
	private function classify_query( $api_key, $model, $user_message ) {
		$categories = Limatco_Chat_Context::get_available_categories();
		$category_list = ! empty( $categories ) ? implode( ', ', array_values( $categories ) ) : '(sin categorías registradas)';

		$system = "Eres un clasificador. Dado un mensaje de un usuario sobre productos de construcción, "
			. "responde SOLO con un JSON válido, sin texto adicional, con este formato exacto:\n"
			. '{"category": "<una de: ' . $category_list . ' o vacío si no aplica>", "keywords": "<palabras clave de búsqueda, 2-5 palabras>"}';

		$messages = array(
			array( 'role' => 'user', 'content' => $user_message ),
		);

		$raw = $this->call_gemini_api( $api_key, $model, $system, $messages, 150 );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$json = json_decode( trim( $raw ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'lac_classify_parse_error', 'No se pudo interpretar la clasificación.' );
		}

		$category_name = isset( $json['category'] ) ? sanitize_text_field( $json['category'] ) : '';
		$keywords      = isset( $json['keywords'] ) ? sanitize_text_field( $json['keywords'] ) : $user_message;

		// El modelo devuelve el NOMBRE de la categoría; lo convertimos a slug real.
		$slug = array_search( $category_name, $categories, true );

		return array(
			'category' => $slug ? $slug : '',
			'keywords' => $keywords,
		);
	}

	/** Se exigen roles alternados (user, assistant, ...), que el primer mensaje sea "user" y que ningún content venga vacío (Gemini es más tolerante que Anthropic con esto, pero el historial del widget no lo garantiza), así que se normaliza aquí antes de enviarlo. */
	private function build_messages( $history, $user_message ) {
		$raw = array();

		$max_turns = 10;
		$history   = array_slice( $history, -1 * $max_turns * 2 );

		foreach ( $history as $turn ) {
			if ( empty( $turn['role'] ) || empty( $turn['content'] ) ) {
				continue;
			}
			$content = trim( sanitize_text_field( $turn['content'] ) );
			if ( '' === $content ) {
				continue;
			}
			$role  = ( 'assistant' === $turn['role'] ) ? 'assistant' : 'user';
			$raw[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		// Debe empezar en "user": si el primer turno guardado es del bot (ej. saludo inicial), lo descartamos.
		while ( ! empty( $raw ) && 'assistant' === $raw[0]['role'] ) {
			array_shift( $raw );
		}

		// Colapsar turnos consecutivos del mismo rol (evita el 400 de la API si el front-end duplica un mensaje).
		$messages = array();
		foreach ( $raw as $turn ) {
			$last_index = count( $messages ) - 1;
			if ( $last_index >= 0 && $messages[ $last_index ]['role'] === $turn['role'] ) {
				$messages[ $last_index ]['content'] .= "\n" . $turn['content'];
				continue;
			}
			$messages[] = $turn;
		}

		// El mensaje nuevo del usuario nunca debe quedar duplicado ni pegado a otro turno "user" sin alternar.
		$last_index = count( $messages ) - 1;
		if ( $last_index >= 0 && 'user' === $messages[ $last_index ]['role'] ) {
			$messages[ $last_index ]['content'] .= "\n" . $user_message;
		} else {
			$messages[] = array(
				'role'    => 'user',
				'content' => $user_message,
			);
		}

		return $messages;
	}

	/** Convierte carácteres de Markdown (negrita, links, listas) a HTML. */
	private function markdown_to_html( $text ) {
	$html = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	$html = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $html );
	$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );

	$lines   = explode( "\n", $html );
	$out     = array();
	$in_list = false;

	foreach ( $lines as $line ) {
		if ( preg_match( '/^[-*]\s+(.*)/', $line, $m ) ) {
			if ( ! $in_list ) {
				$out[]   = '<ul>';
				$in_list = true;
			}
			$out[] = '<li>' . $m[1] . '</li>';
		} else {
			if ( $in_list ) {
				$out[]   = '</ul>';
				$in_list = false;
			}
			$out[] = ( '' === trim( $line ) ) ? '' : '<p>' . $line . '</p>';
		}
	}
	if ( $in_list ) {
		$out[] = '</ul>';
	}

	return implode( '', $out );
}
	/** Llamada genérica a la API de Gemini (endpoint OpenAI-compatible), reutilizada para clasificar (paso 1) y responder (paso 3). A diferencia de Anthropic (system aparte), aquí el prompt de sistema va como un mensaje más dentro de "messages", con role:"system" al principio. */
	private function call_gemini_api( $api_key, $model, $system_prompt, $messages, $max_tokens ) {
		$full_messages = array();
		if ( '' !== trim( (string) $system_prompt ) ) {
			$full_messages[] = array(
				'role'    => 'system',
				'content' => $system_prompt,
			);
		}
		foreach ( $messages as $message ) {
			$full_messages[] = $message;
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'messages'   => $full_messages,
		);

		$response = wp_remote_post(
			self::GEMINI_ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'content-type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				// Con historial largo + respuesta de 1200 tokens, 30s puede no alcanzar.
				'timeout' => 45,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Error desconocido al llamar a la API.';
			return new WP_Error( 'lac_api_error', $message );
		}

		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';

		if ( empty( $text ) ) {
			return new WP_Error( 'lac_empty_reply', 'La API no devolvió texto.' );
		}

		return $text;
	}

	private function check_rate_limit() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'lac_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= 20 ) {
			return new WP_Error( 'lac_rate_limited', 'Demasiadas consultas, intenta de nuevo en un momento.' );
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
