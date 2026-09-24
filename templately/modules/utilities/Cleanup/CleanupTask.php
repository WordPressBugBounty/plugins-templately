<?php
/**
 * The contract every contributed cleanup implements.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

/**
 * Implement this, hand the class name to the registry from your own module's
 * `init_hooks()`, and you get scheduling, retention policy, guarded deletion,
 * estimation, dry-run, journalling and a UI entry. The engine never names you.
 *
 * FOUR OBLIGATIONS ON THE IMPLEMENTER — these are not style notes:
 *
 * 1. NEVER DELETE ANYTHING YOURSELF. Every removal goes through {@see Remover}.
 *    Calling `unlink()` or `rmdir()` directly bypasses path containment, symlink
 *    refusal, the guard-file denylist and the live-log exclusion. The engine
 *    deliberately exposes no other deletion surface.
 * 2. RESPECT THE BUDGET. Check `$context->has_budget()` at your own item
 *    boundary and return `stopped_on_budget()` with work left rather than
 *    running to completion regardless. A run that overshoots gets killed
 *    mid-delete by the host.
 * 3. RE-CHECK ACTIVITY IMMEDIATELY BEFORE REMOVING, not when you built the
 *    candidate list. The engine cannot do this for you — only the feature that
 *    produced the data knows what "still in use" means for it.
 * 4. DO NOT THROW FOR ONE BAD ITEM. Collect per-item failures into
 *    `TaskResult::fail()` so one failure does not abort the whole run.
 */
interface CleanupTask {

	/**
	 * Self-description. See {@see TaskDescriptor::from_array()} for the fields
	 * and their validation.
	 *
	 * Translate the label HERE, not at registration: this is called lazily, once
	 * the textdomain is loaded. A `__()` call during `plugins_loaded` returns the
	 * untranslated string forever.
	 *
	 * @return array
	 */
	public function descriptor(): array;

	/**
	 * What this task WOULD remove. MUST NOT mutate anything.
	 *
	 * The engine calls this to populate the confirmation dialog, so it runs on
	 * an ordinary page load — keep it cheap, or report a bounded figure.
	 */
	public function estimate( Context $context ): TaskResult;

	/**
	 * Perform the removal.
	 *
	 * MUST honour `$context->dry_run` by behaving exactly as `estimate()`. The
	 * engine forces that flag on an unactivated site and on an unconfirmed
	 * manual request, and it does not re-check afterwards — a task that ignores
	 * it deletes data the user never approved.
	 */
	public function run( Context $context ): TaskResult;
}
