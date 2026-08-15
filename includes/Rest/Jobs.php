<?php
/**
 * The sync jobs, over REST.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Rest;

use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
use WooKontorSync\Sync\StockSync;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Starts the product and stock syncs, and reports where a run has got to.
 *
 * Until this existed, a sync could only be started by its schedule or by the Run now
 * button, and a run could only be watched through the settings screen's progress bar —
 * both behind `is_admin()` and a WordPress nonce, so nothing outside a logged-in browser
 * session could start a sync or find out how it went. A deploy script, an ERP-side hook
 * and a monitoring dashboard all want exactly that and none of them has a browser.
 *
 * **Only the product and stock syncs are exposed.** The other three are not shy of an
 * API for want of one: they need a shop selected, they push financial records, and the
 * delivery sync completes orders, which emails customers. None of that is refused
 * forever — it is simply not what anybody has asked for, and each is a different
 * question to answer.
 *
 * The payload is numbers rather than sentences. `Admin\Settings` renders the same status
 * into English or German prose for its own screen; publishing that here would owe every
 * machine reading it a stable translated wording, and give a client no way to key on
 * anything but text.
 *
 * One class rather than the namespace-plus-controllers arrangement a larger plugin would
 * use, because with one controller the registrar would be an empty layer, and the only
 * place in this plugin where a component is registered by a sibling instead of by
 * `Plugin::init()`. **When a second controller appears, REST_NAMESPACE moves to a
 * `Rest\RestApi` that registers both** — at that point two classes would otherwise be
 * importing a constant off one of their own siblings.
 */
class Jobs {

	/**
	 * The REST namespace the routes live under.
	 *
	 * **The `wc-` prefix is load-bearing and must not be tidied away.** WooCommerce
	 * authenticates a consumer key and secret from `WC_REST_Authentication` on
	 * `determine_current_user`, but only for a request `is_request_to_rest_api()` agrees
	 * is one of its own — and that method decides by looking for `wc/` or `wc-` in the
	 * request URI. A namespace of `wksync/v1` registers and routes perfectly well and
	 * then answers 401 to every client holding a key, because the credentials are never
	 * read at all. The prefix is WooCommerce's documented opening for exactly this; the
	 * comment beside it in core reads "Allow third party plugins use our authentication
	 * methods".
	 *
	 * Versioned separately from the plugin: a `v2` would be added beside this one and
	 * both served for as long as anything in the field still asks for the older shape.
	 */
	const REST_NAMESPACE = 'wc-wksync/v1';

