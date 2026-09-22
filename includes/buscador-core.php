<?php
declare(strict_types=1);

/**
 * ShopVivaliz Buscador core.
 *
 * Three-provider research/debate engine:
 * - OpenAI Responses API
 * - Anthropic Messages API
 * - Google Gemini GenerateContent API
 *
 * Secrets are read only from the protected runtime environment. Never include
 * credential values in logs, exceptions returned to clients, or persisted data.
 */

require_once dirname(__DIR__) . '/config/bootstrap-env.php';

final class SvaisManualInterventionRequired extends RuntimeException
{
    public string $model;
    public string $manualPrompt;
    public array $attempts;

    public function __construct(string $model, string $manualPrompt, array $attempts)
    {
        parent::__construct('manual_intervention_required');
        $this->model = $model;
        $this->manualPrompt = $manualPrompt;
        $this->attempts = $attempts;
    }
}

function svais_openai_transport_order(): array
{
    return ['codex_chatgpt', 'direct', 'manual'];
}

function svais_anthropic_transport_order(): array
{
    return ['claude_code'];
}

function svais_gemini_transport_order(): array
{
    return ['vertex_oauth', 'direct', 'openrouter'];
}

function svais_failure_class(Throwable $e): string
{
    $message = strtolower($e->getMessage());
    if (str_contains($message, 'model_mismatch')) return 'model';
    if (str_contains($message, 'timeout')) return 'timeout';
    if (str_contains($message, 'not_configured')) return 'not_configured';
    if (str_contains($message, 'quota')
        || str_contains($message, 'rate limit')
        || str_contains($message, 'usage_limit')
        || str_contains($message, 'credit balance')
        || str_contains($message, 'weighted tokens')
        || str_contains($message, 'provider_http_429')) return 'quota';
    if (str_contains($message, 'auth')
        || str_contains($message, 'unauthorized')
        || str_contains($message, 'token expired')) return 'auth';
    return 'transport';
}

function svais_manual_openai_prompt(array $cfg, string $system, string $prompt): string
{
    return "AI SQUAD — INTERVENÇÃO MANUAL OPENAI\n"
        . "Modelo solicitado: " . (string)$cfg['model'] . "\n\n"
        . "INSTRUÇÕES DO AGENTE:\n{$system}\n\n"
        . "TAREFA DESTA FASE:\n{$prompt}";
}

function svais_non_fable_model(string $envName, string $default): string
{
    $candidate = trim((string)(getenv($envName) ?: ''));
    if ($candidate === '' || stripos($candidate, 'fable') !== false) {
        return $default;
    }
    return $candidate;
}

function svais_profile_catalog(): array
{
    return [
        'deep_research' => [
            'label' => 'Pesquisa profunda',
            'rounds' => 2,
            'web_search' => true,
            'openai' => [
                'model' => getenv('BUSCADOR_OPENAI_MODEL') ?: 'gpt-5.6-terra',
                'effort' => 'medium',
                'max_output_tokens' => 7000,
            ],
            'anthropic' => [
                'model' => svais_non_fable_model('BUSCADOR_ANTHROPIC_MODEL', 'claude-sonnet-5'),
                'effort' => 'medium',
                'max_tokens' => 7000,
                'web_search_max_uses' => 10,
            ],
            'gemini' => [
                'model' => getenv('BUSCADOR_GEMINI_MODEL') ?: 'gemini-3.5-flash',
                'thinking_level' => 'MEDIUM',
                'max_output_tokens' => 7000,
            ],
        ],
        'balanced' => [
            'label' => 'Equilibrado',
            'rounds' => 2,
            'web_search' => true,
            'openai' => [
                'model' => getenv('BUSCADOR_OPENAI_BALANCED_MODEL') ?: 'gpt-5.6-terra',
                'effort' => 'high',
                'max_output_tokens' => 4500,
            ],
            'anthropic' => [
                'model' => svais_non_fable_model('BUSCADOR_ANTHROPIC_BALANCED_MODEL', 'claude-sonnet-5'),
                'effort' => 'high',
                'max_tokens' => 4500,
                'web_search_max_uses' => 6,
            ],
            'gemini' => [
                'model' => getenv('BUSCADOR_GEMINI_BALANCED_MODEL') ?: 'gemini-3.5-flash',
                'thinking_level' => 'MEDIUM',
                'max_output_tokens' => 4500,
            ],
        ],
        'fast' => [
            'label' => 'Rápido',
            'rounds' => 1,
            'web_search' => false,
            'openai' => [
                'model' => getenv('BUSCADOR_OPENAI_FAST_MODEL') ?: 'gpt-5.6-luna',
                'effort' => 'medium',
                'max_output_tokens' => 2500,
            ],
            'anthropic' => [
                'model' => svais_non_fable_model('BUSCADOR_ANTHROPIC_FAST_MODEL', 'claude-haiku-4-5-20251001'),
                'effort' => 'low',
                'max_tokens' => 2500,
                'web_search_max_uses' => 0,
            ],
            'gemini' => [
                'model' => getenv('BUSCADOR_GEMINI_FAST_MODEL') ?: 'gemini-3.5-flash',
                'thinking_level' => 'LOW',
                'max_output_tokens' => 2500,
            ],
        ],
    ];
}

