<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/buscador-core.php';

function ais_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$catalog = svais_profile_catalog();
ais_assert(isset($catalog['deep_research'], $catalog['balanced'], $catalog['fast']), 'expected profiles missing');

$deep = $catalog['deep_research'];
ais_assert(($deep['openai']['model'] ?? '') === (getenv('BUSCADOR_OPENAI_MODEL') ?: 'gpt-5.6-terra'), 'deep OpenAI model mismatch');
ais_assert(($deep['openai']['effort'] ?? '') === 'medium', 'deep OpenAI effort must be medium');
ais_assert(($deep['anthropic']['model'] ?? '') === svais_non_fable_model('BUSCADOR_ANTHROPIC_MODEL', 'claude-sonnet-5'), 'deep Anthropic model mismatch');
ais_assert(($deep['anthropic']['effort'] ?? '') === 'medium', 'deep Anthropic effort must be medium');
ais_assert(($deep['gemini']['model'] ?? '') === (getenv('BUSCADOR_GEMINI_MODEL') ?: 'gemini-3.5-flash'), 'deep Gemini model mismatch');
ais_assert(($deep['gemini']['thinking_level'] ?? '') === 'MEDIUM', 'deep Gemini thinking must be MEDIUM');
ais_assert(svais_gemini_thinking_config($deep['gemini']) === ['thinkingBudget' => 8192], 'Gemini 2.5 MEDIUM must use thinkingBudget 8192');
$fast = $catalog['fast'];
ais_assert(svais_gemini_thinking_config($fast['gemini']) === ['thinkingBudget' => 1024], 'Gemini 2.5 LOW must use thinkingBudget 1024');
ais_assert(svais_gemini_thinking_config(['model' => 'gemini-3-flash-preview', 'thinking_level' => 'MEDIUM']) === ['thinkingLevel' => 'medium'], 'Gemini 3 must use thinkingLevel');

ais_assert(svais_health_state(true, true) === 'verified', 'health state verified mismatch');
ais_assert(svais_health_state(false, true) === 'configured_unverified', 'configured provider must not be reported as verified');
ais_assert(svais_health_state(false, false) === 'unavailable', 'unconfigured provider health mismatch');

$serialized = strtolower(json_encode($catalog, JSON_UNESCAPED_SLASHES) ?: '');
ais_assert(!str_contains($serialized, 'fable'), 'Fable must not appear in any Buscador preset');
putenv('BUSCADOR_TEST_ANTHROPIC_MODEL=claude-fable-5');
ais_assert(
    svais_non_fable_model('BUSCADOR_TEST_ANTHROPIC_MODEL', 'claude-opus-5') === 'claude-opus-5',
    'Fable override must be rejected'
);
putenv('BUSCADOR_TEST_ANTHROPIC_MODEL');


$openaiFixture = [
    'output' => [[
        'type' => 'message',
        'content' => [[
            'type' => 'output_text',
            'text' => 'openai-ok',
            'annotations' => [['type' => 'url_citation', 'url' => 'https://example.com/a']],
        ]],
    ]],
];
ais_assert(svais_text_from_openai($openaiFixture) === 'openai-ok', 'OpenAI parser failed');

$anthropicFixture = [
    'content' => [[
        'type' => 'text',
        'text' => 'claude-ok',
        'citations' => [['type' => 'web_search_result_location', 'url' => 'https://example.com/b']],
    ]],
];
ais_assert(svais_text_from_anthropic($anthropicFixture) === 'claude-ok', 'Anthropic parser failed');

$geminiFixture = [
    'candidates' => [[
        'content' => ['parts' => [['text' => 'gemini-ok']]],
        'groundingMetadata' => [
            'groundingChunks' => [['web' => ['uri' => 'https://example.com/c']]],
        ],
    ]],
];
ais_assert(svais_text_from_gemini($geminiFixture) === 'gemini-ok', 'Gemini parser failed');

$urls = [];
svais_collect_urls([$openaiFixture, $anthropicFixture, $geminiFixture], $urls);
ais_assert(isset($urls['https://example.com/a']), 'OpenAI source URL not collected');
ais_assert(isset($urls['https://example.com/b']), 'Anthropic source URL not collected');
ais_assert(isset($urls['https://example.com/c']), 'Gemini source URL not collected');

