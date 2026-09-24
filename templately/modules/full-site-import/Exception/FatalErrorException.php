<?php

namespace Templately\Modules\FullSiteImport\Exception;

/**
 * An import failure that cannot be retried — the import stops.
 *
 * Renamed from `NonRetirableErrorException` (spec 043 / PRD PHP-3). That name was
 * a misspelling of "non-retryable" which had propagated to 20 sites, and it named
 * the failure by what it is NOT. Behaviour is unchanged.
 *
 * It extends the deprecated old class ON PURPOSE — see that file. The alternative
 * (old extends new) breaks every external `catch` of the old name, which is the
 * one thing a rename must not do.
 */
class FatalErrorException extends NonRetirableErrorException {
}
