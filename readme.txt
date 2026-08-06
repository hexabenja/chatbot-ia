=== Limatco AI Chat (esqueleto) ===

Popup de chat tipo JoinChat que responde usando un modelo de IA (Anthropic API),
restringido a la información de catálogo/contexto que tú le entregues.

## Instalación
1. Copia la carpeta "limatco-ai-chat" a /wp-content/plugins/ de tu sitio.
2. Actívalo desde Plugins en el escritorio de WordPress.
3. Ve a Ajustes → Limatco AI Chat:
   - Pega tu API Key de Anthropic.
   - Revisa/ajusta el modelo (verifica el string vigente en docs.claude.com).
   - Ajusta el "prompt de sistema" si quieres cambiar el tono o las reglas.
   - Pega el catálogo/información en el textarea de "Catálogo / contexto".
4. El botón flotante aparecerá automáticamente en el frontend una vez
   que la API key esté configurada.

## Cómo funciona (resumen)
- El navegador nunca ve la API key: el JS llama a un endpoint REST propio
  de WordPress (/wp-json/limatco-chat/v1/message), y es el PHP del servidor
  el que llama a la API de Anthropic.
- Antes de llamar a la API, el plugin arma el "contexto" (catálogo) y lo
  agrega al prompt de sistema, junto con instrucciones de que la IA
  responda solo con esa información.
- El historial de la conversación se mantiene en el navegador (en memoria)
  y se reenvía completo en cada mensaje, para que el modelo tenga contexto
  del hilo.

## Próximos pasos sugeridos
1. Reemplazar el textarea manual por una carga automática desde WooCommerce:
   implementar `get_catalog_from_woocommerce()` en
   includes/class-limatco-chat-context.php.
2. Si el catálogo crece mucho (cientos/miles de productos), el enfoque de
   "pegar todo el catálogo en el prompt" deja de ser viable. En ese caso
   conviene un paso de búsqueda semántica (embeddings) que traiga solo los
   productos relevantes a cada pregunta antes de llamar a la API — avísame
   cuando llegues a ese punto y lo armamos.
3. Ajustar estilos en assets/css/chat-widget.css al branding de Limatco.
4. Definir un límite de uso/rate-limit más fino si el tráfico crece
   (actualmente: 20 mensajes/minuto por IP).