	/**
	 * The jobs this API serves.
	 *
	 * The one list of what is exposed. It is also the route parameter's `enum`, so an
	 * unknown slug — or a real job this API does not serve, like `orders` — is refused by
	 * WordPress with `rest_invalid_param` before any callback runs, and there is no
	 * second list of permitted jobs to drift out of step with this one.
	 *
	 * The slugs come from the sync classes themselves rather than being spelled out, so
	 * they cannot disagree with the keys `Scheduler::get_jobs()` uses.
	 */
	const EXPOSED = array( ProductSync::JOB, StockSync::JOB );

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * The job is a path segment rather than a query parameter, so a run is addressed by
	 * the thing it acts on. `[\w-]+` cannot match a slash, which is what keeps
	 * `/jobs/products/run` on the run route instead of resolving it as the item route
	 * for a job called "products/run"; a slug outside the enum lands on a 400 that names
	 * the parameter rather than falling off the route table into a bare 404.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				'schema' => array( $this, 'get_collection_schema' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<job>[\w-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => $this->job_arg(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<job>[\w-]+)/run',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_item' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => $this->job_arg(),
				),
				'schema' => array( $this, 'get_run_schema' ),
			)
		);
	}

	/**
	 * Who may use these routes.
	 *
	 * The same capability the settings screen is gated on, named from there rather than
	 * spelled out again, so the API and the screen cannot drift apart on who is allowed
	 * to start a sync.
	 *
	 * Reads and writes share it. Someone who may press Run now may also watch the bar,
	 * and the read/write distinction that matters for an API client is enforced a layer
	 * above this by WooCommerce, which refuses a read-only key on a POST before the
	 * request reaches a callback.
	 *
	 * **There is deliberately no nonce on the POST**, and that is not the gap in a state
	 * change our own rules would otherwise require. Authentication happens before the
	 * callback on a REST route — a signed WooCommerce key, or `X-WP-Nonce` for a client
	 * riding on a login cookie, which core verifies itself — and the authorisation half
	 * belongs here, in the permission callback. What would be wrong is `__return_true`.
	 *
	 * @return true|WP_Error True when the request may proceed.
	 */
	public function permission_check() {
		if ( current_user_can( Settings::CAPABILITY ) ) {
			return true;
		}

		return new WP_Error(
			'wksync_rest_forbidden',
			__( 'You do not have permission to do that.', 'woo-kontor-sync-pro' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Every job this API serves.
	 *
	 * @return WP_REST_Response The jobs, and the image queue behind them.
	 */
	public function get_items() {
		$jobs = array();

		foreach ( self::EXPOSED as $job ) {
			$jobs[] = $this->prepare_job( $job );
		}

		return new WP_REST_Response(
			array(
				'jobs'        => $jobs,
				'image_queue' => Scheduler::pending_count( Scheduler::ACTION_SYNC_PRODUCT_IMAGES ),
			),
			200
		);
	}

	/**
	 * One job.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The job.
	 */
	public function get_item( $request ) {
		return new WP_REST_Response( $this->prepare_job( (string) $request['job'] ), 200 );
	}

	/**
	 * Queue a run of one job.
	 *
	 * Answers 202 rather than 200, because that is all it can honestly claim: the job is
	 * queued, not done, and `Scheduler::trigger()` makes no request to Kontor on the way
	 * — so a 202 does not even mean the credentials authenticate. That answer arrives
	 * later, in the job's own status, and this is why the response carries the status
	 * with it rather than leaving a caller to guess where to look.
	 *
	 * The run identifier is read *before* the job is queued and the progress *after*,
	 * which is what makes the two useful together. Nothing here can mint a run
	 * identifier — only the job does that, from inside the action — so a caller tells
	 * its own run from the last one by watching for `run_id` to change. Reading the
	 * previous one first means that comparison still holds when the queue is quick
	 * enough to have started the run before this response was assembled.
	 *
	 * A second run queued while one is already waiting is not refused. `trigger()` does
	 * not refuse it, and refusing it only here would make this disagree with the button
	 * on the settings screen — and would turn down a run because a recurring one happens
	 * to be due next week. Whichever action runs second finds the job already running
	 * and returns.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error What was queued, or why it was not.
	 */
	public function run_item( $request ) {
		$job      = (string) $request['job'];
		$previous = (int) Status::get( $job )['started'];

		$queued = Scheduler::trigger( $job );

		if ( is_wp_error( $queued ) ) {
			return $this->refusal( $queued );
		}

		return new WP_REST_Response(
			array(
				'job'             => $job,
				'previous_run_id' => $previous,
				'progress'        => $this->prepare_job( $job ),
			),
			202
		);
	}

	/**
	 * How one job stands.
	 *
	 * `state` and `running` are both reported because they are different questions. The
	 * first is what the job last recorded about itself; the second additionally asks
	 * whether that record is still believable, since a run only ever leaves the running
	 * state from inside one of its own actions and a crashed one would otherwise look
	 * in flight forever. A stale run therefore reads as running and not running at once,
	 * which is the truth of it — and is exactly when a new run will be accepted.
	 *
	 * `queued` is what a caller watches after triggering, and it counts actions in
	 * progress as well as waiting: see `Scheduler::queued_count()` for why the
	 * distinction decides whether the answer is usable at all. A `queued` a caller did
	 * not cause is ordinary — a recurring run due to fire is queued too — which is why
	 * the identifier is published beside it.
	 *
	 * @param string $job Job key.
	 * @return array The job's status, as the API reports it.
	 */
	protected function prepare_job( $job ) {
		$jobs   = Scheduler::get_jobs();
		$status = Status::get( $job );
		$hook   = isset( $jobs[ $job ]['action'] ) ? (string) $jobs[ $job ]['action'] : '';

		return array(
			'job'          => $job,
			'label'        => isset( $jobs[ $job ]['label'] ) ? (string) $jobs[ $job ]['label'] : $job,
			'state'        => (string) $status['state'],
			'running'      => Status::is_running( $job ),
			'queued'       => '' !== $hook && Scheduler::queued_count( $hook ) > 0,
			'run_id'       => (int) $status['started'],
			'started_gmt'  => $this->gmt( $status['started'] ),
			'finished_gmt' => $this->gmt( $status['finished'] ),
			'percent'      => Status::percentage( $status ),
			'total'        => (int) $status['total'],
			'processed'    => (int) $status['processed'],
			// An idle job's counts are an empty array, which would encode as a list
			// where every other response has an object. Cast so the type is the same
			// whatever has happened.
			'counts'       => (object) array_map( 'intval', (array) $status['counts'] ),
			'message'      => (string) $status['message'],
			'next_run_gmt' => $this->gmt( Scheduler::next_run( $job ) ),
		);
	}

	/**
	 * A refusal from the scheduler, with an HTTP status put on it.
	 *
	 * The status is added here rather than in the scheduler. Those errors are also
	 * handed to the settings screen, which travels their *codes* through a redirect and
	 * has no notion of HTTP at all, so an HTTP status stored on them would be a concern
	 * recorded in the wrong layer. The code itself is carried through unchanged: it is
	 * already the name that path knows the refusal by, and a `rest_`-prefixed alias
	 * would give one refusal two names.
	 *
	 * @param WP_Error $queued The scheduler's refusal.
	 * @return WP_Error The same refusal, answerable over HTTP.
	 */
	protected function refusal( WP_Error $queued ) {
		$code = (string) $queued->get_error_code();

		return new WP_Error(
			$code,
			$queued->get_error_message(),
			array( 'status' => $this->status_for_code( $code ) )
		);
	}

	/**
	 * The HTTP status one refusal deserves.
	 *
	 * A job already running is a conflict with the state of the thing addressed, and is
	 * the one refusal a caller can do something about: wait, and poll. The rest are this
	 * installation not being ready — no credentials, no shop, no Action Scheduler —
	 * which is a 503 rather than a 4xx, because there is nothing wrong with the request
	 * to correct, and rather than a 500, because a setting nobody has filled in is not a
	 * bug in this plugin. No `Retry-After` goes with it: there is no honest estimate of
	 * when somebody will configure a shop.
	 *
	 * `wksync_no_shop` cannot arise for either job served here — only the three
	 * order-side jobs are checked for a shop — and is mapped regardless, because a map
	 * with a hole in it is a 500 waiting for the day this list grows.
	 *
	 * Anything unrecognised stays a 500. A refusal this has never met is a fault here,
	 * and dressing it up as one of the four would hide that.
	 *
	 * @param string $code Error code from the scheduler.
	 * @return int HTTP status.
	 */
	protected function status_for_code( $code ) {
		$statuses = array(
			'wksync_already_running' => 409,
			'wksync_not_configured'  => 503,
			'wksync_unavailable'     => 503,
			'wksync_no_shop'         => 503,
		);

		return isset( $statuses[ $code ] ) ? $statuses[ $code ] : 500;
	}

	/**
	 * A stored timestamp as the API publishes it.
	 *
	 * ISO 8601 in UTC, the shape every date WooCommerce serves already has, since the
	 * clients of a `wc-` namespace are WooCommerce API clients. Null rather than an
	 * epoch or an empty string when there is no such moment, so "never finished" cannot
	 * be mistaken for a date in 1970.
	 *
	 * No site-local twin beside it: a machine reading this has no use for the shop's
	 * timezone, and two spellings of one instant is one too many.
	 *
	 * @param int $timestamp Unix timestamp, or 0.
	 * @return string|null The instant, or null when there is none.
	 */
	protected function gmt( $timestamp ) {
		$timestamp = (int) $timestamp;

		return $timestamp > 0 ? gmdate( 'Y-m-d\TH:i:s', $timestamp ) : null;
	}

	/**
	 * The job parameter, shared by the routes that name one.
	 *
	 * The `validate_callback` is declared rather than left to WordPress. Core adds none
	 * of its own for a route argument, and the enum would otherwise be enforced only by
	 * the fallback `sanitize_params()` reaches for — which a declared `sanitize_callback`
	 * silently replaces. So do not add one here out of habit: it would switch the
	 * validation off and let a job this API does not serve through to the scheduler.
	 *
	 * @return array Argument definition.
	 */
	protected function job_arg() {
		return array(
			'job' => array(
				'description'       => __( 'Which sync the request is about.', 'woo-kontor-sync-pro' ),
				'type'              => 'string',
				'enum'              => self::EXPOSED,
				'required'          => true,
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * The schema for the collection.
	 *
	 * @return array Schema.
	 */
	public function get_collection_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wksync_jobs',
			'type'       => 'object',
			'properties' => array(
				'jobs'        => array(
					'description' => __( 'Every sync this API serves.', 'woo-kontor-sync-pro' ),
					'type'        => 'array',
					'items'       => $this->get_item_schema(),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'image_queue' => array(
					'description' => __( 'Product images still waiting to be downloaded.', 'woo-kontor-sync-pro' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * The schema for one job.
	 *
	 * @return array Schema.
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wksync_job',
			'type'       => 'object',
			'properties' => $this->job_schema(),
		);
	}

	/**
	 * The schema for the answer to a run request.
	 *
	 * @return array Schema.
	 */
	public function get_run_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wksync_job_run',
			'type'       => 'object',
			'properties' => array(
				'job'             => array(
					'description' => __( 'The sync that was queued.', 'woo-kontor-sync-pro' ),
					'type'        => 'string',
					'enum'        => self::EXPOSED,
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'previous_run_id' => array(
					'description' => __( 'The run this job had recorded before the request. Watch for the identifier to change from this one.', 'woo-kontor-sync-pro' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'progress'        => array(
					'description' => __( 'How the job stood immediately after being queued.', 'woo-kontor-sync-pro' ),
					'type'        => 'object',
					'properties'  => $this->job_schema(),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * The fields describing one job.
	 *
	 * The one description of that object, so the three schemas above cannot drift into
	 * three accounts of it.
	 *
	 * @return array Property definitions.
	 */
	protected function job_schema() {
		return array(
			'job'          => array(
				'description' => __( 'Which sync this is. Key logic on this rather than on the label.', 'woo-kontor-sync-pro' ),
				'type'        => 'string',
				'enum'        => self::EXPOSED,
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'label'        => array(
				'description' => __( "The job's name for display, in the site's language.", 'woo-kontor-sync-pro' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'state'        => array(
				'description' => __( 'What the job last recorded about itself.', 'woo-kontor-sync-pro' ),
				'type'        => 'string',
				'enum'        => array( 'never', 'running', 'success', 'failed' ),
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'running'      => array(
				'description' => __( 'Whether a run is in flight and recent enough to believe.', 'woo-kontor-sync-pro' ),
				'type'        => 'boolean',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'queued'       => array(
				'description' => __( 'Whether a run of this job is waiting or under way.', 'woo-kontor-sync-pro' ),
				'type'        => 'boolean',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'run_id'       => array(
				'description' => __( 'Identifies the run being reported. Zero when the job has never run. Compare it; do not read it as a time.', 'woo-kontor-sync-pro' ),
				'type'        => 'integer',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'started_gmt'  => array(
				'description' => __( 'When the run began, in UTC. Null when the job has never run.', 'woo-kontor-sync-pro' ),
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'finished_gmt' => array(
				'description' => __( 'When the run ended, in UTC. Null while it is still going.', 'woo-kontor-sync-pro' ),
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'percent'      => array(
				'description' => __( 'How far through the run is. Null when it cannot be measured, which is not the same as nothing done yet.', 'woo-kontor-sync-pro' ),
				'type'        => array( 'integer', 'null' ),
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'total'        => array(
				'description' => __( 'Records the run expects to handle. Zero means not yet known.', 'woo-kontor-sync-pro' ),
				'type'        => 'integer',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'processed'    => array(
				'description' => __( 'Records the run has handled so far.', 'woo-kontor-sync-pro' ),
				'type'        => 'integer',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'counts'       => array(
				'description'          => __( 'What happened to each record, by outcome. The names are the jobs\' own and are not a fixed set.', 'woo-kontor-sync-pro' ),
				'type'                 => 'object',
				'context'              => array( 'view' ),
				'readonly'             => true,
				'additionalProperties' => array(
					'type' => 'integer',
				),
			),
			'message'      => array(
				'description' => __( "The run's summary, or the reason it failed.", 'woo-kontor-sync-pro' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'next_run_gmt' => array(
				'description' => __( 'When the schedule next runs this job, in UTC. Null when nothing is scheduled.', 'woo-kontor-sync-pro' ),
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
		);
	}
}
