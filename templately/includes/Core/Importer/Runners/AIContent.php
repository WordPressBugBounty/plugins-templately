<?php

namespace Templately\Core\Importer\Runners;

use Templately\Core\Importer\Runners\BaseRunner;

class AIContent extends BaseRunner {


	public function get_name(): string {
		return 'ai-content';
	}

	public function get_label(): string {
		return __('Extra Contents', 'templately');
	}

	public function should_log(): bool {
		return true;
	}

	public function get_action(): string {
		return 'updateLog';
	}

	public function log_message(): string {
		return __('AI Content Generation', 'templately');
	}

	public function should_run($data, $imported_data = []): bool {
		return ! empty($data['process_id']) && $data['process_id'] !== 'undefined' && "null" !== $data['process_id'] && !empty($data['ai_page_ids']) && !empty($data['missing_pages']);
	}

	public function import($data, $imported_data): array {
		// wp_cache_delete( 'templately_ai_processed_pages', 'options' );
		// global $wpdb;
		// $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'templately_ai_processed_pages'));
		// You might need to manually unserialize the value if it was serialized
		// $processed_pages = maybe_unserialize($value);
		$processed_pages = get_option( "templately_ai_processed_pages", [] );

		$updated_ids = $processed_pages[$data['process_id']] ?? [];
		$ai_page_ids = array_reduce($data['ai_page_ids'], 'array_merge', array());

		// $ai_page_ids = array_filter(array_map('intval', explode(',', $data['ai_page_ids'] ?? '')));
		// Calculate progress percentage

		$total_pages         = count($ai_page_ids);
		$updated_pages       = count($updated_ids['pages'] ?? []);
		$progress_percentage = $total_pages > 0 ? round(($updated_pages / $total_pages) * 100) : 0;

		$this->sse_message([
			'type'            => 'ai-content',
			'action'          => 'updateLog',
			'progress'        => $progress_percentage,
		], false);

		if ($total_pages > $updated_pages && !isset($updated_ids['credit_cost'])) {
			$arr = $this->get_progress([], 'ai_content_time', false);
			$last_progress = $arr['last_progress'] ?? 0;
			$last_time = $arr['last_time'] ?? 0;
			$current_time = time();

			if(isset($updated_ids['credit_cost']) && !empty($last_time) && ($current_time - $last_time) > 10) {
				// do nothing
			}
			// Only proceed if time difference is less than 2 minutes
			else if (empty($last_time) || ($current_time - $last_time) < 2 * MINUTE_IN_SECONDS) {
				// Only update time if progress has changed
				if ($progress_percentage !== $last_progress) {
					$arr['last_progress'] = $progress_percentage;
					$arr['last_time'] = $current_time;
					$this->update_progress($arr, null, 'ai_content_time', false);
				}

				$this->sse_message([
					'type'            => 'wait',
					'action'          => 'wait',
					'name'            => method_exists($this, 'get_name') ? $this->get_name() : '',
					'all_pages'       => $ai_page_ids,
					'generated_pages' => $updated_ids,
				]);
				exit;
			}
			// else {
			// 	$this->sse_message([
			// 		'action'   => 'error',
			// 		'status'   => 'error',
			// 		'type'     => "error",
			// 		// 'retry'    => true,
			// 		'title'    => __("Oops!", "templately"),
			// 		'message'  => __("Taking too long....", "templately"),
			// 		// 'position' => 'plugin',
			// 		// 'progress' => '--',
			// 	]);
			// 	exit;
			// }
		}

		return [
			'ai-content' => [
				'requested' => $data['ai_page_ids'],
				'processed' => $updated_ids,
			],
		];
	}

}
