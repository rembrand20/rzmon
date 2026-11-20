<?php
// 1.292.0: één verzoek dat RzSense bij een relay ophaalde (RelayPull), verwerken met precies dezelfde code als een gewoon
// verzoek van een agent (public/api.php). Alleen via de CLI, gestart door RelayPull::process; het verzoek komt als JSON
// binnen op stdin, het antwoord gaat als JSON naar stdout: {"code": 200, "body": "<base64>", "keep": true}.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Alleen via CLI\n");
}
ini_set('display_errors', 'stderr');   // een waarschuwing mag het antwoord niet onleesbaar maken

$job = json_decode((string) stream_get_contents(STDIN), true);
$query = is_array($job) && preg_match('/^api\.php\?([A-Za-z0-9_=&%.-]{1,300})$/', (string) ($job['target'] ?? ''), $m) ? $m[1] : '';
$ip = is_array($job) ? (string) ($job['ip'] ?? '') : '';
if ($query === '' || !filter_var($ip, FILTER_VALIDATE_IP) || !in_array($job['method'] ?? '', ['GET', 'POST'], true)) {
    echo json_encode(['code' => 400, 'body' => '', 'keep' => false]);
    exit(1);
}

// Het verzoek zoals de webserver het zou geven
$_SERVER['REQUEST_METHOD'] = (string) $job['method'];
$_SERVER['QUERY_STRING'] = $query;
$_SERVER['SCRIPT_NAME'] = '/api.php';
$_SERVER['REQUEST_URI'] = '/api.php?' . $query;
$_SERVER['REMOTE_ADDR'] = $ip;
$_SERVER['HTTP_X_RZ_RELAY'] = 'pull';   // client_ip(): het adres van de agent komt van de relay (Relay::current)
foreach ((array) ($job['headers'] ?? []) as $k => $v) {
    if (is_string($k) && is_string($v) && preg_match('/^X-Agent-[A-Za-z-]+$|^Content-Type$/', $k)) {
        $_SERVER[$k === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }
}
parse_str($query, $_GET);
$GLOBALS['rz_req_body'] = (string) base64_decode((string) ($job['body'] ?? ''), true);
$GLOBALS['rz_pull'] = ['relay' => (int) ($job['relay'] ?? 0), 'for' => $ip, 'rts' => (int) ($job['rts'] ?? 0), 'light' => !empty($job['light'])];

ob_start();
register_shutdown_function(static function (): void {
    $out = (string) ob_get_clean();
    $code = http_response_code();
    echo json_encode(['code' => is_int($code) && $code > 0 ? $code : 200, 'body' => base64_encode($out), 'keep' => empty($GLOBALS['agent_noreply'])]);
});
require __DIR__ . '/../public/api.php';