$prompt = svais_round_prompt('teste', 'critique', [[
    'ok' => true,
    'provider' => 'openai',
    'phase' => 'research',
    'text' => 'achado',
]]);
ais_assert(str_contains($prompt, 'TAREFA ORIGINAL'), 'critique prompt missing original task');
ais_assert(str_contains($prompt, 'OPENAI'), 'critique prompt missing peer transcript');

$consensus = svais_consensus_prompt('teste', [[
    'ok' => true,
    'provider' => 'gemini',
    'phase' => 'converge',
    'text' => 'posição final',
]]);
ais_assert(str_contains($consensus, 'SÍNTESE DE CONSENSO'), 'consensus prompt contract missing');

$completeTranscript = [];
foreach (['research', 'critique', 'converge'] as $phaseName) {
    foreach (['openai', 'anthropic', 'gemini'] as $providerName) {
        $completeTranscript[] = [
            'type' => 'agent_message',
            'ok' => true,
            'provider' => $providerName,
            'phase' => $phaseName,
            'text' => $providerName . '-' . $phaseName,
        ];
    }
}
ais_assert(
    svais_cycle_complete_for_consensus($completeTranscript, ['openai', 'anthropic', 'gemini'], ['research', 'critique', 'converge']),
    'complete three-provider coverage must allow consensus'
);
$missingOne = $completeTranscript;
array_pop($missingOne);
ais_assert(
    !svais_cycle_complete_for_consensus($missingOne, ['openai', 'anthropic', 'gemini'], ['research', 'critique', 'converge']),
    'missing any provider/phase must block consensus'
);
$errorTranscript = $completeTranscript;
$errorTranscript[0]['ok'] = false;
$errorTranscript[0]['type'] = 'agent_error';
ais_assert(
    !svais_cycle_complete_for_consensus($errorTranscript, ['openai', 'anthropic', 'gemini'], ['research', 'critique', 'converge']),
    'provider error must block consensus coverage'
);

$order = svais_openai_transport_order();
ais_assert($order === ['codex_chatgpt', 'manual_chatgpt'], 'OpenAI transport order mismatch');

$anthropicOrder = svais_anthropic_transport_order();
ais_assert($anthropicOrder === ['claude_code'], 'Anthropic transport must use Claude Code account login only');
$geminiOrder = svais_gemini_transport_order();
ais_assert($geminiOrder === ['vertex_oauth', 'direct'], 'Gemini transport order mismatch');

$anthropicCalls = [];
$anthropicResult = svais_anthropic_dispatch(
    $deep['anthropic'],
    'system',
    'prompt',
    true,
    function (string $transport) use (&$anthropicCalls, $deep): array {
        $anthropicCalls[] = $transport;
        if ($transport !== 'claude_code') throw new RuntimeException('unexpected_transport');
        return [
            'text' => 'claude-oauth-ok',
            'sources' => [],
            'usage' => [],
            'model' => $deep['anthropic']['model'],
            'transport' => 'claude_code',
        ];
    }
);
ais_assert(($anthropicResult['text'] ?? '') === 'claude-oauth-ok', 'Claude Code OAuth result missing');
ais_assert($anthropicCalls === ['claude_code'], 'Claude Code account login must be the only Anthropic transport');

$anthropicFailureCalls = [];
$anthropicFailure = null;
try {
    svais_anthropic_dispatch(
        $deep['anthropic'],
        'system',
        'prompt',
        true,
        function (string $transport) use (&$anthropicFailureCalls): array {
            $anthropicFailureCalls[] = $transport;
            throw new RuntimeException('usage_limit_exhausted');
        }
    );
} catch (RuntimeException $e) {
    $anthropicFailure = $e;
}
ais_assert($anthropicFailure instanceof RuntimeException, 'Claude Code failure must surface without provider fallback');
ais_assert($anthropicFailureCalls === ['claude_code'], 'Claude failure must not fall back to direct, Vertex or OpenRouter');
ais_assert(str_contains($anthropicFailure->getMessage(), 'anthropic_transports_exhausted:claude_code=quota'), 'Claude Code failure classification missing');

$geminiCalls = [];
$geminiResult = svais_gemini_dispatch(
    $deep['gemini'],
    'system',
    'prompt',
    true,
    function (string $transport) use (&$geminiCalls, $deep): array {
        $geminiCalls[] = $transport;
        if ($transport !== 'vertex_oauth') throw new RuntimeException('unexpected_transport');
        return [
            'text' => 'gemini-vertex-ok',
            'sources' => [],
            'usage' => [],
            'model' => $deep['gemini']['model'],
            'transport' => 'vertex_oauth',
        ];
    }
);
ais_assert(($geminiResult['text'] ?? '') === 'gemini-vertex-ok', 'Gemini Vertex OAuth result missing');
ais_assert($geminiCalls === ['vertex_oauth'], 'Vertex OAuth must be Gemini primary');

