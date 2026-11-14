<?php
declare(strict_types=1);

/*
 * Configuración compartida y acceso a MariaDB/MySQL.
 *
 * Este archivo no debe servirse directamente: .htaccess y router.php lo
 * bloquean. La aplicación se configura con variables de entorno o con .env.
 */

const RICETTA_SESSION_DAYS = 30;
const RICETTA_MAX_BODY = 1_500_000;
const RICETTA_EMAIL_CHANGE_HOURS = 24;
const RICETTA_PASSWORD_RESET_MINUTES = 30;
const RICETTA_MAX_RECIPES = 2_000;
const RICETTA_MAX_PLANNER_DAYS = 3_660;
const RICETTA_MAX_SHARE_BYTES = 1_000_000;
const RICETTA_MEAL_SLOTS = ['breakfast', 'lunch', 'snack', 'dinner'];
const RICETTA_RECIPE_CATEGORIES = ['Desayuno', 'Comida', 'Merienda', 'Cena', 'Postre', 'Otro'];
const RICETTA_RECIPE_EMOJI = ['🥗', '🍲', '🥑', '🍝', '🥕', '🍰'];
const RICETTA_REQUEST_LIMITS = [
    'login' => [30, 60],
    'register' => [5, 300],
    'password-reset' => [10, 3600],
    'share' => [30, 60],
    'account' => [20, 300],
];

$GLOBALS['RICETTA_ENV'] = [];

/**
 * Carga .env sin dependencias externas. Las variables ya definidas en el
 * entorno (Apache, PHP-FPM, Docker, shell) tienen prioridad.
 */
function ricetta_load_env(): void
{
    $path = __DIR__ . '/.env';
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }
        if (strlen($value) >= 2 && $value[0] === $value[strlen($value) - 1] && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        if (getenv($name) === false) {
            $GLOBALS['RICETTA_ENV'][$name] = $value;
        }
    }
}

ricetta_load_env();

function ricetta_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value !== false) {
        return trim($value);
    }
    return array_key_exists($name, $GLOBALS['RICETTA_ENV'])
        ? trim((string) $GLOBALS['RICETTA_ENV'][$name])
        : $default;
}

function ricetta_env_int(string $name, int $default, int $minimum = 1, int $maximum = 65535): int
{
    $raw = ricetta_env($name, (string) $default);
    if (!preg_match('/^-?\d+$/', $raw)) {
        throw new RuntimeException("$name debe ser un número entero.");
    }
    $value = (int) $raw;
    if ($value < $minimum || $value > $maximum) {
        throw new RuntimeException("$name debe estar entre $minimum y $maximum.");
    }
    return $value;
}

