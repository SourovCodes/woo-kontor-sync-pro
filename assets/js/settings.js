/**
 * Connection test and shop lookup for the Kontor Sync settings screen.
 *
 * Both send whatever is currently typed into the form, so credentials can be used
 * before they are saved. A blank key means "use the stored one".
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( 'undefined' === typeof wksyncSettings ) {
			return;
		}

		/**
		 * Write a message into one of the result paragraphs.
		 *
		 * @param {Element} output  Paragraph to write into.
		 * @param {string}  message Text to show.
		 * @param {boolean} isError Whether to style it as a failure.
		 */
		function report( output, message, isError ) {
			output.textContent = message;
			output.style.color = isError ? '#d63638' : '#00834a';
		}

		/**
		 * Collect the credentials currently typed into the form.
		 *
		 * @param {string} action Admin-ajax action name.
		 * @param {string} nonce  Nonce for that action.
		 * @return {FormData} Body for the request.
		 */
		function credentials( action, nonce ) {
			var body = new FormData();
			var shoptype = document.getElementById( 'wksync-shoptype' );

			body.append( 'action', action );
			body.append( 'nonce', nonce );
			body.append( 'api_base_url', document.getElementById( 'wksync-api-base-url' ).value );
			body.append( 'api_key', document.getElementById( 'wksync-api-key' ).value );

			if ( shoptype ) {
				body.append( 'shoptype', shoptype.value );
			}

			return body;
		}

		/**
		 * POST to admin-ajax and hand the decoded result to a callback.
		 *
		 * @param {FormData} body     Request body.
		 * @param {Element}  button   Button to disable for the duration.
		 * @param {Element}  output   Paragraph for status messages.
		 * @param {string}   failed   Message for a transport-level failure.
		 * @param {Function} onResult Called with the decoded result on success.
		 */
		function send( body, button, output, failed, onResult ) {
			button.disabled = true;

			window.fetch( wksyncSettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( result ) {
				var message = result && result.data && result.data.message
					? result.data.message
					: failed;

				report( output, message, ! ( result && result.success ) );

				if ( result && result.success && onResult ) {
					onResult( result );
				}
			} ).catch( function () {
				report( output, failed, true );
			} ).finally( function () {
				button.disabled = false;
			} );
		}

		var testButton = document.getElementById( 'wksync-test-connection' );
		var testOutput = document.getElementById( 'wksync-test-result' );

		if ( testButton && testOutput ) {
			testButton.addEventListener( 'click', function () {
				report( testOutput, wksyncSettings.testing, false );

				send(
					credentials( 'wksync_test_connection', wksyncSettings.nonce ),
					testButton,
					testOutput,
					wksyncSettings.failed
				);
			} );
		}

		var makersButton = document.getElementById( 'wksync-fetch-manufacturers' );
		var makersClear = document.getElementById( 'wksync-clear-manufacturers' );
		var makersOutput = document.getElementById( 'wksync-manufacturers-result' );
		var makersSummary = document.getElementById( 'wksync-manufacturers-summary' );
		var makersList = document.getElementById( 'wksync-manufacturer-list' );
		var makersNames = document.getElementById( 'wksync-manufacturer-names' );

		if ( makersButton && makersClear && makersOutput && makersSummary && makersList && makersNames ) {
			/**
			 * Every manufacturer checkbox currently in the list.
			 *
			 * @return {Array} The checkbox elements.
			 */
			function makerBoxes() {
				return Array.prototype.slice.call(
					makersList.querySelectorAll( 'input[type="checkbox"]' )
				);
			}

			/**
			 * The IDs and names of the ticked manufacturers.
			 *
			 * @return {Object} Map of ID to display name.
			 */
			function tickedMakers() {
				var ticked = {};

				makerBoxes().forEach( function ( box ) {
					if ( box.checked ) {
						var label = box.parentNode.querySelector( '.wksync-choice-label' );

						ticked[ box.value ] = label ? label.textContent.trim() : box.value;
					}
				} );

				return ticked;
			}

			/**
			 * Reflect the current selection in the hidden names field and the summary.
			 *
			 * The names ride along purely so the saved selection still reads as names
			 * after a reload. Nothing is decided by them server-side.
			 */
			function refreshMakers() {
				var ticked = tickedMakers();
				var count = Object.keys( ticked ).length;
				var template;

				makersNames.value = JSON.stringify( ticked );
				makersClear.disabled = 0 === count;

				if ( 0 === count ) {
					makersSummary.textContent = wksyncSettings.summaryNone;

					return;
				}

				template = 1 === count ? wksyncSettings.summaryOne : wksyncSettings.summaryMany;
				makersSummary.textContent = template.replace( '%s', String( count ) );
			}

			/**
			 * Add one manufacturer checkbox to the list.
			 *
			 * Built here rather than by cloning a template so the markup matches what
			 * PHP renders for an already-saved selection.
			 *
			 * @param {Object}  maker  Manufacturer with id and name.
			 * @param {boolean} ticked Whether it starts selected.
			 */
			function addMaker( maker, ticked ) {
				var label = document.createElement( 'label' );
				var box = document.createElement( 'input' );
				var name = document.createElement( 'span' );
				var id = document.createElement( 'span' );

				box.type = 'checkbox';
				box.name = makersList.getAttribute( 'data-field' );
				box.value = maker.id;
				box.checked = ticked;

				name.className = 'wksync-choice-label';
				name.textContent = maker.name;

				id.className = 'wksync-choice-id';
				id.textContent = maker.id;

				label.appendChild( box );
				label.appendChild( document.createTextNode( ' ' ) );
				label.appendChild( name );
				label.appendChild( document.createTextNode( ' ' ) );
				label.appendChild( id );

				makersList.appendChild( label );
			}

			// One listener on the container, so checkboxes added later are covered too.
			makersList.addEventListener( 'change', refreshMakers );

			makersClear.addEventListener( 'click', function () {
				makerBoxes().forEach( function ( box ) {
					box.checked = false;
				} );

				refreshMakers();
			} );

			makersButton.addEventListener( 'click', function () {
				report( makersOutput, wksyncSettings.fetchingManufacturers, false );

				send(
					credentials( 'wksync_fetch_manufacturers', wksyncSettings.manufacturersNonce ),
					makersButton,
					makersOutput,
					wksyncSettings.manufacturersFailed,
					function ( result ) {
						var previous = tickedMakers();
						var chosen = Object.keys( previous );
						var listed = [];
						var makers = result.data && result.data.manufacturers
							? result.data.manufacturers
							: [];

						makersList.textContent = '';

						makers.forEach( function ( maker ) {
							listed.push( maker.id );

							// Keep the current selection if Kontor still lists it.
							addMaker( maker, -1 !== chosen.indexOf( maker.id ) );
						} );

						/*
						 * A ticked manufacturer Kontor no longer lists stays ticked, at the
						 * end of the list. Pressing a button to look something up must not
						 * quietly edit the selection underneath it.
						 */
						chosen.forEach( function ( id ) {
							if ( -1 === listed.indexOf( id ) ) {
								addMaker( { id: id, name: previous[ id ] }, true );
							}
						} );

						refreshMakers();
						report( makersOutput, wksyncSettings.unsavedManufacturers, false );
					}
				);
			} );
		}

		var shopsButton = document.getElementById( 'wksync-fetch-shops' );
		var shopsOutput = document.getElementById( 'wksync-shops-result' );
		var shopSelect = document.getElementById( 'wksync-shop-id' );
		var shopName = document.getElementById( 'wksync-shop-name' );

		if ( ! shopsButton || ! shopsOutput || ! shopSelect || ! shopName ) {
			return;
		}

		/*
		 * The name is submitted alongside the ID purely so the saved shop still reads
		 * as a name after a reload. Nothing is decided by it server-side.
		 */
		shopSelect.addEventListener( 'change', function () {
			var chosen = shopSelect.options[ shopSelect.selectedIndex ];

			shopName.value = shopSelect.value ? chosen.textContent.trim() : '';
		} );

		shopsButton.addEventListener( 'click', function () {
			report( shopsOutput, wksyncSettings.fetchingShops, false );

			send(
				credentials( 'wksync_fetch_shops', wksyncSettings.shopsNonce ),
				shopsButton,
				shopsOutput,
				wksyncSettings.shopsFailed,
				function ( result ) {
					var previous = shopSelect.value;
					var shops = result.data && result.data.shops ? result.data.shops : [];
					var matched = false;

					shopSelect.textContent = '';
					shopSelect.appendChild( new Option( wksyncSettings.noShop, '' ) );

					shops.forEach( function ( shop ) {
						var option = new Option( shop.name, shop.id );

						// Keep the current selection if Kontor still lists it.
						if ( shop.id === previous ) {
							option.selected = true;
							matched = true;
						}

						shopSelect.appendChild( option );
					} );

					if ( ! matched ) {
						shopName.value = '';
					}

					report( shopsOutput, wksyncSettings.unsavedShop, false );
				}
			);
		} );
	} );
}() );
