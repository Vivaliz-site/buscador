<?php
declare(strict_types=1);

// Deep-research cycles can legitimately exceed the default PHP request timeout.
// Keep the server-side cycle alive long enough to finish all providers and consensus.
@set_time_limit(900);
ignore_user_abort(true);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';
require_once dirname(__DIR__, 2) . '/config/agent-keys.php';
require_once dirname(__DIR__, 2) . '/includes/order-rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/buscador-core.php';

header_remove('X-Powered-By');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function svais_api_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$serverKey] ?? '';
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $headerValue) {
            if (strcasecmp((string)$headerName, $name) === 0) {
                return trim((string)$headerValue);
            }
        }
    }
    return '';
}

function svais_api_is_admin_session(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return !empty($_SESSION['user_id']) && !empty($_SESSION['is_admin']);
}

function svais_api_auth_mode(): string
{
    if (PHP_SAPI === 'cli') {
        return 'cli';
    }
    if (svais_api_is_admin_session()) {
        return 'session';
    }

    $candidates = [];
    foreach (['GEPETO_ACTION_KEY', 'SHOPVIVALIZ_AGENT_KEY', 'RUNTIME_AGENT_KEY', 'AUTONOMOUS_AGENT_KEY', 'SQUAD_TOKEN'] as $name) {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            $candidates[] = trim($value);
        }
    }
    if ($candidates === []) {
        return 'none';
    }

    $provided = svais_api_header('X-Agent-Key');
    if ($provided === '') {
        $provided = svais_api_header('X-Squad-Token');
    }
    if ($provided === '') {
        $authorization = svais_api_header('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $provided = trim((string)$matches[1]);
        }
    }

    foreach ($candidates as $expected) {
        if ($provided !== '' && hash_equals($expected, $provided)) {
            return 'key';
        }
    }
    return 'none';
}

