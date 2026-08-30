<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Endpoint REST: classify_query() detecta categoría/keywords, Limatco_Chat_Context busca en WooCommerce, y call_gemini_api() responde usando solo esos productos como contexto. */
class Limatco_Chat_Api {

	const NAMESPACE_ROUTE = 'limatco-chat/v1';
	// Endpoint Gemini compatible con OpenAI: mismo formato de "messages" y respuesta en choices[0].message.content.
	const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';

	// Instrucciones de formato para la respuesta final (no para la clasificación). Se agregan al prompt de sistema junto con el contexto de productos.
	const RESPONSE_FORMAT_INSTRUCTIONS = "IMPORTANTE — esta regla de formato tiene prioridad sobre cualquier instrucción de listar/enlazar/agrupar productos que pueda venir en el prompt de sistema de arriba: cuando el contexto de abajo SÍ incluya productos encontrados, NO los listes ni los enumeres en tu respuesta (nada de viñetas, encabezados por marca, ni nombre/link/precio de cada uno): esos datos ya se muestran automáticamente como tarjetas visuales con imagen, precio y stock justo debajo de tu mensaje, así que repetirlos en texto es redundante. En ese caso responde en 1-3 frases, en prosa natural: resume brevemente qué encontraste (material, estilo, cuántas opciones) y, si corresponde, guía al usuario con una pregunta de seguimiento sobre su necesidad. Usa Markdown solo para énfasis simple (negrita), nunca para listas de productos ni links a productos. Si el contexto indica que NO se encontraron productos, explica eso con naturalidad y ofrece ayudar a acotar la búsqueda.";

	// Respuesta fija cuando el usuario quiere contactar a un ejecutivo/central de cotizaciones.
	const PHONE_REPLY = "Si deseas recibir ayuda con un ejecutivo, llama a este número, directo a nuestra central de cotizaciones: +56 2 2938 1410 [Haz clic para llamar a Central de Cotizaciones](tel:229381410)";

