<?php

namespace Templately\Core\Importer\Exception;

class SkippableErrorException extends ImporterException {
	protected $item_key;
	protected $item_type;

	public function __construct($message, $item_key = null, $item_type = null, $code = 0, ?\Throwable $previous = null) {
		parent::__construct($message, $code, $previous);
		$this->item_key = $item_key;
		$this->item_type = $item_type;
	}

	public function getItemKey() {
		return $this->item_key;
	}

	public function getItemType() {
		return $this->item_type;
	}
}
