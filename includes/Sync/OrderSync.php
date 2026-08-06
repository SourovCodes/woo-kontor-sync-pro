<?php
/**
 * Order upload to Kontor.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use Exception;
use WC_Order;
use WC_Order_Item_Product;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Pushes WooCommerce orders into Kontor.
 *
 * The only write this plugin performs. Two entry points feed it: an order reaching
 * a pushable status queues a single-order action, and a periodic sweep catches
 * anything the hook missed — a failed run, a site that was down, an order edited
 * into a pushable status by hand.
 *
 * Kontor deduplicates on orderNumber with overwrite_all left false, so re-sending
 * is safe: the second attempt comes back as a per-order "Dublette" instead of
 * creating a duplicate. That reply is treated as success, because it means the
 * order is in the ERP, which is the outcome this job exists to guarantee.
 */
class OrderSync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'orders';

	/**
	 * Meta holding Kontor's internal order number (Auftrnr).
	 */
	const META_KONTOR_ORDER = '_wksync_kontor_order_id';

	/**
	 * Meta holding the time the order reached Kontor.
	 */
	const META_PUSHED_AT = '_wksync_pushed_at';

	/**
	 * Meta holding the order number sent as the deduplication key.
	 *
	 * That key is the order ID, which never changes. It is still recorded rather
	 * than recomputed, so the delivery sync matches on the value Kontor was actually
	 * given even if an order sent by an older version of this plugin used something
	 * else.
	 */
	const META_ORDER_NUMBER = '_wksync_order_number';

	/**
	 * Meta holding the reason the last push attempt was rejected.
	 */
	const META_PUSH_ERROR = '_wksync_push_error';

	/**
	 * The value sent as the required meta.userId.
	 *
	 * Kontor requires the field on every upload and does not validate it; this is the
	 * value agreed for this integration. Fixed rather than filterable, because the
	 * settings screen shows it as read-only and a filter would make that display a
	 * lie about what is actually sent.
	 */
	const UPLOAD_USER_ID = 'WKSP';

	/**
	 * How many orders to send in one request.
	 */
	const BATCH_SIZE = 25;

	/**
	 * How many orders one sweep will look at.
	 */
	const SWEEP_LIMIT = 200;

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Client|null $client   Optional client override, mainly for tests.
	 * @param array|null  $settings Optional settings override, mainly for tests.
	 */
	public function __construct( $client = null, $settings = null ) {
		$this->settings = null === $settings ? Settings::get_settings() : $settings;
		$this->client   = null === $client ? new Client( $this->settings ) : $client;
	}

	/**
	 * The order statuses that get pushed to Kontor.
	 *
	 * Paid states only. An order that is still pending or on hold has not been paid
	 * for, and one that is cancelled or refunded should never reach the ERP as a
	 * live order.
	 *
	 * @return string[] Status slugs, without the "wc-" prefix.
	 */
	public static function pushable_statuses() {
		/**
		 * Filters the order statuses that are sent to Kontor.
		 *
		 * @since 0.3.0
		 *
		 * @param string[] $statuses Status slugs without the "wc-" prefix.
		 */
		return (array) apply_filters(
			'woo_kontor_sync_pushable_statuses',
			array( 'processing', 'completed' )
		);
	}

	/**
	 * Queue an order for pushing after it reaches a pushable status.
	 *
	 * Called from an order status hook, which runs inside a request a customer may
	 * be waiting on, so this only ever enqueues an action.
	 *
	 * @param int $order_id Order that changed status.
	 * @return void
	 */
	public function enqueue( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! in_array( $order->get_status(), self::pushable_statuses(), true ) ) {
			return;
		}

		if ( $order->get_meta( self::META_PUSHED_AT ) ) {
			return;
		}

		Scheduler::chain( Scheduler::ACTION_SYNC_ORDER, array( 'order_id' => (int) $order->get_id() ) );
	}

	/**
	 * Push a single order, identified by ID.
	 *
	 * @param int $order_id Order to push.
	 * @return void
	 */
	public function push_one( $order_id ) {
		$order = wc_get_order( $order_id );

		// Settled locally first: there is no point paying for a preflight round trip
		// to decide not to send anything.
		if ( ! $order || $order->get_meta( self::META_PUSHED_AT ) ) {
			return;
		}

		$ready = Preflight::check( self::JOB, $this->settings );

		if ( is_wp_error( $ready ) ) {
			$this->log( 'error', sprintf( 'Order %d not sent: %s', $order_id, $ready->get_error_message() ) );

			return;
		}

		$this->send( array( $order ) );
	}

	/**
	 * Sweep for orders that should have reached Kontor and have not.
	 *
	 * @return void
	 */
	public function start() {
		if ( Status::is_running( self::JOB ) ) {
			$this->log( 'info', 'Order sync already running; ignoring the request to start another.' );

			return;
		}

		$ready = Preflight::check( self::JOB, $this->settings );

		if ( is_wp_error( $ready ) ) {
			Status::fail( self::JOB, $ready->get_error_message() );
			$this->log( 'error', 'Order sync refused to start: ' . $ready->get_error_message() );

			return;
		}

		Status::start( self::JOB );

		$orders = $this->pending_orders();

		if ( empty( $orders ) ) {
			Status::finish( self::JOB, __( 'No orders were waiting to be sent.', 'woo-kontor-sync-pro' ) );

			return;
		}

		foreach ( array_chunk( $orders, self::BATCH_SIZE ) as $batch ) {
			$this->send( $batch );
		}

		$counts = Status::get( self::JOB )['counts'];

		Status::finish(
			self::JOB,
			sprintf(
				/* translators: 1: orders accepted, 2: orders already present, 3: orders rejected. */
				__( '%1$d sent, %2$d already in Kontor, %3$d rejected.', 'woo-kontor-sync-pro' ),
				isset( $counts['sent'] ) ? (int) $counts['sent'] : 0,
				isset( $counts['duplicate'] ) ? (int) $counts['duplicate'] : 0,
				isset( $counts['failed'] ) ? (int) $counts['failed'] : 0
			)
		);
	}

	/**
	 * Orders in a pushable status that have never reached Kontor.
	 *
	 * @return WC_Order[] Orders to send.
	 */
	protected function pending_orders() {
		$orders = wc_get_orders(
			array(
				'limit'      => self::SWEEP_LIMIT,
				'status'     => self::pushable_statuses(),
				'orderby'    => 'date',
				'order'      => 'ASC',
				'return'     => 'objects',

				/*
				 * Under HPOS this is a query against the order meta table, not postmeta.
				 * It runs in a background sweep, and there is no other way to ask for
				 * "orders this plugin has never sent".
				 */
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
				'meta_query' => array(
					array(
						'key'     => self::META_PUSHED_AT,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Map a batch of orders, send it, and record what came back.
	 *
	 * @param WC_Order[] $orders Orders to send.
	 * @return void
	 */
	protected function send( array $orders ) {
		$payload = array();
		$by_key  = array();

		foreach ( $orders as $order ) {
			try {
				$mapped = $this->build_payload( $order );
			} catch ( Exception $exception ) {
				Status::progress( self::JOB, array( 'failed' => 1 ) );
				$this->log( 'error', sprintf( 'Order %d could not be mapped: %s', $order->get_id(), $exception->getMessage() ) );

				continue;
			}

			$payload[]                        = $mapped;
			$by_key[ $mapped['orderNumber'] ] = $order;
		}

		if ( empty( $payload ) ) {
			return;
		}

		$response = $this->client->push_orders(
			$payload,
			(string) $this->settings['shop_id'],
			self::UPLOAD_USER_ID
		);

		if ( is_wp_error( $response ) ) {
			Status::progress( self::JOB, array( 'failed' => count( $payload ) ) );
			$this->log( 'error', 'Order batch was not accepted: ' . $response->get_error_message() );

			return;
		}

		$this->interpret_rows( $response['data'], $by_key );
	}

	/**
	 * Apply Kontor's per-order verdicts.
	 *
	 * The batch reply reports success at the top level even when every row failed,
	 * so the rows are the only thing worth reading.
	 *
	 * An order the reply says nothing about at all is counted as failed. Nothing is
	 * written on it, so the next sweep sends it again — but silently dropping it from
	 * the counts would report a batch of twenty-five as "five sent" and leave nobody
	 * any reason to look.
	 *
	 * @param array      $rows   Result rows from the upsert reply.
	 * @param WC_Order[] $by_key Orders keyed by the order number that was sent.
	 * @return void
	 */
	protected function interpret_rows( array $rows, array $by_key ) {
		$counts = array(
			'sent'      => 0,
			'duplicate' => 0,
			'failed'    => 0,
		);

		$reported = array();

		foreach ( $rows as $row ) {
			$number = isset( $row['orderNumber'] ) ? (string) $row['orderNumber'] : '';
			$order  = isset( $by_key[ $number ] ) ? $by_key[ $number ] : null;

			if ( ! $order ) {
				// A row we cannot tie back to an order, including the "no orders in the
				// array" reply, which carries a null order number.
				$this->log( 'warning', sprintf( 'Kontor reported on an order number we did not send: %s', '' === $number ? '(null)' : $number ) );

				continue;
			}

			$reported[ $number ] = true;

			$status  = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : '';
			$message = isset( $row['message'] ) ? (string) $row['message'] : '';

			if ( 'ok' === $status ) {
				$this->mark_pushed( $order, isset( $row['auftrnr'] ) ? (string) $row['auftrnr'] : '', $number );
				++$counts['sent'];

				continue;
			}

			/*
			 * A duplicate means the order is already in Kontor — the goal of this job —
			 * so it counts as done rather than as a failure to retry forever. Kontor
			 * does not return the existing Auftrnr here; the delivery sync fills it in.
			 */
			if ( $this->is_duplicate( $message ) ) {
				$this->mark_pushed( $order, '', $number );
				++$counts['duplicate'];

				continue;
			}

			$order->update_meta_data( self::META_PUSH_ERROR, $message );
			$order->save();

			++$counts['failed'];

			$this->log(
				'error',
				sprintf( 'Kontor rejected order %s: %s', $number, '' === $message ? '(no reason given)' : $message )
			);
		}

		foreach ( $by_key as $number => $order ) {
			if ( isset( $reported[ $number ] ) ) {
				continue;
			}

			$order->update_meta_data(
				self::META_PUSH_ERROR,
				__( 'Kontor accepted the batch but said nothing about this order.', 'woo-kontor-sync-pro' )
			);
			$order->save();

			++$counts['failed'];

			$this->log(
				'error',
				sprintf( 'Kontor returned no verdict for order %s; it will be sent again on the next sweep.', $number )
			);
		}

		Status::progress( self::JOB, $counts );
	}

	/**
	 * Whether a rejection message means the order was already there.
	 *
	 * @param string $message Message from the result row.
	 * @return bool True when the order exists in Kontor already.
	 */
	protected function is_duplicate( $message ) {
		return false !== stripos( $message, 'Dublette' );
	}

	/**
	 * Record that an order reached Kontor.
	 *
	 * @param WC_Order $order   Order that was accepted.
	 * @param string   $auftrnr Kontor's internal order number, when it gave one.
	 * @param string   $number  Order number that was sent as the deduplication key.
	 * @return void
	 */
	protected function mark_pushed( $order, $auftrnr, $number ) {
		$order->update_meta_data( self::META_PUSHED_AT, time() );
		$order->update_meta_data( self::META_ORDER_NUMBER, $number );
		$order->delete_meta_data( self::META_PUSH_ERROR );

		if ( '' !== $auftrnr ) {
			$order->update_meta_data( self::META_KONTOR_ORDER, $auftrnr );
		}

		$order->add_order_note(
			'' === $auftrnr
				? __( 'Already present in Kontor; not sent again.', 'woo-kontor-sync-pro' )
				: sprintf(
					/* translators: %s: Kontor's internal order number. */
					__( 'Sent to Kontor as %s.', 'woo-kontor-sync-pro' ),
					$auftrnr
				)
		);

		$order->save();
	}

	/**
	 * Find the order a row coming back from Kontor belongs to.
	 *
	 * Matched on the order number this plugin sent, which is recorded at push time
	 * rather than recomputed. get_order_number() is filterable, so comparing against
	 * it directly would stop matching the day a sequential-order-number plugin is
	 * installed — including for orders pushed long before that.
	 *
	 * Lives here because this is where the number is written, and both the delivery
	 * and invoice imports have to read it back the same way.
	 *
	 * @param string $number Order number as Kontor knows it.
	 * @return WC_Order|null The order, or null when nothing matches.
	 */
	public static function find_by_number( $number ) {
		$number = trim( (string) $number );

		if ( '' === $number ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => 'any',
				'return'     => 'objects',

				/*
				 * Under HPOS this queries the order meta table. Runs in a background job,
				 * and the number Kontor knows is only recorded as meta.
				 */
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
				'meta_query' => array(
					array(
						'key'     => self::META_ORDER_NUMBER,
						'value'   => $number,
						'compare' => '=',
					),
				),
			)
		);

		return empty( $orders ) ? null : $orders[0];
	}

	/**
	 * Map a WooCommerce order onto the API's order shape.
	 *
	 * @param WC_Order $order Order to map.
	 * @return array Order in the shape the upsert endpoint expects.
	 */
	public function build_payload( $order ) {
		/*
		 * The two order fields carry different values on purpose.
		 *
		 * orderNumber is Kontor's deduplication key and the value the delivery sync
		 * matches rows back on, so it has to be stable for the life of the order: the
		 * order ID is, and get_order_number() is not — it is filterable, and installing
		 * a sequential-order-number plugin would change what every existing order calls
		 * itself.
		 *
		 * orderId carries what the shop displays, so an order can still be found in the
		 * ERP by the number the customer and the shop manager both see.
		 */
		$number  = (string) $order->get_id();
		$display = (string) $order->get_order_number();
		$date    = $order->get_date_created();

		/*
		 * orderPlatformid is optional and deliberately not sent: it identifies the
		 * platform to Kontor, and there is no agreed value for this integration.
		 * Inventing one would put a meaningless string on every order in the ERP.
		 */
		$payload = array(
			'orderId'              => $display,
			'orderNumber'          => $number,
			'orderDate'            => $date ? gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() ) : gmdate( 'Y-m-d\TH:i:s\Z' ),
			'currency'             => $order->get_currency(),
			'customerName'         => trim( $order->get_formatted_billing_full_name() ),
			'customerEmail'        => $order->get_billing_email(),
			'customerPhone'        => $order->get_billing_phone(),
			'customerGroup'        => isset( $this->settings['shoptype'] ) ? (string) $this->settings['shoptype'] : '',
			'language'             => substr( get_locale(), 0, 2 ),
			'billingAddress'       => $this->address( $order, 'billing' ),
			'deliveryAddress'      => $this->delivery_address( $order ),
			'shippingTotal'        => (float) $order->get_shipping_total(),
			'paymentMethod'        => $order->get_payment_method(),

			/*
			 * The gateway's own title, which is the wording the customer saw and the
			 * only thing that distinguishes two gateways sharing an id. paymentMethod
			 * stays as well: it is the stable slug, and the title is editable prose.
			 */
			'paymentMethodName'    => $order->get_payment_method_title(),

			/*
			 * The gateway's transaction reference, so a payment can be reconciled from
			 * the ERP without going back to the shop. Empty on an order that was never
			 * paid through a gateway, which is what the field expects.
			 */
			'paymentTransactionId' => $order->get_transaction_id(),

			/*
			 * Whether the amounts on this order include tax. Kontor has no way to tell
			 * from the numbers alone, and reading a gross total as net overstates every
			 * line by the VAT rate.
			 */
			'taxStatus'            => $order->get_prices_include_tax() ? 'gross' : 'net',
			'shippingMethod'       => $order->get_shipping_method(),
			'remarks'              => $order->get_customer_note(),
			'items'                => $this->items( $order ),
		);

		$customer_number = $order->get_customer_id();

		if ( $customer_number ) {
			$payload['customerNumber'] = (string) $customer_number;
		}

		/**
		 * Filters the payload for a single order before it is sent to Kontor.
		 *
		 * @since 0.3.0
		 *
		 * @param array    $payload Mapped order.
		 * @param WC_Order $order   Order it was mapped from.
		 */
		return (array) apply_filters( 'woo_kontor_sync_order_payload', $payload, $order );
	}

	/**
	 * Map the address the order ships to.
	 *
	 * Always sent, and always populated: WooCommerce leaves the shipping address
	 * empty on a virtual order, or on one where the customer did not tick "ship to a
	 * different address", and an order arriving in the ERP with no delivery address
	 * is one nobody can pick and pack. Billing is where it goes in that case, which
	 * is what WooCommerce itself falls back to on the order screen.
	 *
	 * Deciding on the street and postcode rather than on the whole address: a partial
	 * shipping address with only a name filled in is not somewhere a parcel can be
	 * sent.
	 *
	 * @param WC_Order $order Order to read.
	 * @return array Address in the shape the API expects.
	 */
	protected function delivery_address( $order ) {
		$has_shipping = '' !== trim( (string) $order->get_shipping_address_1() )
			|| '' !== trim( (string) $order->get_shipping_postcode() );

		return $this->address( $order, $has_shipping ? 'shipping' : 'billing' );
	}

	/**
	 * Map one of an order's addresses.
	 *
	 * @param WC_Order $order Order to read.
	 * @param string   $type  Either "billing" or "shipping".
	 * @return array Address in the shape the API expects.
	 */
	protected function address( $order, $type ) {
		$getter = function ( $field ) use ( $order, $type ) {
			$method = 'get_' . $type . '_' . $field;

			return method_exists( $order, $method ) ? (string) $order->{$method}() : '';
		};

		$address = array(
			'firstName'   => $getter( 'first_name' ),
			'lastName'    => $getter( 'last_name' ),
			'company'     => $getter( 'company' ),
			'name'        => trim( $getter( 'first_name' ) . ' ' . $getter( 'last_name' ) ),
			'street'      => $getter( 'address_1' ),
			'street2'     => $getter( 'address_2' ),
			'zipcode'     => $getter( 'postcode' ),
			'city'        => $getter( 'city' ),
			'countryCode' => $getter( 'country' ),
			'phone'       => $getter( 'phone' ),
		);

		return array_filter(
			$address,
			static function ( $value ) {
				return '' !== $value;
			}
		);
	}

	/**
	 * Map an order's line items.
	 *
	 * @param WC_Order $order Order to read.
	 * @return array Line items in the shape the API expects.
	 */
	protected function items( $order ) {
		$items    = array();
		$position = 1;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = $product ? (string) $product->get_sku() : '';

			/*
			 * SKU is the only key Kontor matches articles on, so a line without one
			 * cannot be resolved at the other end. Sending it anyway would have the ERP
			 * silently drop the line from an otherwise valid order.
			 */
			if ( '' === $sku ) {
				$this->log(
					'warning',
					sprintf( 'Order %s line %d has no SKU and was left out of the payload.', $order->get_order_number(), $item_id )
				);

				continue;
			}

			$quantity = (float) $item->get_quantity();
			$subtotal = (float) $item->get_subtotal();
			$total    = (float) $item->get_total();

			/*
			 * The three per-unit figures are all derived from the line itself, so they
			 * agree with each other and with totalPrice: regularPrice - discount is
			 * unitPrice, and unitPrice * quantity is totalPrice. Taking regularPrice
			 * from the product instead would read today's price for an order placed
			 * months ago, and would leave the arithmetic inconsistent the moment a
			 * coupon was involved.
			 *
			 * Rounded to four places rather than two for the same reason: two places on
			 * a per-unit figure cannot always be multiplied back up to the line total.
			 */
			$list = $quantity > 0 ? round( $subtotal / $quantity, 4 ) : 0.0;
			$paid = $quantity > 0 ? round( $total / $quantity, 4 ) : 0.0;

			$items[] = array(
				'itemId'       => (string) $item_id,
				'productId'    => (string) $item->get_product_id(),
				'sku'          => $sku,
				'description'  => $item->get_name(),
				'quantity'     => $quantity,
				'unitPrice'    => $paid,
				'regularPrice' => $list,

				/*
				 * The identity factor. Kontor multiplies by this, so what it defaults to
				 * on an absent field is the difference between the right price and none
				 * at all; sending 1 cannot change an amount either way.
				 */
				'priceFaktor'  => 1,
				'discount'     => round( $list - $paid, 4 ),
				'totalPrice'   => $total,
				'position'     => $position,
				'taxRate'      => $this->tax_rate( $item ),
			);

			++$position;
		}

		return $items;
	}

	/**
	 * Work out a line's tax rate as a percentage.
	 *
	 * Derived from the amounts on the line rather than looked up from the tax
	 * tables, so it stays correct for an order placed under a rate that has since
	 * been edited or removed.
	 *
	 * @param WC_Order_Item_Product $item Line item.
	 * @return float Tax rate percentage.
	 */
	protected function tax_rate( $item ) {
		$total = (float) $item->get_total();
		$tax   = (float) $item->get_total_tax();

		if ( $total <= 0.0 || $tax <= 0.0 ) {
			return 0.0;
		}

		return round( $tax / $total * 100, 2 );
	}

	/**
	 * Write a message to the WooCommerce log.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message to record.
	 * @return void
	 */
	protected function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => Client::LOG_SOURCE ) );
	}
}