function svais_profile(string $name): array
{
    $catalog = svais_profile_catalog();
    return $catalog[$name] ?? $catalog['deep_research'];
}

function svais_codex_bridge_url(): string
{
    $url = trim((string)(getenv('BUSCADOR_CODEX_BRIDGE_URL') ?: 'http://127.0.0.1:17656'));
    return rtrim($url, '/');
}

function svais_codex_bridge_health(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    if ((string)(getenv('BUSCADOR_CODEX_ENABLED') ?: '1') === '0') {
        return $cached = ['authenticated' => false, 'available' => false];
    }

    $ch = curl_init(svais_codex_bridge_url() . '/health');
    if ($ch === false) {
        return $cached = ['authenticated' => false, 'available' => false];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 1500,
        CURLOPT_CONNECTTIMEOUT_MS => 300,
        CURLOPT_PROXY => '',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $status !== 200) {
        return $cached = ['authenticated' => false, 'available' => false];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return $cached = ['authenticated' => false, 'available' => false];
    }
    $webSearchMode = (string)($data['web_search_mode'] ?? '');
    if (!in_array($webSearchMode, ['cached', 'live', 'disabled'], true)) {
        $webSearchMode = 'unknown';
    }
    return $cached = [
        'authenticated' => ($data['auth_mode'] ?? '') === 'chatgpt',
        'available' => ($data['ok'] ?? false) === true
            && (int)($data['available_profile_count'] ?? 0) > 0,
        'web_search_mode' => $webSearchMode,
    ];
}

function svais_claude_bridge_url(): string
{
    $url = trim((string)(getenv('BUSCADOR_CLAUDE_BRIDGE_URL') ?: 'http://127.0.0.1:17657'));
    return rtrim($url, '/');
}

function svais_claude_bridge_health(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    if ((string)(getenv('BUSCADOR_CLAUDE_CODE_ENABLED') ?: '1') === '0') {
        return $cached = ['configured' => false, 'authenticated' => false, 'available' => false];
    }

    $ch = curl_init(svais_claude_bridge_url() . '/health');
    if ($ch === false) {
        return $cached = ['configured' => false, 'authenticated' => false, 'available' => false];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 1500,
        CURLOPT_CONNECTTIMEOUT_MS => 300,
        CURLOPT_PROXY => '',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $status !== 200) {
        return $cached = ['configured' => false, 'authenticated' => false, 'available' => false];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return $cached = ['configured' => false, 'authenticated' => false, 'available' => false];
    }
    return $cached = [
        'configured' => ($data['token_configured'] ?? false) === true,
        'authenticated' => ($data['authenticated'] ?? false) === true,
        'available' => ($data['ok'] ?? false) === true && ($data['authenticated'] ?? false) === true,
    ];
}

function svais_google_vertex_configured(): bool
{
    $clientId = trim((string)(getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ''));
    $clientSecret = trim((string)(getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: ''));
    $refreshToken = trim((string)(getenv('GOOGLE_OAUTH_REFRESH_TOKEN') ?: ''));
    $project = trim((string)(getenv('BUSCADOR_GOOGLE_CLOUD_PROJECT') ?: ''));
    if ($project === '' && preg_match('/^(\\d+)-/', $clientId, $match)) {
        $project = (string)$match[1];
    }
    return $clientId !== '' && $clientSecret !== '' && $refreshToken !== '' && $project !== '';
}

