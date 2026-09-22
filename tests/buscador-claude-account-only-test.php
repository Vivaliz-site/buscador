<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/buscador-core.php';

function account_only_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

account_only_assert(
    svais_anthropic_transport_order() === ['claude_code'],
    'Claude must use Claude Code account login only'
);

$cfg = svais_profile('deep_research')['anthropic'];
$calls = [];
$failure = null;

try {
    svais_anthropic_dispatch(
        $cfg,
        'system',
        'prompt',
        true,
        function (string $transport) use (&$calls): array {
            $calls[] = $transport;
            throw new RuntimeException('usage_limit_exhausted');
        }
    );
} catch (RuntimeException $e) {
    $failure = $e;
}

account_only_assert($failure instanceof RuntimeException, 'Claude Code failure must surface');
account_only_assert($calls === ['claude_code'], 'Claude must make exactly one Claude Code attempt');
account_only_assert(
    str_contains($failure->getMessage(), 'anthropic_transports_exhausted:claude_code=quota'),
    'Claude Code failure classification missing'
);

$state = svais_provider_state(svais_profile('deep_research'));
account_only_assert(
    (($state['anthropic']['transport_order'] ?? []) === ['claude_code']),
    'health must expose Claude Code as the only Claude transport'
);

echo "BUSCADOR_CLAUDE_ACCOUNT_ONLY_TEST=PASS\n";
