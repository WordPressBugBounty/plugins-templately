<?php

namespace Templately\Modules\FullSiteImport\Concerns;

use Exception;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Exception\RetryableErrorException;
use Templately\Modules\FullSiteImport\Exception\UnknownErrorException;
use Templately\Utils\Helper;

/**
 * Error-throw helpers for the Full Site Import pipeline.
 *
 * Extracted verbatim from FullSiteImport for readability. These are composed back
 * into FullSiteImport via `use`, so $this and method resolution are identical.
 */
trait ThrowsErrors {
	/**
	 * @throws Exception
	 */
	private function throw($message, $code = 0) {
		if ($this->dev_mode) {
			Helper::log($message, 'fsi_error');
		}
		throw new Exception($message);
	}
	/**
	 * @throws Exception
	 */
	private function throw_non_retryable($message, $code = 0) {
		if ($this->dev_mode) {
			Helper::log($message, 'fsi_error');
		}
		throw new FatalErrorException($message);
	}
	/**
	 * @throws Exception
	 */
	private function throw_retryable($message, $code = 0) {
		if ($this->dev_mode) {
			Helper::log($message, 'fsi_error');
		}
		throw new RetryableErrorException($message);
	}
	/**
	 * @throws Exception
	 */
	private function throw_unknown($message, $code = 0) {
		if ($this->dev_mode) {
			Helper::log($message, 'fsi_error');
		}
		throw new UnknownErrorException($message);
	}
}
