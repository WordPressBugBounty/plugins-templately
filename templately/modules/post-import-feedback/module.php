<?php
/**
 * Post-import-feedback module (spec 027-post-import-feedback).
 *
 * The feedback widget (Widget.php, extracted from includes/Core/Admin.php) + its
 * feedback endpoints. As of the PRD §9 ajax→REST migration (2026-07-16) the primary
 * transport is REST — `REST/Feedback.php` registers:
 *   - POST /templately/v1/feedback       (submit rating + optional review)
 *   - POST /templately/v1/feedback/skip  (record skip/dismiss, start 30-day cooldown)
 * registered lazily from register_rest_routes() (the standard rest_api_init pattern).
 *
 * The two admin-ajax actions (wp_ajax_templately_pack_feedback_form,
 * wp_ajax_templately_pack_import_close_feedback_modal) are KEPT registered as
 * deprecated shims — a browser tab holding an already-cached feedback JS bundle would
 * still hit the old action names, so dropping them would 400 those requests. As of
 * 2026-07-17 THIS module supplies its own Ajax\FeedbackController through FSI's
 * `templately_fsi_ajax_handlers` filter seam (registered in init_hooks below); FSI
 * still executes the handlers through its shared nonce/capability AJAX wrapper but no
 * longer imports this module's class. The spec's Ownership note holds: the endpoints
 * belong to this spec, spec 025's registrar only runs them.
 *
 * @package Templately
 */

namespace Templately\Modules\PostImportFeedback;

use Templately\Core\Module_Base;
use Templately\Modules\PostImportFeedback\REST\Feedback;

class Module extends Module_Base {

	public function get_name(): string {
		return 'post-import-feedback';
	}

	// NOTE: intentionally NO get_dependencies() on full-site-import. This module has
	// NO code-level or boot-order dependency on FSI: the `templately_fsi_pack_id`
	// value it reads is a runtime option share (not a class reference), its ajax
	// shims are wired BY FSI (an FSI→feedback edge, the reverse direction), and the
	// shared error-body helper it used to borrow from FSI is gone entirely — both
	// modules now call the core `ResponseNormalizer` (spec 043). Re-declaring
	// FSI here would recreate that cycle for a dependency that no longer exists.
	// (spec 027's "Depends On: 025" is a spec-authoring lineage note, not a boot edge.)

	protected function init_hooks(): void {
		$widget = new Widget();
		add_action( 'admin_enqueue_scripts', [ $widget, 'enqueue' ] );
		add_action( 'admin_footer', [ $widget, 'render_mount_point' ] );

		// Supply the two deprecated ajax shims through FSI's handler seam (see the
		// file docblock). FSI applies this filter on 'init' and registers each
		// handler through its shared nonce/capability AJAX wrapper.
		add_filter( 'templately_fsi_ajax_handlers', [ $this, 'provide_ajax_handlers' ] );
	}

	/**
	 * Contribute this module's deprecated ajax shim handlers to full-site-import's
	 * `templately_fsi_ajax_handlers` seam (action name => handler object exposing a
	 * method of the same name).
	 */
	public function provide_ajax_handlers( array $handlers ): array {
		$feedback = new Ajax\FeedbackController();

		$handlers['feedback_form']               = $feedback;
		$handlers['import_close_feedback_modal'] = $feedback;

		return $handlers;
	}

	public function register_rest_routes(): void {
		// PRD §9 ajax→REST migration: the feedback submission + skip endpoints.
		// The old ajax actions stay registered as deprecated shims (see file docblock).
		Feedback::get_instance()->register_routes();
	}
}
