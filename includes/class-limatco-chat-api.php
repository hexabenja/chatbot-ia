<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint REST que el widget consume. Flujo por cada mensaje:
 *  1) classify_query()   -> el modelo detecta categoría + keywords de búsqueda
 *  2) Limatco_Chat_Context -> busca en WooCommerce con esa categoría/keywords
 *  3) call_anthropic_api() -> respuesta final, usando solo esos productos como contexto
 *
 * Usa DeepSeek V4 a través de su endpoint compatible con el formato de
 * mensajes de Anthropic (mismo body/headers, solo cambia el host).
 * La API key nunca se expone al navegador: todo pasa por aquí (server-side).
 */
class Limatco_Chat_Api {

	const NAMESPACE_ROUTE = 'limatco-chat/v1';
	const ANTHROPIC_ENDPOINT = 'https://api.deepseek.com/anthropic/v1/messages';
	const ANTHROPIC_VERSION  = '2023-06-01';

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
				'permission_callback' => '__return_true', // Público: es un chat de cara al visitante.
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
	}

	public function handle_message( WP_REST_Request $request ) {

		// El nonce de WP dependía de cookies de sesión y de que el HTML no
		// estuviera cacheado (Cloudflare, plugins de caché), lo que lo hacía
		// fallar constantemente para visitantes anónimos reales. Como esta
		// ruta ya es pública (permission_callback __return_true), en vez de
		// nonce se valida que la petición venga del propio dominio del sitio.
		if ( ! $this->request_is_same_origin( $request ) ) {
			return new WP_REST_Response( array( 'error' => 'Origen no permitido.' ), 403 );
		}

		$rate_limit_error = $this->check_rate_limit();
		if ( is_wp_error( $rate_limit_error ) ) {
			return new WP_REST_Response( array( 'error' => $rate_limit_error->get_error_message() ), 429 );
		}

		$user_message = trim( $request->get_param( 'message' ) );
		if ( empty( $user_message ) ) {
			return new WP_REST_Response( array( 'error' => 'Mensaje vacío.' ), 400 );
		}

		$history = $request->get_param( 'history' );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		// Debe ser una API key de DeepSeek (Platform → API Keys en
		// platform.deepseek.com), no de Anthropic — este endpoint usa el
		// modo de compatibilidad de DeepSeek con el formato de Anthropic.
		$api_key = get_option( 'lac_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_REST_Response( array( 'error' => 'El plugin no está configurado (falta API key).' ), 500 );
		}

		$model = get_option( 'lac_model', 'deepseek-v4-flash' );
		// Paso 1 es una llamada corta y barata (clasificar categoría/keywords).
		// V4 Flash ya es el modelo rápido/económico de DeepSeek, así que se
		// reusa el mismo para ambos pasos (a diferencia de Claude, donde
		// convenía separar Haiku de Sonnet).
		$classify_model = get_option( 'lac_classify_model', 'deepseek-v4-flash' );

		// Paso 1: clasificar la consulta (categoría + keywords).
		$classification = $this->classify_query( $api_key, $classify_model, $user_message );
		if ( is_wp_error( $classification ) ) {
			// Si falla la clasificación, seguimos igual pero sin filtro de categoría.
			$classification = array( 'category' => '', 'keywords' => $user_message );
		}

		// Paso 2: buscar en WooCommerce con esa categoría/keywords.
		$context = Limatco_Chat_Context::get_context_for_query(
			$classification['category'],
			$classification['keywords']
		);

		// Paso 3: responder usando SOLO esos productos como contexto.
		$system_prompt = get_option( 'lac_system_prompt', '' );
		$full_system   = $system_prompt . "\n\n--- PRODUCTOS ENCONTRADOS PARA ESTA CONSULTA ---\n" . $context;

		$messages = $this->build_messages( $history, $user_message );
		$response = $this->call_anthropic_api( $api_key, $model, $full_system, $messages, 800 );

		if ( is_wp_error( $response ) ) {
			// 502 solo cuando de verdad no se pudo conectar (timeout, DNS,
			// etc.). Si DeepSeek respondió pero con error (401, 402, 429...),
			// eso no es "Bad Gateway": es 500 con mensaje genérico para no
			// exponer detalles internos de la API al navegador.
			$status = ( 'lac_network_error' === $response->get_error_code() ) ? 502 : 500;
			return new WP_REST_Response(
				array( 'error' => 'El asistente no está disponible en este momento, intenta de nuevo en un momento.' ),
				$status
			);
		}

		return new WP_REST_Response( array( 'reply' => $response ), 200 );
	}

	/**
	 * Paso 1: llamada rápida y barata (pocos tokens) que le pide al modelo
	 * devolver SOLO un JSON con la categoría (de las categorías reales de
	 * WooCommerce) y las palabras clave de búsqueda a partir del mensaje.
	 *
	 * @return array{category:string,keywords:string}|WP_Error
	 */
	private function classify_query( $api_key, $model, $user_message ) {
		$categories = Limatco_Chat_Context::get_available_categories();
		$category_list = ! empty( $categories ) ? implode( ', ', array_values( $categories ) ) : '(sin categorías registradas)';

		$system = "Eres un clasificador. Dado un mensaje de un usuario sobre productos de construcción, "
			. "responde SOLO con un JSON válido, sin texto adicional, con este formato exacto:\n"
			. '{"category": "<una de: ' . $category_list . ' o vacío si no aplica>", "keywords": "<palabras clave de búsqueda, 2-5 palabras>"}';

		$messages = array(
			array( 'role' => 'user', 'content' => $user_message ),
		);

		$raw = $this->call_anthropic_api( $api_key, $model, $system, $messages, 150 );

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

	/**
	 * La API de Anthropic exige roles estrictamente alternados
	 * (user, assistant, user, ...), que el primer mensaje sea "user" y que
	 * ningún content venga vacío. El historial que manda el widget no
	 * garantiza nada de eso (puede empezar en "assistant" por el saludo
	 * inicial del bot, o traer dos turnos del mismo rol seguidos por algún
	 * bug del front-end). Antes eso llegaba tal cual a la API, que respondía
	 * 400, y ese 400 terminaba mapeado como un 502 genérico acá. Ahora se
	 * normaliza antes de enviarlo.
	 */
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

		// Debe empezar en "user": si el primer turno guardado es del bot
		// (ej. saludo inicial), lo descartamos.
		while ( ! empty( $raw ) && 'assistant' === $raw[0]['role'] ) {
			array_shift( $raw );
		}

		// Colapsar turnos consecutivos del mismo rol (no debería pasar, pero
		// si el front-end duplica un mensaje, esto evita el 400 de la API).
		$messages = array();
		foreach ( $raw as $turn ) {
			$last_index = count( $messages ) - 1;
			if ( $last_index >= 0 && $messages[ $last_index ]['role'] === $turn['role'] ) {
				$messages[ $last_index ]['content'] .= "\n" . $turn['content'];
				continue;
			}
			$messages[] = $turn;
		}

		// El mensaje nuevo del usuario nunca debe quedar duplicado ni
		// pegado a otro turno "user" sin alternar.
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

	/**
	 * Llamada genérica a la API de Anthropic. Se reutiliza tanto para
	 * clasificar (paso 1) como para responder (paso 3).
	 */
	private function call_anthropic_api( $api_key, $model, $system_prompt, $messages, $max_tokens ) {
		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'system'     => $system_prompt,
			'messages'   => $messages,
		);

		$response = wp_remote_post(
			self::ANTHROPIC_ENDPOINT,
			array(
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => self::ANTHROPIC_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				// Con historial largo + respuesta de 800 tokens, 30s puede no
				// alcanzar (a diferencia de una prueba en Postman con un solo
				// mensaje corto).
				'timeout' => 45,
			)
		);

		if ( is_wp_error( $response ) ) {
			// SOLO PARA DEBUG: agrega define('LAC_DEBUG', true); en
			// wp-config.php para ver el motivo real en el error_log.
			if ( defined( 'LAC_DEBUG' ) && LAC_DEBUG ) {
				error_log( '[Limatco Chat] Falla de red/timeout: ' . $response->get_error_code() . ' - ' . $response->get_error_message() );
			}
			return new WP_Error( 'lac_network_error', $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Error desconocido al llamar a la API.';
			if ( defined( 'LAC_DEBUG' ) && LAC_DEBUG ) {
				error_log( '[Limatco Chat] DeepSeek respondió ' . $status . ': ' . wp_remote_retrieve_body( $response ) );
			}
			return new WP_Error( 'lac_api_error', $message );
		}

		$text = '';
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		if ( empty( $text ) ) {
			return new WP_Error( 'lac_empty_reply', 'La API no devolvió texto.' );
		}

		return $text;
	}

	/**
	 * Reemplaza al nonce como filtro liviano anti-abuso: confirma que la
	 * petición venga del propio dominio (via Origin u, si falta, Referer).
	 * No es autenticación fuerte -ambos headers los puede falsificar un
	 * script hecho a mano-, pero filtra el abuso casual sin depender de
	 * cookies de sesión ni de que el HTML no esté cacheado.
	 */
	private function request_is_same_origin( WP_REST_Request $request ) {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		$origin = $request->get_header( 'Origin' );
		if ( ! empty( $origin ) ) {
			return wp_parse_url( $origin, PHP_URL_HOST ) === $site_host;
		}

		$referer = $request->get_header( 'Referer' );
		if ( ! empty( $referer ) ) {
			return wp_parse_url( $referer, PHP_URL_HOST ) === $site_host;
		}

		// Sin Origin ni Referer (algunos navegadores/extensiones los
		// bloquean legítimamente): no se puede verificar, se deja pasar y
		// que el rate-limit por IP siga siendo la protección real.
		return true;
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
