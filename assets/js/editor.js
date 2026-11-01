/**
 * Customer-facing editor: text and images placed inside print areas, drawn on
 * a Fabric.js canvas, saved as a preview plus one print file per area.
 */
( function () {
	'use strict';

	var data = window.lecouzpdData;
	var fabric = window.fabric;
	var openButton = document.querySelector( '.lecouzpd-open' );

	if ( ! data || ! fabric || ! openButton ) {
		return;
	}

	var i18n = data.i18n;
	var wrapper = openButton.closest( '.lecouzpd-field' );
	var hidden = wrapper.querySelector( 'input[name="lecouzpd_design_id"]' );
	var thumb = wrapper.querySelector( '.lecouzpd-thumb' );
	var status = wrapper.querySelector( '.lecouzpd-status' );

	var MAX_FILE = 15 * 1024 * 1024;
	var MAX_SOURCE_SIDE = 3000;
	var LOW_DPI = 150;
	var PRINT_DPI = 300;
	var MAX_PRINT_SIDE = 4000;
	var FILE_TYPES = [ 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ];

	// Canvas coordinates: the base image scaled down to at most 1000 px.
	var ratio = Math.min( 1, 1000 / Math.max( data.image.width, data.image.height ) );
	var cw = Math.round( data.image.width * ratio );
	var ch = Math.round( data.image.height * ratio );

	var ui = {};
	var canvas = null;
	var modal = null;
	var activeArea = data.areas[ 0 ];
	var exporting = false;
	var busy = false;

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

	function button( label, className, onClick ) {
		var el = make( 'button', className, label );

		el.type = 'button';
		el.addEventListener( 'click', onClick );

		return el;
	}

	function field( label, control ) {
		var wrap = make( 'label', 'lecouzpd-control' );

		wrap.appendChild( make( 'span', '', label ) );
		wrap.appendChild( control );

		return wrap;
	}

	function areaById( id ) {
		return data.areas.filter( function ( area ) {
			return area.id === id;
		} )[ 0 ];
	}

	function rectOf( area ) {
		return { x: area.x * cw, y: area.y * ch, w: area.w * cw, h: area.h * ch };
	}

	function designObjects() {
		return canvas.getObjects();
	}

	function hasObjects( area ) {
		return designObjects().some( function ( object ) {
			return object.lecouzArea === area.id;
		} );
	}

	function message( text, isError ) {
		ui.message.textContent = text || '';
		ui.message.classList.toggle( 'is-error', !! isError );
	}

	/* ---------------------------------------------------------------- build */

	function build() {
		modal = make( 'div', 'lecouzpd-modal' );
		modal.hidden = true;
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		modal.setAttribute( 'aria-label', i18n.title );

		var panel = make( 'div', 'lecouzpd-panel' );
		var header = make( 'div', 'lecouzpd-header' );
		var body = make( 'div', 'lecouzpd-body' );
		var side = make( 'div', 'lecouzpd-side' );
		var stage = make( 'div', 'lecouzpd-stage' );
		var footer = make( 'div', 'lecouzpd-footer' );
		var canvasEl = make( 'canvas' );

		ui.close = button( '×', 'lecouzpd-close', closeModal );
		ui.close.setAttribute( 'aria-label', i18n.close );
		header.appendChild( make( 'strong', '', i18n.title ) );
		header.appendChild( ui.close );

		buildSide( side );

		stage.appendChild( canvasEl );
		ui.stage = stage;

		ui.message = make( 'p', 'lecouzpd-message' );
		ui.message.setAttribute( 'role', 'status' );
		ui.save = button( i18n.save, 'button alt lecouzpd-save', save );
		footer.appendChild( ui.message );
		footer.appendChild( button( i18n.cancel, 'button', closeModal ) );
		footer.appendChild( ui.save );

		body.appendChild( side );
		body.appendChild( stage );
		panel.appendChild( header );
		panel.appendChild( body );
		panel.appendChild( footer );
		modal.appendChild( panel );
		document.body.appendChild( modal );

		buildCanvas( canvasEl );
		setActiveArea( activeArea );

		modal.addEventListener( 'keydown', trapFocus );
		modal.addEventListener( 'mousedown', function ( event ) {
			if ( event.target === modal ) {
				closeModal();
			}
		} );
		window.addEventListener( 'resize', fit );
	}

	function buildSide( side ) {
		if ( data.areas.length > 1 ) {
			var tabs = make( 'div', 'lecouzpd-tabs' );

			tabs.setAttribute( 'role', 'group' );
			tabs.setAttribute( 'aria-label', i18n.area );
			ui.tabs = {};

			data.areas.forEach( function ( area ) {
				ui.tabs[ area.id ] = button( area.name, 'lecouzpd-tab', function () {
					setActiveArea( area );
				} );
				tabs.appendChild( ui.tabs[ area.id ] );
			} );

			side.appendChild( tabs );
		}

		var tools = make( 'div', 'lecouzpd-tools' );
		var picker = make( 'input' );

		picker.type = 'file';
		picker.accept = FILE_TYPES.join( ',' );
		picker.hidden = true;
		picker.addEventListener( 'change', function () {
			if ( picker.files && picker.files[ 0 ] ) {
				addImage( picker.files[ 0 ] );
			}
			picker.value = '';
		} );

		tools.appendChild( button( i18n.addText, 'button', addText ) );
		tools.appendChild( button( i18n.addImage, 'button', function () {
			picker.click();
		} ) );
		tools.appendChild( picker );
		side.appendChild( tools );

		ui.hint = make( 'p', 'lecouzpd-hint', i18n.hint );
		side.appendChild( ui.hint );

		ui.warning = make( 'p', 'lecouzpd-warning' );
		ui.warning.hidden = true;
		side.appendChild( ui.warning );

		buildProperties( side );
	}

	function buildProperties( side ) {
		var props = make( 'div', 'lecouzpd-props' );
		var textGroup = make( 'div', 'lecouzpd-textgroup' );

		props.hidden = true;

		ui.textInput = make( 'textarea' );
		ui.textInput.rows = 3;
		ui.textInput.addEventListener( 'input', function () {
			change( 'text', ui.textInput.value );
		} );

		ui.font = make( 'select' );
		data.fonts.forEach( function ( font ) {
			var option = make( 'option', '', font );

			option.value = font;
			ui.font.appendChild( option );
		} );
		ui.font.addEventListener( 'change', function () {
			change( 'fontFamily', ui.font.value );
		} );

		ui.color = make( 'input' );
		ui.color.type = 'color';
		ui.color.addEventListener( 'input', function () {
			change( 'fill', ui.color.value );
		} );

		ui.size = make( 'input' );
		ui.size.type = 'number';
		ui.size.min = '6';
		ui.size.max = '400';
		ui.size.addEventListener( 'input', function () {
			var size = parseInt( ui.size.value, 10 );

			if ( size >= 6 && size <= 400 ) {
				change( 'fontSize', size );
			}
		} );

		ui.bold = button( 'B', 'lecouzpd-toggle lecouzpd-bold', function () {
			var object = canvas.getActiveObject();

			change( 'fontWeight', object && 'bold' === object.fontWeight ? 'normal' : 'bold' );
		} );
		ui.bold.setAttribute( 'aria-label', i18n.bold );
		ui.italic = button( 'I', 'lecouzpd-toggle lecouzpd-italic', function () {
			var object = canvas.getActiveObject();

			change( 'fontStyle', object && 'italic' === object.fontStyle ? 'normal' : 'italic' );
		} );
		ui.italic.setAttribute( 'aria-label', i18n.italic );

		var style = make( 'div', 'lecouzpd-row' );

		style.appendChild( ui.bold );
		style.appendChild( ui.italic );

		ui.align = {};
		[ [ 'left', i18n.alignLeft, '←' ], [ 'center', i18n.alignCenter, '↔' ], [ 'right', i18n.alignRight, '→' ] ].forEach( function ( item ) {
			ui.align[ item[ 0 ] ] = button( item[ 2 ], 'lecouzpd-toggle', function () {
				change( 'textAlign', item[ 0 ] );
			} );
			ui.align[ item[ 0 ] ].setAttribute( 'aria-label', item[ 1 ] );
			style.appendChild( ui.align[ item[ 0 ] ] );
		} );

		textGroup.appendChild( field( i18n.text, ui.textInput ) );
		textGroup.appendChild( field( i18n.font, ui.font ) );

		var look = make( 'div', 'lecouzpd-row' );

		look.appendChild( field( i18n.color, ui.color ) );
		look.appendChild( field( i18n.size, ui.size ) );
		textGroup.appendChild( look );
		textGroup.appendChild( style );
		ui.textGroup = textGroup;

		var actions = make( 'div', 'lecouzpd-row lecouzpd-actions' );

		actions.appendChild( button( i18n.center, 'button', centerObject ) );
		actions.appendChild( button( i18n.forward, 'button', function () {
			arrange( 'bringForward' );
		} ) );
		actions.appendChild( button( i18n.backward, 'button', function () {
			arrange( 'sendBackwards' );
		} ) );
		actions.appendChild( button( i18n.remove, 'button lecouzpd-delete', removeObject ) );

		props.appendChild( textGroup );
		props.appendChild( actions );
		side.appendChild( props );
		ui.props = props;
	}

	function buildCanvas( canvasEl ) {
		canvas = new fabric.Canvas( canvasEl, {
			width: cw,
			height: ch,
			selection: false,
			preserveObjectStacking: true
		} );

		loadBackground();

		// Dashed outlines of the print areas, drawn over the canvas but never exported.
		canvas.on( 'after:render', function ( event ) {
			if ( exporting ) {
				return;
			}

			var ctx = event.ctx;

			ctx.save();
			ctx.lineWidth = 2;
			ctx.setLineDash( [ 8, 6 ] );
			data.areas.forEach( function ( area ) {
				var r = rectOf( area );

				ctx.strokeStyle = area === activeArea ? '#1d4ed8' : 'rgba(107, 114, 128, 0.6)';
				ctx.strokeRect( r.x, r.y, r.w, r.h );
			} );
			ctx.restore();
		} );

		canvas.on( 'selection:created', syncProps );
		canvas.on( 'selection:updated', syncProps );
		canvas.on( 'selection:cleared', syncProps );
		canvas.on( 'text:changed', syncProps );
		canvas.on( 'object:scaling', updateWarning );
		canvas.on( 'object:modified', function ( event ) {
			normalizeText( event.target );
			syncProps();
			updateWarning();
		} );
	}

	function loadBackground() {
		var options = { crossOrigin: 'anonymous' };

		function apply( image ) {
			image.set( {
				left: 0,
				top: 0,
				originX: 'left',
				originY: 'top',
				scaleX: cw / image.width,
				scaleY: ch / image.height
			} );
			canvas.setBackgroundImage( image, canvas.requestRenderAll.bind( canvas ) );
		}

		fabric.Image.fromURL( data.image.url, function ( image, failed ) {
			if ( ! failed ) {
				apply( image );
				return;
			}

			// The image host sent no CORS headers: show it anyway, the preview then skips it.
			fabric.Image.fromURL( data.image.url, function ( plain, failedAgain ) {
				if ( ! failedAgain ) {
					apply( plain );
				}
			} );
		}, options );
	}

	/* -------------------------------------------------------------- objects */

	function setActiveArea( area ) {
		activeArea = area;
		canvas.discardActiveObject();

		designObjects().forEach( function ( object ) {
			var on = object.lecouzArea === area.id;

			object.selectable = on;
			object.evented = on;
		} );

		if ( ui.tabs ) {
			Object.keys( ui.tabs ).forEach( function ( id ) {
				ui.tabs[ id ].setAttribute( 'aria-pressed', id === area.id ? 'true' : 'false' );
			} );
		}

		canvas.requestRenderAll();
		syncProps();
		updateWarning();
	}

	function place( object, area ) {
		var r = rectOf( area );

		object.set( {
			left: r.x + r.w / 2,
			top: r.y + r.h / 2,
			originX: 'center',
			originY: 'center',
			clipPath: new fabric.Rect( { left: r.x, top: r.y, width: r.w, height: r.h, absolutePositioned: true } ),
			selectable: true,
			evented: true
		} );
		object.lecouzArea = area.id;
		canvas.add( object );
		canvas.setActiveObject( object );
		canvas.requestRenderAll();
		message( '' );
	}

	function addText() {
		var r = rectOf( activeArea );

		place( new fabric.Textbox( i18n.defaultText, {
			width: r.w * 0.8,
			fontFamily: data.fonts[ 0 ] || 'Arial',
			fontSize: Math.max( 14, Math.round( r.h * 0.16 ) ),
			fill: '#111111',
			textAlign: 'center'
		} ), activeArea );
	}

	function addImage( file ) {
		if ( FILE_TYPES.indexOf( file.type ) === -1 ) {
			message( i18n.badFile, true );
			return;
		}

		if ( file.size > MAX_FILE ) {
			message( i18n.fileTooLarge, true );
			return;
		}

		var area = activeArea;
		var reader = new FileReader();

		reader.onload = function () {
			downscale( String( reader.result ), file.type, function ( source ) {
				fabric.Image.fromURL( source, function ( image ) {
					var r = rectOf( area );

					image.scale( Math.min( ( r.w * 0.9 ) / image.width, ( r.h * 0.9 ) / image.height ) );
					place( image, area );
					updateWarning();
				} );
			} );
		};
		reader.readAsDataURL( file );
	}

	// Very large photos are shrunk before they reach the canvas to keep the editor responsive.
	function downscale( source, type, done ) {
		var image = new Image();

		image.onload = function () {
			var longest = Math.max( image.naturalWidth, image.naturalHeight );

			if ( longest <= MAX_SOURCE_SIDE ) {
				done( source );
				return;
			}

			var factor = MAX_SOURCE_SIDE / longest;
			var scratch = make( 'canvas' );

			scratch.width = Math.round( image.naturalWidth * factor );
			scratch.height = Math.round( image.naturalHeight * factor );
			scratch.getContext( '2d' ).drawImage( image, 0, 0, scratch.width, scratch.height );
			done( scratch.toDataURL( 'image/jpeg' === type ? 'image/jpeg' : 'image/png', 0.92 ) );
		};
		image.onerror = function () {
			message( i18n.badFile, true );
		};
		image.src = source;
	}

	function change( property, value ) {
		var object = canvas.getActiveObject();

		if ( ! object ) {
			return;
		}

		object.set( property, value );
		object.setCoords();
		canvas.requestRenderAll();
		syncProps( property );
	}

	// Corner handles scale a text box; turn that scale back into a real font size.
	function normalizeText( object ) {
		if ( ! object || 'textbox' !== object.type || 1 === object.scaleX ) {
			return;
		}

		object.set( {
			fontSize: Math.round( object.fontSize * object.scaleX ),
			width: object.width * object.scaleX,
			scaleX: 1,
			scaleY: 1
		} );
		object.setCoords();
		canvas.requestRenderAll();
	}

	function centerObject() {
		var object = canvas.getActiveObject();

		if ( ! object ) {
			return;
		}

		var r = rectOf( areaById( object.lecouzArea ) );

		object.set( { left: r.x + r.w / 2, top: r.y + r.h / 2 } );
		object.setCoords();
		canvas.requestRenderAll();
	}

	function arrange( method ) {
		var object = canvas.getActiveObject();

		if ( object ) {
			canvas[ method ]( object );
			canvas.requestRenderAll();
		}
	}

	function removeObject() {
		var object = canvas.getActiveObject();

		if ( object ) {
			canvas.remove( object );
			canvas.discardActiveObject();
			canvas.requestRenderAll();
			updateWarning();
		}
	}

	function syncProps( skip ) {
		var object = canvas.getActiveObject();

		ui.props.hidden = ! object;
		ui.hint.hidden = !! object;

		if ( ! object ) {
			return;
		}

		var isText = 'textbox' === object.type;

		ui.textGroup.hidden = ! isText;

		if ( ! isText ) {
			return;
		}

		if ( 'text' !== skip && ui.textInput.value !== object.text ) {
			ui.textInput.value = object.text;
		}

		ui.font.value = object.fontFamily;
		ui.color.value = object.fill;
		ui.size.value = Math.round( object.fontSize );
		ui.bold.setAttribute( 'aria-pressed', 'bold' === object.fontWeight ? 'true' : 'false' );
		ui.italic.setAttribute( 'aria-pressed', 'italic' === object.fontStyle ? 'true' : 'false' );

		Object.keys( ui.align ).forEach( function ( key ) {
			ui.align[ key ].setAttribute( 'aria-pressed', key === object.textAlign ? 'true' : 'false' );
		} );
	}

	// Effective resolution of an image once stretched over the real size of its print area.
	function dpiOf( object ) {
		var area = areaById( object.lecouzArea );

		if ( ! area || ! ( area.w_mm > 0 ) ) {
			return null;
		}

		var millimetresPerPixel = area.w_mm / ( area.w * cw );
		var shown = ( object.getScaledWidth() * millimetresPerPixel ) / 25.4;

		return object.width / shown;
	}

	function updateWarning() {
		var lowest = null;

		designObjects().forEach( function ( object ) {
			var dpi = 'image' === object.type ? dpiOf( object ) : null;

			if ( null !== dpi && ( null === lowest || dpi < lowest ) ) {
				lowest = dpi;
			}
		} );

		var low = null !== lowest && lowest < LOW_DPI;

		ui.warning.hidden = ! low;
		ui.warning.textContent = low ? i18n.lowRes.replace( '%d', Math.round( lowest ) ) : '';
	}

	/* --------------------------------------------------------------- export */

	function multiplierFor( area ) {
		var r = rectOf( area );
		var wanted = area.w_mm > 0 ? ( ( area.w_mm / 25.4 ) * PRINT_DPI ) / r.w : 2;

		return Math.max( 1, Math.min( wanted, MAX_PRINT_SIDE / Math.max( r.w, r.h ) ) );
	}

	function withCleanCanvas( hideOthersOf, dropBackground, render ) {
		var selected = canvas.getActiveObject();
		var hiddenObjects = [];
		var background = canvas.backgroundImage;

		canvas.discardActiveObject();

		if ( hideOthersOf ) {
			designObjects().forEach( function ( object ) {
				if ( object.lecouzArea !== hideOthersOf.id && object.visible ) {
					object.visible = false;
					hiddenObjects.push( object );
				}
			} );
		}

		if ( dropBackground ) {
			canvas.backgroundImage = null;
		}

		exporting = true;

		try {
			return render();
		} finally {
			exporting = false;
			canvas.backgroundImage = background;
			hiddenObjects.forEach( function ( object ) {
				object.visible = true;
			} );

			if ( selected ) {
				canvas.setActiveObject( selected );
			}

			canvas.requestRenderAll();
		}
	}

	function exportPreview() {
		var options = { format: 'png', multiplier: Math.min( 1, 800 / Math.max( cw, ch ) ), enableRetinaScaling: false };

		try {
			return withCleanCanvas( null, false, function () {
				return canvas.toDataURL( options );
			} );
		} catch ( error ) {
			// The base image taints the canvas (no CORS): export the design alone.
			return withCleanCanvas( null, true, function () {
				return canvas.toDataURL( options );
			} );
		}
	}

	function exportArea( area ) {
		var r = rectOf( area );

		return withCleanCanvas( area, true, function () {
			return canvas.toDataURL( {
				format: 'png',
				left: r.x,
				top: r.y,
				width: r.w,
				height: r.h,
				multiplier: multiplierFor( area ),
				enableRetinaScaling: false
			} );
		} );
	}

	function setBusy( state ) {
		busy = state;
		ui.save.disabled = state;
		ui.save.textContent = state ? i18n.saving : i18n.save;
	}

	function save() {
		if ( busy ) {
			return;
		}

		var filled = data.areas.filter( hasObjects );

		if ( ! filled.length ) {
			message( i18n.empty, true );
			return;
		}

		setBusy( true );
		message( '' );

		var payload = { product_id: data.productId, preview: exportPreview(), areas: {} };

		filled.forEach( function ( area ) {
			payload.areas[ area.id ] = exportArea( area );
		} );

		window.fetch( data.restUrl, {
			method: 'POST',
			credentials: 'omit',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload )
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				return { ok: response.ok, body: body };
			} );
		} ).then( function ( result ) {
			if ( ! result.ok || ! result.body.id ) {
				throw new Error( result.body && result.body.message ? result.body.message : i18n.saveError );
			}

			hidden.value = result.body.id;
			thumb.src = result.body.preview;
			thumb.hidden = false;
			openButton.textContent = i18n.edit;
			status.textContent = i18n.saved;
			closeModal();
		} ).catch( function ( error ) {
			message( error.message || i18n.saveError, true );
		} ).then( function () {
			setBusy( false );
		} );
	}

	/* ---------------------------------------------------------------- modal */

	function fit() {
		if ( ! modal || modal.hidden ) {
			return;
		}

		var scale = Math.min( 1, ui.stage.clientWidth / cw, ui.stage.clientHeight / ch );

		canvas.setDimensions( { width: Math.floor( cw * scale ) + 'px', height: Math.floor( ch * scale ) + 'px' }, { cssOnly: true } );
		canvas.calcOffset();
	}

	function openModal() {
		if ( ! modal ) {
			build();
		}

		modal.hidden = false;
		document.documentElement.classList.add( 'lecouzpd-lock' );
		fit();
		ui.close.focus();
	}

	function closeModal() {
		if ( ! modal || modal.hidden ) {
			return;
		}

		modal.hidden = true;
		document.documentElement.classList.remove( 'lecouzpd-lock' );
		openButton.focus();
	}

	function trapFocus( event ) {
		if ( 'Escape' === event.key && ! busy ) {
			closeModal();
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		var items = Array.prototype.filter.call(
			modal.querySelectorAll( 'button, input, select, textarea' ),
			function ( item ) {
				return ! item.disabled && ! item.hidden && null !== item.offsetParent;
			}
		);

		if ( ! items.length ) {
			return;
		}

		var first = items[ 0 ];
		var last = items[ items.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	openButton.addEventListener( 'click', openModal );

	if ( hidden.form ) {
		hidden.form.addEventListener( 'submit', function ( event ) {
			if ( data.required && ! hidden.value ) {
				event.preventDefault();
				status.textContent = i18n.needDesign;
				openModal();
			}
		} );
	}
}() );
