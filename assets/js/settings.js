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

		/*
		 * The order settings are rendered whatever the toggle says and hidden when it
		 * is off, so ticking it reveals them straight away rather than after a save.
		 * The server decides the rendered state; this only keeps up with the box.
		 */
		var ordersToggle = document.getElementById( 'wksync-sync-orders' );
		var ordersPanel = document.getElementById( 'wksync-order-settings' );

		if ( ordersToggle && ordersPanel ) {
			ordersToggle.addEventListener( 'change', function () {
				ordersPanel.hidden = ! ordersToggle.checked;
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

		/*
		 * Progress bars.
		 *
		 * The table is rendered complete by PHP, so this only ever updates what is
		 * already there. Polling runs while at least one job is running and stops as
		 * soon as none is, which on a normally idle site means it never starts at all.
		 */
		( function () {
			var table = document.getElementById( 'wksync-jobs' );
			var timer = null;

			if ( ! table ) {
				return;
			}

			/**
			 * Update one job's row from the polled status.
			 *
			 * @param {string} key   Job key.
			 * @param {Object} state Status for that job.
			 */
			function paint( key, state ) {
				var row = table.querySelector( '[data-wksync-job="' + key + '"]' );

				if ( ! row ) {
					return;
				}

				var summary = row.querySelector( '.wksync-summary' );
				var wrapper = row.querySelector( '.wksync-progress' );
				var bar = row.querySelector( '.wksync-progress-bar' );
				var position = row.querySelector( '.wksync-position' );
				var nextRun = row.querySelector( '.wksync-next-run' );

				if ( summary ) {
					summary.textContent = state.summary;
				}

				if ( nextRun ) {
					nextRun.textContent = state.nextText;
				}

				if ( position ) {
					position.textContent = state.detail;
				}

				if ( wrapper ) {
					wrapper.hidden = ! state.running;
				}

				if ( ! bar ) {
					return;
				}

				/*
				 * A progress element with no value renders as indeterminate, which is
				 * exactly right for a run that has not counted its work yet — removing the
				 * attribute is what produces that, rather than setting it to zero and
				 * claiming no progress has been made.
				 */
				if ( null === state.percent || undefined === state.percent ) {
					bar.removeAttribute( 'value' );
				} else {
					bar.value = state.percent;
				}
			}

			/**
			 * Show or hide the count of image downloads still queued.
			 *
			 * @param {Object} images Image queue state.
			 */
			function paintImages( images ) {
				var line = table.querySelector( '.wksync-image-queue' );

				if ( ! line ) {
					return;
				}

				line.textContent = images.text;
				line.hidden = images.pending < 1;
			}

			/**
			 * Read every job's position and repaint the table.
			 */
			function poll() {
				var body = new FormData();

				body.append( 'action', 'wksync_job_progress' );
				body.append( 'nonce', wksyncSettings.progressNonce );

				window.fetch( wksyncSettings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} ).then( function ( response ) {
					return response.json();
				} ).then( function ( result ) {
					if ( ! result || ! result.success || ! result.data ) {
						stop();

						return;
					}

					var busy = false;

					Object.keys( result.data.jobs ).forEach( function ( key ) {
						var state = result.data.jobs[ key ];

						paint( key, state );

						if ( state.running ) {
							busy = true;
						}
					} );

					paintImages( result.data.images );

					// Images keep the poll alive: they are the one thing that carries on
					// after every job has reported itself finished.
					if ( ! busy && result.data.images.pending < 1 ) {
						stop();
					}
				} ).catch( function () {
					// A failed poll is not worth a message on screen — the row still shows
					// what the page was rendered with. Stop rather than hammer.
					stop();
				} );
			}

			/**
			 * Stop polling.
			 */
			function stop() {
				if ( null !== timer ) {
					window.clearInterval( timer );
					timer = null;
				}
			}

			var running = table.querySelector( '.wksync-progress:not([hidden])' );
			var queued = table.querySelector( '.wksync-image-queue:not([hidden])' );

			if ( running || queued ) {
				timer = window.setInterval( poll, wksyncSettings.progressInterval );
				poll();
			}
		}() );
	} );
}() );
