/**
 * Product screen: choose a base image and place print areas on it.
 * Areas are stored as fractions (0-1) of the image in a hidden JSON field.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.lecouzpd-admin' );
	var field = document.querySelector( 'input[name="lecouzpd_config"]' );

	if ( ! root || ! field || ! window.lecouzpdAdmin ) {
		return;
	}

	var text = window.lecouzpdAdmin.i18n;
	var stage = root.querySelector( '.lecouzpd-admin__stage' );
	var list = root.querySelector( '.lecouzpd-admin__areas' );
	var addButton = root.querySelector( '.lecouzpd-add' );
	var pickButton = root.querySelector( '.lecouzpd-pick' );
	var state = { image_id: 0, areas: [] };
	var imageUrl = root.getAttribute( 'data-image-url' ) || '';

	try {
		var saved = JSON.parse( field.value );
		state.image_id = parseInt( saved.image_id, 10 ) || 0;
		state.areas = Array.isArray( saved.areas ) ? saved.areas : [];
	} catch ( e ) {
		state = { image_id: 0, areas: [] };
	}

	function clamp( value, min, max ) {
		return Math.min( max, Math.max( min, value ) );
	}

	function persist() {
		field.value = JSON.stringify( state );
	}

	function newId() {
		return Math.random().toString( 36 ).slice( 2, 8 );
	}

	function make( tag, className, content ) {
		var el = document.createElement( tag );

		if ( className ) {
			el.className = className;
		}

		if ( content ) {
			el.textContent = content;
		}

		return el;
	}

	function renderStage() {
		stage.textContent = '';

		if ( ! imageUrl ) {
			stage.appendChild( make( 'p', 'description', text.noImage ) );
			return;
		}

		var img = make( 'img' );
		img.src = imageUrl;
		img.alt = '';
		img.draggable = false;
		stage.appendChild( img );

		state.areas.forEach( function ( area, index ) {
			var box = make( 'div', 'lecouzpd-admin__box' );
			var tag = make( 'span', 'lecouzpd-admin__tag', String( index + 1 ) );
			var handle = make( 'span', 'lecouzpd-admin__handle' );

			box.style.left = area.x * 100 + '%';
			box.style.top = area.y * 100 + '%';
			box.style.width = area.w * 100 + '%';
			box.style.height = area.h * 100 + '%';
			box.appendChild( tag );
			box.appendChild( handle );
			stage.appendChild( box );

			box.addEventListener( 'pointerdown', function ( event ) {
				startDrag( event, area, event.target === handle ? 'resize' : 'move' );
			} );
		} );
	}

	function startDrag( event, area, mode ) {
		event.preventDefault();

		var bounds = stage.getBoundingClientRect();
		var start = { x: event.clientX, y: event.clientY, area: Object.assign( {}, area ) };

		function onMove( move ) {
			var dx = ( move.clientX - start.x ) / bounds.width;
			var dy = ( move.clientY - start.y ) / bounds.height;

			if ( 'move' === mode ) {
				area.x = clamp( start.area.x + dx, 0, 1 - area.w );
				area.y = clamp( start.area.y + dy, 0, 1 - area.h );
			} else {
				area.w = clamp( start.area.w + dx, 0.03, 1 - area.x );
				area.h = clamp( start.area.h + dy, 0.03, 1 - area.y );
			}

			renderStage();
			persist();
		}

		function onUp() {
			document.removeEventListener( 'pointermove', onMove );
			document.removeEventListener( 'pointerup', onUp );
		}

		document.addEventListener( 'pointermove', onMove );
		document.addEventListener( 'pointerup', onUp );
	}

	function numberInput( label, value, onChange ) {
		var wrap = make( 'label', 'lecouzpd-admin__mm' );
		var input = make( 'input' );

		input.type = 'number';
		input.min = '0';
		input.step = '0.1';
		input.value = value || '';
		input.addEventListener( 'input', function () {
			onChange( parseFloat( input.value ) || 0 );
			persist();
		} );

		wrap.appendChild( make( 'span', '', label ) );
		wrap.appendChild( input );

		return wrap;
	}

	function renderList() {
		list.textContent = '';

		state.areas.forEach( function ( area, index ) {
			var row = make( 'li' );
			var name = make( 'input' );
			var remove = make( 'button', 'button-link-delete', text.remove );

			row.appendChild( make( 'strong', '', String( index + 1 ) ) );

			name.type = 'text';
			name.maxLength = 40;
			name.value = area.name || '';
			name.placeholder = text.name;
			name.addEventListener( 'input', function () {
				area.name = name.value;
				persist();
			} );
			row.appendChild( name );

			row.appendChild( numberInput( text.width, area.w_mm, function ( value ) {
				area.w_mm = value;
			} ) );

			remove.type = 'button';
			remove.addEventListener( 'click', function () {
				state.areas.splice( index, 1 );
				render();
			} );
			row.appendChild( remove );

			list.appendChild( row );
		} );

		addButton.disabled = state.areas.length >= window.lecouzpdAdmin.maxAreas;
	}

	function render() {
		renderStage();
		renderList();
		persist();
	}

	addButton.addEventListener( 'click', function () {
		if ( state.areas.length >= window.lecouzpdAdmin.maxAreas ) {
			return;
		}

		state.areas.push( {
			id: newId(),
			name: text.areaName.replace( '%d', state.areas.length + 1 ),
			x: 0.3,
			y: 0.3,
			w: 0.4,
			h: 0.4,
			w_mm: 0
		} );
		render();
	} );

	pickButton.addEventListener( 'click', function () {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		var frame = window.wp.media( {
			title: text.chooseImage,
			button: { text: text.useImage },
			library: { type: 'image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var size = attachment.sizes && attachment.sizes.large ? attachment.sizes.large : attachment;

			state.image_id = attachment.id;
			imageUrl = size.url;
			render();
		} );

		frame.open();
	} );

	render();
}() );
