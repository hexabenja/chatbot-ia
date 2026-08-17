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

	function appendMessage( role, text ) {
		var el = document.createElement( 'div' );
		el.className = 'lac-msg ' + role;
		el.innerHTML = text;
		messagesEl.appendChild( el );
		messagesEl.scrollTop = messagesEl.scrollHeight;
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

			var stock = document.createElement( 'span' );
			stock.className = 'lac-badge lac-badge-stock ' + ( product.in_stock ? 'in-stock' : 'out-of-stock' );
			stock.textContent = product.stock_text || ( product.in_stock ? 'Disponible' : 'Sin stock' );
			info.appendChild( stock );

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

			card.appendChild( info );
			container.appendChild( card );
		} );

		messagesEl.appendChild( container );
		messagesEl.scrollTop = messagesEl.scrollHeight;
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

	function sendMessage( message ) {
		appendMessage( 'user', message );
		history.push( { role: 'user', content: message } );

		var loadingEl = appendMessage( 'assistant', 'Escribiendo…' );
		loadingEl.classList.add( 'lac-loading' );

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

				appendMessage( 'assistant', result.data.reply );
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
