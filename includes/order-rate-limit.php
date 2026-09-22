<?php
declare(strict_types=1);

function svorl_client_ip(): string
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $trustedProxy = filter_var((string)getenv('SHOPVIVALIZ_TRUST_PROXY'), FILTER_VALIDATE_BOOLEAN);
    if ($trustedProxy) {
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $candidate = trim(explode(',', $forwarded)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

function svorl_client_key(): string
{
    return hash('sha256', svorl_client_ip() . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function svorl_scope(string $scope): string
{
    $scope = strtolower(trim($scope));
    $scope = preg_replace('/[^a-z0-9_-]+/', '-', $scope) ?: 'default';
    return trim($scope, '-') ?: 'default';
}

function svorl_allow(int $limit = 10, int $window = 300, string $scope = 'orders'): bool
{
    $limit = max(1, $limit);
    $window = max(1, $window);
    $scope = svorl_scope($scope);

    // Rodada 2 (2026-08-18): dirname(__DIR__) resolve para dentro de
    // releases/<timestamp>-<sha>/ (release imutavel trocado por symlink a
    // cada deploy -- ver CLAUDE.md). Isso zera todos os contadores de rate
    // limit a cada novo release. SHOPVIVALIZ_RUNTIME_DIR permite apontar para
    // um caminho compartilhado fora do release (ex: shopvivaliz-deploy/shared/
    // na VM); sem essa variavel configurada, o comportamento e identico ao de
    // antes (fallback preservado). Ver E2 no relatorio da Rodada 2.
    $runtimeBase = rtrim((string)(getenv('SHOPVIVALIZ_RUNTIME_DIR') ?: ''), '/\\');
    $dir = ($runtimeBase !== '' ? $runtimeBase : dirname(__DIR__)) . '/storage/rate-limit/' . $scope;
    if ((!is_dir($dir) && !@mkdir($dir, 0755, true)) || !is_writable($dir)) {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'shopvivaliz-rate-limit'
            . DIRECTORY_SEPARATOR . $scope;
        if ((!is_dir($dir) && !@mkdir($dir, 0755, true)) || !is_writable($dir)) {
            // Fail closed: sem armazenamento confiavel nao ha como aplicar o limite.
            return false;
        }
    }

    // Rodada 7 (2026-08-19): storage/rate-limit/<scope>/ nunca tinha rotina
    // de limpeza -- um arquivo novo por IP+User-Agent distinto, crescimento
    // sem teto proporcional ao trafego (pior ainda com SHOPVIVALIZ_RUNTIME_DIR
    // configurado, ja que os contadores deixam de zerar a cada release). GC
    // probabilistico de baixo custo, no mesmo padrao usado pelo proprio PHP
    // pra sessao: 1/100 das chamadas apaga arquivos mais velhos que
    // 10x a janela do escopo. Ver R7-9 no relatorio da Rodada 7.
    if (random_int(1, 100) === 1) {
        $cutoff = time() - (10 * $window);
        foreach (glob($dir . '/*.json') ?: [] as $staleFile) {
            if ((int)@filemtime($staleFile) < $cutoff) {
                @unlink($staleFile);
            }
        }
    }

    $path = $dir . '/' . svorl_client_key() . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $state = is_array($state) ? $state : [];

        $now = time();
        $started = (int)($state['started_at'] ?? 0);
        $count = (int)($state['count'] ?? 0);
        if ($started <= 0 || ($now - $started) >= $window) {
            $started = $now;
            $count = 0;
        }
        $count++;

        $encoded = json_encode([
            'started_at' => $started,
            'count' => $count,
            'window' => $window,
            'scope' => $scope,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) === false || !fflush($handle)) {
            return false;
        }

        return $count <= $limit;
    } catch (Throwable $error) {
        error_log('[rate-limit] counter failure: ' . $error->getMessage());
        return false;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
