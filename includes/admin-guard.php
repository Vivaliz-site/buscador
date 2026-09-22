<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Admin authentication required.';
    exit;
}
