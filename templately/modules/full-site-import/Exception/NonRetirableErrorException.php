<?php

namespace Templately\Modules\FullSiteImport\Exception;

/**
 * @deprecated Use {@see FatalErrorException}.
 *
 * Kept as the BASE of the renamed class rather than a subclass of it, which looks
 * backwards until you consider what a rename has to preserve: `catch` blocks
 * outside this repo (ai-builder consumes this module) still name the old class,
 * and a subclass would mean they silently stop catching anything — every new
 * throw would sail past a handler that used to stop it.
 *
 * With the old name as the base, `catch ( NonRetirableErrorException $e )` still
 * catches every throw, and new code catching `FatalErrorException` is unaffected
 * because nothing throws the old class any more.
 *
 * Delete this once ai-builder has migrated; then `FatalErrorException` extends
 * `ImporterException` directly.
 */
class NonRetirableErrorException extends ImporterException {
}
