<?php

header('Content-Type: application/json');

$serverVersion = '1.1.0'; // Increment for every deployed code change.
$stateFile = __DIR__ . '/agent_state.json';

function rpcError($id, int $code, string $message): void
{
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

function validSessionId($value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value) === 1;
}

$request = json_decode(file_get_contents('php://input'), true);
$id = $request['id'] ?? null;
$method = $request['method'] ?? '';

if ($method === 'initialize') {
    $result = [
        'protocolVersion' => '2025-06-18',
        'capabilities' => ['tools' => (object) []],
        'serverInfo' => ['name' => 'avanza-poc', 'version' => $serverVersion],
    ];
} elseif ($method === 'tools/list') {
    // Tool descriptions and schemas guide the model's tool selection.
    $idSchema = ['type' => 'string', 'pattern' => '^\d+$'];
    $result = ['tools' => [
        [
            'name' => 'get_server_info',
            'description' => 'Show the MCP server name and current version.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) []],
        ],
        [
            'name' => 'search_instruments',
            'description' => 'Search for stocks by name, ticker, or ISIN. Returns separate matches for share classes such as Class A and Class B.',
            'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'minLength' => 1]], 'required' => ['query']],
        ],
        [
            'name' => 'get_quote',
            'description' => 'Get the current stock price from Avanza.',
            'inputSchema' => ['type' => 'object', 'properties' => ['instrument_id' => $idSchema], 'required' => ['instrument_id']],
        ],
        [
            'name' => 'get_stock_info',
            'description' => 'Get company information, share class, and key metrics from Avanza.',
            'inputSchema' => ['type' => 'object', 'properties' => ['instrument_id' => $idSchema], 'required' => ['instrument_id']],
        ],
        [
            'name' => 'get_dividends',
            'description' => 'Get historical dividends for a stock from Avanza.',
            'inputSchema' => ['type' => 'object', 'properties' => ['instrument_id' => $idSchema], 'required' => ['instrument_id']],
        ],
        [
            'name' => 'get_history',
            'description' => 'Get historical stock prices from Avanza.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'instrument_id' => $idSchema,
                    'time_period' => ['type' => 'string', 'enum' => ['one_week', 'one_month', 'three_months', 'this_year', 'one_year', 'three_years', 'five_years']],
                ],
                'required' => ['instrument_id', 'time_period'],
            ],
        ],
        [
            'name' => 'get_options',
            'description' => 'Get the option chain for a stock. Set expiry to YYYY-MM-DD to return all strikes for the selected expiration date.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'instrument_id' => $idSchema,
                    'expiry' => ['type' => 'string', 'format' => 'date'],
                ],
                'required' => ['instrument_id'],
            ],
        ],
        [
            'name' => 'get_option_details',
            'description' => 'Get an option\'s Greeks (delta, gamma, theta, vega, and rho), theoretical price, implied volatility, and order book. instrument_id is the option orderbookId returned by get_options.',
            'inputSchema' => ['type' => 'object', 'properties' => ['instrument_id' => $idSchema], 'required' => ['instrument_id']],
        ],
        [
            'name' => 'load_state',
            'description' => 'Load persistent state for a session.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['session_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,100}$']],
                'required' => ['session_id'],
            ],
        ],
        [
            'name' => 'save_state',
            'description' => 'Save the complete persistent state for a session.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['session_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,100}$']],
                'required' => ['session_id'],
                'additionalProperties' => true,
            ],
        ],
    ]];
} elseif ($method === 'tools/call') {
    $name = $request['params']['name'] ?? '';
    $arguments = $request['params']['arguments'] ?? [];
    $instrumentId = $arguments['instrument_id'] ?? '';

    if (in_array($name, ['load_state', 'save_state'], true)) {
        $sessionId = $arguments['session_id'] ?? null;
        if (!validSessionId($sessionId)) {
            rpcError($id, -32602, 'Invalid session_id');
        }

        $handle = fopen($stateFile, 'c+');
        if ($handle === false) {
            rpcError($id, -32603, 'Could not open state storage');
        }

        $lockType = $name === 'save_state' ? LOCK_EX : LOCK_SH;
        if (!flock($handle, $lockType)) {
            fclose($handle);
            rpcError($id, -32603, 'Could not lock state storage');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $sessions = $contents === '' ? [] : json_decode($contents, true);
        if (!is_array($sessions)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            rpcError($id, -32603, 'State storage contains invalid JSON');
        }

        if ($name === 'load_state') {
            $state = $sessions[$sessionId] ?? ['exists' => false, 'session_id' => $sessionId];
        } else {
            $state = $arguments;
            $state['session_id'] = $sessionId;
            $state['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $sessions[$sessionId] = $state;

            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false || !fflush($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                rpcError($id, -32603, 'Could not save state');
            }
        }

        flock($handle, LOCK_UN);
        fclose($handle);
        $result = ['content' => [['type' => 'text', 'text' => json_encode($state, JSON_UNESCAPED_UNICODE)]]];
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
        exit;
    }

    $headers = "Accept: application/json\r\nUser-Agent: Mozilla/5.0\r\n";

    if ($name === 'get_server_info') {
        $response = json_encode(['name' => 'avanza-poc', 'version' => $serverVersion]);
    } elseif ($name === 'search_instruments') {
        $query = trim($arguments['query'] ?? '');
        if ($query === '') {
            echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Invalid query']]);
            exit;
        }
        $url = 'https://www.avanza.se/_api/search/filtered-search';
        $payload = json_encode(['query' => $query, 'instrumentTypes' => ['STOCK'], 'limit' => 10]);
        $context = ['http' => ['method' => 'POST', 'header' => $headers . "Content-Type: application/json\r\n", 'content' => $payload]];
        $search = json_decode(file_get_contents($url, false, stream_context_create($context)), true);
        $search['hits'] = array_values(array_filter($search['hits'] ?? [], fn($hit) => ($hit['type'] ?? '') === 'STOCK'));
        $response = json_encode($search);
    } else {
        // Numeric validation prevents IDs from altering the Avanza URL path.
        if (!is_string($instrumentId) || !ctype_digit($instrumentId)) {
            echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Invalid instrument_id']]);
            exit;
        }
    }

    if ($name === 'get_quote') {
        $url = "https://www.avanza.se/_api/market-guide/stock/$instrumentId/quote";
        $response = file_get_contents($url, false, stream_context_create(['http' => ['header' => $headers]]));
    } elseif ($name === 'get_stock_info') {
        $url = "https://www.avanza.se/_api/market-guide/stock/$instrumentId";
        $response = file_get_contents($url, false, stream_context_create(['http' => ['header' => $headers]]));
    } elseif ($name === 'get_dividends') {
        $url = "https://www.avanza.se/_api/market-guide/stock/$instrumentId/analysis";
        $analysis = json_decode(file_get_contents($url, false, stream_context_create(['http' => ['header' => $headers]])), true);
        $response = json_encode(['dividendsByYear' => $analysis['dividendsByYear'] ?? []]);
    } elseif ($name === 'get_history') {
        $period = $arguments['time_period'] ?? '';
        $periods = ['one_week', 'one_month', 'three_months', 'this_year', 'one_year', 'three_years', 'five_years'];
        if (!in_array($period, $periods, true)) {
            echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Invalid time_period']]);
            exit;
        }
        $url = "https://www.avanza.se/_api/price-chart/stock/$instrumentId?timePeriod=$period";
        $response = file_get_contents($url, false, stream_context_create(['http' => ['header' => $headers]]));
    } elseif ($name === 'get_option_details') {
        $url = "https://www.avanza.se/_api/market-guide/option/$instrumentId/details";
        $response = file_get_contents($url, false, stream_context_create(['http' => ['header' => $headers]]));
    } elseif ($name === 'get_options') {
        $url = 'https://www.avanza.se/_api/market-option-future-forward-list/matrix';
        $expiry = $arguments['expiry'] ?? '';
        if ($expiry !== '') {
            $date = date_parse_from_format('Y-m-d', $expiry);
            if ($date['error_count'] > 0 || $date['warning_count'] > 0 || $date['year'] . '-' . sprintf('%02d-%02d', $date['month'], $date['day']) !== $expiry) {
                echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Invalid expiry; use YYYY-MM-DD']]);
                exit;
            }
        }
        // A selected expiry fits in 100 rows; the unfiltered overview stays small.
        $payload = json_encode([
            'filter' => ['optionTypes' => [], 'yearMonths' => [], 'endDates' => $expiry === '' ? [] : [$expiry], 'underlyingInstruments' => [$instrumentId], 'callIndicators' => []],
            'offset' => 0,
            'limit' => $expiry === '' ? 20 : 100,
            'sortBy' => ['field' => 'strikePrice', 'order' => 'asc'],
        ]);
        $context = ['http' => ['method' => 'POST', 'header' => $headers . "Content-Type: application/json\r\n", 'content' => $payload]];
        $response = file_get_contents($url, false, stream_context_create($context));
    } elseif (!in_array($name, ['get_server_info', 'search_instruments'], true)) {
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Tool not found']]);
        exit;
    }

    // Avanza responses stay unmodified so clients receive all source fields.
    $result = ['content' => [['type' => 'text', 'text' => $response]]];
} else {
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
    exit;
}

echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
