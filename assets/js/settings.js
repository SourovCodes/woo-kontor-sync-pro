/**
 * Connection test for the Kontor Sync settings screen.
 *
 * Sends whatever is currently typed into the form, so credentials can be checked
 * before they are saved. A blank key means "use the stored one".
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'wksync-test-connection' );
		var output = document.getElementById( 'wksync-test-result' );

		if ( ! button || ! output || 'undefined' === typeof wksyncSettings ) {
			return;
		}

		function report( message, isError ) {
			output.textContent = message;
			output.style.color = isError ? '#d63638' : '#00834a';
		}

		button.addEventListener( 'click', function () {
			var body = new FormData();

			body.append( 'action', 'wksync_test_connection' );
			body.append( 'nonce', wksyncSettings.nonce );
			body.append( 'api_base_url', document.getElementById( 'wksync-api-base-url' ).value );
			body.append( 'api_key', document.getElementById( 'wksync-api-key' ).value );
			body.append( 'shoptype', document.getElementById( 'wksync-shoptype' ).value );

			button.disabled = true;
			report( wksyncSettings.testing, false );

			window.fetch( wksyncSettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( result ) {
				var message = result && result.data && result.data.message
					? result.data.message
					: wksyncSettings.failed;

				report( message, ! ( result && result.success ) );
			} ).catch( function () {
				report( wksyncSettings.failed, true );
			} ).finally( function () {
				button.disabled = false;
			} );
		} );
	} );
}() );
