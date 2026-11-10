/**
 * Resenje Site Mover – scheduled backups screen.
 *
 * @package ResenjeSiteMover
 */

( function () {
	'use strict';

	var data = window.rsmoverSchedule;
	if ( ! data ) {
		return;
	}
	var t       = data.i18n;
	var message = document.getElementById( 'rsmover-schedule-message' );

	function toggle( selectId, rowClass ) {
		var select = document.getElementById( selectId );
		if ( ! select ) {
			return;
		}
		var update = function () {
			document.querySelectorAll( '.' + rowClass ).forEach(
				function ( row ) {
					row.hidden = ! row.classList.contains( rowClass + '-' + select.value );
				}
			);
		};
		select.addEventListener( 'change', update );
		update();
	}

	toggle( 'rsmover-frequency', 'rsmover-when' );
	toggle( 'rsmover-destination', 'rsmover-dest' );

	function decode( text ) {
		return new window.DOMParser().parseFromString( String( text ), 'text/html' ).documentElement.textContent;
	}

	function say( text, error ) {
		message.textContent = decode( text );
		message.className   = error ? 'rsmover-error' : 'rsmover-ok';
	}

	function post( action ) {
		var form = new FormData();
		form.append( 'action', 'rsmover_' + action );
		form.append( 'nonce', data.nonce );
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

	var test = document.getElementById( 'rsmover-test' );
	if ( test ) {
		test.addEventListener(
			'click',
			function () {
				test.disabled = true;
				say( t.testing, false );
				post( 'test_remote' ).then(
					function ( result ) {
						say( result.message, false );
					}
				).catch(
					function ( error ) {
						say( t.error + ' ' + error.message, true );
					}
				).then(
					function () {
						test.disabled = false;
					}
				);
			}
		);
	}

	var run = document.getElementById( 'rsmover-run-now' );
	if ( run ) {
		run.addEventListener(
			'click',
			function () {
				run.disabled = true;
				post( 'run_now' ).then(
					function () {
						window.location.href = data.mainPage;
					}
				).catch(
					function ( error ) {
						run.disabled = false;
						say( t.error + ' ' + error.message, true );
					}
				);
			}
		);
	}
}() );
