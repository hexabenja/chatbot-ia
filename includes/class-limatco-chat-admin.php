<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Página de ajustes con segunda capa de autenticación propia del plugin.
 *
 * SEGURIDAD:
 * - Contraseña cifrada con password_hash(BCRYPT, cost=12) en wp_options.
 * - Sesión PHP con TTL + fingerprint del hash (cambiar contraseña invalida sesiones).
 * - Verificación de integridad SHA-256 de todos los archivos del plugin.
 *   Si algún archivo fue modificado externamente (cPanel, otro admin WP) se muestra
 *   alerta con el archivo afectado, la fecha de modificación y el último usuario WP
 *   que inició sesión en el panel justo antes del cambio.
 * - Log de accesos al panel del plugin (IP + user WP + timestamp) en wp_options.
 */
class Limatco_Chat_Admin {

	const SESSION_KEY   = 'lac_admin_auth_v1';
	const OPTION_HASH   = 'lac_admin_password_hash';
	const OPTION_SUMS   = 'lac_file_checksums';      // hashes SHA-256 de referencia
	const OPTION_LOG    = 'lac_access_log';           // log de accesos al panel
	const OPTION_WP_LOG = 'lac_wp_user_log';          // último usuario WP logueado antes de cada cambio
	const SESSION_TTL   = 3600;                       // 1 hora

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) ); // página en ajustes
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		+		add_action( 'init',       array( $this, 'maybe_start_session' ) );
		// Rastrear qué usuario WP está activo en cada request admin
		add_action( 'admin_init', array( $this, 'track_wp_user' ) );
	}

	// ── Sesión PHP ────────────────────────────────────────────────────────────

	public function maybe_start_session() {
		if ( ! session_id() && ! headers_sent() ) {
			session_start();
		}
	}
	private function is_plugin_authenticated() {
		if ( empty( $_SESSION[ self::SESSION_KEY ] ) ) {
		return false;
		}
		$data = $_SESSION[ self::SESSION_KEY ];
		if ( empty( $data['expires'] ) || time() > (int) $data['expires'] ) {
		unset( $_SESSION[ self::SESSION_KEY ] );
		return false;
		}
		$stored = get_option( self::OPTION_HASH, '' );
		if ( empty( $stored ) || ( $data['fp'] ?? '' ) !== substr( $stored, -12 ) ) {
			unset( $_SESSION[ self::SESSION_KEY ] );
			return false;
	}
	return true;
	}

	private function authenticate_session() {
		$stored = get_option( self::OPTION_HASH, '' );
		$_SESSION[ self::SESSION_KEY ] = array(
			'expires' => time() + self::SESSION_TTL,
		'fp'      => substr( $stored, -12 ),
			'ip'      => $this->get_ip(),
		);
		$this->write_access_log( 'LOGIN_OK' );
	}

	private function logout() {
		$this->write_access_log( 'LOGOUT' );
		unset( $_SESSION[ self::SESSION_KEY ] );
	}

	// ── Rastreo de usuario WP ─────────────────────────────────────────────────

	/**
	 * Guarda el usuario WP activo en cada request del admin.
	 * Se usa para correlacionar quién estaba logueado cuando se detecta un cambio de archivo.
	 */
	public function track_wp_user() {
		$user = wp_get_current_user();
		if ( $user && $user->ID ) {
			$entry = array(
				'user_id'    => $user->ID,
				'user_login' => $user->user_login,
				'display'    => $user->display_name,
				'ip'         => $this->get_ip(),
				'time'       => current_time( 'mysql' ),
				'time_unix'  => time(),
			);
			// Guardamos los últimos 20 registros en un array rotativo.
			$log = get_option( self::OPTION_WP_LOG, array() );
			array_unshift( $log, $entry );
			$log = array_slice( $log, 0, 20 );
			update_option( self::OPTION_WP_LOG, $log, false );
		}
	}

	// ── Log de accesos al panel del plugin ───────────────────────────────────

	private function write_access_log( $event ) {
		$user  = wp_get_current_user();
		$entry = array(
			'event'      => $event,
			'ip'         => $this->get_ip(),
			'wp_user'    => $user ? $user->user_login : '—',
			'time'       => current_time( 'mysql' ),
			'time_unix'  => time(),
		);
		$log = get_option( self::OPTION_LOG, array() );
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 50 );
		update_option( self::OPTION_LOG, $log, false );
	}

	private function get_ip() {
		foreach ( array( 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
			}
		}
		return 'unknown';
	}

	// ── Integridad de archivos ────────────────────────────────────────────────

	/**
	 * Calcula SHA-256 de todos los archivos PHP/JS/CSS del plugin.
	 * Devuelve array[ ruta_relativa => hash ].
	 */
	private function compute_checksums() {
		$base  = LAC_PLUGIN_DIR;
		$sums  = array();
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $files as $file ) {
			if ( ! $file->isFile() ) continue;
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, array( 'php', 'js', 'css' ), true ) ) continue;
			$rel          = str_replace( $base, '', $file->getPathname() );
			$sums[ $rel ] = hash_file( 'sha256', $file->getPathname() );
		}
		ksort( $sums );
		return $sums;
	}

	/**
	 * Graba los checksums actuales como línea base de referencia.
	 */
	private function save_baseline() {
		$sums = $this->compute_checksums();
		update_option( self::OPTION_SUMS, array(
			'sums'       => $sums,
			'saved_at'   => current_time( 'mysql' ),
			'saved_unix' => time(),
		), false );
		return $sums;
	}

	/**
	 * Compara los archivos actuales contra la línea base.
	 * Devuelve array de anomalías: [ {file, status, mtime, mtime_fmt, suspect_users[]} ]
	 * status: 'modified' | 'added' | 'deleted'
	 */
	private function check_integrity() {
		$stored = get_option( self::OPTION_SUMS, null );
		if ( ! $stored ) {
			return array( 'baseline_missing' => true, 'issues' => array() );
		}

		$baseline = $stored['sums'] ?? array();
		$current  = $this->compute_checksums();
		$issues   = array();
		$wp_log   = get_option( self::OPTION_WP_LOG, array() );

		// Archivos modificados o eliminados.
		foreach ( $baseline as $rel => $hash ) {
			$full = LAC_PLUGIN_DIR . $rel;
			if ( ! file_exists( $full ) ) {
				$issues[] = $this->build_issue( $rel, 'deleted', 0, $stored['saved_unix'], $wp_log );
			} elseif ( $current[ $rel ] !== $hash ) {
				$mtime    = filemtime( $full );
				$issues[] = $this->build_issue( $rel, 'modified', $mtime, $stored['saved_unix'], $wp_log );
			}
		}

		// Archivos nuevos (no estaban en la línea base).
		foreach ( $current as $rel => $hash ) {
			if ( ! isset( $baseline[ $rel ] ) ) {
				$full     = LAC_PLUGIN_DIR . $rel;
				$mtime    = file_exists( $full ) ? filemtime( $full ) : 0;
				$issues[] = $this->build_issue( $rel, 'added', $mtime, $stored['saved_unix'], $wp_log );
			}
		}

		return array(
			'baseline_missing' => false,
			'baseline_date'    => $stored['saved_at'] ?? '—',
			'issues'           => $issues,
		);
	}

	/**
	 * Construye un registro de anomalía, buscando en el log de usuarios WP
	 * quién estaba activo en la ventana de tiempo próxima al cambio del archivo.
	 */
	private function build_issue( $rel, $status, $mtime, $baseline_unix, $wp_log ) {
		// Buscar usuarios WP que estuvieron activos en las 2 horas previas al mtime.
		$suspects = array();
		if ( $mtime > 0 ) {
			foreach ( $wp_log as $entry ) {
				$diff = $mtime - (int) $entry['time_unix'];
				if ( $diff >= -300 && $diff <= 7200 ) { // desde 5min después hasta 2h antes
					$suspects[] = $entry['display'] . ' (' . $entry['user_login'] . ') @ ' . $entry['ip'] . ' — ' . $entry['time'];
				}
			}
			$suspects = array_unique( $suspects );
		}

		return array(
			'file'          => $rel,
			'status'        => $status,
			'mtime'         => $mtime,
			'mtime_fmt'     => $mtime > 0 ? date( 'Y-m-d H:i:s', $mtime ) : '—',
			'suspect_users' => $suspects,
		);
	}

	// ── WP hooks ──────────────────────────────────────────────────────────────


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
		register_setting( 'lac_settings_group', 'lac_api_key',          array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lac_settings_group', 'lac_model',            array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lac_settings_group', 'lac_system_prompt',    array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( 'lac_settings_group', 'lac_welcome_text',     array( 'sanitize_callback' => 'sanitize_text_field' ) );		register_setting( 'lac_settings_group', 'lac_placeholder_text', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lac_settings_group', 'lac_button_label', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lac_settings_group', 'lac_placeholder_text', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'lac_settings_group', 'lac_button_label',     array( 'sanitize_callback' => 'sanitize_text_field' ) );		
	}

	// ── Render principal ──────────────────────────────────────────────────────

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stored_hash     = get_option( self::OPTION_HASH, '' );
		$password_error  = '';
		$password_notice = '';

		// Logout.
		if ( isset( $_GET['lac_logout'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['lac_logout'] ) ), 'lac_logout' ) ) {
			$this->logout();
			wp_redirect( admin_url( 'options-general.php?page=limatco-ai-chat' ) );
			exit;
		}

		// Guardar nueva línea base de integridad.
		if ( isset( $_POST['lac_save_baseline'] ) && check_admin_referer( 'lac_baseline_action' ) && $this->is_plugin_authenticated() ) {
			$this->save_baseline();
			$password_notice = 'Línea base de integridad guardada correctamente.';
		}

		// Set / cambio de contraseña.
		if ( isset( $_POST['lac_set_password'] ) && check_admin_referer( 'lac_set_password_action' ) ) {
			$new_pass    = $_POST['lac_new_password']     ?? '';
			$confirm     = $_POST['lac_confirm_password'] ?? '';
			$current_raw = $_POST['lac_current_password'] ?? '';

			if ( ! empty( $stored_hash ) && ! password_verify( $current_raw, $stored_hash ) ) {
				$password_error = 'La contraseña actual es incorrecta.';
			} elseif ( mb_strlen( $new_pass ) < 10 ) {
				$password_error = 'La nueva contraseña debe tener al menos 10 caracteres.';
			} elseif ( $new_pass !== $confirm ) {
				$password_error = 'Las contraseñas no coinciden.';
			} else {
				$hash = password_hash( $new_pass, PASSWORD_BCRYPT, array( 'cost' => 12 ) );
				update_option( self::OPTION_HASH, $hash );
				$this->logout();
				$password_notice = 'Contraseña guardada. Inicia sesión con la nueva contraseña.';
				$stored_hash     = $hash;
			}
		}

		// Login.
		$login_error = '';
		if ( isset( $_POST['lac_login'] ) && check_admin_referer( 'lac_login_action' ) ) {
			$attempt = $_POST['lac_password'] ?? '';
			if ( empty( $stored_hash ) ) {
				$login_error = 'No hay contraseña configurada aún.';
			} elseif ( password_verify( $attempt, $stored_hash ) ) {
				$this->authenticate_session();
				wp_redirect( admin_url( 'options-general.php?page=limatco-ai-chat' ) );
				exit;
			} else {
				$login_error = 'Contraseña incorrecta.';
				$this->write_access_log( 'LOGIN_FAIL' );
			}
		}

		// Gate: mostrar login si no está autenticado.
	if ( ! $this->is_plugin_authenticated() ) {
			$this->render_login_screen( $login_error, empty( $stored_hash ), $password_error, $password_notice );
			return;
		}

		// ── Panel principal autenticado ───────────────────────────────────────
		$integrity     = $this->check_integrity();
		$access_log    = get_option( self::OPTION_LOG, array() );
		$default_prompt = "Eres el asistente virtual de Limatco (limatco.cl), empresa chilena de materiales de construcción.\n"
			. "Responde ÚNICAMENTE con base en la información de catálogo/contexto proporcionada más abajo.\n"
			. "Si la pregunta no se puede responder con esa información, dilo explícitamente y ofrece derivar a un asesor humano. No inventes precios, stock ni especificaciones.";
		?>
		<div class="wrap">
			<h1>Limatco AI Chat — Ajustes
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=limatco-ai-chat&lac_logout=1' ), 'lac_logout', 'lac_logout' ) ); ?>"
				   class="button button-small" style="margin-left:12px;font-size:12px;">Cerrar sesión</a>
			</h1>

			<?php if ( $password_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $password_error ); ?></p></div>
			<?php elseif ( $password_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $password_notice ); ?></p></div>
			<?php endif; ?>

			<?php
			// ── Alerta de integridad ──────────────────────────────────────────
			if ( $integrity['baseline_missing'] ) {
				echo '<div class="notice notice-warning"><p><strong>⚠ Sin línea base de integridad.</strong> Guarda una línea base en la sección de seguridad para activar la detección de cambios.</p></div>';
			} elseif ( ! empty( $integrity['issues'] ) ) {
				$n = count( $integrity['issues'] );
				echo '<div class="notice notice-error"><p><strong>🚨 ' . esc_html( $n ) . ' archivo(s) del plugin fueron modificados desde la última línea base.</strong> Revisa la sección Seguridad.</p></div>';
			}
			?>

			<!-- ── Ajustes generales ─────────────────────────────────────────── -->
			<form method="post" action="options.php">
				<?php settings_fields( 'lac_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lac_api_key">API Key (Gemini)</label></th>
						<td>
							<input type="password" id="lac_api_key" name="lac_api_key"
								value="<?php echo esc_attr( get_option( 'lac_api_key', '' ) ); ?>"
								class="regular-text" autocomplete="off" />
							<p class="description">Sólo uso server-side debido a Php, nunca se envía al navegador. Reforzar bbdd wp</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lac_model">Modelo</label></th>
						<td>
							<input type="text" id="lac_model" name="lac_model"
+								value="<?php echo esc_attr( get_option( 'lac_model', 'gemini-3.5-flash-lite' ) ); ?>"
								class="regular-text" />
							<p class="description">Gemini Flash Lite 3.5 estándar</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lac_system_prompt">Prompt de sistema (instrucciones estrictas en el contexto)</label></th>
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
				Categorías de producto detectadas actualmente: (Acorde a estas categorías va a responder)
				<strong><?php
					$cats = Limatco_Chat_Context::get_available_categories();
					echo $cats ? esc_html( implode( ', ', $cats ) ) : 'ninguna (no se detectan productos.)';
				?></strong>
			</p>

			<!-- ── Seguridad ─────────────────────────────────────────────────── -->
			<hr />
			<h2>Seguridad</h2>

			<!-- Cambio de contraseña -->
			<h3>Contraseña del panel</h3>
			<form method="post">
				<?php wp_nonce_field( 'lac_set_password_action' ); ?>
				<table class="form-table" role="presentation">
					<?php if ( ! empty( $stored_hash ) ) : ?>
					<tr>
						<th scope="row"><label for="lac_current_password">Contraseña actual</label></th>
						<td><input type="password" id="lac_current_password" name="lac_current_password" class="regular-text" autocomplete="current-password" /></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="lac_new_password">Nueva contraseña</label></th>
						<td>
							<input type="password" id="lac_new_password" name="lac_new_password" class="regular-text" autocomplete="new-password" minlength="10" required />
							<p class="description">Mínimo 10 caracteres. Cifrada con bcrypt (cost 12).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lac_confirm_password">Confirmar contraseña</label></th>
						<td><input type="password" id="lac_confirm_password" name="lac_confirm_password" class="regular-text" autocomplete="new-password" required /></td>
					</tr>
				</table>
				<input type="hidden" name="lac_set_password" value="1" />
				<?php submit_button( empty( $stored_hash ) ? 'Establecer contraseña' : 'Cambiar contraseña', 'secondary' ); ?>
			</form>

			<!-- Integridad de archivos -->
			<h3>Integridad de archivos del plugin</h3>
			<?php if ( ! $integrity['baseline_missing'] ) : ?>
				<p class="description">Línea base guardada el: <strong><?php echo esc_html( $integrity['baseline_date'] ); ?></strong></p>
			<?php endif; ?>

			<?php if ( ! $integrity['baseline_missing'] && empty( $integrity['issues'] ) ) : ?>
				<div class="notice notice-success inline"><p>Todos los archivos están intactos desde la última línea base.</p></div>
			<?php elseif ( ! empty( $integrity['issues'] ) ) : ?>
				<table class="widefat striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th>Archivo</th>
							<th>Estado</th>
							<th>Fecha de modificación</th>
							<th>Usuarios WP activos en esa ventana</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $integrity['issues'] as $issue ) : ?>
						<tr>
							<td><code><?php echo esc_html( $issue['file'] ); ?></code></td>
							<td>
								<?php
								$badge_color = array(
									'modified' => '#d63638',
									'added'    => '#dba617',
									'deleted'  => '#888',
								)[ $issue['status'] ] ?? '#888';
								echo '<span style="color:' . esc_attr( $badge_color ) . ';font-weight:700;">'
									. esc_html( strtoupper( $issue['status'] ) ) . '</span>';
								?>
							</td>
							<td><?php echo esc_html( $issue['mtime_fmt'] ); ?></td>
							<td>
								<?php if ( empty( $issue['suspect_users'] ) ) : ?>
									<em style="color:#888;">Sin correlación en log (cambio vía cPanel o fuera de sesión WP)</em>
								<?php else : ?>
									<?php foreach ( $issue['suspect_users'] as $u ) : ?>
										<div style="color:#d63638;font-weight:600;"><?php echo esc_html( $u ); ?></div>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'lac_baseline_action' ); ?>
				<input type="hidden" name="lac_save_baseline" value="1" />
				<?php submit_button( $integrity['baseline_missing'] ? 'Guardar línea base ahora' : 'Actualizar línea base', 'secondary' ); ?>
				<p class="description">Guarda una nueva línea base cuando hayas actualizado el plugin intencionalmente.</p>
			</form>

			<!-- Log de accesos al panel del plugin -->
			<h3>Log de accesos al panel</h3>
			<?php if ( empty( $access_log ) ) : ?>
				<p class="description">Sin registros aún.</p>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:8px;">
					<thead><tr><th>Evento</th><th>Usuario WP</th><th>IP</th><th>Fecha</th></tr></thead>
					<tbody>
					<?php foreach ( $access_log as $entry ) : ?>
						<tr>
							<td>
								<?php
								$color = array(
									'LOGIN_OK'   => '#0f7a3c',
									'LOGIN_FAIL' => '#d63638',
									'LOGOUT'     => '#888',
								)[ $entry['event'] ] ?? '#333';
								echo '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $entry['event'] ) . '</strong>';
								?>
							</td>
							<td><?php echo esc_html( $entry['wp_user'] ); ?></td>
							<td><code><?php echo esc_html( $entry['ip'] ); ?></code></td>
							<td><?php echo esc_html( $entry['time'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

		</div>
		<?php
	}

	// ── Pantalla de login ─────────────────────────────────────────────────────

	private function render_login_screen( $login_error, $no_password_set, $pw_error, $pw_notice ) {
		?>
		<div class="wrap" style="max-width:440px;margin-top:48px;">
			<h1>Limatco AI Chat — Acceso</h1>

			<?php if ( $pw_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $pw_error ); ?></p></div>
			<?php elseif ( $pw_notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $pw_notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( $no_password_set ) : ?>
				<div class="notice notice-warning"><p>No hay contraseña configurada. Establece una para proteger el panel.</p></div>
				<form method="post" style="background:#fff;padding:24px;border:1px solid #ddd;border-radius:6px;margin-top:12px;">
					<?php wp_nonce_field( 'lac_set_password_action' ); ?>
					<p><label><strong>Nueva contraseña</strong> (mín. 10 caracteres)<br />
					<input type="password" name="lac_new_password" class="regular-text" autocomplete="new-password" minlength="10" required style="margin-top:6px;" /></label></p>
					<p><label><strong>Confirmar contraseña</strong><br />
					<input type="password" name="lac_confirm_password" class="regular-text" autocomplete="new-password" required style="margin-top:6px;" /></label></p>
					<input type="hidden" name="lac_set_password" value="1" />
					<?php submit_button( 'Establecer contraseña', 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<?php if ( $login_error ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $login_error ); ?></p></div>
				<?php endif; ?>
				<form method="post" style="background:#fff;padding:24px;border:1px solid #ddd;border-radius:6px;">
					<?php wp_nonce_field( 'lac_login_action' ); ?>
					<p><label><strong>Contraseña del panel</strong><br />
					<input type="password" name="lac_password" class="regular-text" autocomplete="current-password" style="margin-top:6px;" /></label></p>
					<input type="hidden" name="lac_login" value="1" />
					<?php submit_button( 'Ingresar', 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