function svais_provider_state(array $profile): array
{
    $openRouterConfigured = trim((string)(getenv('OPENROUTER_API_KEY') ?: '')) !== '';
    $openAiDirectConfigured = trim((string)(getenv('OPENAI_API_KEY') ?: '')) !== '';
    $geminiDirectConfigured = trim((string)(getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '')) !== '';
    $vertexConfigured = svais_google_vertex_configured();
    $codex = svais_codex_bridge_health();
    $claude = svais_claude_bridge_health();
    return [
        'openai' => [
            'configured' => $codex['available'] || $openAiDirectConfigured,
            'codex_chatgpt_authenticated' => $codex['authenticated'],
            'codex_chatgpt_available' => $codex['available'],
            'codex_web_search_mode' => (string)($codex['web_search_mode'] ?? 'unknown'),
            'direct_configured' => $openAiDirectConfigured,
            'manual_fallback' => true,
            'transport_order' => svais_openai_transport_order(),
            'model' => (string)$profile['openai']['model'],
            'reasoning' => (string)$profile['openai']['effort'],
        ],
        'anthropic' => [
            'configured' => $claude['available'],
            'claude_code_oauth_configured' => $claude['configured'],
            'claude_code_authenticated' => $claude['authenticated'],
            'claude_code_available' => $claude['available'],
            'account_login_only' => true,
            'transport_order' => svais_anthropic_transport_order(),
            'model' => (string)$profile['anthropic']['model'],
            'reasoning' => (string)$profile['anthropic']['effort'],
        ],
        'gemini' => [
            'configured' => $vertexConfigured || $geminiDirectConfigured || $openRouterConfigured,
            'vertex_oauth_configured' => $vertexConfigured,
            'direct_configured' => $geminiDirectConfigured,
            'openrouter_fallback_configured' => $openRouterConfigured,
            'transport_order' => svais_gemini_transport_order(),
            'model' => (string)$profile['gemini']['model'],
            'reasoning' => strtolower((string)$profile['gemini']['thinking_level']),
        ],
    ];
}

function svais_safe_error(Throwable $e): string
{
    $message = preg_replace('/(sk-[A-Za-z0-9_-]+|AIza[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $e->getMessage());
    return mb_substr((string)$message, 0, 500, 'UTF-8');
}

function svais_http_json(string $url, array $headers, array $payload, int $timeout = 180): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init_failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('provider_transport_error');
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('provider_invalid_json');
    }

    if ($status < 200 || $status >= 300) {
        $providerMessage = '';
        if (is_string($decoded['error']['message'] ?? null)) {
            $providerMessage = (string)$decoded['error']['message'];
        } elseif (is_string($decoded['message'] ?? null)) {
            $providerMessage = (string)$decoded['message'];
        }
        throw new RuntimeException('provider_http_' . $status . ($providerMessage !== '' ? ': ' . $providerMessage : ''));
    }

    return $decoded;
}

function svais_codex_bridge_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    if ((string)(getenv('BUSCADOR_CODEX_ENABLED') ?: '1') === '0') {
        throw new RuntimeException('codex_bridge_not_configured');
    }

    $payload = [
        'model' => (string)$cfg['model'],
        'effort' => (string)$cfg['effort'],
        'prompt' => "SYSTEM:\n{$system}\n\nUSER:\n{$prompt}",
        'web_search' => $webSearch,
    ];
    $ch = curl_init(svais_codex_bridge_url() . '/v1/respond');
    if ($ch === false) {
        throw new RuntimeException('codex_bridge_transport_error');
    }
    $timeout = max(30, min(300, (int)(getenv('BUSCADOR_CODEX_HTTP_TIMEOUT') ?: 240)));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_PROXY => '',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $curlError !== '') {
        throw new RuntimeException('codex_bridge_transport_error');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('codex_bridge_invalid_json');
    }
    if ($status !== 200 || ($data['ok'] ?? false) !== true) {
        $classes = [];
        foreach ((array)($data['attempts'] ?? []) as $class) {
            if (is_string($class) && preg_match('/^[a-z_]+$/', $class)) {
                $classes[$class] = true;
            }
        }
        $suffix = $classes !== [] ? implode('_', array_keys($classes)) : 'unavailable';
        throw new RuntimeException('codex_bridge_' . $suffix);
    }

    return [
        'text' => trim((string)($data['text'] ?? '')),
        'sources' => array_values(array_filter((array)($data['sources'] ?? []), 'is_string')),
        'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
        'model' => (string)($data['model'] ?? ''),
        'transport' => 'codex_chatgpt',
    ];
}

