<?php
/**
 * Test endpoints for Playwright testing
 * This file provides WordPress endpoints to test AIContentHelper functionality
 */

namespace Templately\Core\Developer;

use Templately\Core\Importer\Utils\AIContentHelper;
use Exception;
use ArgumentCountError;

class TestEndpoints {

    public function __construct() {
        add_action('wp_ajax_templately_test_ai_content', [$this, 'test_ai_content_validation']);
        add_action('wp_ajax_nopriv_templately_test_ai_content', [$this, 'test_ai_content_validation']);
        add_action('admin_init', [$this, 'handle_test_requests']);
    }

    /**
     * Handle test requests via query parameters
     */
    public function handle_test_requests() {
        if (!isset($_GET['test']) || !current_user_can('manage_options')) {
            return;
        }

        $test_type = sanitize_text_field($_GET['test']);

        switch ($test_type) {
            case 'ai-content-validation':
                $this->run_ai_content_validation_tests();
                break;
            case 'wordpress-core-errors':
                $this->test_wordpress_core_error_scenarios();
                break;
            case 'optional-parameters':
                $this->test_optional_parameters();
                break;
        }
    }

    /**
     * Run AI content validation tests
     */
    public function run_ai_content_validation_tests() {
        // Set content type to JSON for easier parsing in Playwright
        header('Content-Type: application/json');

        $results = [];

        // Test 1: Valid block structure validation
        $valid_blocks = [
            [
                'blockName' => 'core/paragraph',
                'attrs' => ['blockId' => 'test'],
                'innerBlocks' => [],
                'innerHTML' => '<p>Test</p>',
                'innerContent' => ['<p>Test</p>']
            ]
        ];

        $results['valid_blocks'] = [
            'test' => 'validateBlockStructure with valid blocks',
            'result' => AIContentHelper::validateBlockStructure($valid_blocks),
            'expected' => true,
            'passed' => AIContentHelper::validateBlockStructure($valid_blocks) === true
        ];

        // Test 2: Invalid block structure validation
        $invalid_blocks = [
            [
                // Missing blockName
                'attrs' => ['blockId' => 'test'],
                'innerBlocks' => [],
                'innerHTML' => '<p>Test</p>',
                'innerContent' => null // Null innerContent
            ]
        ];

        $results['invalid_blocks'] = [
            'test' => 'validateBlockStructure with invalid blocks',
            'result' => AIContentHelper::validateBlockStructure($invalid_blocks),
            'expected' => false,
            'passed' => AIContentHelper::validateBlockStructure($invalid_blocks) === false
        ];

        // Test 3: Test mergeAiContentWithOriginalGutenberg with 2 parameters
        $ai_template = [
            'test-block' => [
                'blockName' => 'core/paragraph',
                'contents' => [
                    ['attribute' => 'content', 'content' => 'AI generated content']
                ]
            ]
        ];

        $original_template = [
            'content' => '<!-- wp:paragraph {"blockId":"test-block"} -->
<p>Original content</p>
<!-- /wp:paragraph -->'
        ];

        try {
            $merge_result = AIContentHelper::mergeAiContentWithOriginalGutenberg($ai_template, $original_template);
            $results['merge_2_params'] = [
                'test' => 'mergeAiContentWithOriginalGutenberg with 2 parameters',
                'result' => is_array($merge_result) && isset($merge_result['content']),
                'expected' => true,
                'passed' => is_array($merge_result) && isset($merge_result['content']),
                'error' => null
            ];
        } catch (Exception $e) {
            $results['merge_2_params'] = [
                'test' => 'mergeAiContentWithOriginalGutenberg with 2 parameters',
                'result' => false,
                'expected' => true,
                'passed' => false,
                'error' => $e->getMessage()
            ];
        }

        // Test 4: Test with malformed content that would cause WordPress errors
        $malformed_template = [
            'content' => '<!-- wp:paragraph -->
<p>Malformed content without proper closing'
        ];

        try {
            $malformed_result = AIContentHelper::mergeAiContentWithOriginalGutenberg([], $malformed_template);
            $results['malformed_content'] = [
                'test' => 'mergeAiContentWithOriginalGutenberg with malformed content',
                'result' => is_array($malformed_result),
                'expected' => true,
                'passed' => is_array($malformed_result),
                'error' => null,
                'note' => 'Should handle gracefully without WordPress core errors'
            ];
        } catch (Exception $e) {
            $results['malformed_content'] = [
                'test' => 'mergeAiContentWithOriginalGutenberg with malformed content',
                'result' => false,
                'expected' => true,
                'passed' => false,
                'error' => $e->getMessage()
            ];
        }

        // Calculate overall test results
        $total_tests = count($results);
        $passed_tests = array_sum(array_column($results, 'passed'));

        $summary = [
            'total_tests' => $total_tests,
            'passed_tests' => $passed_tests,
            'failed_tests' => $total_tests - $passed_tests,
            'success_rate' => $total_tests > 0 ? round(($passed_tests / $total_tests) * 100, 2) : 0,
            'overall_status' => $passed_tests === $total_tests ? 'PASS' : 'FAIL'
        ];

        $response = [
            'test_type' => 'ai-content-validation',
            'timestamp' => current_time('mysql'),
            'summary' => $summary,
            'results' => $results,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION
        ];

        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Test WordPress core error scenarios
     */
    public function test_wordpress_core_error_scenarios() {
        header('Content-Type: application/json');

        $results = [];

        // Test scenarios that previously caused WordPress core errors
        $error_scenarios = [
            'missing_blockname' => [
                [
                    'attrs' => ['blockId' => 'test'],
                    'innerBlocks' => [],
                    'innerHTML' => '<p>Test</p>',
                    'innerContent' => ['<p>Test</p>']
                ]
            ],
            'null_innercontent' => [
                [
                    'blockName' => 'core/paragraph',
                    'attrs' => ['blockId' => 'test'],
                    'innerBlocks' => [],
                    'innerHTML' => '<p>Test</p>',
                    'innerContent' => null
                ]
            ],
            'null_innerblocks' => [
                [
                    'blockName' => 'core/paragraph',
                    'attrs' => ['blockId' => 'test'],
                    'innerBlocks' => null,
                    'innerHTML' => '<p>Test</p>',
                    'innerContent' => ['<p>Test</p>']
                ]
            ]
        ];

        foreach ($error_scenarios as $scenario_name => $blocks) {
            $is_valid = AIContentHelper::validateBlockStructure($blocks);
            $results[$scenario_name] = [
                'test' => "WordPress core error scenario: $scenario_name",
                'blocks_valid' => $is_valid,
                'expected_valid' => false,
                'passed' => $is_valid === false,
                'note' => 'Should be rejected to prevent WordPress core errors'
            ];
        }

        $total_tests = count($results);
        $passed_tests = array_sum(array_column($results, 'passed'));

        $response = [
            'test_type' => 'wordpress-core-errors',
            'timestamp' => current_time('mysql'),
            'summary' => [
                'total_tests' => $total_tests,
                'passed_tests' => $passed_tests,
                'overall_status' => $passed_tests === $total_tests ? 'PASS' : 'FAIL'
            ],
            'results' => $results
        ];

        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Test optional parameters functionality
     */
    public function test_optional_parameters() {
        header('Content-Type: application/json');

        $results = [];

        $ai_template = [
            'test-block' => [
                'blockName' => 'core/paragraph',
                'contents' => [
                    ['attribute' => 'content', 'content' => 'AI content']
                ]
            ]
        ];

        $original_template = [
            'content' => '<!-- wp:paragraph {"blockId":"test-block"} --><p>Original</p><!-- /wp:paragraph -->'
        ];

        // Test 2 parameters
        try {
            $result_2_params = AIContentHelper::mergeAiContentWithOriginalGutenberg($ai_template, $original_template);
            $results['two_parameters'] = [
                'test' => 'Call with 2 parameters',
                'success' => true,
                'passed' => is_array($result_2_params),
                'error' => null
            ];
        } catch (ArgumentCountError $e) {
            $results['two_parameters'] = [
                'test' => 'Call with 2 parameters',
                'success' => false,
                'passed' => false,
                'error' => 'ArgumentCountError: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            $results['two_parameters'] = [
                'test' => 'Call with 2 parameters',
                'success' => false,
                'passed' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }

        // Test 3 parameters
        try {
            $result_3_params = AIContentHelper::mergeAiContentWithOriginalGutenberg($ai_template, $original_template, 'test.json');
            $results['three_parameters'] = [
                'test' => 'Call with 3 parameters',
                'success' => true,
                'passed' => is_array($result_3_params),
                'error' => null
            ];
        } catch (Exception $e) {
            $results['three_parameters'] = [
                'test' => 'Call with 3 parameters',
                'success' => false,
                'passed' => false,
                'error' => $e->getMessage()
            ];
        }

        $total_tests = count($results);
        $passed_tests = array_sum(array_column($results, 'passed'));

        $response = [
            'test_type' => 'optional-parameters',
            'timestamp' => current_time('mysql'),
            'summary' => [
                'total_tests' => $total_tests,
                'passed_tests' => $passed_tests,
                'overall_status' => $passed_tests === $total_tests ? 'PASS' : 'FAIL'
            ],
            'results' => $results
        ];

        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * AJAX endpoint for testing
     */
    public function test_ai_content_validation() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $test_type = sanitize_text_field($_POST['test_type'] ?? 'ai-content-validation');

        switch ($test_type) {
            case 'ai-content-validation':
                $this->run_ai_content_validation_tests();
                break;
            case 'wordpress-core-errors':
                $this->test_wordpress_core_error_scenarios();
                break;
            case 'optional-parameters':
                $this->test_optional_parameters();
                break;
            default:
                wp_send_json_error('Invalid test type');
        }
    }
}
