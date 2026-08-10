<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="lac-widget" class="lac-widget" aria-live="polite">

	<button id="lac-toggle-btn" class="lac-toggle-btn" type="button" aria-expanded="false" aria-controls="lac-chat-window">
		<span class="lac-toggle-icon" aria-hidden="true">💬</span>
		<span class="lac-toggle-label"><?php echo esc_html( get_option( 'lac_button_label', 'Chat' ) ); ?></span>
	</button>

	<div id="lac-chat-window" class="lac-chat-window" hidden>
		<div class="lac-chat-header">
			<span>[Beta] Asistente Virtual Limatco</span>
			<button id="lac-close-btn" class="lac-close-btn" type="button" aria-label="Cerrar chat">&times;</button>
		</div>

		<div id="lac-messages" class="lac-messages"></div>

		<form id="lac-form" class="lac-form">
			<input
				type="text"
				id="lac-input"
				class="lac-input"
				placeholder="<?php echo esc_attr( get_option( 'lac_placeholder_text', 'Escribe tu pregunta…' ) ); ?>"
				autocomplete="off"
				required
			/>
			<button type="submit" class="lac-send-btn" aria-label="Enviar">➤</button>
		</form>
	</div>
</div>