function svais_claude_bridge_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    if ((string)(getenv('BUSCADOR_CLAUDE_CODE_ENABLED') ?: '1') === '0') {
        throw new RuntimeException('claude_bridge_not_configured');
    }

    $payload = [
        'model' => (string)$cfg['model'],
        'effort' => (string)$cfg['effort'],
        'system' => $system,
        'prompt' => $prompt,
        'web_search' => $webSearch,
    ];
    $ch = curl_init(svais_claude_bridge_url() . '/v1/respond');
    if ($ch === false) {
        throw new RuntimeException('claude_bridge_transport_error');
    }
    $timeout = max(30, min(300, (int)(getenv('BUSCADOR_CLAUDE_HTTP_TIMEOUT') ?: 240)));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_PROXY => '',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!is_string($body) || $curlError !== '') {
        throw new RuntimeException('claude_bridge_transport_error');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('claude_bridge_invalid_json');
    }
    if ($status !== 200 || ($data['ok'] ?? false) !== true) {
        $class = (string)($data['error_class'] ?? 'unavailable');
        if (!preg_match('/^[a-z_]+$/', $class)) $class = 'unavailable';
        throw new RuntimeException('claude_bridge_' . $class);
    }

    return [
        'text' => trim((string)($data['text'] ?? '')),
        'sources' => array_values(array_filter((array)($data['sources'] ?? []), 'is_string')),
        'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
        'model' => (string)($data['model'] ?? ''),
        'transport' => 'claude_code',
    ];
}

function svais_collect_urls(mixed $value, array &$urls): void
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (($key === 'url' || $key === 'uri') && is_string($item) && preg_match('#^https?://#i', $item)) {
                $urls[$item] = true;
            } else {
                svais_collect_urls($item, $urls);
            }
        }
    }
}

function svais_text_from_openai(array $data): string
{
    if (is_string($data['output_text'] ?? null) && trim((string)$data['output_text']) !== '') {
        return trim((string)$data['output_text']);
    }

    $parts = [];
    foreach (($data['output'] ?? []) as $item) {
        if (!is_array($item)) continue;
        foreach (($item['content'] ?? []) as $content) {
            if (!is_array($content)) continue;
            if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                $parts[] = (string)$content['text'];
            }
        }
    }
    return trim(implode("\n", $parts));
}

function svais_text_from_anthropic(array $data): string
{
    $parts = [];
    foreach (($data['content'] ?? []) as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
            $parts[] = (string)$block['text'];
        }
    }
    return trim(implode("\n", $parts));
}

function svais_text_from_gemini(array $data): string
{
    $parts = [];
    foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (is_array($part) && is_string($part['text'] ?? null)) {
            $parts[] = (string)$part['text'];
        }
    }
    return trim(implode("\n", $parts));
}

function svais_base_system(string $provider, string $phase): string
{
    $providerName = match ($provider) {
        'openai' => 'OpenAI',
        'anthropic' => 'Claude',
        'gemini' => 'Gemini',
        default => $provider,
    };

    $phaseInstruction = match ($phase) {
        'research' => 'Trabalhe de forma independente. Pesquise antes de concluir quando a resposta depender de fatos atuais, disponibilidade, preço, regras, versões ou qualquer dado que possa ter mudado.',
        'critique' => 'Faça contraditório real. Verifique as evidências dos outros dois agentes, identifique falso consenso, dados desatualizados, links fracos e conclusões sem suporte. Corrija com pesquisa própria quando necessário.',
        'converge' => 'Busque convergência. Depois de revisar toda a discussão, declare a proposta final que você considera sustentável pelas evidências e explicite qualquer divergência que ainda importe.',
        'moderate' => 'Atue apenas como moderador. Sintetize o que os três agentes realmente sustentam em comum e preserve divergências materiais. Não invente consenso.',
        default => 'Raciocine cuidadosamente e baseie conclusões em evidências.',
    };

    return "Você é {$providerName}, um dos três pesquisadores independentes do Buscador ShopVivaliz. "
        . $phaseInstruction . "\n"
        . "Regras: priorize fontes primárias e páginas do produto/serviço; informe incerteza; nunca invente preço, estoque, modelo, data, desconto ou disponibilidade; "
        . "quando usar a web, inclua URLs ou referências verificáveis no texto final. "
        . "Não revele raciocínio privado nem chain-of-thought: entregue apenas conclusões, evidências, checagens e justificativas resumidas. "
        . "Responda em português do Brasil.";
}

