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

$serialized = strtolower(json_encode($catalog, JSON_UNESCAPED_SLASHES) ?: '');
ais_assert(!str_contains($serialized, 'fable'), 'Fable must not appear in any Buscador preset');
putenv('BUSCADOR_TEST_ANTHROPIC_MODEL=claude-fable-5');
ais_assert(
    svais_non_fable_model('BUSCADOR_TEST_ANTHROPIC_MODEL', 'claude-opus-5') === 'claude-opus-5',
    'Fable override must be rejected'
);
putenv('BUSCADOR_TEST_ANTHROPIC_MODEL');

ais_assert(svais_openrouter_model_slug('openai', 'gpt-5.6-sol') === 'openai/gpt-5.6-sol', 'OpenRouter OpenAI slug mismatch');
ais_assert(svais_openrouter_model_slug('anthropic', 'claude-opus-5') === 'anthropic/claude-opus-5', 'OpenRouter Anthropic slug mismatch');
ais_assert(svais_openrouter_model_slug('gemini', 'gemini-3.1-pro-preview') === 'google/gemini-3.1-pro-preview', 'OpenRouter Gemini slug mismatch');

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

$order = svais_openai_transport_order();
ais_assert($order === ['codex_chatgpt', 'direct', 'manual'], 'OpenAI transport order mismatch');

$anthropicOrder = svais_anthropic_transport_order();
ais_assert($anthropicOrder === ['claude_code'], 'Anthropic transport must use Claude Code account login only');
$geminiOrder = svais_gemini_transport_order();
ais_assert($geminiOrder === ['vertex_oauth', 'direct', 'openrouter'], 'Gemini transport order mismatch');

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
$directResult = svais_openai_dispatch(
    $dispatchCfg,
    'system',
    'prompt',
    false,
    function (string $transport) use (&$calls, $dispatchCfg): array {
        $calls[] = $transport;
        if ($transport === 'codex_chatgpt') {
            throw new RuntimeException('usage_limit_exhausted');
        }
        if ($transport === 'direct') {
            return [
                'text' => 'direct-ok',
                'sources' => [],
                'usage' => [],
                'model' => $dispatchCfg['model'],
                'transport' => 'direct',
            ];
        }
        throw new RuntimeException('unexpected_transport');
    }
);
ais_assert(($directResult['text'] ?? '') === 'direct-ok', 'direct fallback failed');
ais_assert($calls === ['codex_chatgpt', 'direct'], 'direct fallback order mismatch');

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
ais_assert(count($manual->attempts) === 2, 'manual fallback attempts must cover Codex and direct OpenAI only');
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
ais_assert(($state['openai']['manual_fallback'] ?? false) === true, 'health manual fallback missing');
ais_assert(!array_key_exists('openrouter_fallback_configured', $state['openai']), 'OpenAI health must not advertise OpenRouter fallback');
ais_assert(($state['anthropic']['transport_order'] ?? []) === $anthropicOrder, 'Anthropic health transport order missing');
ais_assert(array_key_exists('claude_code_oauth_configured', $state['anthropic']), 'Anthropic health Claude OAuth state missing');
ais_assert(($state['anthropic']['account_login_only'] ?? false) === true, 'Anthropic health must declare account login only');
ais_assert(!array_key_exists('direct_configured', $state['anthropic']), 'Anthropic health must not advertise direct fallback');
ais_assert(!array_key_exists('vertex_oauth_configured', $state['anthropic']), 'Anthropic health must not advertise Vertex fallback');
ais_assert(!array_key_exists('openrouter_fallback_configured', $state['anthropic']), 'Anthropic health must not advertise OpenRouter fallback');
ais_assert(($state['gemini']['transport_order'] ?? []) === $geminiOrder, 'Gemini health transport order missing');
ais_assert(array_key_exists('vertex_oauth_configured', $state['gemini']), 'Gemini health Vertex OAuth state missing');

echo "BUSCADOR_CORE_TEST=PASS\n";