$dispatchCfg = $deep['openai'];
$calls = [];
$codexResult = svais_openai_dispatch(
    $dispatchCfg,
    'system',
    'prompt',
    false,
    function (string $transport) use (&$calls, $dispatchCfg): array {
        $calls[] = $transport;
        if ($transport !== 'codex_chatgpt') {
            throw new RuntimeException('unexpected_transport');
        }
        return [
            'text' => 'codex-ok',
            'sources' => [],
            'usage' => [],
            'model' => $dispatchCfg['model'],
            'transport' => 'codex_chatgpt',
        ];
    }
);
ais_assert(($codexResult['text'] ?? '') === 'codex-ok', 'Codex transport result missing');
ais_assert($calls === ['codex_chatgpt'], 'Codex success must stop OpenAI chain');

$calls = [];
$quotaFallback = null;
try {
    svais_openai_dispatch(
        $dispatchCfg,
        'system',
        'prompt',
        false,
        function (string $transport) use (&$calls): array {
            $calls[] = $transport;
            throw new RuntimeException('usage_limit_exhausted');
        }
    );
} catch (SvaisManualInterventionRequired $e) {
    $quotaFallback = $e;
}
ais_assert($quotaFallback instanceof SvaisManualInterventionRequired, 'Codex quota exhaustion must fall back to ChatGPT manual mode');
ais_assert($calls === ['codex_chatgpt'], 'OpenAI Platform API must not be attempted after Codex quota exhaustion');
ais_assert(($quotaFallback->attempts[0]['class'] ?? '') === 'quota', 'Codex quota fallback class missing');

$manual = null;
try {
    svais_openai_dispatch(
        $dispatchCfg,
        'system-marker',
        'prompt-marker',
        true,
        static function (string $transport): array {
            throw new RuntimeException($transport . '_unavailable');
        }
    );
} catch (SvaisManualInterventionRequired $e) {
    $manual = $e;
}
ais_assert($manual instanceof SvaisManualInterventionRequired, 'manual fallback exception missing');
ais_assert($manual->model === $dispatchCfg['model'], 'manual fallback model mismatch');
ais_assert(count($manual->attempts) === 1, 'manual fallback attempts must cover only ChatGPT/Codex before manual ChatGPT');
ais_assert(str_contains($manual->manualPrompt, 'system-marker'), 'manual prompt missing system');
ais_assert(str_contains($manual->manualPrompt, 'prompt-marker'), 'manual prompt missing task');

$modelMismatch = null;
try {
    svais_openai_dispatch(
        $dispatchCfg,
        'system',
        'prompt',
        false,
        static function (string $transport) use ($dispatchCfg): array {
            if ($transport === 'codex_chatgpt') {
                return [
                    'text' => 'wrong-model',
                    'sources' => [],
                    'usage' => [],
                    'model' => 'gpt-5.6-sol',
                    'transport' => 'codex_chatgpt',
                ];
            }
            throw new RuntimeException('unavailable');
        }
    );
} catch (SvaisManualInterventionRequired $e) {
    $modelMismatch = $e;
}
ais_assert($modelMismatch instanceof SvaisManualInterventionRequired, 'model mismatch must not be accepted');
ais_assert(($modelMismatch->attempts[0]['class'] ?? '') === 'model', 'model mismatch class missing');