function svais_google_oauth_context(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $clientId = trim((string)(getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ''));
    $clientSecret = trim((string)(getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: ''));
    $refreshToken = trim((string)(getenv('GOOGLE_OAUTH_REFRESH_TOKEN') ?: ''));
    $project = trim((string)(getenv('BUSCADOR_GOOGLE_CLOUD_PROJECT') ?: ''));
    if ($project === '' && preg_match('/^(\d+)-/', $clientId, $match)) {
        $project = (string)$match[1];
    }
    if ($clientId === '' || $clientSecret === '' || $refreshToken === '' || $project === '') {
        throw new RuntimeException('google_vertex_oauth_not_configured');
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    if ($ch === false) {
        throw new RuntimeException('google_vertex_oauth_transport_error');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if (!is_string($body) || $curlError !== '') {
        throw new RuntimeException('google_vertex_oauth_transport_error');
    }
    $data = json_decode($body, true);
    if (!is_array($data) || $status < 200 || $status >= 300) {
        throw new RuntimeException('google_vertex_oauth_http_' . $status);
    }
    $accessToken = trim((string)($data['access_token'] ?? ''));
    if ($accessToken === '') {
        throw new RuntimeException('google_vertex_oauth_invalid_response');
    }

    return $cached = ['access_token' => $accessToken, 'project' => $project];
}

function svais_gemini_vertex_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    $oauth = svais_google_oauth_context();
    $payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'maxOutputTokens' => (int)$cfg['max_output_tokens'],
            'thinkingConfig' => ['thinkingLevel' => (string)$cfg['thinking_level']],
        ],
    ];
    if ($webSearch) {
        $payload['tools'] = [['googleSearch' => new stdClass()]];
    }

    $model = rawurlencode((string)$cfg['model']);
    $project = rawurlencode((string)$oauth['project']);
    $data = svais_http_json(
        'https://aiplatform.googleapis.com/v1/projects/' . $project
            . '/locations/global/publishers/google/models/' . $model . ':generateContent',
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (string)$oauth['access_token'],
        ],
        $payload,
        240
    );

    $urls = [];
    svais_collect_urls($data, $urls);
    return [
        'text' => svais_text_from_gemini($data),
        'sources' => array_keys($urls),
        'usage' => $data['usageMetadata'] ?? [],
        'model' => (string)$cfg['model'],
        'transport' => 'vertex_oauth',
    ];
}

function svais_anthropic_vertex_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    $oauth = svais_google_oauth_context();
    $maxTokens = max(1025, (int)$cfg['max_tokens']);
    $budget = match ((string)($cfg['effort'] ?? 'high')) {
        'xhigh' => 6000,
        'high' => 3500,
        'medium' => 2000,
        default => 1024,
    };
    $budget = min($budget, $maxTokens - 1);

    $payload = [
        'anthropic_version' => 'vertex-2023-10-16',
        'max_tokens' => $maxTokens,
        'stream' => false,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'thinking' => ['type' => 'enabled', 'budget_tokens' => $budget],
    ];
    if ($webSearch && (int)($cfg['web_search_max_uses'] ?? 0) > 0) {
        $payload['tools'] = [[
            'type' => 'web_search_20250305',
            'name' => 'web_search',
            'max_uses' => (int)$cfg['web_search_max_uses'],
        ]];
    }

    $model = rawurlencode((string)$cfg['model']);
    $project = rawurlencode((string)$oauth['project']);
    $data = svais_http_json(
        'https://aiplatform.googleapis.com/v1/projects/' . $project
            . '/locations/global/publishers/anthropic/models/' . $model . ':rawPredict',
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (string)$oauth['access_token'],
        ],
        $payload,
        240
    );

    $urls = [];
    svais_collect_urls($data, $urls);
    return [
        'text' => svais_text_from_anthropic($data),
        'sources' => array_keys($urls),
        'usage' => $data['usage'] ?? [],
        'model' => (string)$cfg['model'],
        'transport' => 'vertex_oauth',
    ];
}

function svais_openai_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    $key = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
    if ($key === '') {
        throw new RuntimeException('OPENAI_API_KEY_not_configured');
    }

    $payload = [
        'model' => (string)$cfg['model'],
        'instructions' => $system,
        'input' => $prompt,
        'reasoning' => ['effort' => (string)$cfg['effort']],
        'max_output_tokens' => (int)$cfg['max_output_tokens'],
    ];
    if ($webSearch) {
        $payload['tools'] = [['type' => 'web_search']];
        $payload['tool_choice'] = 'auto';
    }

    $data = svais_http_json('https://api.openai.com/v1/responses', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ], $payload, 240);

    $urls = [];
    svais_collect_urls($data, $urls);
    return [
        'text' => svais_text_from_openai($data),
        'sources' => array_keys($urls),
        'usage' => $data['usage'] ?? [],
        'model' => (string)($data['model'] ?? $cfg['model']),
    ];
}

