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
		 * Show one panel and mark its tab.
		 *
		 * Hiding rather than unloading, always. Every field on this screen posts into
		 * one option and the save reads an absent api_base_url as an empty one, so a
		 * panel taken out of the document would take the API URL with it the next time
		 * somebody pressed Save.
		 */
		( function () {
			var wrap = document.querySelector( '.wksync-settings' );

			if ( ! wrap ) {
				return;
			}

			var tabs = Array.prototype.slice.call( wrap.querySelectorAll( '[data-wksync-tab]' ) );
			var panels = Array.prototype.slice.call( wrap.querySelectorAll( '[data-wksync-panel]' ) );

			if ( ! tabs.length || ! panels.length ) {
				return;
			}

			// Only now does hiding begin. Before this line every panel is visible, which
			// is what a browser with no JavaScript is left with.
			wrap.classList.add( 'wksync-tabbed' );

			/**
			 * Bring one tab to the front.
			 *
			 * @param {string} key Tab key.
			 */
			function show( key ) {
				var found = panels.some( function ( panel ) {
					return panel.getAttribute( 'data-wksync-panel' ) === key;
				} );

				if ( ! found ) {
					key = panels[ 0 ].getAttribute( 'data-wksync-panel' );
				}

				panels.forEach( function ( panel ) {
					panel.classList.toggle( 'is-active', panel.getAttribute( 'data-wksync-panel' ) === key );
				} );

				tabs.forEach( function ( tab ) {
					var current = tab.getAttribute( 'data-wksync-tab' ) === key;

					tab.classList.toggle( 'nav-tab-active', current );
					tab.setAttribute( 'aria-current', current ? 'page' : 'false' );
				} );

				// The Save button belongs to the form rather than to a tab, so it is shown
				// only where something on screen actually posts into it.
				wrap.classList.toggle(
					'wksync-on-settings-tab',
					null !== wrap.querySelector( '.wksync-panel.is-active input, .wksync-panel.is-active select' )
						&& null !== wrap.querySelector( 'form[action$="options.php"] .wksync-panel.is-active' )
				);
			}

			tabs.forEach( function ( tab ) {
				tab.addEventListener( 'click', function ( event ) {
					event.preventDefault();

					var key = tab.getAttribute( 'data-wksync-tab' );

					show( key );

					/*
					 * The referer field is written when the page renders, so it still names
					 * whichever tab the URL carried then. options.php sends the save back to
					 * it, and without this a save made from Orders would land on Jobs.
					 *
					 * Done before the address bar, and not after it: replaceState throws on
					 * a cross-origin URL, and anything below a throw here would be a save
					 * quietly returning to the wrong tab.
					 */
					var referer = wrap.querySelector( 'input[name="_wp_http_referer"]' );

					if ( referer ) {
						referer.value = tab.getAttribute( 'href' );
					}

					/*
					 * The address bar is kept in step so the tab can be copied, bookmarked
					 * and reloaded. Guarded because it is the convenience of the two: a
					 * browser that refuses the history call should still switch tabs and
					 * still save to the right one.
					 */
					try {
						if ( window.history && window.history.replaceState ) {
							window.history.replaceState( {}, '', tab.getAttribute( 'href' ) );
						}
					} catch ( error ) {
						// Nothing to do about it, and nothing depends on it.
					}
				} );
			} );

			var initial = wrap.querySelector( '.nav-tab-active[data-wksync-tab]' );

			show( initial ? initial.getAttribute( 'data-wksync-tab' ) : panels[ 0 ].getAttribute( 'data-wksync-panel' ) );
		}() );

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
		 * The order and category settings are rendered whatever their toggles say and
		 * hidden when they are off, so ticking one reveals the rest straight away rather
		 * than after a save. The server decides the rendered state; this only keeps up
		 * with the boxes.
		 */
		var ordersToggle = document.getElementById( 'wksync-sync-orders' );
		var ordersPanel = document.getElementById( 'wksync-order-settings' );
		var categoriesToggle = document.getElementById( 'wksync-sync-categories' );
		var categoriesPanel = document.getElementById( 'wksync-category-settings' );
		var shopRow = document.getElementById( 'wksync-shop-row' );

		/*
		 * The shop is wanted by two unrelated features now, so it follows whichever of
		 * them is on rather than belonging to either. A shop that does neither is still
		 * never asked to choose one.
		 */
		function syncShopRow() {
			if ( ! shopRow ) {
				return;
			}

			shopRow.hidden = ! (
				( ordersToggle && ordersToggle.checked ) ||
				( categoriesToggle && categoriesToggle.checked )
			);
		}

		if ( ordersToggle ) {
			ordersToggle.addEventListener( 'change', function () {
				if ( ordersPanel ) {
					ordersPanel.hidden = ! ordersToggle.checked;
				}

				syncShopRow();
			} );
		}

		if ( categoriesToggle ) {
			categoriesToggle.addEventListener( 'change', function () {
				if ( categoriesPanel ) {
					categoriesPanel.hidden = ! categoriesToggle.checked;
				}

				syncShopRow();
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
				/*
				 * A hidden tab is nobody watching. Left running, a settings screen forgotten
				 * in a background tab goes on asking the server where a sync has got to for
				 * as long as the browser is open. The visibility listener below starts it
				 * again the moment the tab comes back, so nothing is lost by waiting.
				 */
				if ( 'hidden' === document.visibilityState ) {
					return;
				}

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

			/**
			 * Begin polling, unless it is already under way.
			 */
			function start() {
				if ( null === timer ) {
					timer = window.setInterval( poll, wksyncSettings.progressInterval );
				}

				poll();
			}

			var running = table.querySelector( '.wksync-progress:not([hidden])' );
			var queued = table.querySelector( '.wksync-image-queue:not([hidden])' );

			if ( running || queued ) {
				start();

				// Coming back to the tab should show the current state at once rather than
				// whatever it said when the reader looked away.
				document.addEventListener( 'visibilitychange', function () {
					if ( 'visible' === document.visibilityState && null !== timer ) {
						poll();
					}
				} );
			}
		}() );
	} );
}() );
