<?php

namespace BackgroundJob;

class Server {

	protected $data;

	public function __construct($data) {
		$this->data = $data;
	}

	public function process() {
		// ini_set('zlib.output_compression', 0); // @todo more info

		// register shutdown function to "catch" fatal errors, like timeout or class not found
		register_shutdown_function(function() {
			if ($e = error_get_last()) {
				header('X-BackgroundJob-Error: ' . urlencode(serialize($e)), false); // dont replace
			}
		});

		// set error handler for non-fatal errors
		set_error_handler(function($errno, $errstr, $errfile, $errline) {
			if (!(error_reporting() & $errno)) {
				// This error code is not included in error_reporting
				return;
			}

			$e = [
				'type' => $errno,
				'message' => $errstr,
				'file' => $errfile,
				'line' => $errline
			];

			header('X-BackgroundJob-Error: ' . urlencode(serialize($e)), false); // dont replace
		});

		try {
			ignore_user_abort(true);

			$job = unserialize($this->data);
			set_time_limit($job['timeout']);

			$ret = call_user_func($job['handler']);
			header('X-BackgroundJob-Success: true');
			echo serialize($ret);
		} catch (\Exception $e) {
			header('X-BackgroundJob-Exception: ' . urlencode($e->getMessage()));
		}
	}
}