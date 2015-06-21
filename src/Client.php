<?php

namespace BackgroundJob;

use SuperClosure\SerializerInterface;
use SuperClosure\SerializableClosure;

/*
http://stackoverflow.com/questions/10924276/how-to-encrypt-non-blocking-php-socket-streams
http://php.net/manual/en/function.stream-select.php
*/

class Client {

	/**
	 * @var Serializer
	 */
	protected $serializer;

	/**
	 * Url of BackgroundJob\Server component
	 *
	 * @var string
	 */
	protected $serverUrl;

	/**
	 * Queue of jobs to be run later
	 *
	 * @var array
	 */
	protected $queue = [];

	/**
	 * List of running jobs
	 *
	 * @var array
	 */
	protected $jobs = [];

	/**
	 * Default timeout for executing background jobs in seconds
	 *
	 * @var integer
	 */
	protected $timeout = 300;

	/**
	 * Connection timeout (time to open socket) in miliseconds
	 *
	 * @var integer
	 */
	protected $connectTimeout = 1000;

	/**
	 * Create client for running background jobs
	 *
	 * Options:
	 *     ['connectTimeout'] integer   time limit for opening socket in miliseconds, default: 1000
	 *     ['timeout']        integer   time limit for job in seconds, 0 means no time limit, default: 300
	 *     ['host']           string    hostname of server, default: $_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME'] or 'localhost'
	 *     ['port']           integer   server port, default: 80 or 443 if ssl = true
	 *     ['ssl']            boolean   should use SSL encryption, default: false
	 *
	 * @param SerializerInterface $serializer
	 * @param string $serverUrl
	 * @param array $options Options array, see above
	 */
	public function __construct(SerializerInterface $serializer, $serverUrl, array $options = []) {
		$this->serializer = $serializer;
		$this->serverUrl = $serverUrl;

		if (array_key_exists('timeout', $options) && is_int($options['timeout'])) {
			$this->timeout = $options['timeout'];
		}

		if (array_key_exists('connectTimeout', $options) && is_int($options['connectTimeout'])) {
			$this->connectTimeout = $options['connectTimeout'];
		}
	}

	/**
	 * Run job in background
	 *
	 * Options:
	 *     ['tmeout']             integer   time limit for job in seconds, 0 means no time limit, default: 300
	 *     ['onSuccess']          callable  function to run after background job finish succesfully, function($returnValue, $errors) ...
	 *     ['onError']            callable  function to run after error in background job, function($returnValue, $errors, $exception)
	 *     ['onComplete']         callable  function to run after onSuccess/onError handler, function($returnValue, $errors, $exception)
	 *     ['onConnectTimeout']   callable  function to run when background job didn't started (cannot connect to server)
	 *
	 * Callback function arguments:
	 *     $returnValue       mixed         return value of background job
	 *     $errors            array         array of errors reported during background job execution, each error is an array like php's "error_get_last()"
	 *     $exception         string|null   exception message if uncatched exception occured in background job, or null
	 *
	 * @param callable $handler Callable to run in background
	 * @param array $options Options array, see above
	 */
	public function run(callable $handler, array $options = []) {
		$handler = $this->serialize($handler);
		$this->startBatch([['handler' => $handler, 'options' => $options]]);
	}

	/**
	 * Queue job. Run queued jobs with "runQueue()"
	 *
	 * @param callable $handler Callable to run in background
	 * @param array $options Options array, same as in "run()"
	 */
	public function queue(callable $handler, array $options = []) {
		$handler = $this->serialize($handler);
		$this->queue[] = ['handler' => $handler, 'options' => $options];
	}

	/**
	 * Starts all jobs in queue
	 */
	public function runQueue() {
		if (count($this->queue) > 0) {
			$this->startBatch($this->queue);
			$this->queue = [];
		}
	}

	/**
	 * Wait for all jobs to finish
	 *
	 * Use with caution when using without timeouts
	 */
	public function waitAll() {
		while (count($this->jobs) > 0) {
			$this->waitFirst();
		}
	}

	/**
	 * Wait for first job to finish
	 */
	public function waitFirst() {
		if (count($this->jobs) > 0) {
			$streams = [];
			foreach ($this->jobs as $job) {
				$streams[] = $job['stream'];
			}

			$read = $streams;
			$except = null;
			$write = null;

			if (stream_select($read, $write, $except, 86400)) { // wait for one day
				foreach ($read as $stream) {
					$data = fread($stream, 8192);
					foreach ($this->jobs as $key => $job) {
						if ($job['stream'] == $stream) {
							if (strlen($data) == 0) {
								$this->finish($job);
								unset($this->jobs[$key]);
								return;
							} else {
								$this->jobs[$key]['data'] .= $data;
							}
						}
					}
				}
			}
			$this->waitFirst();
		}
	}

