<?php
declare(strict_types=1);

/*
 * Router para el servidor integrado de PHP:
 *
 *   php -S 127.0.0.1:8000 router.php
 *
 * Sirve los archivos estáticos y envía /api/* a api.php. En Apache esta tarea
 * la hace .htaccess; en nginx, la configuración de deploy/.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$relative = ltrim($path, '/');

$blocked_prefixes = ['data/', 'tests/', 'deploy/', 'dist/', '.git/', '.github/'];
foreach ($blocked_prefixes as $prefix) {
    if (str_starts_with($relative, $prefix)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "No encontrado.\n";
        return true;
    }
}

$blocked_names = [
    '.env', '.env.example', '.htaccess', '.dockerignore', '.gitignore',
    'config.php', 'router.php', 'schema.sql', 'api.py', 'server.py',
    'make_dist.py', 'README.md', 'SECURITY.md', 'LICENSE', 'VERSION',
    'Dockerfile', 'docker-compose.yml', 'docker-compose.yaml',
];
$blocked_extensions = ['py', 'pyc', 'pyo', 'sql', 'sqlite', 'sqlite3', 'db', 'log', 'bak', 'swp', 'md', 'example'];
if (in_array($relative, $blocked_names, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No encontrado.\n";
    return true;
}
$extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
if (in_array($extension, $blocked_extensions, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No encontrado.\n";
    return true;
}
if ($relative === 'api.php' || $relative === 'api') {
    if ($relative === 'api') {
        $_SERVER['RICETTA_ROUTE'] = '/';
        require __DIR__ . '/api.php';
        return true;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No encontrado.\n";
    return true;
}

if (str_starts_with($path, '/api/')) {
    $_SERVER['RICETTA_ROUTE'] = substr($path, 4);
    require __DIR__ . '/api.php';
    return true;
}

// Cabeceras de seguridad para los archivos estáticos del servidor integrado.
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-Permitted-Cross-Domain-Policies: none');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; worker-src 'none'; manifest-src 'self'");

if ($path === '/') {
    $index = __DIR__ . '/index.html';
    if (is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);
        return true;
    }
}

$file = __DIR__ . $path;
if (is_file($file)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "No encontrado.\n";
return true;
