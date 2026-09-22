<?php
declare(strict_types=1);

if (!function_exists('sv_bootstrap_env_assign')) {
    function sv_bootstrap_env_assign(string $key, mixed $value): void
    {
        if ($key === '') {
            return;
        }

        $stringValue = is_scalar($value) ? (string)$value : '';
        $currentValue = getenv($key);
        if (trim($stringValue) === '' || (is_string($currentValue) && trim($currentValue) !== '')) {
            return;
        }
        putenv($key . '=' . $stringValue);
        $_ENV[$key] = $stringValue;
        $_SERVER[$key] = $stringValue;
    }
}

if (!function_exists('sv_bootstrap_env')) {
    function sv_bootstrap_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $root = dirname(__DIR__);
        $runtimeSecretsFile = __DIR__ . '/runtime-secrets.php';
        if (is_file($runtimeSecretsFile) && is_readable($runtimeSecretsFile)) {
            $runtimeSecrets = require $runtimeSecretsFile;
            if (is_array($runtimeSecrets)) {
                foreach ($runtimeSecrets as $key => $value) {
                    if (!is_string($key)) {
                        continue;
                    }
                    sv_bootstrap_env_assign($key, $value);
                }
            }
        }

        $dotenvFile = $root . '/.env';
        if (!is_file($dotenvFile) || !is_readable($dotenvFile)) {
            return;
        }

        $lines = file($dotenvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            sv_bootstrap_env_assign(trim($key), trim(trim($value), "\"'"));
        }
    }
}

sv_bootstrap_env();

// Compatibilidade de migracao: BUSCADOR_* e canonico; AI_SQUAD_* continua aceito temporariamente.
foreach ($_ENV + $_SERVER as $key => $value) {
    if (!is_string($key) || !str_starts_with($key, 'AI_SQUAD_')) {
        continue;
    }
    $newKey = 'BUSCADOR_' . substr($key, strlen('AI_SQUAD_'));
    sv_bootstrap_env_assign($newKey, $value);
}
