<?php

require(__DIR__ . '/../vendor/autoload.php');


$serializer = new SuperClosure\Serializer();

$bg = new BackgroundJob\Client($serializer, '/BackgroundJob2/test/server.php', ['timeout' => 1]);

$bg->run(function() {
	$x = a;
	$x = b;
	// return 12;
}, ['onComplete' => function($data) {

} ]);

$bg->waitAll();
