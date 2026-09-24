<?php

namespace Templately\Modules\BlockRecovery\REST;

use Templately\API\API;
use Templately\Modules\BlockRecovery\AttributeSnapshot;
use Templately\Modules\BlockRecovery\Module;
use Templately\Modules\BlockRecovery\Queue;
use Templately\Modules\BlockRecovery\Report;
use WP_Error;
use WP_REST_Request;

/**
 * The queue/report endpoints for the post-import block rebuild.
 *
 * The repair itself happens in the browser (`save()` is JS-only and third-party blocks register
 * only inside a block editor), so PHP's part is to say WHAT to visit and to record what came
 * back. Both routes are gated on `edit_posts` rather than the base class's connection check:
 * this is local content maintenance and must keep working on a site whose Templately connection
 * has since lapsed — the posts are already imported either way.
 */
class Rebuild extends API {

	private $endpoint = 'block-recovery';

	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to repair imported blocks.', 'templately' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	public function register_routes() {
		$this->get( $this->endpoint . '/queue', [ $this, 'get_queue' ] );
		$this->post( $this->endpoint . '/report', [ $this, 'post_report' ] );
		$this->post( $this->endpoint . '/snapshot', [ $this, 'post_snapshot' ] );
		$this->post( $this->endpoint . '/restore', [ $this, 'post_restore' ] );
	}

	/**
	 * Remember a post's stored block attributes before the editor rebuilds it.
	 *
	 * Must run BEFORE the save: the attributes this protects are exactly the ones the rebuild is
	 * about to drop, and once the editor has written the post they are no longer anywhere to be
	 * read. See {@see AttributeSnapshot}.
	 */
	public function post_snapshot() {
		$post_id = (int) $this->get_param( 'post_id', 0, 'intval' );

		if ( empty( $post_id ) ) {
			return $this->error( 'invalid_requirements', __( 'A post id is required.', 'templately' ), $this->endpoint . '/snapshot', 400 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'invalid_permission', __( 'Sorry, you are not allowed to edit this post.', 'templately' ), $this->endpoint . '/snapshot', 403 );
		}

		return $this->envelope( [ 'captured' => AttributeSnapshot::capture( $post_id ) ] );
	}

	/**
	 * Put back any attribute the rebuild dropped, leaving the regenerated markup alone.
	 */
	public function post_restore() {
		$post_id = (int) $this->get_param( 'post_id', 0, 'intval' );

		if ( empty( $post_id ) ) {
			return $this->error( 'invalid_requirements', __( 'A post id is required.', 'templately' ), $this->endpoint . '/restore', 400 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'invalid_permission', __( 'Sorry, you are not allowed to edit this post.', 'templately' ), $this->endpoint . '/restore', 403 );
		}

		return $this->envelope( AttributeSnapshot::restore( $post_id ) );
	}

	/**
	 * Posts still worth visiting, plus the merge-time parity warnings as priority information.
	 */
	public function get_queue() {
		$queue = Queue::get();

		$items = array_map( function ( $item ) {
			return [
				'id'      => (int) $item['id'],
				'type'    => (string) $item['type'],
				'editUrl' => get_edit_post_link( (int) $item['id'], 'raw' ),
			];
		}, Queue::pending() );

		return $this->envelope(
			[
				'enabled'        => Module::is_enabled(),
				'sessionId'      => $queue['session_id'] ?? null,
				'items'          => $items,
				'parityWarnings' => $queue['parity_warnings'] ?? [],
				'report'         => Report::get(),
			]
		);
	}

	/**
	 * Record one post's outcome and take it off the queue.
	 *
	 * A post is taken off the queue whatever the outcome, failures included — re-opening an
	 * editor that already refused to boot would spend the same time for the same answer, and
	 * the failure is reported separately.
	 */
	public function post_report() {
		$post_id   = (int) $this->get_param( 'post_id', 0, 'intval' );
		$status    = (string) $this->get_param( 'status', 'clean' );
		$rebuilt   = (int) $this->get_param( 'rebuilt', 0, 'intval' );
		$skipped   = $this->get_param( 'skipped', [], null );
		$preserved = (int) $this->get_param( 'preserved', 0, 'intval' );
		$restored  = (int) $this->get_param( 'restored', 0, 'intval' );
		$trigger   = (string) $this->get_param( 'trigger', Report::TRIGGER_BATCH, 'sanitize_key' );
		$duration  = (int) $this->get_param( 'durationMs', 0, 'absint' );

		if ( empty( $post_id ) ) {
			return $this->error( 'invalid_requirements', __( 'A post id is required.', 'templately' ), $this->endpoint . '/report', 400 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'invalid_permission', __( 'Sorry, you are not allowed to edit this post.', 'templately' ), $this->endpoint . '/report', 403 );
		}

		if ( ! in_array( $status, [ 'clean', 'rebuilt', 'skipped', 'failed' ], true ) ) {
			$status = 'clean';
		}

		Report::record( $post_id, $status, $rebuilt, $this->sanitize_skipped( $skipped ), $preserved, $restored, $trigger, $duration );
		Queue::mark_done( $post_id );

		return $this->envelope( [ 'ok' => true, 'remaining' => count( Queue::pending() ) ] );
	}

	/**
	 * The skipped list is client-supplied, so it is rebuilt field by field rather than trusted:
	 * it ends up in an option and in the UI.
	 *
	 * @param mixed $skipped
	 * @return array<int,array{block:string,droppedAttrs:array<int,string>}>
	 */
	private function sanitize_skipped( $skipped ): array {
		if ( ! is_array( $skipped ) ) {
			return [];
		}

		$clean = [];
		foreach ( $skipped as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['block'] ) ) {
				continue;
			}

			$attributes = [];
			foreach ( (array) ( $entry['droppedAttrs'] ?? [] ) as $attribute ) {
				if ( is_string( $attribute ) || is_numeric( $attribute ) ) {
					$attributes[] = sanitize_text_field( (string) $attribute );
				}
			}

			$clean[] = [
				'block'        => sanitize_text_field( (string) $entry['block'] ),
				'droppedAttrs' => $attributes,
			];
		}

		return $clean;
	}
}
