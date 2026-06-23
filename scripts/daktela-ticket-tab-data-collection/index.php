<?php

declare(strict_types=1);

$dumpFile = './daktela-ticket-tab-data-collection.dump';

$phpInput = file_get_contents('php://input');
$postData = $_POST;
$getData = $_GET;
$cookies = $_COOKIE;

file_put_contents($dumpFile, json_encode([
    'php_input' => $phpInput,
    'post_data' => $postData,
    'get_data' => $getData,
    'cookies' => $cookies,
], JSON_PRETTY_PRINT));
