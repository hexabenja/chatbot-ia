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

		// El HTML de la página se cachea (Cloudflare / plugin de caché), así
		// que un nonce incrustado en ese HTML queda "congelado" y termina
		// expirado para casi todos los visitantes. Esta ruta entrega un
		// nonce fresco en cada llamada, independiente del caché de la página.
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
		// Instruye a Cloudflare y a navegadores a no cachear esta respuesta,
		// para que siempre entregue un nonce vigente.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	public function handle_message( WP_REST_Request $request ) {

		if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			// Antes esto devolvía 200 con un "success" falso, lo que ocultaba
			// fallos de nonce (típico con caché de página) haciéndolos ver
			// como éxitos silenciosos sin respuesta. Ahora se reporta como error real.
			return new WP_REST_Response( array( 'error' => 'Nonce inválido o expirado, recarga la página.' ), 403 );
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

		$api_key = get_option( 'lac_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_REST_Response( array( 'error' => 'El plugin no está configurado (falta API key).' ), 500 );
		}

		$model = get_option( 'lac_model', 'deepseek-v4-flash' );

		// Paso 1: clasificar la consulta (categoría + keywords).
		$classification = $this->classify_query( $api_key, $model, $user_message );
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
			return new WP_REST_Response( array( 'error' => $response->get_error_message() ), 502 );
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
	 * La API de Anthropic/DeepSeek exige roles estrictamente alternados
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
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Error desconocido al llamar a la API.';
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
