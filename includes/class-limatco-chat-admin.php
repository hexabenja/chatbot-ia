<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Página de ajustes: API key, prompt de sistema y textos del widget.
 * La carga del catálogo/contexto vive en class-limatco-chat-context.php.
 */
class Limatco_Chat_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_settings_page() {
		add_options_page(
			'Limatco AI Chat',
			'Limatco AI Chat',
			'manage_options',
			'limatco-ai-chat',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'lac_settings_group', 'lac_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lac_settings_group', 'lac_model', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lac_settings_group', 'lac_system_prompt', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( 'lac_settings_group', 'lac_welcome_text', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lac_settings_group', 'lac_placeholder_text', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lac_settings_group', 'lac_button_label', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$default_prompt = "Eres el asistente virtual de Limatco (limatco.cl), empresa chilena de materiales de construcción.\n"
			. "Responde ÚNICAMENTE con base en la información de catálogo/contexto proporcionada más abajo.\n"
			. "Si la pregunta no se puede responder con esa información, dilo explícitamente y ofrece derivar a un asesor humano. No inventes precios, stock ni especificaciones.";
		?>
		<div class="wrap">
			<h1>Limatco AI Chat — Ajustes</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'lac_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lac_api_key">API Key (DeepSeek)</label></th>
						<td>
							<input type="password" id="lac_api_key" name="lac_api_key"
								value="<?php echo esc_attr( get_option( 'lac_api_key', '' ) ); ?>"
								class="regular-text" autocomplete="off" />
							<p class="description">Key de DeepSeek (platform.deepseek.com), no de Anthropic. Se guarda en la base de datos de WP y solo se usa server-side; nunca se envía al navegador.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lac_model">Modelo</label></th>
						<td>
							<input type="text" id="lac_model" name="lac_model"
								value="<?php echo esc_attr( get_option( 'lac_model', 'deepseek-v4-flash' ) ); ?>"
								class="regular-text" />
							<p class="description">deepseek-v4-flash (rápido/económico) o deepseek-v4-pro (razonamiento más fuerte). Verifica el nombre vigente en api-docs.deepseek.com antes de publicar.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lac_system_prompt">Prompt de sistema (instrucciones estrictas)</label></th>
						<td>
							<textarea id="lac_system_prompt" name="lac_system_prompt" rows="6" class="large-text"><?php
								echo esc_textarea( get_option( 'lac_system_prompt', $default_prompt ) );
							?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lac_welcome_text">Mensaje de bienvenida</label></th>
						<td>
							<input type="text" id="lac_welcome_text" name="lac_welcome_text"
								value="<?php echo esc_attr( get_option( 'lac_welcome_text', '¡Hola! ¿En qué te puedo ayudar?' ) ); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lac_placeholder_text">Placeholder del input</label></th>
						<td>
							<input type="text" id="lac_placeholder_text" name="lac_placeholder_text"
								value="<?php echo esc_attr( get_option( 'lac_placeholder_text', 'Escribe tu pregunta…' ) ); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lac_button_label">Texto del botón flotante</label></th>
						<td>
							<input type="text" id="lac_button_label" name="lac_button_label"
								value="<?php echo esc_attr( get_option( 'lac_button_label', 'Chat' ) ); ?>"
								class="regular-text" />
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2>Catálogo / contexto (dinámico)</h2>
			<p class="description">
				El catálogo ya NO se pega a mano: cada mensaje del visitante dispara una
				búsqueda en vivo sobre tus productos de WooCommerce (categoría detectada +
				palabras clave). Categorías de producto detectadas actualmente:
				<strong><?php
					$cats = Limatco_Chat_Context::get_available_categories();
					echo $cats ? esc_html( implode( ', ', $cats ) ) : 'ninguna (revisa que WooCommerce tenga productos publicados).';
				?></strong>
			</p>
		</div>
		<?php
	}
}
