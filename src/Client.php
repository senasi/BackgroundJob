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
	 * @param SerializerInterface $serializer
	 * @param string $serverUrl
	 * @param array $options
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
	 * @param callable $handler
	 * @param array $options
	 */
	public function run(callable $handler, array $options = []) {
		$handler = $this->serialize($handler);
		$this->startBatch([['handler' => $handler, 'options' => $options]]);
	}

	/**
	 * Queue job. Run queued jobs with "runQueue()"
	 *
	 * @param callable $handler
	 * @param array $options
	 */
	public function queue(callable $handler, array $options = []) {
		$handler = $this->serialize($handler);
		$this->queue[] = ['handler' => $handler, 'options' => $options];
	}

	/**
	 * Run all jobs in queue
	 */
	public function runQueue() {
		if (count($this->queue) > 0) {
			$this->startBatch($this->queue);
			$this->queue = [];
		}
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

	protected function getTimeout(array $options) {
		if (array_key_exists('timeout', $options) && is_int($options['timeout'])) {
			return $options['timeout'];
		}
		return $this->timeout;
	}

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
							$this->jobs[] = ['stream' => $stream, 'options' => $job['options']];
							unset($jobs[$key]);
							break;
						}
					}
				}
			}
		}

		// call onConnectTimeout for remaning jobs
		foreach ($jobs as $job) {
			if (array_key_exists('onConnectTimeout', $job['options'])) {
				call_user_func($job['options']['onConnectTimeout']);
			}
		}
	}

	protected function createRequest(callable $handler, $timeout) {
		$a = [
			'handler' => $handler,
			'timeout' => $timeout
		];

		$data = serialize($a);

		$headers = array(
			'Host' => 'localhost', // @todo host
			'Connection' => 'Close',
			// 'Content-Type' => 'application/x-www-form-urlencoded',
			'Content-Length' => strlen($data)
		);

		if (count($_COOKIE) > 0) {
			$headers['Cookie'] = http_build_query($_COOKIE);
		}

		$nl = "\r\n";

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