function svais_anthropic_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    $key = trim((string)(getenv('ANTHROPIC_API_KEY') ?: ''));
    if ($key === '') {
        throw new RuntimeException('ANTHROPIC_API_KEY_not_configured');
    }

    $payload = [
        'model' => (string)$cfg['model'],
        'max_tokens' => (int)$cfg['max_tokens'],
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => (string)$cfg['effort']],
    ];
    if ($webSearch && (int)$cfg['web_search_max_uses'] > 0) {
        $payload['tools'] = [[
            'type' => 'web_search_20250305',
            'name' => 'web_search',
            'max_uses' => (int)$cfg['web_search_max_uses'],
            'user_location' => [
                'type' => 'approximate',
                'country' => 'BR',
                'timezone' => 'America/Sao_Paulo',
            ],
        ]];
    }

    $data = svais_http_json('https://api.anthropic.com/v1/messages', [
        'Content-Type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ], $payload, 240);

    if (($data['stop_reason'] ?? '') === 'pause_turn') {
        $payload['messages'][] = ['role' => 'assistant', 'content' => $data['content'] ?? []];
        $data = svais_http_json('https://api.anthropic.com/v1/messages', [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ], $payload, 240);
    }

    $urls = [];
    svais_collect_urls($data, $urls);
    return [
        'text' => svais_text_from_anthropic($data),
        'sources' => array_keys($urls),
        'usage' => $data['usage'] ?? [],
        'model' => (string)($data['model'] ?? $cfg['model']),
    ];
}

function svais_gemini_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    $key = trim((string)(getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: ''));
    if ($key === '') {
        throw new RuntimeException('GEMINI_API_KEY_not_configured');
    }

    $payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'maxOutputTokens' => (int)$cfg['max_output_tokens'],
            'thinkingConfig' => ['thinkingLevel' => (string)$cfg['thinking_level']],
        ],
    ];
    if ($webSearch) {
        $payload['tools'] = [['googleSearch' => new stdClass()]];
    }

    $model = rawurlencode((string)$cfg['model']);
    $data = svais_http_json(
        'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
        [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $key,
        ],
        $payload,
        240
    );

    $urls = [];
    svais_collect_urls($data, $urls);
    return [
        'text' => svais_text_from_gemini($data),
        'sources' => array_keys($urls),
        'usage' => $data['usageMetadata'] ?? [],
        'model' => (string)$cfg['model'],
    ];
}

function svais_openrouter_model_slug(string $provider, string $model): string
{
    if (str_contains($model, '/')) {
        return $model;
    }
    return match ($provider) {
        'openai' => 'openai/' . $model,
        'anthropic' => 'anthropic/' . $model,
        'gemini' => 'google/' . $model,
        default => $model,
    };
}

function svais_openrouter_call(string $provider, array $cfg, string $system, string $prompt, bool $webSearch): array
{
    $key = trim((string)(getenv('OPENROUTER_API_KEY') ?: ''));
    if ($key === '') {
        throw new RuntimeException('OPENROUTER_API_KEY_not_configured');
    }

    $effort = (string)($cfg['effort'] ?? strtolower((string)($cfg['thinking_level'] ?? 'high')));
    $maxTokens = (int)($cfg['max_output_tokens'] ?? $cfg['max_tokens'] ?? 5000);
    $payload = [
        'model' => svais_openrouter_model_slug($provider, (string)$cfg['model']),
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ],
        'max_tokens' => $maxTokens,
        'reasoning' => ['effort' => strtolower($effort)],
    ];
    if ($webSearch) {
        $payload['tools'] = [['type' => 'openrouter:web_search']];
    }

    $data = svais_http_json('https://openrouter.ai/api/v1/chat/completions', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
        'HTTP-Referer: https://shopvivaliz.com.br',
        'X-Title: ShopVivaliz Buscador',
    ], $payload, 240);

    $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    $urls = [];
    svais_collect_urls($data, $urls);
    return [
        'text' => $text,
        'sources' => array_keys($urls),
        'usage' => $data['usage'] ?? [],
        'model' => (string)($data['model'] ?? $payload['model']),
        'transport' => 'openrouter',
    ];
}

function svais_provider_model_matches(string $provider, string $requested, string $actual): bool
{
    if ($requested === $actual) return true;
    if ($actual === '') return false;
    return $actual === svais_openrouter_model_slug($provider, $requested);
}

