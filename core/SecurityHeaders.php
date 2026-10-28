<?php
declare(strict_types=1);
// core/SecurityHeaders.php

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    // Application-level HTTPS check
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            // behind proxy that already terminates SSL – ok
        } else {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri  = $_SERVER['REQUEST_URI'] ?? '';
            if ($host !== '') {
                header('Location: https://' . $host . $uri, true, 301);
                exit;
            }
        }
    }

    // Central Security Headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.tailwindcss.com https://code.jquery.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; img-src 'self' data: blob: https:; font-src 'self' https: data:; connect-src 'self' https:; frame-src 'self' blob:; object-src 'self' blob:; frame-ancestors 'self'; base-uri 'self'; form-action 'self';");
}
