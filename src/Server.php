<?php

namespace BackgroundJob;

class Server {

	protected $data;

	public function __construct($data) {
		$this->data = $data;
	}

	public function process() {
		register_shutdown_function(function() {
			if ($e = error_get_last()) {

			}
		});
		try {
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