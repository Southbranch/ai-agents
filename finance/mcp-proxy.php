<?php

// Public proxy so web chat clients without MCP support can reach the MCP server via plain HTTPS/JSON.
header('Content-Type: application/json; charset=utf-8');

$MCP_URL = 'https://adduco.se/api/mcp.php';
$ALLOWED_METHODS = ['initialize', 'tools/list', 'tools/call'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

$method = $data['method'] ?? null;
$params = $data['params'] ?? (object) [];

if (!$method) {
    http_response_code(400);
    echo json_encode(['error' => 'method_required']);
    exit;
}
// Whitelist limits the proxy to the MCP protocol surface, even without auth.
if (!in_array($method, $ALLOWED_METHODS, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$rpc = [
    'jsonrpc' => '2.0',
    'id' => $data['id'] ?? random_int(1, 999999999),
    'method' => $method,
    'params' => $params,
];

$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
    'content' => json_encode($rpc),
    'timeout' => 60,
    'ignore_errors' => true,
]]);
$response = file_get_contents($MCP_URL, false, $context);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'upstream_failure']);
    exit;
}

echo $response;
