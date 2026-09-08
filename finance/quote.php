<?php

$url = 'https://www.avanza.se/_api/market-guide/stock/5241/quote';
$options = ['http' => ['header' => "Accept: application/json\r\nUser-Agent: Mozilla/5.0\r\n"]];
$response = file_get_contents($url, false, stream_context_create($options));

header('Content-Type: application/json');
echo $response;