	// Contexto de sucursales (dirección, teléfonos, horarios). Se inyecta en el prompt
	// is_branches_query()); IA responde de forma natural y específica a lo que se le pregunte
	const BRANCHES_CONTEXT = "[Sucursal Independencia](https://limatco.cl/sucursal-independencia/) ubicada en: Coronel Agustín López de Alcázar 546, Independencia\nSala de Ventas\n+56 2 2637 5566\n+56 2 2637 5500\n+56 2 2716 4650\n+56 5 7276 9342\nSucursal de Central Cotizaciones\n+56 2 2938 1410\n[Llamar a Central de Cotizaciones](tel:56229381410)\nHorario de Atención\nLunes a Viernes 9:00 - 19:00 Hrs\nSábado 9:00 - 14:00 Hrs.\n\n[Sucursal Vespucio Sur](https://limatco.cl/sucursal-vespucio-sur/) ubicada en: Av. Américo Vespucio 4288\nVentas\n+56 2 2221 1030\n+56 2 2221 1656\nAtención a clientes\n+56 2 2221 2477\n+56 2 2711 7603\n+56 6 5275 8446\nHorario de Atención\nLunes a Viernes 09:00 - 19:00 Hrs.\nSábado 09:00 - 14:00 Hrs.\n\n[Sucursal Manquehue sur](https://limatco.cl/sucursal-manquehue-sur/) ubicada en: Manquehue Sur 676\nVentas\n+56 2 2342 2481\n+56 2 2298 5739\n+56 9 6407 2969\nHorario de Atención\nLunes a Viernes 10:00 - 18:30 Hrs.\nSábado 10:00 - 14:00 Hrs.\n\n[Sucursal San Miguel](https://limatco.cl/sucursal-san-miguel/) ubicada en: Gran Avenida 4559\nVentas\n+56 2 2324 5681\n+56 9 6520 2999\n+56 9 5333 4007\nHorario de Atención\nLunes a Viernes 10:00 - 18:30 Hrs.\nSábado 10:00 - 14:00 Hrs.\n\n[Sucursal Puente Alto](https://limatco.cl/sucursal-puente-alto/) ubicada en: Eyzaguirre 077, esquina Balmaceda\nVentas\n+56 2 2493 1506\n+56 9 8527 5859\nHorario de Atención\nLunes a Viernes 10:00 - 18:30 Hrs.\nSábado 10:00 - 14:00 Hrs.\n\n[Sucursal Maipú](https://limatco.cl/sucursal-maipu/) ubicada en: Libertador Gral. Bernardo O'Higgins 10 (esquina Pajaritos)\nVentas\n+56 2 2458 0935\n+56 2 2418 0613\n+56 9 7430 0982\n+56 9 6495 4936\nHorario de Atención\nLunes a Viernes 10:00 - 18:30 Hrs.\nSábado 10:00 - 14:00 Hrs.\n\n[Sucursal San Bernardo](https://limatco.cl/sucursal-san-bernardo/) ubicada en: Barros Arana 796\nVentas\n+56 2 2859 1103\n+56 9 8500 7033\n+56 5 7276 2920\nHorario de Atención\nLunes a Viernes 10:00 - 18:30 Hrs.\nSábado 10:00 - 14:00 Hrs.\n\n[Sucursal Las Condes](https://limatco.cl/sucursal-lascondes/) ubicada en: Av. Las Condes 12803, Centro Comercial Portal la Cabaña\nVentas\n+56 2 3280 0371\nAtención a clientes\n+56 2 3280 0391\nHorario de Atención\nLunes a Viernes 10:00 - 18:30 Hrs.\nSábado 10:00 - 14:00 Hrs.\n\n[Sucursal Talagante](https://limatco.cl/sucursal-talagante/) ubicada en: Av. Bernardo O'Higgins 0225 (referencia Volcán Llaima 799)\nVentas\n+56 2 2938 1377\n+56 9 6159 8807\n+56 9 6354 6171\nHorario de Atención\nLunes a Viernes 10:00 - 18:30 Hrs.\nSábado 10:00 - 14:00 Hrs.\n\n[Sucursal Chicureo](https://limatco.cl/sucursal-chicureo/) ubicada en: Carretera General San Martín 6000, Local 119\nVentas\n+56 2 2733 5911\n+56 2 2733 5910\nHorario de Atención\nLunes a Viernes 10:00 - 18:30 Hrs.\nSábado 10:00 - 14:00 Hrs.\n\n[Sucursal Padre Hurtado](https://limatco.cl/sucursal-padre-hurtado/) ubicada en: Calle San Ignacio N° 1624, Locales 18 y 19, Centro Comercial Laguna del Sol\nVentas\n+56 9 9634 6019\n+56 9 9733 1068\nHorario de Atención\nLunes a Viernes 10:00 - 18:30 Hrs.\nSábado 10:00 - 14:00 Hrs. Sólamente en Limatco Vespucio y Limatco Independencia está disponible el retiro inmediato, en las demás sucursales de Limatco el pedido está listo al día siguiente. ";

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/message', // composicion de mensajes en json
			array(
				'methods'             => 'POST', // evita que sea por get obligando a entrar al sitio web
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

		// Refresh de wp-nonce para evitar que se cachee
		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_nonce' ),
				'permission_callback' => '__return_true',
			)
		);

		// Botón "Agregar" de una tarjeta de producto del chat: agrega el producto al carrito de WooCommerce.
		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/add-to-cart',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_add_to_cart' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
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
			error_log(
			    'LIMATCO QUERY [' . current_time( 'mysql' ) . '] ' .
    			'Usuario: "' . $user_message . '" | ' .
    			'Búsqueda ejecutada: "' . $term . '"'
			);
		}

		$history = $request->get_param( 'history' );
		if ( ! is_array( $history ) ) {
			$history = array(); // 
		}

		// Respuesta fija para contacto telefónico/ejecutivo: Respuestas hardcodeadas
		// Las preguntas de sucursales, ($is_branches_query) sí ocupan tokens
		$hardcoded_reply = $this->check_hardcoded_reply( $user_message );
		if ( null !== $hardcoded_reply ) {
			return new WP_REST_Response(
				array(
					'reply'    => $this->markdown_to_html( $hardcoded_reply ),
					'products' => array(),
				),
				200
			);
		}

		$api_key = get_option( 'lac_api_key', '' );
		if ( empty( $api_key ) ) {
			error_log( 'No hay API configurada en el sistema' );
			return new WP_REST_Response( array( 'error' => 'Espere un momento y recargue la página' ), 500 );
		}

		$model = get_option( 'lac_model', 'gemini-2.0-flash' );

		// 1.- Clasifica la consulta (categoría + keywords + si corresponde buscar productos).
		// Se le pasa el historial para que pueda interpretar respuestas de
		// seguimiento (ej. el usuario responde "en dormitorio" a una pregunta
		// aclaratoria previa) en vez de clasificar el mensaje aislado.
		$classification = $this->classify_query( $api_key, $model, $user_message, $history );
		error_log(
    		'LIMATCO DEBUG - CLASSIFICATION: ' .
    		wp_json_encode( $classification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
		if ( is_wp_error( $classification ) ) {
			// Si falla la clasificación, seguimos igual pero sin filtro de categoría ni atributos.
			$classification = array(
				'category'          => '',
				'keywords'          => $user_message,
				'needs_search'      => true,
				'colores'           => array(),
				'single_color_only' => false,
				'atributos'         => array(),
			);
		}

		$is_branches_query = $this->is_branches_query( $user_message );

		// 2.- Buscar en WooCommerce con esa categoría/keywords/atributos SOLO si el mensaje es
		// realmente sobre productos. Si no (ej. "hola", "gracias", o una pregunta de
		// sucursales/horarios), evitamos la búsqueda: con categoría/keywords vacías la
		// cascada terminaba trayendo productos al azar del catálogo para un simple saludo.
		if ( ! empty( $classification['needs_search'] ) && ! $is_branches_query ) {
			// get_context_for_query() devuelve el texto para el prompt de la IA
			// y, aparte, la data (imagen/precio/stock/oferta) para las tarjetas del widget.
			$context_data = Limatco_Chat_Context::get_context_for_query(
				$classification['category'],
				$classification['keywords'],
				$classification['colores'],
				$classification['single_color_only'],
				$classification['atributos']
			);
		} else {
			$context_data = array(
				'text'     => 'El usuario no está buscando un producto en este mensaje (ej. saludo, agradecimiento, pregunta de sucursales/horarios u otro comentario). No muestres ni menciones productos; responde solo de forma natural a lo que dijo.',
				'products' => array(),
			);
		}

		// 3.- Responder usando SOLO esos productos como contexto, con instrucciones de formato para que la respuesta sea legible (headers, listas, links) en vez de un volcado rígido de campos.
		$system_prompt = get_option( 'lac_system_prompt', '' );
		$full_system   = $system_prompt . "\n\n" . self::RESPONSE_FORMAT_INSTRUCTIONS . "\n\n--- Acorde a su consulta:\n" . $context_data['text'];

		// Se agrega SOLO si la pregunta parece ser de sucursales/horarios/contacto, para no
		// gastar tokens de más en cada mensaje. La IA responde específicamente a lo que se
		// preguntó (ej. el horario de una sola sucursal) usando este contexto, no un texto fijo.
		if ( $is_branches_query ) {
			$full_system .= "\n\n--- Información de sucursales (dirección, teléfonos, horario). Responde solo con lo que se pregunte, no vuelques todo el listado salvo que el usuario pida ver todas las sucursales:\n" . self::BRANCHES_CONTEXT;
		}

		$messages = $this->build_messages( $history, $user_message );
		$response = $this->call_gemini_api( $api_key, $model, $full_system, $messages, 1200 );

		if ( is_wp_error( $response ) ) {
			error_log( 'Error 502 posterior a la consulta del usuario, revisar api, modelo y o mensaje' );
			return new WP_REST_Response( array( 'error' => 'Error al intentar crear una respuesta, vuelva a intentar en unos momentos,' ), 502 );
		}

		return new WP_REST_Response(
			array(
				'reply'    => $this->markdown_to_html( $response ),
				'products' => $context_data['products'],
			),
			200
		);
	}

	/** Recibe el product_id del botón "Agregar" de una tarjeta de producto en el chat y lo agrega al carrito de WooCommerce vía WC()->cart->add_to_cart(). */
	public function handle_add_to_cart( WP_REST_Request $request ) {

		if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			error_log( 'Revisar si hay algún plugin de Caché, Cloudfare o modo administrador de WP activo' );
			return new WP_REST_Response( array( 'error' => 'Nonce inválido o expirado, recargue la página' ), 403 );
		}

		if ( ! function_exists( 'wc_load_cart' ) || ! function_exists( 'wc_get_product' ) ) {
			error_log( 'WooCommerce no está activo, no se puede agregar al carrito' );
			return new WP_REST_Response( array( 'error' => 'WooCommerce no está activo en este sitio.' ), 500 );
		}

		// En una request REST el carrito/sesión de WooCommerce no siempre queda inicializado como en una visita normal al frontend.
		wc_load_cart();

		$product_id = (int) $request->get_param( 'product_id' );
		$product    = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return new WP_REST_Response( array( 'error' => 'Este producto ya no está disponible.' ), 400 );
		}

		$added = WC()->cart->add_to_cart( $product_id, 1 );

		if ( ! $added ) {
			error_log( "No se pudo agregar el producto {$product_id} al carrito desde el chat" );
			return new WP_REST_Response( array( 'error' => 'No se pudo agregar el producto al carrito.' ), 500 );
		}

		return new WP_REST_Response(
			array(
				'success'    => true,
				'cart_count' => WC()->cart->get_cart_contents_count(),
			),
			200
		);
	}

	/** Paso 1: llamada rápida y barata que le pide al modelo devolver SOLO un JSON con la categoría (de las categorías reales de WooCommerce), las keywords de búsqueda y si el mensaje amerita buscar productos (needs_search), a partir del mensaje. Recibe el historial reciente para poder interpretar respuestas de seguimiento (ej. "en dormitorio") que por sí solas no dicen qué producto se busca. @return array{category:string,keywords:string,needs_search:bool}|WP_Error */
	private function classify_query( $api_key, $model, $user_message, $history = array() ) {
		$categories = Limatco_Chat_Context::get_available_categories();
		$category_list = ! empty( $categories ) ? implode( ', ', array_values( $categories ) ) : '(sin categorías registradas)';

		$system = "Clasificador de mensajes sobre productos de construcción (Limatco). Analiza el MENSAJE MÁS RECIENTE "
			. "en el contexto de los turnos previos (puede ser respuesta a una aclaración, no consulta aislada). "
			. "Responde SOLO este JSON, sin texto extra:\n"
			. '{"category": "<una de: ' . $category_list . ' o vacío>", "keywords": "<2-5 palabras>", "needs_search": <true|false>, '
			. '"colores": [<lista de colores predominantes pedidos, ej ["blanco"] o ["blanco","gris"], vacío si no aplica>], '
			. '"single_color_only": <true SOLO si el usuario pidió explícitamente un color puro/sin combinar, si no false>, '
			. '"atributos": {"formato": "<ej. 60x60, o vacío>", "terminacion": "<ej. antideslizante/R10/R11, o vacío>", "estetica-o-diseno": "<ej. madera/madera tipo tabla/cemento/decorado/monocolor, o vacío>", "acabado": "<ej. mate/satinado/texturado, o vacío>", "cantos-o-bordes": "<ej. rectificado/encastre, o vacío>", "caras-o-destonalizado": "<vacío salvo que el usuario lo pida explícito>"}}' . "\n\n"
			. "needs_search=false SOLO si el mensaje no trata de productos/servicios Limatco (saludos, agradecimientos, despedidas, small talk, preguntas del bot). Cualquier búsqueda/pregunta/respuesta de seguimiento sobre producto, aunque sea vaga, => true. Si false: category, keywords, colores y atributos van vacíos.\n\n"
			. "Reglas de colores/atributos:\n"
			. "- 'colores' son SOLO los colores predominantes reales del producto pedido (ej. 'cerámica blanca' -> [\"blanco\"]); no confundir con ambiente/estilo.\n"
			. "- Si el usuario pide 2+ colores a la vez (ej. 'blanco y gris'), inclúyelos todos en la lista: el producto debe tener esa combinación completa.\n"
			. "- 'single_color_only' es true SOLO si el usuario dice explícitamente que sea de un solo color / puro / sin combinar / liso; en cualquier otro caso, false (aunque pida un solo color en la lista, igual puede combinar con otros a menos que lo pida expreso).\n"
			. "- 'antideslizante' o 'que no resbale' (baño, terraza, piscina, exterior en general) -> atributos.terminacion = 'antideslizante'. Si mencionan un código R explícito (R9-R13), respétalo tal cual.\n"
			. "- Si el usuario da una medida (ej. '19x57', '60x120'), va en atributos.formato tal cual la escribió.\n"
			. "- 'tipo tabla' NO significa simplemente 'madera'. En este catálogo, cuando el usuario pide 'tipo tabla', 'tabla' o 'madera tipo tabla', usa atributos.estetica-o-diseno = 'madera tipo tabla'. Si además pide una medida, conserva también atributos.formato.\n"
			. "- Si el usuario pide 'otras alternativas en otros formatos' pero mantiene 'tipo tabla', elimina SOLO el formato anterior y conserva estetica-o-diseno = 'madera tipo tabla'.\n"
			. "- Una medida explícita (60x60, 60x120, 30x30, etc.) es un filtro obligatorio y no debe convertirse en una keyword.\n"
			. "- No inventes ningún valor de atributo que el usuario no haya mencionado o insinuado con claridad; deja vacío si no aplica.\n\n"
			. "Reglas de keywords:\n"
			. "- Deben aparecer LITERAL en nombre/descripción del producto (se buscan por separado); solo términos que aporten.\n"
			. "- Ambiente (dormitorio/living/sala/pieza/comedor/cocina/baño) -> 'interior'. Exterior (terraza/patio/jardín/piscina) -> 'exterior'. Tránsito alto/comercial/local/negocio -> 'alto tránsito'. Nivel PEI explícito -> respétalo tal cual (ej. 'PEI 4').\n"
			. "- No inventes ni agregues color/tono/estilo como keyword (el color ya va en 'colores', la medida en formato y el diseño en estetica-o-diseno) salvo que el usuario haya dado un término muy específico y ya haya funcionado antes en la conversación.\n"
			. "- Mensaje vago/confirmación sin términos nuevos ('todas las alternativas','cualquiera','sí','muéstrame más','recomiéndame') -> ignóralo como keyword y usa el producto/ambiente ya buscado en turnos previos. Nunca devuelvas la frase vaga tal cual.\n"
			. "- Tono/estilo/diseño equivalen entre sí (ej. 'tono madera'='estilo madera'='diseño madera'): usa el término del cliente. 'Hidráulicos' = 'Decorados'.";


		// Últimos turnos de mensajes alcanzan para resolver respuestas de seguimiento.
		$recent_history = array_slice( $history, -6 ); // 6 mensajes hacia atrás

		$messages = array();
		foreach ( $recent_history as $turn ) {
			if ( empty( $turn['role'] ) || empty( $turn['content'] ) ) {
				continue;
			}
			$content = trim( sanitize_text_field( $turn['content'] ) );
			if ( '' === $content ) {
				continue;
			}
			$messages[] = array(
				'role'    => ( 'assistant' === $turn['role'] ) ? 'assistant' : 'user',
				'content' => $content,
			);
		}
		$messages[] = array( 'role' => 'user', 'content' => $user_message );

		$raw = $this->call_gemini_api( $api_key, $model, $system, $messages, 300 );

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

		// Colores predominantes pedidos, ya sanitizados; se descartan valores vacíos por si el modelo devuelve "" dentro del array.
		$colores = array();
		if ( isset( $json['colores'] ) && is_array( $json['colores'] ) ) {
			foreach ( $json['colores'] as $color ) {
				$color = sanitize_text_field( (string) $color );
				if ( '' !== $color ) {
					$colores[] = $color;
				}
			}
		}

		// Filtros de atributo adicionales (formato, terminación, etc.); mismos slugs que ATTRIBUTE_LABELS en Limatco_Chat_Context.
		$atributos = array();
		if ( isset( $json['atributos'] ) && is_array( $json['atributos'] ) ) {
			foreach ( $json['atributos'] as $attr_slug => $attr_value ) {
				$attr_value = sanitize_text_field( (string) $attr_value );
				if ( '' !== $attr_value ) {
					$atributos[ sanitize_text_field( (string) $attr_slug ) ] = $attr_value;
				}
			}
		}

		return array(
			'category'          => $slug ? $slug : '',
			'keywords'          => $keywords,
			'needs_search'      => ! isset( $json['needs_search'] ) || (bool) $json['needs_search'],
			'colores'           => $colores,
			'single_color_only' => ! empty( $json['single_color_only'] ),
			'atributos'         => $atributos,
		);
	}

	/** Se exigen roles alternados (user, assistant), que el primer mensaje sea "user" y que ningún content venga vacío. */
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

		// Debe empezar en "user": si el primer turno guardado es del bot (ej. saludo inicial), se descarta.
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
	/** Llamada genérica a la API de Gemini, reutilizada para clasificar (paso 1) y responder (paso 3). el prompt de sistema va como un mensaje más dentro de "messages", con role:"system". */
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
				// Con historial largo + respuesta de 1200 tokens, 30s de espera puede no alcanzar.
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

	/**
	 * Detecta la intención de contacto telefónico/ejecutivo por palabras clave, sin gastar tokens.
	 * Se normaliza a minúsculas y sin tildes para que coincida
	 */
	private function check_hardcoded_reply( $user_message ) {
		$normalized = strtolower( remove_accents( $user_message ) );

		$phone_triggers = array(
			'central cotizaciones',
			'necesito comunicarme con un ejecutivo',
			'quisiese comunicarme con un ejecutivo',
			'comunicarme con un ejecutivo',
			'hablar con un ejecutivo',
			'contactar a un ejecutivo',
			'llamada',
			'telefono',
			'numero telefonico',
			'numero de telefono',
		);
		foreach ( $phone_triggers as $trigger ) {
			if ( false !== strpos( $normalized, $trigger ) ) {
				return self::PHONE_REPLY;
			}
		}

		return null;
	}

	/**
	 * Detecta si el mensaje pregunta por sucursales, direcciones, horarios de atención o contacto de una tienda en particular. 
	 * estoe NO devuelve una respuesta fija como check_hardcoded_reply(),: solo decide si se agrega BRANCHES_CONTEXT al contexto

	 */
	private function is_branches_query( $user_message ) {
		$normalized = strtolower( remove_accents( $user_message ) );

		$branch_triggers = array(
			'sucursal',
			'sucursales',
			'direccion',
			'direcciones',
			'ubicacion',
			'ubicaciones',
			'donde queda',
			'donde estan',
			'donde estan ubicados',
			'horario de atencion',
			'horarios de atencion',
			'a que hora abren',
			'a que hora cierran',
		);
		foreach ( $branch_triggers as $trigger ) {
			if ( false !== strpos( $normalized, $trigger ) ) {
				return true;
			}
		}

		return false;
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