function svais_transport_exhausted(string $provider, array $attempts): RuntimeException
{
    $parts = [];
    foreach ($attempts as $attempt) {
        $transport = preg_replace('/[^a-z_]/', '', (string)($attempt['transport'] ?? 'unknown'));
        $class = preg_replace('/[^a-z_]/', '', (string)($attempt['class'] ?? 'transport'));
        $parts[] = $transport . '=' . $class;
    }
    return new RuntimeException($provider . '_transports_exhausted:' . implode(',', $parts));
}

function svais_anthropic_dispatch(
    array $cfg,
    string $system,
    string $prompt,
    bool $webSearch,
    ?callable $invoke = null
): array {
    $invoke ??= static function (
        string $transport,
        array $cfg,
        string $system,
        string $prompt,
        bool $webSearch
    ): array {
        return match ($transport) {
            'claude_code' => svais_claude_bridge_call($cfg, $system, $prompt, $webSearch),
            default => throw new InvalidArgumentException('unknown_anthropic_transport'),
        };
    };

    $attempts = [];
    foreach (svais_anthropic_transport_order() as $transport) {
        try {
            $result = $invoke($transport, $cfg, $system, $prompt, $webSearch);
            if (trim((string)($result['text'] ?? '')) === '') {
                throw new RuntimeException('provider_empty_response');
            }
            if (!svais_provider_model_matches('anthropic', (string)$cfg['model'], (string)($result['model'] ?? ''))) {
                throw new RuntimeException('model_mismatch');
            }
            $result['transport'] = $transport;
            $result['transport_attempts'] = $attempts;
            return $result;
        } catch (Throwable $e) {
            $attempts[] = ['transport' => $transport, 'class' => svais_failure_class($e)];
        }
    }

    throw svais_transport_exhausted('anthropic', $attempts);
}

function svais_gemini_dispatch(
    array $cfg,
    string $system,
    string $prompt,
    bool $webSearch,
    ?callable $invoke = null
): array {
    $invoke ??= static function (
        string $transport,
        array $cfg,
        string $system,
        string $prompt,
        bool $webSearch
    ): array {
        return match ($transport) {
            'vertex_oauth' => svais_gemini_vertex_call($cfg, $system, $prompt, $webSearch),
            'direct' => svais_gemini_call($cfg, $system, $prompt, $webSearch),
            'openrouter' => svais_openrouter_call('gemini', $cfg, $system, $prompt, $webSearch),
            default => throw new InvalidArgumentException('unknown_gemini_transport'),
        };
    };

    $attempts = [];
    foreach (svais_gemini_transport_order() as $transport) {
        try {
            $result = $invoke($transport, $cfg, $system, $prompt, $webSearch);
            if (trim((string)($result['text'] ?? '')) === '') {
                throw new RuntimeException('provider_empty_response');
            }
            if (!svais_provider_model_matches('gemini', (string)$cfg['model'], (string)($result['model'] ?? ''))) {
                throw new RuntimeException('model_mismatch');
            }
            $result['transport'] = $transport;
            $result['transport_attempts'] = $attempts;
            return $result;
        } catch (Throwable $e) {
            $attempts[] = ['transport' => $transport, 'class' => svais_failure_class($e)];
        }
    }

    throw svais_transport_exhausted('gemini', $attempts);
}

