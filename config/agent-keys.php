<?php
declare(strict_types=1);

/**
 * Agent key compatibility loader.
 *
 * Secrets must be supplied by the runtime environment. This file intentionally
 * contains no fallback value and never copies a secret into logs or responses.
 */
if (!function_exists('ensure_agent_keys_configured')) {
    function ensure_agent_keys_configured(): void
    {
        $canonical = getenv('SHOPVIVALIZ_AGENT_KEY');
        if (!is_string($canonical) || trim($canonical) === '') {
            return;
        }

        $canonical = trim($canonical);
        foreach (['RUNTIME_AGENT_KEY', 'WATCHDOG_AGENT_KEY', 'AUTONOMOUS_AGENT_KEY'] as $alias) {
            if (getenv($alias) === false && !isset($_SERVER[$alias])) {
                putenv($alias . '=' . $canonical);
                $_SERVER[$alias] = $canonical;
            }
        }
    }
}

ensure_agent_keys_configured();
