<?php
declare(strict_types=1);

function mf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/api/agent/buscador.php');
$ui = (string)file_get_contents($root . '/admin/buscador.php');

mf_assert(
    str_contains($api, 'catch (SvaisManualInterventionRequired $manual)'),
    'API must catch manual OpenAI fallback explicitly'
);
mf_assert(
    str_contains($api, "'type' => 'agent_manual_required'"),
    'API manual event missing'
);
mf_assert(
    str_contains($api, "'manual_required'"),
    'API provider status manual_required missing'
);
mf_assert(
    str_contains($api, "'prompt' => \$manual->manualPrompt"),
    'API must expose only the safe manual prompt'
);
mf_assert(
    str_contains($api, "=== 'agent_message'"),
    'successful transcript must remain agent_message-only'
);

mf_assert(
    str_contains($ui, "e.type==='agent_manual_required'"),
    'UI manual event handler missing'
);
mf_assert(
    str_contains($ui, 'Copiar prompt'),
    'UI copy action missing'
);
mf_assert(
    str_contains($ui, 'textContent=e.prompt'),
    'manual prompt must be rendered with textContent'
);
mf_assert(
    str_contains($ui, "codex_chatgpt:'via ChatGPT/Codex'"),
    'Codex transport label missing'
);
mf_assert(
    str_contains($ui, "manual:'manual'"),
    'manual transport label missing'
);

echo "BUSCADOR_MANUAL_FALLBACK_CONTRACT_TEST=PASS\n";
