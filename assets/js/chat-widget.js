(function () {
	'use strict';

	var toggleBtn = document.getElementById( 'lac-toggle-btn' );
	var closeBtn  = document.getElementById( 'lac-close-btn' );
	var chatWindow = document.getElementById( 'lac-chat-window' );
	var messagesEl = document.getElementById( 'lac-messages' );
	var form       = document.getElementById( 'lac-form' );
	var input      = document.getElementById( 'lac-input' );

	if ( ! toggleBtn || ! chatWindow || ! form ) {
		return;
	}

	// Historial en memoria: [{role: 'user'|'assistant', content: '...'}]
	var history = [];
	var welcomed = false;

	function openChat() {
		chatWindow.hidden = false;
		toggleBtn.setAttribute( 'aria-expanded', 'true' );
		input.focus();

		if ( ! welcomed ) {
			appendMessage( 'assistant', lacChatConfig.welcomeText );
			welcomed = true;
		}
	}

	function closeChat() {
		chatWindow.hidden = true;
		toggleBtn.setAttribute( 'aria-expanded', 'false' );
	}

	function appendMessage( role, text, noScroll ) {
		var el = document.createElement( 'div' );
		el.className = 'lac-msg ' + role;
		el.innerHTML = text;
		messagesEl.appendChild( el );
		if ( ! noScroll ) {
			messagesEl.scrollTop = messagesEl.scrollHeight;
		}
		return el;
	}

	// Burbuja de "pensando": 3 puntos animados en CSS puro.
	function appendLoadingBubble() {
		var el = document.createElement( 'div' );
		el.className = 'lac-msg assistant lac-loading';
		var dots = document.createElement( 'div' );
		dots.className = 'lac-typing-dots';
		dots.innerHTML = '<span></span><span></span><span></span>';
		el.appendChild( dots );
		messagesEl.appendChild( el );
		// No forzar scroll: el usuario controla la posición.
		return el;
	}

	// Typing animation: muestra el HTML carácter a carácter (sobre texto plano).
	// El cursor parpadeante es puro CSS via clase lac-typing-text.
	function appendWithTyping( html ) {
		var el = document.createElement( 'div' );
		el.className = 'lac-msg assistant lac-typing-text';
		messagesEl.appendChild( el );

		// Extraer texto plano para animar, luego setear HTML completo al terminar.
		var tmp = document.createElement( 'div' );
		tmp.innerHTML = html;
		var plain = tmp.textContent || tmp.innerText || '';
		var total = plain.length;
		var i = 0;
		var chunkSize = 3; // caracteres por frame: más rápido que 1 a 1
		var delay = 18;    // ms por frame

		function tick() {
			i += chunkSize;
			if ( i >= total ) {
				// Terminó: poner HTML real y quitar cursor
				el.innerHTML = html;
				el.classList.remove( 'lac-typing-text' );
				el.classList.add( 'lac-typing-done' );
				return;
			}
			el.textContent = plain.slice( 0, i );
			setTimeout( tick, delay );
		}
		setTimeout( tick, delay );
		return el;
	}

	// Pinta las tarjetas de producto (imagen, precio, stock, oferta) debajo del
	// texto de la respuesta. Se arma con createElement/textContent (salvo la
	// imagen, que va por .src) para no meter HTML sin sanitizar del catálogo.
	function renderProductCards( products ) {
		var container = document.createElement( 'div' );
		container.className = 'lac-products';

		products.forEach( function ( product ) {
			var card = document.createElement( 'a' );
			card.className = 'lac-product-card';
			card.href = product.url || '#';
			card.target = '_blank';
			card.rel = 'noopener';

			var imageWrap = document.createElement( 'div' );
			imageWrap.className = 'lac-product-image';
			if ( product.image ) {
				var img = document.createElement( 'img' );
				img.src = product.image;
				img.alt = product.name || '';
				img.loading = 'lazy';
				imageWrap.appendChild( img );
			}
			card.appendChild( imageWrap );

			var info = document.createElement( 'div' );
			info.className = 'lac-product-info';

			if ( product.brand ) {
				var brand = document.createElement( 'div' );
				brand.className = 'lac-product-brand';
				brand.textContent = product.brand;
				info.appendChild( brand );
			}

			var name = document.createElement( 'div' );
			name.className = 'lac-product-name';
			name.textContent = product.name || '';
			info.appendChild( name );

			// Solo se muestra el badge cuando NO hay stock; si está disponible no se
			// pinta nada (menos ruido visual en la tarjeta).
			if ( ! product.in_stock ) {
				var stock = document.createElement( 'span' );
				stock.className = 'lac-badge lac-badge-stock out-of-stock';
				stock.textContent = product.stock_text || 'Sin stock';
				info.appendChild( stock );
			}

			var price = document.createElement( 'div' );
			price.className = 'lac-product-price';
			price.textContent = product.price || '';
			info.appendChild( price );

			if ( product.on_sale && product.regular_price ) {
				var regularPrice = document.createElement( 'div' );
				regularPrice.className = 'lac-product-regular-price';
				regularPrice.textContent = product.regular_price;
				info.appendChild( regularPrice );

				var saleBox = document.createElement( 'div' );
				saleBox.className = 'lac-sale-box';
				var saleLabel = document.createElement( 'span' );
				saleLabel.textContent = product.discount_percent > 0
					? '-' + product.discount_percent + '% Oferta'
					: 'Oferta';
				saleBox.appendChild( saleLabel );
				info.appendChild( saleBox );
			}

			// La tarjeta completa es un <a> (abre la ficha del producto), así que el clic del botón de carrito no debe llevar a la ficha
			var addBtn = document.createElement( 'button' );
			addBtn.type = 'button';
			addBtn.className = 'lac-add-cart-btn';
			addBtn.textContent = 'Agregar al carrito';
			addBtn.disabled = ! product.in_stock;
			addBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				addToCart( product, addBtn );
			} );
			info.appendChild( addBtn );

			card.appendChild( info );
			container.appendChild( card );
		} );

		messagesEl.appendChild( container );
		return container;
	}

	// El nonce que viene en lacChatConfig puede estar "congelado" en HTML
	// cacheado (Cloudflare, plugin de caché, etc.), así que en vez de usarlo
	// directamente, se pide uno fresco a /nonce (que nunca se cachea) justo
	// antes de cada mensaje.
	function getFreshNonce() {
		return fetch( lacChatConfig.restUrl.replace( '/message', '/nonce' ), {
			method: 'GET',
			cache: 'no-store'
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( data ) { return data.nonce; } )
			.catch( function () { return lacChatConfig.nonce; } ); // fallback si /nonce falla
	}

	// REVISAR COMPATIBILIDAD CON LIMATCO. Llama al endpoint /add-to-cart al hacer clic en "Agregar al carrito" de una
	// tarjeta, y avisa en el chat (como mensaje del asistente) si se agregó o no.
	function addToCart( product, btn ) {
		btn.disabled = true;
		var originalLabel = btn.textContent;
		btn.textContent = 'Agregando…';

		getFreshNonce().then( function ( nonce ) {
			return fetch( lacChatConfig.restUrl.replace( '/message', '/add-to-cart' ), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: JSON.stringify( { product_id: product.id } )
			} );
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.data && result.data.success ) {
					btn.textContent = 'Producto agregado al carrito';
					appendMessage( 'assistant', 'Se agregó "' + ( product.name || 'el producto' ) + '" al carrito.', true );
				} else {
					btn.disabled = false;
					btn.textContent = originalLabel;
					var errMsg = ( result.data && result.data.error ) ? result.data.error : 'No se pudo agregar al carrito.';
					appendMessage( 'assistant', errMsg );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				btn.textContent = originalLabel;
				appendMessage( 'assistant', 'No se pudo conectar para agregar el producto al carrito.' );
			} );
	}

	function sendMessage( message ) {
		appendMessage( 'user', message ); // usuario: sí scrollea al fondo
		history.push( { role: 'user', content: message } );

		var loadingEl = appendLoadingBubble();

		getFreshNonce().then( function ( nonce ) {
			return fetch( lacChatConfig.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: JSON.stringify( {
					message: message,
					history: history
				} )
			} );
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				loadingEl.remove();

				if ( ! result.ok || ! result.data || ! result.data.reply ) {
					var errMsg = ( result.data && result.data.error )
						? result.data.error
						: 'Ocurrió un error, intenta de nuevo.';
					appendMessage( 'assistant', errMsg );
					return;
				}

				appendWithTyping( result.data.reply );
				history.push( { role: 'assistant', content: result.data.reply } );

				if ( result.data.products && result.data.products.length ) {
					renderProductCards( result.data.products );
				}
			} )
			.catch( function () {
				loadingEl.remove();
				appendMessage( 'assistant', 'No se pudo conectar. Intenta de nuevo en un momento.' );
			} );
	}

	toggleBtn.addEventListener( 'click', function () {
		if ( chatWindow.hidden ) {
			openChat();
		} else {
			closeChat();
		}
	} );

	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', closeChat );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var message = input.value.trim();
		if ( ! message ) {
			return;
		}
		input.value = '';
		sendMessage( message );
	} );
})();