function svais_api_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svais_api_emit(array $payload, bool $stream, array &$events): void
{
    $payload['at'] = $payload['at'] ?? date('c');
    if (!$stream) {
        $events[] = $payload;
        return;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

function svais_api_log_cycle(array $metadata): void
{
    $dir = dirname(__DIR__, 2) . '/storage/private';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    @file_put_contents(
        $dir . '/buscador-cycles.jsonl',
        json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$profiles = svais_profile_catalog();

if ($method === 'GET' && ($_GET['health'] ?? '') === '1') {
    $profileName = isset($profiles[(string)($_GET['profile'] ?? '')]) ? (string)$_GET['profile'] : 'deep_research';
    $profile = svais_profile($profileName);
    svais_api_json(200, [
        'ok' => true,
        'endpoint' => 'buscador',
        'profile' => $profileName,
        'providers' => svais_provider_state($profile),
        'profiles' => array_map(static fn(array $p): string => (string)$p['label'], $profiles),
        'anthropic_policy' => 'claude_code_account_only_no_fable',
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    svais_api_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$authMode = svais_api_auth_mode();
if ($authMode === 'none') {
    svais_api_json(401, ['ok' => false, 'error' => 'unauthorized']);
}
if ($authMode === 'session') {
    $csrf = svais_api_header('X-CSRF-Token');
    $expectedCsrf = (string)($_SESSION['ai_squad_csrf'] ?? '');
    if ($csrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
        svais_api_json(403, ['ok' => false, 'error' => 'csrf_failed']);
    }
}

if (!svorl_allow(6, 600, 'buscador')) {
    svais_api_json(429, ['ok' => false, 'error' => 'rate_limited']);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 131072) {
    svais_api_json(413, ['ok' => false, 'error' => 'payload_too_large']);
}

$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    svais_api_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$topic = trim((string)($body['message'] ?? ''));
if ($topic === '' || mb_strlen($topic, 'UTF-8') > 20000) {
    svais_api_json(422, ['ok' => false, 'error' => 'invalid_message']);
}

$profileName = (string)($body['profile'] ?? 'deep_research');
if (!isset($profiles[$profileName])) {
    svais_api_json(422, ['ok' => false, 'error' => 'invalid_profile']);
}
$profile = svais_profile($profileName);

$mode = strtolower((string)($body['mode'] ?? 'research'));
if (!in_array($mode, ['parallel', 'debate', 'research'], true)) {
    svais_api_json(422, ['ok' => false, 'error' => 'invalid_mode']);
}

$stream = ($body['stream'] ?? true) !== false;
if (!defined('SVAIS_STREAM_HEARTBEAT')) {
    define('SVAIS_STREAM_HEARTBEAT', $stream);
}
if ($stream) {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
}

@set_time_limit(0);
ignore_user_abort(true);

$cycleId = 'ais_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(8)), 0, 10);
$providers = ['openai', 'anthropic', 'gemini'];
$events = [];
$transcript = [];
$providerStatus = [];
$startedAt = microtime(true);

svais_api_emit([
    'type' => 'cycle_started',
    'cycle_id' => $cycleId,
    'profile' => $profileName,
    'profile_label' => (string)$profile['label'],
    'mode' => $mode,
    'providers' => svais_provider_state($profile),
], $stream, $events);

$phases = ['research'];
if ($mode === 'debate' || $mode === 'research') {
    $phases[] = 'critique';
}
if ($mode === 'research') {
    $phases[] = 'converge';
}

foreach ($phases as $phase) {
    svais_api_emit([
        'type' => 'phase_started',
        'cycle_id' => $cycleId,
        'phase' => $phase,
    ], $stream, $events);

    $phaseBaseTranscript = $transcript;
    foreach ($providers as $provider) {
        if (($providerStatus[$provider] ?? '') === 'manual_required') {
            continue;
        }

        $prompt = svais_round_prompt($topic, $phase, $phase === 'research' ? [] : $phaseBaseTranscript);
        svais_api_emit([
            'type' => 'agent_started',
            'cycle_id' => $cycleId,
            'phase' => $phase,
            'provider' => $provider,
        ], $stream, $events);

        try {
            $result = svais_call_provider($provider, $profile, $phase, $prompt);
            $entry = [
                'type' => 'agent_message',
                'cycle_id' => $cycleId,
                'phase' => $phase,
                'provider' => $provider,
                'model' => (string)$result['model'],
                'text' => (string)$result['text'],
                'sources' => array_slice((array)$result['sources'], 0, 30),
                'usage' => $result['usage'],
                'latency_ms' => (int)$result['latency_ms'],
                'transport' => (string)($result['transport'] ?? 'direct'),
                'ok' => true,
            ];
            $transcript[] = $entry;
            $providerStatus[$provider] = 'ok';
            svais_api_emit($entry, $stream, $events);
        } catch (SvaisManualInterventionRequired $manual) {
            $providerStatus[$provider] = 'manual_required';
            $entry = [
                'type' => 'agent_manual_required',
                'cycle_id' => $cycleId,
                'phase' => $phase,
                'provider' => $provider,
                'model' => $manual->model,
                'prompt' => $manual->manualPrompt,
                'attempts' => $manual->attempts,
                'transport' => 'manual_chatgpt',
                'ok' => false,
            ];
            $transcript[] = $entry;
            svais_api_emit($entry, $stream, $events);
        } catch (Throwable $e) {
            $providerStatus[$provider] = 'error';
            $entry = [
                'type' => 'agent_error',
                'cycle_id' => $cycleId,
                'phase' => $phase,
                'provider' => $provider,
                'error' => svais_safe_error($e),
                'ok' => false,
            ];
            $transcript[] = $entry;
            svais_api_emit($entry, $stream, $events);
        }
    }
}

$successful = array_values(array_filter(
    $transcript,
    static fn(array $entry): bool => ($entry['type'] ?? '') === 'agent_message' && ($entry['ok'] ?? false) === true
));

$completeCoverage = svais_cycle_complete_for_consensus($transcript, $providers, $phases);
$consensus = null;
if ($completeCoverage) {
    $consensusPrompt = svais_consensus_prompt($topic, $successful);
    svais_api_emit([
        'type' => 'phase_started',
        'cycle_id' => $cycleId,
        'phase' => 'consensus',
    ], $stream, $events);

    $moderators = ['openai', 'anthropic', 'gemini'];
    foreach ($moderators as $moderator) {
        if (($providerStatus[$moderator] ?? '') !== 'ok') {
            continue;
        }
        try {
            $result = svais_call_provider($moderator, $profile, 'moderate', $consensusPrompt, false);
            $consensus = [
                'type' => 'consensus',
                'cycle_id' => $cycleId,
                'provider' => $moderator,
                'model' => (string)$result['model'],
                'text' => (string)$result['text'],
                'sources' => array_slice((array)$result['sources'], 0, 30),
                'usage' => $result['usage'],
                'transport' => (string)($result['transport'] ?? 'direct'),
                'ok' => true,
            ];
            svais_api_emit($consensus, $stream, $events);
            break;
        } catch (Throwable $e) {
            svais_api_emit([
                'type' => 'moderator_error',
                'cycle_id' => $cycleId,
                'provider' => $moderator,
                'error' => svais_safe_error($e),
                'ok' => false,
            ], $stream, $events);
        }
    }
}

$durationMs = (int)round((microtime(true) - $startedAt) * 1000);
$cycleOk = $completeCoverage && is_array($consensus);
$done = [
    'type' => 'cycle_finished',
    'cycle_id' => $cycleId,
    'ok' => $cycleOk,
    'profile' => $profileName,
    'mode' => $mode,
    'provider_status' => $providerStatus,
    'message_count' => count($successful),
    'complete_provider_coverage' => $completeCoverage,
    'consensus_available' => is_array($consensus),
    'duration_ms' => $durationMs,
];
svais_api_emit($done, $stream, $events);

svais_api_log_cycle([
    'cycle_id' => $cycleId,
    'at' => date('c'),
    'profile' => $profileName,
    'mode' => $mode,
    'topic_length' => mb_strlen($topic, 'UTF-8'),
    'provider_status' => $providerStatus,
    'message_count' => count($successful),
    'complete_provider_coverage' => $completeCoverage,
    'consensus_available' => is_array($consensus),
    'duration_ms' => $durationMs,
]);

if (!$stream) {
    echo json_encode([
        'ok' => $cycleOk,
        'endpoint' => 'buscador',
        'cycle_id' => $cycleId,
        'events' => $events,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