$state = svais_provider_state($deep);
ais_assert(($state['openai']['transport_order'] ?? []) === $order, 'health transport order missing');
ais_assert(($state['openai']['manual_chatgpt_fallback'] ?? false) === true, 'health manual ChatGPT fallback missing');
ais_assert(($state['openai']['platform_api_fallback'] ?? true) === false, 'OpenAI health must explicitly disable Platform API fallback');
ais_assert(($state['anthropic']['transport_order'] ?? []) === $anthropicOrder, 'Anthropic health transport order missing');
ais_assert(array_key_exists('claude_code_oauth_configured', $state['anthropic']), 'Anthropic health Claude OAuth state missing');
ais_assert(($state['anthropic']['account_login_only'] ?? false) === true, 'Anthropic health must declare account login only');
ais_assert(!array_key_exists('direct_configured', $state['anthropic']), 'Anthropic health must not advertise direct fallback');
ais_assert(!array_key_exists('vertex_oauth_configured', $state['anthropic']), 'Anthropic health must not advertise Vertex fallback');
ais_assert(!array_key_exists('openrouter_fallback_configured', $state['anthropic']), 'Anthropic health must not advertise OpenRouter fallback');
ais_assert(($state['gemini']['transport_order'] ?? []) === $geminiOrder, 'Gemini health transport order missing');
ais_assert(array_key_exists('vertex_oauth_configured', $state['gemini']), 'Gemini health Vertex OAuth state missing');
ais_assert(!array_key_exists('openrouter_fallback_configured', $state['gemini']), 'Gemini health must not advertise broken OpenRouter fallback');

foreach (['openai', 'anthropic', 'gemini'] as $providerId) {
    ais_assert(
        in_array((string)($state[$providerId]['health'] ?? ''), ['verified', 'configured_unverified', 'unavailable'], true),
        $providerId . ' provider health state missing or invalid'
    );
}

$uiSource = (string)file_get_contents(dirname(__DIR__) . '/admin/buscador.php');
ais_assert(str_contains($uiSource, 'configured_unverified'), 'UI must expose configured-but-unverified state');
ais_assert(str_contains($uiSource, 'healthState(p)'), 'UI must normalize provider health state');
ais_assert(!str_contains($uiSource, "(p.configured?'ok':'bad')"), 'UI must not paint configured-only providers green');
ais_assert(str_contains($uiSource, "j.endpoint!=='buscador'"), 'UI must validate Buscador health endpoint identity');

$coreSource = (string)file_get_contents(dirname(__DIR__) . '/includes/buscador-core.php');
ais_assert(!str_contains($coreSource, 'function svais_openai_call'), 'Buscador core must not retain dormant OpenAI Platform API transport');
ais_assert(!str_contains($coreSource, "getenv('OPENAI_API_KEY')"), 'Buscador core must not read OPENAI_API_KEY');
ais_assert(!str_contains($coreSource, 'function svais_anthropic_call'), 'Buscador core must not retain dormant Anthropic API transport');
ais_assert(!str_contains($coreSource, 'function svais_anthropic_vertex_call'), 'Buscador core must not retain dormant Anthropic Vertex transport');
ais_assert(!str_contains($coreSource, "getenv('ANTHROPIC_API_KEY')"), 'Buscador core must not read ANTHROPIC_API_KEY');

$adminSource = (string)file_get_contents(dirname(__DIR__) . '/admin/buscador.php');
ais_assert(str_contains($adminSource, 'manual_chatgpt'), 'UI must expose manual ChatGPT fallback');
ais_assert(str_contains($adminSource, 'https://chatgpt.com/'), 'UI must provide explicit ChatGPT fallback action');

$apiSource = (string)file_get_contents(dirname(__DIR__) . '/api/agent/buscador.php');
ais_assert(str_contains($apiSource, "claude_code_account_only_no_fable"), 'Claude policy label must reflect account-only transport');
$legacyPolicy = 'opus' . '5_primary_no_fable';
ais_assert(!str_contains($apiSource, $legacyPolicy), 'stale Claude policy label must not remain');

$apiSource = file_get_contents(__DIR__ . '/../api/agent/buscador.php');
ais_assert(str_contains($apiSource, 'set_time_limit(900)'), 'Buscador API must allow deep-research cycles beyond default PHP timeout');
ais_assert(str_contains($apiSource, 'ignore_user_abort(true)'), 'Buscador API must finish audit cycle after transient client disconnect');
ais_assert(str_contains($apiSource, 'svais_cycle_complete_for_consensus'), 'API must gate consensus on complete provider/phase coverage');
ais_assert(!str_contains($apiSource, 'if ($successful !== [])'), 'API must not allow partial-success consensus');
ais_assert(str_contains((string)file_get_contents(dirname(__DIR__) . '/includes/buscador-core.php'), 'CURLOPT_TIMEOUT_MS => 25000'), 'Codex health probe timeout must cover live bridge verification');
echo "BUSCADOR_CORE_TEST=PASS\n";

// Provider contract regression guard.
ais_assert((svais_profile('deep_research')['gemini']['model'] ?? '') === 'gemini-3.5-flash', 'deep_research must default to gemini-3.5-flash');