function svais_openai_dispatch(
    array $cfg,
    string $system,
    string $prompt,
    bool $webSearch,
    ?callable $invoke = null,
    bool $skipCodex = false
): array {
    $invoke ??= static function (
        string $transport,
        array $cfg,
        string $system,
        string $prompt,
        bool $webSearch
    ): array {
        return match ($transport) {
            'codex_chatgpt' => svais_codex_bridge_call($cfg, $system, $prompt, $webSearch),
            'direct' => svais_openai_call($cfg, $system, $prompt, $webSearch),
            default => throw new InvalidArgumentException('unknown_openai_transport'),
        };
    };

    $attempts = [];
    foreach (svais_openai_transport_order() as $transport) {
        if ($transport === 'manual') break;
        if ($skipCodex && $transport === 'codex_chatgpt') continue;

        try {
            $result = $invoke($transport, $cfg, $system, $prompt, $webSearch);
            $text = trim((string)($result['text'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('provider_empty_response');
            }

            $requestedModel = (string)$cfg['model'];
            $actualModel = (string)($result['model'] ?? '');
            if ($actualModel !== $requestedModel) {
                throw new RuntimeException('model_mismatch');
            }

            $result['transport'] = $transport;
            $result['transport_attempts'] = $attempts;
            return $result;
        } catch (Throwable $e) {
            $attempts[] = [
                'transport' => $transport,
                'class' => svais_failure_class($e),
            ];
        }
    }

    throw new SvaisManualInterventionRequired(
        (string)$cfg['model'],
        svais_manual_openai_prompt($cfg, $system, $prompt),
        $attempts
    );
}

function svais_attempts_include_codex_failure(array $attempts): bool
{
    foreach ($attempts as $attempt) {
        if (($attempt['transport'] ?? '') === 'codex_chatgpt') return true;
    }
    return false;
}

function svais_call_provider(string $provider, array $profile, string $phase, string $prompt, ?bool $webSearchOverride = null): array
{
    static $codexUnavailableForRequest = false;

    $webSearch = $webSearchOverride ?? (bool)($profile['web_search'] ?? false);
    $system = svais_base_system($provider, $phase);

    $started = microtime(true);
    $cfg = $profile[$provider] ?? null;
    if (!is_array($cfg)) {
        throw new InvalidArgumentException('unknown_provider');
    }

    if ($provider === 'openai') {
        try {
            $result = svais_openai_dispatch(
                $cfg,
                $system,
                $prompt,
                $webSearch,
                null,
                $codexUnavailableForRequest
            );
            if (svais_attempts_include_codex_failure((array)($result['transport_attempts'] ?? []))) {
                $codexUnavailableForRequest = true;
            }
        } catch (SvaisManualInterventionRequired $manual) {
            if (svais_attempts_include_codex_failure($manual->attempts)) {
                $codexUnavailableForRequest = true;
            }
            throw $manual;
        }
    } else {
        $result = match ($provider) {
            'anthropic' => svais_anthropic_dispatch($profile['anthropic'], $system, $prompt, $webSearch),
            'gemini' => svais_gemini_dispatch($profile['gemini'], $system, $prompt, $webSearch),
            default => throw new InvalidArgumentException('unknown_provider'),
        };
    }

    if (trim((string)($result['text'] ?? '')) === '') {
        throw new RuntimeException('provider_empty_response');
    }

    $result['latency_ms'] = (int)round((microtime(true) - $started) * 1000);
    return $result;
}

function svais_transcript_text(array $entries): string
{
    $chunks = [];
    foreach ($entries as $entry) {
        if (!is_array($entry) || ($entry['ok'] ?? false) !== true) continue;
        $label = strtoupper((string)($entry['provider'] ?? 'agent'));
        $phase = (string)($entry['phase'] ?? '');
        $text = trim((string)($entry['text'] ?? ''));
        if ($text === '') continue;
        $chunks[] = "### {$label} ({$phase})\n{$text}";
    }
    return implode("\n\n", $chunks);
}

function svais_round_prompt(string $topic, string $phase, array $transcript): string
{
    if ($phase === 'research') {
        return "TAREFA DO USUÁRIO:\n{$topic}\n\n"
            . "Faça sua pesquisa independente. Para fatos que podem ter mudado, valide ao vivo. "
            . "Diferencie fatos confirmados, inferências e pontos ainda não verificados.";
    }

    $history = svais_transcript_text($transcript);
    if ($phase === 'critique') {
        return "TAREFA ORIGINAL:\n{$topic}\n\n"
            . "RESULTADOS DOS TRÊS PESQUISADORES:\n{$history}\n\n"
            . "Faça a revisão contraditória. Confirme ou derrube as afirmações materiais com evidência. "
            . "Aponte explicitamente onde concorda, discorda e o que precisa ser corrigido.";
    }

    if ($phase === 'converge') {
        return "TAREFA ORIGINAL:\n{$topic}\n\n"
            . "DEBATE COMPLETO ATÉ AQUI:\n{$history}\n\n"
            . "Formule sua posição final depois do contraditório. Proponha a conclusão comum mais defensável, "
            . "mas não esconda divergências relevantes.";
    }

    return "TAREFA ORIGINAL:\n{$topic}\n\nTRANSCRIÇÃO:\n{$history}";
}

function svais_consensus_prompt(string $topic, array $transcript): string
{
    $history = svais_transcript_text($transcript);
    return "TAREFA ORIGINAL:\n{$topic}\n\n"
        . "TRANSCRIÇÃO DOS TRÊS AGENTES:\n{$history}\n\n"
        . "Produza a SÍNTESE DE CONSENSO. Separe: (1) consenso confirmado pelos três, "
        . "(2) divergências que permanecem, (3) conclusão final sustentada pelas evidências. "
        . "Quando a tarefa pedir ranking ou escolhas, só apresente uma lista final se houver suporte na discussão; "
        . "explique brevemente por que cada item sobreviveu ao contraditório.";
}
