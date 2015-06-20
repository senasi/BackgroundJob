<?php

require(__DIR__ . '/../vendor/autoload.php');

$server = new BackgroundJob\Server(file_get_contents('php://input'));
$server->process();
// should die here!!!