function ricetta_env_bool(string $name, bool $default = false): bool
{
    $value = strtolower(ricetta_env($name, $default ? '1' : '0'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function ricetta_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = ricetta_env('RICETTA_DB_HOST', '127.0.0.1');
    $port = ricetta_env_int('RICETTA_DB_PORT', 3306);
    $name = ricetta_env('RICETTA_DB_NAME', 'ricetta');
    $user = ricetta_env('RICETTA_DB_USER', 'root');
    $password = ricetta_env('RICETTA_DB_PASSWORD', '');
    $socket = ricetta_env('RICETTA_DB_SOCKET', '');
    $charset = 'utf8mb4';

    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('RICETTA_DB_NAME sólo puede contener letras, números y guiones bajos.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $server_dsn = $socket !== ''
        ? "mysql:unix_socket=$socket;charset=$charset"
        : "mysql:host=$host;port=$port;charset=$charset";
    $database_dsn = $socket !== ''
        ? "mysql:unix_socket=$socket;dbname=$name;charset=$charset"
        : "mysql:host=$host;port=$port;dbname=$name;charset=$charset";

    try {
        $pdo = new PDO($database_dsn, $user, $password, $options);
    } catch (PDOException $error) {
        $mysql_code = isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : 0;
        if (!ricetta_env_bool('RICETTA_DB_AUTO_CREATE', true) || $mysql_code !== 1049) {
            throw $error;
        }
        // La base no existe todavía: se crea con las mismas credenciales.
        // En XAMPP el usuario root puede hacerlo; en producción es preferible
        // crear la base a mano y dar sólo permisos sobre ella.
        $server = new PDO($server_dsn, $user, $password, $options);
        $server->exec(
            "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $server = null;
        $pdo = new PDO($database_dsn, $user, $password, $options);
    }

    $pdo->exec("SET time_zone = '+00:00'");
    ricetta_initialize_schema($pdo);
    return $pdo;
}

function ricetta_initialize_schema(PDO $pdo): void
{
    $already_installed = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    if ($already_installed) {
        return;
    }
    $schema_path = __DIR__ . '/schema.sql';
    $schema = file_get_contents($schema_path);
    if ($schema === false) {
        throw new RuntimeException('No se pudo leer schema.sql.');
    }
    foreach (explode(';', $schema) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

function ricetta_is_https(): bool
{
    $forwarded = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0] ?? ''));
    if ($forwarded !== '') {
        return $forwarded === 'https';
    }
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    return $https !== '' && $https !== 'off' && $https !== '0';
}

function ricetta_cookie_path(): string
{
    $configured = ricetta_env('RICETTA_PUBLIC_URL');
    if ($configured === '') {
        return '/';
    }
    $path = parse_url($configured, PHP_URL_PATH);
    if (!is_string($path)) {
        return '/';
    }
    $path = rtrim($path, '/');
    return $path === '' ? '/' : $path;
}

function ricetta_public_base_url(): string
{
    $configured = rtrim(ricetta_env('RICETTA_PUBLIC_URL'), '/');
    $parsed = $configured === '' ? false : parse_url($configured);
    if (!is_array($parsed)
        || !in_array(strtolower((string) ($parsed['scheme'] ?? '')), ['https', 'http'], true)
        || empty($parsed['host'])
        || isset($parsed['query'])
        || isset($parsed['fragment'])) {
        throw new RuntimeException('Configura RICETTA_PUBLIC_URL con la URL pública de Ricetta.');
    }
    $host = strtolower((string) $parsed['host']);
    if (strtolower((string) $parsed['scheme']) !== 'https' && !in_array($host, ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('La dirección pública de Ricetta debe usar HTTPS.');
    }
    return $configured;
}

function ricetta_client_ip(): string
{
    $forwarded = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0] ?? '');
    $candidate = $forwarded !== '' ? $forwarded : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : 'unknown';
}

function ricetta_request_rate_limited(string $action): bool
{
    if (!isset(RICETTA_REQUEST_LIMITS[$action])) {
        return false;
    }
    [$limit, $window] = RICETTA_REQUEST_LIMITS[$action];
    $pdo = ricetta_db();
    $now = time();
    $client_hash = hash('sha256', ricetta_client_ip());
    $pdo->prepare('DELETE FROM request_limits WHERE requested_at < ?')
        ->execute([gmdate('Y-m-d H:i:s', $now - 86400)]);
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM request_limits WHERE action=? AND client_hash=? AND requested_at > ?'
    );
    $statement->execute([$action, $client_hash, gmdate('Y-m-d H:i:s', $now - $window)]);
    if ((int) $statement->fetchColumn() >= $limit) {
        return true;
    }
    $pdo->prepare('INSERT INTO request_limits(action, client_hash, requested_at) VALUES(?,?,?)')
        ->execute([$action, $client_hash, gmdate('Y-m-d H:i:s', $now)]);
    return false;
}

function ricetta_email_rate_limited(string $action, string $email, int $seconds = 60): bool
{
    $pdo = ricetta_db();
    $email_hash = hash('sha256', strtolower(trim($email)));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $pdo->prepare('DELETE FROM email_action_limits WHERE requested_at < ?')
        ->execute([$now->modify('-1 day')->format('Y-m-d H:i:s')]);
    $statement = $pdo->prepare(
        'SELECT requested_at FROM email_action_limits WHERE action=? AND email_hash=?'
    );
    $statement->execute([$action, $email_hash]);
    $requested = $statement->fetchColumn();
    if (is_string($requested) && $requested !== '') {
        $requested_at = new DateTimeImmutable($requested, new DateTimeZone('UTC'));
        if ($now->getTimestamp() - $requested_at->getTimestamp() < $seconds) {
            return true;
        }
    }
    $pdo->prepare(
        'INSERT INTO email_action_limits(action, email_hash, requested_at) VALUES(?,?,?)
         ON DUPLICATE KEY UPDATE requested_at=VALUES(requested_at)'
    )->execute([$action, $email_hash, $now->format('Y-m-d H:i:s')]);
    return false;
}
