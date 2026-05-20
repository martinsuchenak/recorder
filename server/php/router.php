<?php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/recorder(/.*)?$#', $uri)) {
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Embedder-Policy: require-corp');

    $relativePath = $uri === '/recorder' || $uri === '/recorder/'
        ? '/index.html'
        : substr($uri, strlen('/recorder'));

    $file = __DIR__ . '/recorder' . $relativePath;

    if (is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $types = [
            'html'  => 'text/html',
            'js'    => 'application/javascript',
            'mjs'   => 'application/javascript',
            'css'   => 'text/css',
            'json'  => 'application/json',
            'svg'   => 'image/svg+xml',
            'png'   => 'image/png',
            'ico'   => 'image/x-icon',
            'wasm'  => 'application/wasm',
            'ttf'   => 'font/ttf',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'webp'  => 'image/webp',
            'jpg'   => 'image/jpeg',
            'webm'  => 'video/webm',
            'mp4'   => 'video/mp4',
        ];
        if (isset($types[$ext])) {
            header('Content-Type: ' . $types[$ext]);
        }
        readfile($file);
        return true;
    }

    http_response_code(404);
    echo 'Not found';
    return true;
}

if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

require __DIR__ . '/index.php';
