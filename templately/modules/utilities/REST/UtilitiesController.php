<?php
/**
 * The only bridge between the cleanup engine and the Utilities tab.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\REST;

use Templately\API\API;
use Templately\Modules\Utilities\Cleanup\Activation;
use Templately\Modules\Utilities\Cleanup\Context;
use Templately\Modules\Utilities\Cleanup\RetentionPolicy;
use Templately\Modules\Utilities\Cleanup\RunJournal;
use Templately\Modules\Utilities\Cleanup\Scheduler;
use Templately\Modules\Utilities\Cleanup\Settings;
use Templately\Modules\Utilities\Cleanup\TaskRegistry;
use Templately\Modules\Utilities\Cleanup\UsageReport;
use WP_Error;
use WP_REST_Request;

/**
 * Four routes, all administrator-only — READS INCLUDED. The storage report
 * exposes absolute server paths and sizes, which is not information to hand to
 * a subscriber.
 *
 * The destructive rails live HERE, on the server, not in the interface:
 *
 *  - a run without an explicit `confirm` executes as a dry run;
 *  - an unactivated site executes as a dry run whatever `confirm` says;
 *  - a run while another holds a live claim is refused.
 *
 * A UI that forgot any of these could not cause a deletion the user did not ask
 * for, which is the point of putting them at this layer.
 */
class UtilitiesController extends API {

	public function permission_check( WP_REST_Request $request ) {
		return current_user_can( 'manage_options' );
	}

	public function register_routes() {
		$this->get( 'utilities/storage', [ $this, 'get_storage' ] );
		$this->get( 'utilities/tasks', [ $this, 'get_tasks' ] );
		$this->post( 'utilities/cleanup', [ $this, 'run_cleanup' ] );
		$this->post( 'utilities/settings', [ $this, 'update_settings' ] );
	}

	/**
	 * GET utilities/storage — everything the tab needs for a first paint, in one
	 * round trip.
	 */
	public function get_storage() {
		$continuation = $this->get_param( 'continuation', '', 'sanitize_text_field' );

		return $this->envelope(
			[
				'usage'            => UsageReport::build( $this->bool_param( 'refresh' ), $continuation ?: null ),
				'activation'       => Activation::state(),
				'journal'          => RunJournal::all(),
				'settings'         => Settings::get(),
				'cleanup_disabled' => Settings::is_disabled(),
				'running'          => Scheduler::is_running(),
			]
		);
	}

	/**
	 * GET utilities/tasks — descriptors plus a per-task estimate.
	 *
	 * The estimate comes from the same code path as the run, with dry-run set,
	 * so the number shown in the confirmation is the number the run produces.
	 */
	public function get_tasks() {
		$registry = TaskRegistry::get_instance();
		$context  = Context::create( Context::TRIGGER_MANUAL, RetentionPolicy::from_settings(), true );

		$tasks = [];
		foreach ( $registry->descriptors() as $id => $descriptor ) {
			$estimate = $registry->estimate( $id, $context );

			$tasks[] = array_merge(
				$descriptor->to_array(),
				[
					'available' => ! $estimate->unavailable,
					'estimate'  => $estimate->to_array(),
				]
			);
		}

		return $this->envelope( [ 'tasks' => $tasks ] );
	}

	/**
	 * POST utilities/cleanup — run one task, or every schedulable task.
	 */
	public function run_cleanup() {
		$task_id = $this->get_param( 'task_id', '', 'sanitize_key' );
		$confirm = $this->bool_param( 'confirm' );

		$registry = TaskRegistry::get_instance();

		if ( $task_id ) {
			if ( null === $registry->get_descriptor( $task_id ) ) {
				return $this->error(
					'unknown_task',
					__( 'That cleanup does not exist.', 'templately' ),
					'run_cleanup',
					404
				);
			}
			$ids = [ $task_id ];
		} else {
			// The batch action deliberately excludes manual-only tasks — those
			// exist precisely because running them unattended is wrong.
			$ids = $registry->schedulable_ids();
		}

		// RAIL: no explicit confirmation means this is a preview, not a run.
		// Enforced here rather than in the UI, so a caller that forgets cannot
		// delete anything.
		$dry_run = ! $confirm;

		$outcome = Scheduler::run( $ids, Context::TRIGGER_MANUAL, $dry_run );

		if ( ! empty( $outcome['skipped'] ) ) {
			return $this->error(
				'cleanup_in_progress',
				__( 'A cleanup is already running. Try again in a moment.', 'templately' ),
				'run_cleanup',
				409
			);
		}

		$results = [];
		foreach ( $outcome['results'] as $id => $result ) {
			$results[ $id ] = $result->to_array();
		}

		return $this->envelope(
			[
				'dry_run'             => $outcome['dry_run'],
				// True when the site has never been activated: the caller asked
				// for a real run and got a preview, and the UI must say so
				// rather than reporting "0 bytes removed".
				'activation_required' => Activation::forces_dry_run(),
				'trigger'             => Context::TRIGGER_MANUAL,
				'results'             => $results,
				'journal_entry'       => $outcome['entry'],
			]
		);
	}

	/**
	 * POST utilities/settings — retention values and activation actions.
	 */
	public function update_settings() {
		$changes = [];

		foreach ( [ 'age_days', 'ceiling_bytes', 'log_keep' ] as $field ) {
			$value = $this->get_param( $field, null, 'absint' );
			if ( null !== $value && '' !== $value ) {
				$changes[ $field ] = (int) $value;
			}
		}

		foreach ( [ 'ceiling_enabled', 'disabled' ] as $field ) {
			$raw = $this->get_param( $field, null, null );
			if ( null !== $raw ) {
				$changes[ $field ] = rest_sanitize_boolean( $raw );
			}
		}

		if ( $changes ) {
			$updated = Settings::update( $changes );

			// Out-of-range values are REFUSED with a reason rather than silently
			// clamped — telling someone their "0 days" was accepted when it was
			// quietly turned into 1 is worse than rejecting it.
			if ( $updated instanceof WP_Error ) {
				return $updated;
			}

			if ( array_key_exists( 'disabled', $changes ) ) {
				// Turning cleanup off returns the site to unactivated: the
				// backlog that builds while it is off is exactly what the gate
				// exists for, so re-enabling must ask again.
				if ( $changes['disabled'] ) {
					Activation::deactivate();
				}
				Scheduler::ensure_scheduled();
			}
		}

		if ( rest_sanitize_boolean( $this->get_param( 'activate', false, null ) ) ) {
			Activation::activate( get_current_user_id() );
		}

		if ( rest_sanitize_boolean( $this->get_param( 'dismiss_prompt', false, null ) ) ) {
			// Dismissal hides the notice; it is NOT consent, so the tab keeps
			// showing the pending state.
			Activation::dismiss_prompt();
		}

		return $this->envelope(
			[
				'settings'   => Settings::get(),
				'activation' => Activation::state(),
			]
		);
	}

	/**
	 * `null` sanitizer plus a manual cast: there is no WordPress boolean
	 * sanitizer, and `sanitize_text_field` would turn `false` into `''`.
	 */
	private function bool_param( string $name ): bool {
		return rest_sanitize_boolean( $this->get_param( $name, false, null ) );
	}
}
