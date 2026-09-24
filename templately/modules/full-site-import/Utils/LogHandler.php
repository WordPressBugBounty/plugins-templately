<?php
namespace Templately\Modules\FullSiteImport\Utils;

use Templately\Modules\FullSiteImport\FullSiteImport;
use Templately\Utils\Helper;

class LogHandler {

    /**
     * How many import logs to retain. Support needs the last few runs; the
     * count, not an age, is the right rule here — an age window would delete
     * the log of a week-old failure someone is still asking about and keep
     * hundreds from one busy afternoon.
     */
    const KEEP_LOGS = 10;

    public static function get_log_dir() {
        // Import logs carry pack and session detail and sit in web-served
        // wp-uploads, so the root gets its access guards on the way past.
        $log_dir    = Helper::upload_dir('log');
        return $log_dir;
    }

    public static function get_log_file_path() {
        $session_id = SessionData::get_session_id();
        $log_dir = self::get_log_dir();
        return trailingslashit($log_dir) . "fsi-$session_id.log";
    }

    public static function create_log_dir() {
        $upload_dir = wp_upload_dir();
        $log_dir    = self::get_log_dir();

        if (!$log_dir) {
            return false;
        }

        if (!is_writable($upload_dir['basedir'])) {
            return false;
        }

        if ($session_id = SessionData::get_session_id()) {
            SessionData::set($session_id, 'log_type', 'file');
        }

        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        } else {
            self::prune_import_logs($log_dir);
        }
    }

    /**
     * Keep only the newest {@see self::KEEP_LOGS} import logs.
     *
     * SCOPED TO `fsi-*.log` ON PURPOSE. This used to enumerate the whole
     * directory (`scandir` minus `.`/`..`), sort by mtime and unlink everything
     * past the tenth entry — but this directory also holds the access guards
     * written by `Utils\Log\LogFile::ensure_guards()` (an `.htaccess` deny rule
     * and a blank `index.php`), the plugin's own hash-named log and its rotated
     * generation, and the developer HTTP inspector's JSONL file. The guards are
     * written once and never touched, so they carried the OLDEST mtimes and
     * were deleted FIRST: after ~11 import sessions an Apache site silently lost
     * `Require all denied` on a web-reachable directory.
     *
     * Counting entries rather than import logs was the second half of the bug —
     * every guard file present shrank the number of logs actually retained.
     *
     * @param string $log_dir Trailing-slashed log directory.
     * @return void
     */
    private static function prune_import_logs($log_dir) {
        $files = glob($log_dir . 'fsi-*.log');

        if (!is_array($files) || count($files) <= self::KEEP_LOGS) {
            return;
        }

        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        foreach (array_slice($files, 0, count($files) - self::KEEP_LOGS) as $file) {
            unlink($file);
        }
    }

    public static function sse_log_file($log) {
        $file_path  = self::get_log_file_path();

        // Write to the log file
        return file_put_contents($file_path, json_encode($log) . PHP_EOL, FILE_APPEND);
    }

    public static function read_log_file($start_line = 0) {
        $file_path = self::get_log_file_path();

        $log = [];

        // Check if SplFileObject exists
        if (class_exists('SplFileObject')) {
            try {
                $file = new \SplFileObject($file_path);

                // Seek to the specified line number (0-indexed in SplFileObject)
                $file->seek($start_line);
                while (!$file->eof()) {
                    $line = trim($file->current());
                    if ($line) {
                        $log[] = json_decode($line, true); // JSON decode each line
                    }
                    $file->next();
                }
            } catch (\Exception $e) {
                // Handle exception if file operations fail
                Helper::log("Failed to read log file: " . $e->getMessage(), 'fsi_log_handler', 'error');
            }
        } else {
            // Fallback if SplFileObject doesn't exist
            $current_line = 1;
            $handle = fopen($file_path, "r");
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    if ($current_line >= $start_line) {
                        $line = trim($line);
                        if ($line) {
                            $log[] = json_decode($line, true); // JSON decode each line
                        }
                    }
                    $current_line++;
                }
                fclose($handle);
            } else {
                // Handle error if file can't be opened
                Helper::log("Failed to open log file: $file_path", 'fsi_log_handler', 'error');
            }
        }

        return $log;
    }
}
