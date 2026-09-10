<?php

declare(strict_types=1);

const SWEDBANK_A_INSTRUMENT_ID = '5241';

function callMcp(string $endpoint, string $tool, array $arguments = []): array
{
    static $requestId = 0;

    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => ++$requestId,
        'method' => 'tools/call',
        'params' => [
            'name' => $tool,
            'arguments' => $arguments,
        ],
    ], JSON_THROW_ON_ERROR);

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: finance-poc/1.0\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);

    $response = file_get_contents($endpoint, false, $context);
    if ($response === false) {
        throw new RuntimeException("Could not reach MCP endpoint for $tool");
    }

    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s2\d{2}\s/', $statusLine)) {
        throw new RuntimeException("MCP endpoint returned $statusLine for $tool");
    }

    $rpcResponse = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if (isset($rpcResponse['error'])) {
        $message = $rpcResponse['error']['message'] ?? 'Unknown MCP error';
        throw new RuntimeException("$tool failed: $message");
    }

    $text = $rpcResponse['result']['content'][0]['text'] ?? null;
    if (!is_string($text)) {
        throw new RuntimeException("$tool returned no text content");
    }

    $result = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException("$tool returned invalid JSON content");
    }

    return $result;
}

function callMcpWithRetry(string $endpoint, string $tool, array $arguments = []): array
{
    $lastError = null;

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            return callMcp($endpoint, $tool, $arguments);
        } catch (Throwable $error) {
            $lastError = $error;
            if ($attempt < 3) {
                sleep(5);
            }
        }
    }

    throw new RuntimeException($tool . ' failed after 3 attempts: ' . $lastError?->getMessage());
}

try {
    $endpoint = getenv('MCP_ENDPOINT');
    if ($endpoint === false || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('MCP_ENDPOINT must contain a valid URL');
    }

    $server = callMcpWithRetry($endpoint, 'get_server_info');
    $quote = callMcpWithRetry($endpoint, 'avanza_get_quote', [
        'instrument_id' => SWEDBANK_A_INSTRUMENT_ID,
    ]);

    $serverName = $server['name'] ?? 'unknown';
    $serverVersion = $server['version'] ?? 'unknown';
    $lastPrice = $quote['last'] ?? null;
    if (!is_int($lastPrice) && !is_float($lastPrice)) {
        throw new RuntimeException('Avanza response did not contain a numeric last price');
    }

    echo "Finance POC\n";
    echo "Server: $serverName $serverVersion\n";
    echo 'Swedbank A: ' . number_format((float) $lastPrice, 2, ',', ' ') . " SEK\n";
    echo 'Time: ' . gmdate('Y-m-d H:i:s') . " UTC\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Finance POC failed: ' . $error->getMessage() . "\n");
    exit(1);
}