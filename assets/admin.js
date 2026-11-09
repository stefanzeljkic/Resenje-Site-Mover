/**
 * Resenje Site Mover admin screen: runs backup / import steps and uploads backups in parts.
 *
 * @package ResenjeSiteMover
 */

( function () {
	'use strict';

	var data = window.rsmoverData;
	if ( ! data ) {
		return;
	}
	var t        = data.i18n;
	var progress = document.getElementById( 'rsmover-progress' );
	var result   = document.getElementById( 'rsmover-result' );
	var bar      = progress.querySelector( '.rsmover-bar span' );
	var message  = progress.querySelector( '.rsmover-message' );
	var busy     = false;
	var chunk    = parseInt( data.chunk, 10 ) || 1048576;

	function post( action, fields, file ) {
		var form = new FormData();
		form.append( 'action', 'rsmover_' + action );
		form.append( 'nonce', data.nonce );
		Object.keys( fields || {} ).forEach(
			function ( key ) {
				form.append( key, fields[ key ] );
			}
		);
		if ( file ) {
			form.append( 'chunk', file, 'chunk.bin' );
		}
		return fetch( data.ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' } ).then(
			function ( response ) {
				return response.json().catch(
					function () {
						throw new Error( t.network );
					}
				);
			}
		).then(
			function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || t.network );
				}
				return json.data || {};
			}
		);
	}

	function decode( text ) {
		return new window.DOMParser().parseFromString( String( text ), 'text/html' ).documentElement.textContent;
	}

	function show( percent, text ) {
		progress.hidden     = false;
		result.hidden       = true;
		bar.style.width     = Math.max( 0, Math.min( 100, percent ) ) + '%';
		message.textContent = decode( text );
		message.classList.remove( 'rsmover-error' );
	}

	function fail( error ) {
		busy                = false;
		progress.hidden     = false;
		message.textContent = t.error + ' ' + decode( error.message );
		message.classList.add( 'rsmover-error' );
	}

	function finish( type ) {
		busy            = false;
		progress.hidden = true;
		result.hidden   = false;
		result.querySelector( '.rsmover-result-text' ).textContent = 'import' === type ? t.importDone : t.exportDone;
		result.querySelector( '.rsmover-result-login' ).hidden     = 'import' !== type;
		if ( 'export' === type ) {
			setTimeout(
				function () {
					window.location.reload();
				},
				4000
			);
		}
	}

	function loop() {
		post( 'step' ).then(
			function ( job ) {
				show( job.percent, job.message );
				if ( job.done ) {
					finish( job.type );
				} else {
					loop();
				}
			}
		).catch( fail );
	}

	function start( fields ) {
		if ( busy ) {
			return;
		}
		busy = true;
		show( 0, t.starting );
		post( 'start', fields ).then(
			function ( job ) {
				show( job.percent, job.message );
				loop();
			}
		).catch( fail );
	}

	function upload( file ) {
		var size    = file.size;
		var offset  = 0;
		var archive = '';
		busy        = true;

		function next() {
			var end = Math.min( size, offset + chunk );
			show( Math.floor( 100 * offset / Math.max( 1, size ) ), t.uploading.replace( '%d', Math.floor( 100 * offset / Math.max( 1, size ) ) ).replace( '%%', '%' ) );
			return post(
				'upload',
				{ offset: offset, archive: archive, filename: file.name, final: end >= size ? '1' : '' },
				file.slice( offset, end )
			).then(
				function ( res ) {
					archive = res.archive;
					offset  = end;
					if ( offset < size ) {
						return next();
					}
					busy = false;
					start( { type: 'import', archive: archive } );
				}
			);
		}
		next().catch( fail );
	}

	document.getElementById( 'rsmover-export' ).addEventListener(
		'click',
		function () {
			start( { type: 'export', exclude_media: document.getElementById( 'rsmover-exclude-media' ).checked ? '1' : '' } );
		}
	);

	document.getElementById( 'rsmover-upload' ).addEventListener(
		'click',
		function () {
			var input = document.getElementById( 'rsmover-file' );
			if ( busy ) {
				return;
			}
			if ( ! input.files || ! input.files.length ) {
				window.alert( t.chooseFile );
				return;
			}
			if ( window.confirm( t.confirmImport ) ) {
				upload( input.files[0] );
			}
		}
	);

	document.querySelectorAll( '.rsmover-restore' ).forEach(
		function ( button ) {
			button.addEventListener(
				'click',
				function () {
					if ( ! busy && window.confirm( t.confirmImport ) ) {
						start( { type: 'import', archive: button.getAttribute( 'data-file' ) } );
					}
				}
			);
		}
	);

	document.querySelectorAll( '.rsmover-delete' ).forEach(
		function ( button ) {
			button.addEventListener(
				'click',
				function () {
					if ( ! busy && window.confirm( t.confirmDelete ) ) {
						post( 'delete', { archive: button.getAttribute( 'data-file' ) } ).then(
							function () {
								button.closest( 'tr' ).remove();
							}
						).catch(
							function ( e ) {
								window.alert( decode( e.message ) );
							}
						);
					}
				}
			);
		}
	);

	document.getElementById( 'rsmover-cancel' ).addEventListener(
		'click',
		function () {
			if ( window.confirm( t.confirmCancel ) ) {
				post( 'cancel' ).then(
					function () {
						window.location.reload();
					}
				).catch( fail );
			}
		}
	);

	// A job was running when the page loaded (for example after a reload): continue it.
	if ( '1' === progress.getAttribute( 'data-running' ) ) {
		busy = true;
		loop();
	}
}() );
