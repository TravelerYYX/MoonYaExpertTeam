<?php
declare(strict_types=1);

/** Emit CORS only for the same origin or an explicit allowlist. */
function applyCorsPolicy(
    string $methods = 'GET, POST, PUT, DELETE, OPTIONS',
    string $headers = 'Content-Type, Authorization, X-CSRF-Token'
): void {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }
    $originParts = parse_url($origin);
    if (!is_array($originParts) || !in_array(strtolower((string)($originParts['scheme'] ?? '')), ['http', 'https'], true)) {
        return;
    }

    $originHost = strtolower((string)($originParts['host'] ?? ''));
    $originPort = isset($originParts['port']) ? (int)$originParts['port'] : null;
    $requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $sameOrigin = $requestHost !== '' && strtolower($originHost . ($originPort ? ':' . $originPort : '')) === $requestHost;

    $configured = getenv('CORS_ALLOWED_ORIGINS');
    $allowlist = is_string($configured)
        ? array_values(array_filter(array_map('trim', explode(',', $configured))))
        : [];
    $allowed = $sameOrigin || in_array(rtrim($origin, '/'), array_map(
        static fn(string $item): string => rtrim($item, '/'),
        $allowlist
    ), true);
    if (!$allowed) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin', false);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: ' . $headers);
}