	/**
	 * Execute callbacks based on background job result
	 *
	 * @param array $job
	 */
	protected function finish(array $job) {
		list($returnValue, $success, $errors, $exception) = $this->parseMessage($job['data']);

		if ($success && array_key_exists('onSuccess', $job['options'])) {
			call_user_func($job['options']['onSuccess'], $returnValue, $errors);
		} elseif (!$success && array_key_exists('onError', $job['options'])) {
			call_user_func($job['options']['onError'], $returnValue, $errors, $exception);
		}

		if (array_key_exists('onComplete', $job['options'])) {
			call_user_func($job['options']['onComplete'], $returnValue, $errors, $exception);
		}
	}


	/**
	 * Parse HTTP response message
	 *
	 * @param string $message Raw HTTP response
	 * @return array
	 */
	protected function parseMessage($message) {
		$returnValue = null;
		$success = false;
		$errors = [];
		$exception = null;

		// explode http message to headers part and body part (if any)
		$split = explode("\r\n\r\n", $message, 2);

		if (array_key_exists(1, $split)) {
			$returnValue = unserialize($split[1]);
		}

		// process all headers and set errors/exception/success flag
		if (preg_match_all('/^([^\:]+)\:+(.+)$/m', $split[0], $m, PREG_SET_ORDER)) {
			foreach ($m as $header) {
				switch($header[1]) {
					case 'X-BackgroundJob-Error':
						$errors[] = unserialize(urldecode(trim($header[2])));
						break;
					case 'X-BackgroundJob-Exception':
						$exception = urldecode(trim($header[2]));
						break;
					case 'X-BackgroundJob-Success':
						$success = true;
						break;
				}
			}
		}

		return [$returnValue, $success, $errors, $exception];
	}

	/**
	 * Make handler Serializable
	 *
	 * @param callable $handler Callable
	 * @return callable Serializable callable
	 */
	protected  function serialize(callable $handler) {
		if ($handler instanceof \Closure) {
			$handler = new SerializableClosure($handler);
		}
		return $handler;
	}

	/**
	 * Get timeout from job options if exists or global options
	 *
	 * @param array $options
	 * @return integer Time limit for background job
	 */
	protected function getTimeout(array $options) {
		if (array_key_exists('timeout', $options) && is_int($options['timeout'])) {
			return $options['timeout'];
		}
		return $this->timeout;
	}

	/**
	 * Start batch of jobs, init sockets, send request message
	 *
	 * @param array $jobs
	 */
	protected function startBatch(array $jobs) {
		$streams = [];

		foreach ($jobs as $key => $job) {
			$stream = stream_socket_client('localhost:80', $errno, $errstr, null, STREAM_CLIENT_ASYNC_CONNECT|STREAM_CLIENT_CONNECT);
			if (!$stream) {
				throw new \Exception('Error creating socket stream');
			}
			$streams[] = $stream;
			$jobs[$key]['stream'] = $stream;
		}

		$read = null;
		$except = null;
		$write = $streams;

		$connectTimeout = $this->connectTimeout * 1000;
		$start = microtime(true);

		while (count($jobs) > 0 && $connectTimeout > 0) {
			$connectTimeout = min(0, $connectTimeout - ((microtime(true) - $start) * 1000));
			if (stream_select($read, $write, $except, 0, $connectTimeout)) {
				foreach ($write as $stream) {
					foreach ($jobs as $key => $job) {
						if ($stream == $job['stream']) {
							fwrite($stream, $this->createRequest($job['handler'], $this->getTimeout($job['options'])));
							$this->jobs[] = ['stream' => $stream, 'options' => $job['options'], 'data' => ''];
							unset($jobs[$key]);
							break;
						}
					}
				}
			}
		}

		// call onConnectTimeout for remaining jobs
		foreach ($jobs as $job) {
			if (array_key_exists('onConnectTimeout', $job['options'])) {
				call_user_func($job['options']['onConnectTimeout']);
			}
		}
	}

	/**
	 * Create HTTP request message
	 *
	 * @param callable $handler Callable to execute in background
	 * @param integer $timeout Time limit of operation
	 * @return string HTTP Message
	 */
	protected function createRequest(callable $handler, $timeout) {
		$a = [
			'handler' => $handler,
			'timeout' => $timeout
		];

		$data = serialize($a);

		$headers = array(
			'Host' => 'localhost', // @todo host
			'Connection' => 'Close',
			'Content-Length' => strlen($data)
		);

		if (count($_COOKIE) > 0) {
			$headers['Cookie'] = http_build_query($_COOKIE);
		}

		$nl = "\r\n"; // new line

		$message = sprintf('POST %s HTTP/1.0', $this->serverUrl); // HTTP 1.0 to disable chunked encoding
		$message .= $nl;
		$message .= implode($nl, array_map(function($key, $value) { return $key . ': ' . $value;  }, array_keys($headers), $headers));
		$message .= $nl;
		$message .= $nl;
		$message .= $data;
		$message .= $nl;
		$message .= $nl;

		return $message;
	}

}
