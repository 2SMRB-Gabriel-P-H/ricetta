<?php
declare(strict_types=1);

/*
 * API de Ricetta para PHP + MariaDB/MySQL.
 *
 * Mantiene el mismo contrato JSON que la versión anterior: el frontend no
 * necesita cambios. Apache reescribe /api/* a este archivo; router.php hace lo
 * mismo para el servidor integrado de PHP.
 */

require __DIR__ . '/config.php';

$GLOBALS['ricetta_session_token'] = null;

function ricetta_route_path(): string
{
    $route = $_SERVER['RICETTA_ROUTE'] ?? null;
    if (is_string($route)) {
        $route = rtrim($route, '/');
        return $route === '' ? '/' : $route;
    }

    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uri = is_string($uri) ? $uri : '/';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(dirname($script), '/');
    if ($base !== '' && str_starts_with($uri, $base . '/api')) {
        $route = substr($uri, strlen($base) + 4);
    } else {
        $position = strpos($uri, '/api');
        $route = $position === false ? $uri : substr($uri, $position + 4);
    }
    $route = rtrim($route, '/');
    return $route === '' ? '/' : $route;
}

function ricetta_log(Throwable $error): void
{
    error_log(date('c') . ' ' . get_class($error) . ': ' . $error->getMessage());
}

function ricetta_security_headers(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}

function ricetta_set_session_cookie(string $token, bool $clear = false): void
{
    $options = [
        'expires' => $clear ? time() - 3600 : time() + RICETTA_SESSION_DAYS * 86400,
        'path' => ricetta_cookie_path(),
        'secure' => ricetta_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    setcookie('mesa_session', $clear ? '' : $token, $options);
}

function ricetta_json_response(int $status, mixed $value, array $extra_headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    ricetta_security_headers();
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

    $session_token = $GLOBALS['ricetta_session_token'] ?? null;
    if (is_string($session_token) && $session_token !== '' && !isset($extra_headers['Set-Cookie'])) {
        ricetta_set_session_cookie($session_token);
    }
    foreach ($extra_headers as $name => $value) {
        header($name . ': ' . $value, false);
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        $json = '{"error":"Error interno del servidor."}';
    }
    header('Content-Length: ' . strlen($json));
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $json;
    }
    exit;
}

function ricetta_html_response(int $status, string $title, string $result, ?string $token = null): void
{
    $safe_title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe_result = htmlspecialchars($result, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $form = '';
    if ($token !== null && $token !== '') {
        $safe_token = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $form = '<form action="confirm-email" method="post">'
            . '<input type="hidden" name="token" value="' . $safe_token . '">'
            . '<button type="submit">Confirmar correo</button></form>';
    }
    $page = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width">'
        . '<meta name="referrer" content="no-referrer">'
        . '<title>' . $safe_title . ' · Ricetta</title>'
        . '<link rel="stylesheet" href="../styles.css"></head>'
        . '<body class="email-confirmation-page"><main class="email-confirmation">'
        . '<h1>' . $safe_title . '</h1><p>' . $safe_result . '</p>' . $form
        . '<a href="../">Volver a Ricetta</a></main></body></html>';

    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    ricetta_security_headers();
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('Content-Length: ' . strlen($page));
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $page;
    }
    exit;
}

function ricetta_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function ricetta_is_object_array(mixed $value): bool
{
    return is_array($value) && ($value === [] || !array_is_list($value));
}

function ricetta_valid_email(mixed $value): bool
{
    return is_string($value)
        && ricetta_text_length($value) <= 254
        && preg_match('/^[^@\s]+@[^@\s.]+(?:\.[^@\s.]+)+$/u', $value) === 1;
}

function ricetta_hash_password(string $password): string
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('No se pudo proteger la contraseña.');
    }
    return $hash;
}

function ricetta_verify_password(string $password, string $hash): bool
{
    // Las contraseñas nuevas usan password_hash(). Las cuentas antiguas de
    // SQLite usan scrypt de Python y necesitan restablecer la contraseña al
    // pasar a PHP; no se acepta un hash desconocido.
    return password_verify($password, $hash);
}

function ricetta_random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function ricetta_clean_recipe(mixed $recipe): ?array
{
    if (!is_array($recipe)) {
        return null;
    }
    $recipe_id = $recipe['id'] ?? null;
    $name = $recipe['name'] ?? null;
    $category = $recipe['category'] ?? null;
    $ingredients = $recipe['ingredients'] ?? null;
    $preparation = array_key_exists('preparation', $recipe) ? $recipe['preparation'] : '';
    $time_value = array_key_exists('time', $recipe) ? $recipe['time'] : 0;
    $servings_value = array_key_exists('servings', $recipe) ? $recipe['servings'] : 1;

    if (!is_string($recipe_id) || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $recipe_id) !== 1) {
        return null;
    }
    if (!is_string($name) || trim($name) === '' || ricetta_text_length($name) > 70) {
        return null;
    }
    if (!is_string($category) || !in_array($category, RICETTA_RECIPE_CATEGORIES, true)) {
        return null;
    }
    if (!is_string($ingredients) || ricetta_text_length($ingredients) > 20_000) {
        return null;
    }
    if (!is_string($preparation) || ricetta_text_length($preparation) > 30_000) {
        return null;
    }
    if (is_bool($time_value)
        || (!is_int($time_value) && !is_float($time_value))
        || !is_finite((float) $time_value)
        || floor((float) $time_value) !== (float) $time_value
        || $time_value < 0
        || $time_value > 999) {
        return null;
    }
    if (is_bool($servings_value)
        || (!is_int($servings_value) && !is_float($servings_value))
        || !is_finite((float) $servings_value)
        || floor((float) $servings_value) !== (float) $servings_value
        || $servings_value < 1
        || $servings_value > 99) {
        return null;
    }
    $emoji = array_key_exists('emoji', $recipe) ? $recipe['emoji'] : null;
    if (!is_string($emoji) || !in_array($emoji, RICETTA_RECIPE_EMOJI, true)) {
        $emoji = null;
    }
    return [
        'id' => $recipe_id,
        'name' => trim($name),
        'category' => $category,
        'time' => (int) $time_value,
        'servings' => (int) $servings_value,
        'ingredients' => $ingredients,
        'preparation' => $preparation,
        'emoji' => $emoji,
    ];
}

function ricetta_valid_day(string $day): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    $errors = DateTimeImmutable::getLastErrors();
    return $date instanceof DateTimeImmutable
        && $date->format('Y-m-d') === $day
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function ricetta_normalize_state(mixed $value, bool $strict = false): ?array
{
    if (!is_array($value)
        || !isset($value['recipes'])
        || !is_array($value['recipes'])
        || !array_is_list($value['recipes'])
        || !isset($value['planner'])
        || !ricetta_is_object_array($value['planner'])) {
        return null;
    }
    if (count($value['recipes']) > RICETTA_MAX_RECIPES
        || count($value['planner']) > RICETTA_MAX_PLANNER_DAYS) {
        return null;
    }

    $recipes = [];
    $recipe_ids = [];
    foreach ($value['recipes'] as $candidate) {
        $recipe = ricetta_clean_recipe($candidate);
        if ($recipe === null || isset($recipe_ids[$recipe['id']])) {
            if ($strict) {
                return null;
            }
            continue;
        }
        $recipe_ids[$recipe['id']] = true;
        $recipes[] = $recipe;
    }

    $planner = [];
    foreach ($value['planner'] as $day => $meals) {
        if (!is_string($day)
            || !ricetta_valid_day($day)
            || !is_array($meals)
            || count($meals) > count(RICETTA_MEAL_SLOTS)) {
            if ($strict) {
                return null;
            }
            continue;
        }
        $clean_meals = [];
        foreach ($meals as $slot => $recipe_id) {
            if (!is_string($slot)
                || !in_array($slot, RICETTA_MEAL_SLOTS, true)
                || !is_string($recipe_id)
                || !isset($recipe_ids[$recipe_id])) {
                if ($strict) {
                    return null;
                }
                continue;
            }
            $clean_meals[$slot] = $recipe_id;
        }
        if ($clean_meals !== []) {
            $planner[$day] = $clean_meals;
        }
    }
    return ['recipes' => array_values($recipes), 'planner' => $planner];
}

function ricetta_decode_state(string $value): array
{
    $state = null;
    try {
        $state = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $state = null;
    }
    if (!is_array($state)) {
        $state = ['recipes' => [], 'planner' => []];
    }
    if (!isset($state['recipes']) || !is_array($state['recipes']) || !array_is_list($state['recipes'])) {
        $state['recipes'] = [];
    }
    if (!isset($state['planner']) || !is_array($state['planner'])) {
        $state['planner'] = [];
    }

    $demo_ids = ['seed-1' => true, 'seed-2' => true, 'seed-3' => true, 'seed-4' => true];
    $state['recipes'] = array_values(array_filter($state['recipes'], static function ($recipe) use ($demo_ids): bool {
        return !is_array($recipe)
            || !is_string($recipe['id'] ?? null)
            || !isset($demo_ids[$recipe['id']]);
    }));
    foreach (array_keys($state['planner']) as $day) {
        $meals = $state['planner'][$day];
        if (!is_array($meals)) {
            continue;
        }
        $clean = [];
        foreach ($meals as $slot => $recipe_id) {
            if (!is_string($recipe_id) || !isset($demo_ids[$recipe_id])) {
                $clean[$slot] = $recipe_id;
            }
        }
        $state['planner'][$day] = $clean;
    }

    $normalized = ricetta_normalize_state($state);
    if ($normalized === null) {
        $state['recipes'] = array_slice($state['recipes'], 0, RICETTA_MAX_RECIPES);
        $state['planner'] = array_slice($state['planner'], 0, RICETTA_MAX_PLANNER_DAYS, true);
        $normalized = ricetta_normalize_state($state);
    }
    return $normalized ?? ['recipes' => [], 'planner' => []];
}

function ricetta_public_state(array $state): array
{
    return [
        'recipes' => array_values($state['recipes']),
        'planner' => (object) $state['planner'],
    ];
}

function ricetta_state_json(array $state): string
{
    $json = json_encode(ricetta_public_state($state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar el estado.');
    }
    return $json;
}

function ricetta_parse_share_payload(string $value): ?array
{
    try {
        $payload = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return ricetta_is_object_array($payload) ? $payload : null;
}

function ricetta_session_user(PDO $pdo): ?array
{
    $token = $_COOKIE['mesa_session'] ?? '';
    if (!is_string($token) || $token === '') {
        return null;
    }
    $token_hash = hash('sha256', $token);
    $now = gmdate('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'SELECT users.id, users.username, users.email, users.email_verified
         FROM sessions JOIN users ON users.id=sessions.user_id
         WHERE sessions.token_hash=? AND sessions.expires_at>?'
    );
    $statement->execute([$token_hash, $now]);
    $user = $statement->fetch();
    if (!$user) {
        return null;
    }
    $expiry = gmdate('Y-m-d H:i:s', time() + RICETTA_SESSION_DAYS * 86400);
    $pdo->prepare('UPDATE sessions SET expires_at=? WHERE token_hash=?')
        ->execute([$expiry, $token_hash]);
    $GLOBALS['ricetta_session_token'] = $token;
    return $user;
}

function ricetta_new_session(PDO $pdo, int $user_id): string
{
    $token = ricetta_random_token(32);
    $token_hash = hash('sha256', $token);
    $now = gmdate('Y-m-d H:i:s');
    $expiry = gmdate('Y-m-d H:i:s', time() + RICETTA_SESSION_DAYS * 86400);
    $pdo->prepare('DELETE FROM sessions WHERE expires_at<=?')->execute([$now]);
    $pdo->prepare('INSERT INTO sessions(token_hash,user_id,expires_at) VALUES(?,?,?)')
        ->execute([$token_hash, $user_id, $expiry]);
    return $token;
}

function ricetta_check_origin(): bool
{
    $fetch_site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetch_site !== '' && $fetch_site !== 'same-origin') {
        return false;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return $fetch_site === 'same-origin';
    }
    $parsed = parse_url($origin);
    if (!is_array($parsed)) {
        return false;
    }
    $expected_host = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '')[0] ?? ''));
    if ($expected_host === '') {
        $expected_host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    }
    $expected_scheme = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0] ?? ''));
    if ($expected_scheme === '') {
        $expected_scheme = ricetta_is_https() ? 'https' : 'http';
    }
    $origin_host = strtolower((string) ($parsed['host'] ?? ''));
    if (isset($parsed['port'])) {
        $origin_host .= ':' . $parsed['port'];
    }
    return strtolower((string) ($parsed['scheme'] ?? '')) === $expected_scheme
        && $origin_host === $expected_host
        && !isset($parsed['user'])
        && !isset($parsed['pass'])
        && (!isset($parsed['path']) || $parsed['path'] === '')
        && !isset($parsed['query'])
        && !isset($parsed['fragment']);
}

function ricetta_smtp_configured(): bool
{
    // El correo transaccional queda pendiente en esta versión PHP. Las rutas
    // de correo existen para conservar el contrato del frontend, pero no se
    // envían mensajes. El registro funciona sin SMTP.
    return false;
}

function ricetta_send_transactional_email(string $recipient, string $subject, string $body): void
{
    if (!ricetta_smtp_configured()) {
        throw new RuntimeException('El envío de correo no está configurado.');
    }
    throw new RuntimeException('El envío SMTP todavía no está disponible en la versión PHP.');
}

function ricetta_send_reset_email(string $recipient, string $body, int $user_id, string $token_hash): void
{
    try {
        ricetta_send_transactional_email($recipient, 'Restablece tu contraseña de Ricetta', $body);
    } catch (Throwable $error) {
        $pdo = ricetta_db();
        $pdo->prepare('DELETE FROM password_resets WHERE user_id=? AND token_hash=?')
            ->execute([$user_id, $token_hash]);
        ricetta_log($error);
    }
}

function ricetta_send_verification_email(string $recipient, string $body, int $user_id, string $token_hash): void
{
    try {
        ricetta_send_transactional_email($recipient, 'Verifica tu correo de Ricetta', $body);
    } catch (Throwable $error) {
        $pdo = ricetta_db();
        $pdo->prepare('DELETE FROM pending_emails WHERE user_id=? AND token_hash=?')
            ->execute([$user_id, $token_hash]);
        ricetta_log($error);
    }
}

function ricetta_read_json_body(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 1 || $length > RICETTA_MAX_BODY) {
        throw new InvalidArgumentException('Tamaño de solicitud no válido.');
    }
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) < 1 || strlen($raw) > RICETTA_MAX_BODY) {
        throw new InvalidArgumentException('Tamaño de solicitud no válido.');
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new InvalidArgumentException('La solicitud no es válida.');
    }
    return $data;
}

function ricetta_read_form_body(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 1 || $length > RICETTA_MAX_BODY) {
        throw new InvalidArgumentException('Tamaño de solicitud no válido.');
    }
    $raw = file_get_contents('php://input');
    if (!is_string($raw)) {
        throw new InvalidArgumentException('La solicitud no es válida.');
    }
    $data = [];
    parse_str($raw, $data);
    return $data;
}

function ricetta_handle_get(string $route): void
{
    if ($route === '/confirm-email') {
        $token = $_GET['token'] ?? '';
        $valid_token = is_string($token) && preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token) === 1;
        $token_hash = $valid_token ? hash('sha256', $token) : '';
        $result = 'El enlace no es válido o ha caducado. Solicita de nuevo el cambio desde tu cuenta.';
        $title = 'No se pudo confirmar el correo';
        $form_token = null;

        if ($token_hash !== '') {
            $pdo = ricetta_db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT user_id,email,expires_at FROM pending_emails WHERE token_hash=?');
            $statement->execute([$token_hash]);
            $pending = $statement->fetch();
            $now = gmdate('Y-m-d H:i:s');
            if ($pending && $pending['expires_at'] > $now) {
                $result = 'Confirma que quieres verificar esta dirección de correo en Ricetta.';
                $title = 'Confirma tu correo';
                $form_token = $token;
                $pdo->commit();
            } elseif ($pending) {
                $pdo->prepare('DELETE FROM pending_emails WHERE user_id=?')->execute([$pending['user_id']]);
                $pdo->commit();
            } else {
                $pdo->commit();
            }
        }
        ricetta_html_response(200, $title, $result, $form_token);
    }

    if ($route !== '/me') {
        ricetta_json_response(404, ['error' => 'Ruta desconocida.']);
    }

    $pdo = ricetta_db();
    $user = ricetta_session_user($pdo);
    if (!$user) {
        ricetta_json_response(401, ['error' => 'Inicia sesión para continuar.']);
    }
    $statement = $pdo->prepare('SELECT value, version FROM user_state WHERE user_id=?');
    $statement->execute([$user['id']]);
    $row = $statement->fetch();
    $state = $row ? ricetta_decode_state($row['value']) : ['recipes' => [], 'planner' => []];
    $state_version = $row ? (int) $row['version'] : 0;
    $statement = $pdo->prepare('SELECT email FROM pending_emails WHERE user_id=? AND expires_at>?');
    $statement->execute([$user['id'], gmdate('Y-m-d H:i:s')]);
    $pending_email = $statement->fetchColumn();
    $statement = $pdo->prepare(
        'SELECT shares.id, shares.kind, shares.title, shares.payload, shares.created_at,
                shares.copied_at, users.username AS sender
         FROM shares JOIN users ON users.id=shares.sender_id
         WHERE shares.recipient_id=? AND shares.dismissed_at IS NULL
         ORDER BY shares.id DESC LIMIT 500'
    );
    $statement->execute([$user['id']]);
    $shares = $statement->fetchAll();
    $shared = [];
    foreach ($shares as $item) {
        $payload = ricetta_parse_share_payload($item['payload']);
        if ($payload === null) {
            continue;
        }
        $item['id'] = (int) $item['id'];
        $item['payload'] = $payload;
        $shared[] = $item;
    }

    ricetta_json_response(200, [
        'user' => $user['username'],
        'email' => $user['email'],
        'emailVerified' => (bool) $user['email_verified'],
        'pendingEmail' => is_string($pending_email) && $pending_email !== '' ? $pending_email : null,
        'state' => ricetta_public_state($state),
        'stateVersion' => $state_version,
        'shares' => $shared,
    ]);
}

function ricetta_handle_post(string $route): void
{
    if (!ricetta_check_origin() && $route !== '/confirm-email') {
        ricetta_json_response(403, ['error' => 'Origen no permitido.']);
    }

    $rate_action = [
        '/login' => 'login',
        '/register' => 'register',
        '/password-reset-request' => 'password-reset',
        '/share' => 'share',
        '/password' => 'account',
        '/email' => 'account',
        '/delete-account' => 'account',
    ][$route] ?? null;
    if ($rate_action !== null && ricetta_request_rate_limited($rate_action)) {
        $message = 'Demasiadas solicitudes. Espera un poco antes de volver a intentarlo.';
        ricetta_json_response(429, ['error' => $message, 'message' => $message]);
    }

    try {
        if ($route === '/logout') {
            $data = [];
        } elseif ($route === '/confirm-email'
            && strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0] ?? '')) === 'application/x-www-form-urlencoded') {
            $data = ricetta_read_form_body();
        } else {
            $data = ricetta_read_json_body();
        }
    } catch (InvalidArgumentException | JsonException) {
        ricetta_json_response(400, ['error' => 'La solicitud no es válida.']);
    }

    if ($route === '/confirm-email') {
        $token = $data['token'] ?? '';
        $valid_token = is_string($token) && preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token) === 1;
        $token_hash = $valid_token ? hash('sha256', $token) : '';
        $pdo = ricetta_db();
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT user_id,email,expires_at FROM pending_emails WHERE token_hash=?');
        $statement->execute([$token_hash]);
        $pending = $statement->fetch();
        if (!$pending || $pending['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            if ($pending) {
                $pdo->prepare('DELETE FROM pending_emails WHERE user_id=?')->execute([$pending['user_id']]);
            }
            $pdo->commit();
            ricetta_html_response(
                400,
                'Enlace no válido',
                'El enlace no es válido o ha caducado. Solicita una nueva confirmación.'
            );
        }
        try {
            $pdo->prepare('UPDATE users SET email=?, email_verified=1 WHERE id=?')
                ->execute([$pending['email'], $pending['user_id']]);
            $pdo->prepare('DELETE FROM pending_emails WHERE user_id=?')->execute([$pending['user_id']]);
            $pdo->commit();
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (isset($error->errorInfo[0]) && $error->errorInfo[0] === '23000') {
                $pdo->prepare('DELETE FROM pending_emails WHERE user_id=?')->execute([$pending['user_id']]);
                ricetta_html_response(
                    409,
                    'Correo no disponible',
                    'Esa dirección de correo ya está asociada a otra cuenta.'
                );
            }
            throw $error;
        }
        ricetta_html_response(
            200,
            'Correo confirmado',
            'La dirección de correo de tu cuenta ya está actualizada y verificada.'
        );
    }

    if ($route === '/register' || $route === '/login') {
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        $password_confirm = $data['password_confirm'] ?? null;
        $email = $data['email'] ?? null;
        if (!is_string($username) || preg_match('/^[A-Za-z0-9_.-]{3,24}$/', $username) !== 1) {
            ricetta_json_response(400, ['error' => 'El usuario debe tener entre 3 y 24 caracteres: letras, números, punto, guion o guion bajo.']);
        }
        $minimum_password_length = $route === '/register' ? 12 : 8;
        if (!is_string($password)
            || ricetta_text_length($password) < $minimum_password_length
            || ricetta_text_length($password) > 200) {
            ricetta_json_response(400, ['error' => "La contraseña debe tener entre $minimum_password_length y 200 caracteres."]);
        }
        if ($route === '/register') {
            if ($password !== $password_confirm) {
                ricetta_json_response(400, ['error' => 'Las contraseñas no coinciden.']);
            }
            if (!ricetta_valid_email($email)) {
                ricetta_json_response(400, ['error' => 'Escribe una dirección de correo válida.']);
            }
            $email = strtolower(trim($email));
        }

        $verification_base = null;
        if ($route === '/register' && ricetta_smtp_configured()) {
            try {
                $verification_base = rtrim(ricetta_public_base_url(), '/');
            } catch (RuntimeException) {
                $verification_base = null;
            }
        }

        $pdo = ricetta_db();
        if ($route === '/register') {
            $verification_token = null;
            $verification_hash = null;
            $user_id = 0;
            $legacy_state = ['recipes' => [], 'planner' => []];
            try {
                $pdo->beginTransaction();
                $statement = $pdo->prepare('SELECT id FROM users WHERE username=?');
                $statement->execute([$username]);
                if ($statement->fetch()) {
                    $pdo->rollBack();
                    ricetta_json_response(409, ['error' => 'Ese nombre de usuario ya está en uso.']);
                }
                $statement = $pdo->prepare('SELECT 1 FROM users WHERE email=? AND email_verified=1');
                $statement->execute([$email]);
                if ($statement->fetch()) {
                    $pdo->rollBack();
                    ricetta_json_response(409, ['error' => 'Ese correo ya está asociado a otra cuenta.']);
                }
                $first_user = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
                $statement = $pdo->prepare('INSERT INTO users(username,email,password_hash) VALUES(?,?,?)');
                $statement->execute([$username, $email, ricetta_hash_password($password)]);
                $user_id = (int) $pdo->lastInsertId();

                if ($verification_base !== null) {
                    $verification_token = ricetta_random_token(32);
                    $verification_hash = hash('sha256', $verification_token);
                    $expiry = gmdate('Y-m-d H:i:s', time() + RICETTA_EMAIL_CHANGE_HOURS * 3600);
                    $pdo->prepare('INSERT INTO pending_emails(user_id,email,token_hash,expires_at) VALUES(?,?,?,?)')
                        ->execute([$user_id, $email, $verification_hash, $expiry]);
                }

                if ($first_user) {
                    $legacy = $data['legacyState'] ?? null;
                    if (!is_array($legacy)) {
                        $legacy = ['recipes' => [], 'planner' => []];
                    }
                    $legacy_json = json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $legacy_state = ricetta_decode_state(is_string($legacy_json) ? $legacy_json : '{}');
                }
                $pdo->prepare('INSERT INTO user_state(user_id,value,version) VALUES(?,?,1)')
                    ->execute([$user_id, ricetta_state_json($legacy_state)]);
                $token = ricetta_new_session($pdo, $user_id);
                $pdo->commit();
            } catch (PDOException $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (isset($error->errorInfo[0]) && $error->errorInfo[0] === '23000') {
                    $message = str_contains($error->getMessage(), 'uniq_users_email_verified')
                        ? 'Ese correo ya está asociado a otra cuenta.'
                        : 'Ese nombre de usuario ya está en uso.';
                    ricetta_json_response(409, ['error' => $message]);
                }
                throw $error;
            }

            if ($verification_token !== null && $verification_hash !== null && $verification_base !== null) {
                try {
                    $verify_link = $verification_base . '/confirm-email.html#token=' . $verification_token;
                    $verify_body = "Has creado una cuenta en Ricetta.\n\n"
                        . "Confirma que tienes acceso a este correo abriendo este enlace (válido durante "
                        . RICETTA_EMAIL_CHANGE_HOURS . " horas):\n$verify_link\n\n"
                        . 'Si no has creado la cuenta, ignora este mensaje.';
                    ricetta_send_verification_email($email, $verify_body, $user_id, $verification_hash);
                } catch (Throwable $error) {
                    $pdo->prepare('DELETE FROM pending_emails WHERE user_id=? AND token_hash=?')
                        ->execute([$user_id, $verification_hash]);
                    ricetta_log($error);
                }
            }
            $GLOBALS['ricetta_session_token'] = $token;
            ricetta_json_response(201, [
                'user' => $username,
                'email' => $email,
                'emailVerified' => false,
                'pendingEmail' => $verification_token !== null ? $email : null,
                'state' => ricetta_public_state($legacy_state),
                'stateVersion' => 1,
                'shares' => [],
            ]);
        }

        $statement = $pdo->prepare('SELECT id, username, email, email_verified, password_hash FROM users WHERE username=?');
        $statement->execute([$username]);
        $user = $statement->fetch();
        if (!$user || !ricetta_verify_password($password, $user['password_hash'])) {
            ricetta_json_response(401, ['error' => 'Usuario o contraseña incorrectos.']);
        }
        $token = ricetta_new_session($pdo, (int) $user['id']);
        $statement = $pdo->prepare('SELECT value, version FROM user_state WHERE user_id=?');
        $statement->execute([$user['id']]);
        $row = $statement->fetch();
        $state = $row ? ricetta_decode_state($row['value']) : ['recipes' => [], 'planner' => []];
        $GLOBALS['ricetta_session_token'] = $token;
        ricetta_json_response(200, [
            'user' => $user['username'],
            'email' => $user['email'],
            'emailVerified' => (bool) $user['email_verified'],
            'state' => ricetta_public_state($state),
            'stateVersion' => $row ? (int) $row['version'] : 0,
        ]);
    }

    if ($route === '/password-reset-request') {
        $email = $data['email'] ?? null;
        $generic = ['ok' => true, 'message' => 'Si la dirección corresponde a una cuenta verificada, recibirás un enlace en breve.'];
        if (!ricetta_valid_email($email) || !ricetta_smtp_configured()) {
            ricetta_json_response(200, $generic);
        }
        $email = strtolower(trim($email));
        $pdo = ricetta_db();
        $pdo->beginTransaction();
        $limited = ricetta_email_rate_limited('password-reset', $email);
        $user = null;
        if (!$limited) {
            $statement = $pdo->prepare('SELECT id FROM users WHERE email=? AND email_verified=1');
            $statement->execute([$email]);
            $user = $statement->fetch();
        }
        if ($user) {
            $token = ricetta_random_token(32);
            $token_hash = hash('sha256', $token);
            $expiry = gmdate('Y-m-d H:i:s', time() + RICETTA_PASSWORD_RESET_MINUTES * 60);
            $pdo->prepare(
                'INSERT INTO password_resets(user_id,token_hash,expires_at,created_at) VALUES(?,?,?,?)
                 ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash), expires_at=VALUES(expires_at), created_at=VALUES(created_at)'
            )->execute([$user['id'], $token_hash, $expiry, gmdate('Y-m-d H:i:s')]);
            $pdo->commit();
            try {
                $link = rtrim(ricetta_public_base_url(), '/') . '/#reset=' . $token;
                $body = "Has solicitado cambiar la contraseña de tu cuenta de Ricetta.\n\n"
                    . 'Abre este enlace para elegir una nueva contraseña (válido durante '
                    . RICETTA_PASSWORD_RESET_MINUTES . " minutos):\n$link\n\n"
                    . 'El enlace solo se puede usar una vez. Si no has solicitado este cambio, ignora este mensaje; tu contraseña seguirá igual.';
                ricetta_send_reset_email($email, $body, (int) $user['id'], $token_hash);
            } catch (Throwable $error) {
                $pdo->prepare('DELETE FROM password_resets WHERE user_id=? AND token_hash=?')
                    ->execute([$user['id'], $token_hash]);
                ricetta_log($error);
            }
        } else {
            $pdo->commit();
        }
        ricetta_json_response(200, $generic);
    }

    if ($route === '/password-reset') {
        $token = $data['token'] ?? '';
        $password = $data['new_password'] ?? null;
        $confirmation = $data['new_password_confirm'] ?? null;
        if (!is_string($password)
            || ricetta_text_length($password) < 12
            || ricetta_text_length($password) > 200) {
            ricetta_json_response(400, ['error' => 'La contraseña nueva debe tener entre 12 y 200 caracteres.']);
        }
        if ($password !== $confirmation) {
            ricetta_json_response(400, ['error' => 'Las contraseñas no coinciden.']);
        }
        $token_hash = is_string($token) && $token !== '' ? hash('sha256', $token) : '';
        $pdo = ricetta_db();
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT user_id,expires_at FROM password_resets WHERE token_hash=?');
        $statement->execute([$token_hash]);
        $reset = $statement->fetch();
        if (!$reset || $reset['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            if ($reset) {
                $pdo->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$reset['user_id']]);
            }
            $pdo->commit();
            ricetta_json_response(400, ['error' => 'El enlace no es válido o ha caducado. Solicita uno nuevo.']);
        }
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
            ->execute([ricetta_hash_password($password), $reset['user_id']]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$reset['user_id']]);
        $pdo->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$reset['user_id']]);
        $pdo->commit();
        ricetta_json_response(200, ['ok' => true]);
    }

    if ($route === '/logout') {
        $pdo = ricetta_db();
        $user = ricetta_session_user($pdo);
        if ($user) {
            $token = $_COOKIE['mesa_session'] ?? '';
            if (is_string($token) && $token !== '') {
                $pdo->prepare('DELETE FROM sessions WHERE token_hash=?')
                    ->execute([hash('sha256', $token)]);
            }
        }
        $GLOBALS['ricetta_session_token'] = null;
        ricetta_set_session_cookie('', true);
        ricetta_json_response(200, ['ok' => true]);
    }

    $share_action = preg_match('#^/shares/(\d+)/(copy|dismiss)$#', $route, $share_matches) === 1;
    $needs_auth = in_array($route, ['/state', '/share', '/email', '/password', '/delete-account'], true) || $share_action;
    if ($needs_auth) {
        $pdo = ricetta_db();
        $user = ricetta_session_user($pdo);
        if (!$user) {
            ricetta_json_response(401, ['error' => 'Inicia sesión para continuar.']);
        }

        if ($route === '/delete-account') {
            $confirmation = $data['confirmation'] ?? '';
            $password = $data['password'] ?? '';
            if ($confirmation !== 'ELIMINAR') {
                ricetta_json_response(400, ['error' => 'Escribe ELIMINAR para confirmar.']);
            }
            $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
            $statement->execute([$user['id']]);
            $row = $statement->fetch();
            if (!is_string($password)
                || ricetta_text_length($password) > 200
                || !$row
                || !ricetta_verify_password($password, $row['password_hash'])) {
                ricetta_json_response(401, ['error' => 'La contraseña no es correcta.']);
            }
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$user['id']]);
            $GLOBALS['ricetta_session_token'] = null;
            ricetta_set_session_cookie('', true);
            ricetta_json_response(200, ['ok' => true]);
        }

        if ($route === '/email') {
            $email = $data['email'] ?? null;
            $current_password = $data['current_password'] ?? '';
            if (!ricetta_valid_email($email)) {
                ricetta_json_response(400, ['error' => 'Escribe una dirección de correo válida.']);
            }
            $email = strtolower(trim($email));
            $statement = $pdo->prepare('SELECT email,email_verified,password_hash FROM users WHERE id=?');
            $statement->execute([$user['id']]);
            $row = $statement->fetch();
            if (!is_string($current_password)
                || ricetta_text_length($current_password) > 200
                || !$row
                || !ricetta_verify_password($current_password, $row['password_hash'])) {
                ricetta_json_response(401, ['error' => 'La contraseña actual no es correcta.']);
            }
            $same_email = $email === strtolower((string) ($row['email'] ?? ''));
            if ($same_email && (bool) $row['email_verified']) {
                $pdo->prepare('DELETE FROM pending_emails WHERE user_id=?')->execute([$user['id']]);
                ricetta_json_response(200, [
                    'ok' => true,
                    'email' => $row['email'],
                    'pendingEmail' => null,
                    'unchanged' => true,
                ]);
            }
            if (!ricetta_smtp_configured()) {
                ricetta_json_response(503, ['error' => 'El envío de correo no está configurado.']);
            }
            $statement = $pdo->prepare('SELECT id FROM users WHERE email=? AND email_verified=1 AND id<>?');
            $statement->execute([$email, $user['id']]);
            if ($statement->fetch()) {
                ricetta_json_response(409, ['error' => 'Ese correo ya está asociado a otra cuenta.']);
            }
            $statement = $pdo->prepare('SELECT email,created_at FROM pending_emails WHERE user_id=?');
            $statement->execute([$user['id']]);
            $pending = $statement->fetch();
            if ($pending && is_string($pending['created_at']) && $pending['created_at'] !== '') {
                $created = strtotime($pending['created_at'] . ' UTC');
                if ($created !== false && time() - $created < 60) {
                    ricetta_json_response(429, ['error' => 'Espera un minuto antes de solicitar otra confirmación.']);
                }
            }
            $token = ricetta_random_token(32);
            $token_hash = hash('sha256', $token);
            $expiry = gmdate('Y-m-d H:i:s', time() + RICETTA_EMAIL_CHANGE_HOURS * 3600);
            $pdo->prepare(
                'INSERT INTO pending_emails(user_id,email,token_hash,expires_at,created_at) VALUES(?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE email=VALUES(email), token_hash=VALUES(token_hash),
                 expires_at=VALUES(expires_at), created_at=VALUES(created_at)'
            )->execute([$user['id'], $email, $token_hash, $expiry, gmdate('Y-m-d H:i:s')]);
            try {
                $base_url = rtrim(ricetta_public_base_url(), '/');
                $link = $base_url . '/confirm-email.html#token=' . $token;
                if ($same_email) {
                    $subject = 'Verifica tu correo de Ricetta';
                    $body = "Has solicitado verificar el correo de tu cuenta de Ricetta.\n\n"
                        . 'Confirma que puedes recibir mensajes en esta dirección abriendo este enlace (válido durante '
                        . RICETTA_EMAIL_CHANGE_HOURS . " horas):\n$link\n\n"
                        . 'Si no lo has solicitado, ignora este mensaje.';
                } else {
                    $subject = 'Confirma tu nuevo correo de Ricetta';
                    $body = "Has solicitado cambiar el correo de tu cuenta de Ricetta.\n\n"
                        . 'Confirma la nueva dirección abriendo este enlace (válido durante '
                        . RICETTA_EMAIL_CHANGE_HOURS . " horas):\n$link\n\n"
                        . 'Si no lo has solicitado, ignora este mensaje. El correo actual seguirá asociado a tu cuenta.';
                }
                ricetta_send_transactional_email($email, $subject, $body);
            } catch (Throwable $error) {
                $pdo->prepare('DELETE FROM pending_emails WHERE user_id=? AND token_hash=?')
                    ->execute([$user['id'], $token_hash]);
                ricetta_log($error);
                ricetta_json_response(503, ['error' => 'No se pudo enviar el correo de confirmación. Revisa la configuración de correo e inténtalo de nuevo.']);
            }
            ricetta_json_response(200, [
                'ok' => true,
                'email' => $row['email'],
                'emailVerified' => (bool) $row['email_verified'],
                'pendingEmail' => $email,
                'verificationOnly' => $same_email,
            ]);
        }

        if ($route === '/password') {
            $current_password = $data['current_password'] ?? '';
            $new_password = $data['new_password'] ?? null;
            $confirmation = $data['new_password_confirm'] ?? null;
            $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
            $statement->execute([$user['id']]);
            $row = $statement->fetch();
            if (!is_string($current_password)
                || ricetta_text_length($current_password) > 200
                || !$row
                || !ricetta_verify_password($current_password, $row['password_hash'])) {
                ricetta_json_response(401, ['error' => 'La contraseña actual no es correcta.']);
            }
            if (!is_string($new_password)
                || ricetta_text_length($new_password) < 12
                || ricetta_text_length($new_password) > 200) {
                ricetta_json_response(400, ['error' => 'La nueva contraseña debe tener entre 12 y 200 caracteres.']);
            }
            if ($new_password !== $confirmation) {
                ricetta_json_response(400, ['error' => 'Las contraseñas nuevas no coinciden.']);
            }
            if (ricetta_verify_password($new_password, $row['password_hash'])) {
                ricetta_json_response(400, ['error' => 'Elige una contraseña distinta de la actual.']);
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([ricetta_hash_password($new_password), $user['id']]);
            $current_token_hash = hash('sha256', (string) ($GLOBALS['ricetta_session_token'] ?? ''));
            $pdo->prepare('DELETE FROM sessions WHERE user_id=? AND token_hash<>?')
                ->execute([$user['id'], $current_token_hash]);
            $pdo->commit();
            ricetta_json_response(200, ['ok' => true]);
        }

        if ($route === '/state') {
            $state = ricetta_normalize_state($data['state'] ?? null, true);
            if ($state === null) {
                ricetta_json_response(400, ['error' => 'Los datos de la aplicación no son válidos.']);
            }
            $base_version = $data['baseVersion'] ?? null;
            if ($base_version !== null
                && (!is_int($base_version) || $base_version < 0)) {
                ricetta_json_response(400, ['error' => 'La versión de los datos no es válida.']);
            }
            $encoded = ricetta_state_json($state);
            if ($base_version === null) {
                $statement = $pdo->prepare('SELECT version FROM user_state WHERE user_id=?');
                $statement->execute([$user['id']]);
                $row = $statement->fetch();
                if ($row) {
                    $pdo->prepare('UPDATE user_state SET value=?, version=version+1 WHERE user_id=?')
                        ->execute([$encoded, $user['id']]);
                    $new_version = (int) $row['version'] + 1;
                } else {
                    $pdo->prepare('INSERT INTO user_state(user_id,value,version) VALUES(?,?,1)')
                        ->execute([$user['id'], $encoded]);
                    $new_version = 1;
                }
                ricetta_json_response(200, ['ok' => true, 'stateVersion' => $new_version]);
            }
            $statement = $pdo->prepare(
                'UPDATE user_state SET value=?, version=version+1 WHERE user_id=? AND version=?'
            );
            $statement->execute([$encoded, $user['id'], $base_version]);
            if ($statement->rowCount() === 0) {
                $statement = $pdo->prepare('SELECT value, version FROM user_state WHERE user_id=?');
                $statement->execute([$user['id']]);
                $current = $statement->fetch();
                if (!$current && $base_version === 0) {
                    try {
                        $pdo->prepare('INSERT INTO user_state(user_id,value,version) VALUES(?,?,1)')
                            ->execute([$user['id'], $encoded]);
                        ricetta_json_response(200, ['ok' => true, 'stateVersion' => 1]);
                    } catch (PDOException $error) {
                        if (!isset($error->errorInfo[0]) || $error->errorInfo[0] !== '23000') {
                            throw $error;
                        }
                        $statement = $pdo->prepare('SELECT value, version FROM user_state WHERE user_id=?');
                        $statement->execute([$user['id']]);
                        $current = $statement->fetch();
                    }
                }
                $latest_state = $current ? ricetta_decode_state($current['value']) : ['recipes' => [], 'planner' => []];
                $latest_version = $current ? (int) $current['version'] : 0;
                ricetta_json_response(409, [
                    'error' => 'Los datos han cambiado en otro dispositivo o pestaña.',
                    'stateVersion' => $latest_version,
                    'state' => ricetta_public_state($latest_state),
                ]);
            }
            ricetta_json_response(200, ['ok' => true, 'stateVersion' => $base_version + 1]);
        }

        if ($route === '/share') {
            $kind = $data['kind'] ?? null;
            $title = $data['title'] ?? null;
            $recipient = $data['recipient'] ?? null;
            $payload = $data['payload'] ?? null;
            if (!in_array($kind, ['recipe', 'planner'], true)
                || !is_string($title)
                || !ricetta_is_object_array($payload)) {
                ricetta_json_response(400, ['error' => 'Contenido para compartir no válido.']);
            }
            $title = trim($title);
            if ($title === '' || ricetta_text_length($title) > 100) {
                ricetta_json_response(400, ['error' => 'El título debe tener entre 1 y 100 caracteres.']);
            }
            if (!is_string($recipient) || preg_match('/^[A-Za-z0-9_.-]{3,24}$/', $recipient) !== 1) {
                ricetta_json_response(400, ['error' => 'El nombre de usuario destinatario no es válido.']);
            }
            if ($kind === 'recipe') {
                $recipe = ricetta_clean_recipe($payload['recipe'] ?? null);
                if ($recipe === null) {
                    ricetta_json_response(400, ['error' => 'La receta compartida no es válida.']);
                }
                $payload = ['recipe' => $recipe];
            } else {
                $normalized = ricetta_normalize_state([
                    'recipes' => $payload['recipes'] ?? null,
                    'planner' => $payload['planner'] ?? null,
                ], true);
                if ($normalized === null || $normalized['recipes'] === [] || $normalized['planner'] === []) {
                    ricetta_json_response(400, ['error' => 'La planificación compartida no es válida.']);
                }
                $payload = ricetta_public_state($normalized);
            }
            $statement = $pdo->prepare('SELECT id FROM users WHERE username=?');
            $statement->execute([$recipient]);
            $target = $statement->fetch();
            if (!$target) {
                ricetta_json_response(404, ['error' => 'No hay ninguna cuenta con ese nombre de usuario.']);
            }
            if ((int) $target['id'] === (int) $user['id']) {
                ricetta_json_response(400, ['error' => 'Elige a otro usuario para compartir.']);
            }
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || strlen($encoded) > RICETTA_MAX_SHARE_BYTES) {
                ricetta_json_response(413, ['error' => 'El contenido es demasiado grande.']);
            }
            $pdo->prepare('INSERT INTO shares(sender_id,recipient_id,kind,title,payload) VALUES(?,?,?,?,?)')
                ->execute([$user['id'], $target['id'], $kind, $title, $encoded]);
            ricetta_json_response(201, ['ok' => true]);
        }

        if ($share_action) {
            $share_id = (int) $share_matches[1];
            $action = $share_matches[2];
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT * FROM shares WHERE id=? AND recipient_id=? AND dismissed_at IS NULL FOR UPDATE'
            );
            $statement->execute([$share_id, $user['id']]);
            $share = $statement->fetch();
            if (!$share) {
                $pdo->rollBack();
                ricetta_json_response(404, ['error' => 'El contenido compartido ya no está disponible.']);
            }
            if ($action === 'dismiss') {
                $pdo->prepare('UPDATE shares SET dismissed_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([$share_id]);
                $pdo->commit();
                ricetta_json_response(200, ['ok' => true]);
            }
            if ($share['copied_at']) {
                $pdo->rollBack();
                ricetta_json_response(409, ['error' => 'Ya guardaste una copia de este contenido.']);
            }
            $base_version = $data['baseVersion'] ?? null;
            if ($base_version !== null && (!is_int($base_version) || $base_version < 0)) {
                $pdo->rollBack();
                ricetta_json_response(400, ['error' => 'La versión de los datos no es válida.']);
            }
            $statement = $pdo->prepare('SELECT value, version FROM user_state WHERE user_id=? FOR UPDATE');
            $statement->execute([$user['id']]);
            $row = $statement->fetch();
            $state = $row ? ricetta_decode_state($row['value']) : ['recipes' => [], 'planner' => []];
            $current_version = $row ? (int) $row['version'] : 0;
            if ($base_version !== null && $base_version !== $current_version) {
                $pdo->rollBack();
                ricetta_json_response(409, [
                    'error' => 'Los datos han cambiado en otro dispositivo o pestaña.',
                    'stateVersion' => $current_version,
                    'state' => ricetta_public_state($state),
                ]);
            }
            $payload = ricetta_parse_share_payload($share['payload']);
            if ($payload === null) {
                $pdo->rollBack();
                ricetta_json_response(400, ['error' => 'El contenido compartido está dañado.']);
            }
            if ($share['kind'] === 'recipe') {
                $recipe = ricetta_clean_recipe($payload['recipe'] ?? null);
                if ($recipe === null) {
                    $pdo->rollBack();
                    ricetta_json_response(400, ['error' => 'La receta compartida no es válida.']);
                }
                if (count($state['recipes']) >= RICETTA_MAX_RECIPES) {
                    $pdo->rollBack();
                    ricetta_json_response(409, ['error' => 'Tu recetario ha alcanzado el límite máximo de recetas.']);
                }
                array_unshift($state['recipes'], ['id' => 'r-' . ricetta_random_token(12)] + $recipe);
            } else {
                $normalized = ricetta_normalize_state([
                    'recipes' => $payload['recipes'] ?? null,
                    'planner' => $payload['planner'] ?? null,
                ], true);
                if ($normalized === null) {
                    $pdo->rollBack();
                    ricetta_json_response(400, ['error' => 'La planificación compartida no es válida.']);
                }
                $clones = [];
                $id_map = [];
                foreach ($normalized['recipes'] as $recipe) {
                    $old_id = $recipe['id'];
                    $clone = $recipe;
                    $clone['id'] = 'r-' . ricetta_random_token(12);
                    $id_map[$old_id] = $clone['id'];
                    $clones[] = $clone;
                }
                $incoming_planner = [];
                foreach ($normalized['planner'] as $day => $meals) {
                    $clean_meals = [];
                    foreach ($meals as $slot => $recipe_id) {
                        if (isset($id_map[$recipe_id])) {
                            $clean_meals[$slot] = $id_map[$recipe_id];
                        }
                    }
                    if ($clean_meals !== []) {
                        $incoming_planner[$day] = $clean_meals;
                    }
                }
                if (count($state['recipes']) + count($clones) > RICETTA_MAX_RECIPES) {
                    $pdo->rollBack();
                    ricetta_json_response(409, ['error' => 'Tu recetario no tiene espacio para esta planificación.']);
                }
                if (count(array_unique(array_merge(array_keys($state['planner']), array_keys($incoming_planner)))) > RICETTA_MAX_PLANNER_DAYS) {
                    $pdo->rollBack();
                    ricetta_json_response(409, ['error' => 'Tu planificación ha alcanzado el límite máximo de días.']);
                }
                $state['recipes'] = array_merge($clones, $state['recipes']);
                foreach ($incoming_planner as $day => $meals) {
                    $state['planner'][$day] = $meals;
                }
            }
            $final_state = ricetta_normalize_state($state, true);
            if ($final_state === null) {
                $pdo->rollBack();
                ricetta_json_response(500, ['error' => 'No se pudo preparar el contenido compartido.']);
            }
            $state = $final_state;
            $encoded = ricetta_state_json($state);
            if ($base_version === null) {
                if ($row) {
                    $pdo->prepare('UPDATE user_state SET value=?, version=version+1 WHERE user_id=?')
                        ->execute([$encoded, $user['id']]);
                    $new_version = $current_version + 1;
                } else {
                    $pdo->prepare('INSERT INTO user_state(user_id,value,version) VALUES(?,?,1)')
                        ->execute([$user['id'], $encoded]);
                    $new_version = 1;
                }
            } elseif (!$row && $base_version === 0) {
                try {
                    $pdo->prepare('INSERT INTO user_state(user_id,value,version) VALUES(?,?,1)')
                        ->execute([$user['id'], $encoded]);
                    $new_version = 1;
                } catch (PDOException $error) {
                    $pdo->rollBack();
                    if (!isset($error->errorInfo[0]) || $error->errorInfo[0] !== '23000') {
                        throw $error;
                    }
                    $statement = $pdo->prepare('SELECT value, version FROM user_state WHERE user_id=?');
                    $statement->execute([$user['id']]);
                    $latest = $statement->fetch();
                    ricetta_json_response(409, [
                        'error' => 'Los datos han cambiado en otro dispositivo o pestaña.',
                        'stateVersion' => $latest ? (int) $latest['version'] : 0,
                        'state' => $latest ? ricetta_public_state(ricetta_decode_state($latest['value'])) : ['recipes' => [], 'planner' => (object) []],
                    ]);
                }
            } else {
                $statement = $pdo->prepare(
                    'UPDATE user_state SET value=?, version=version+1 WHERE user_id=? AND version=?'
                );
                $statement->execute([$encoded, $user['id'], $base_version]);
                if ($statement->rowCount() === 0) {
                    $statement = $pdo->prepare('SELECT value, version FROM user_state WHERE user_id=?');
                    $statement->execute([$user['id']]);
                    $latest = $statement->fetch();
                    $pdo->rollBack();
                    ricetta_json_response(409, [
                        'error' => 'Los datos han cambiado en otro dispositivo o pestaña.',
                        'stateVersion' => $latest ? (int) $latest['version'] : 0,
                        'state' => $latest ? ricetta_public_state(ricetta_decode_state($latest['value'])) : ['recipes' => [], 'planner' => (object) []],
                    ]);
                }
                $new_version = $base_version + 1;
            }
            $pdo->prepare('UPDATE shares SET copied_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$share_id]);
            $pdo->commit();
            ricetta_json_response(200, [
                'ok' => true,
                'state' => ricetta_public_state($state),
                'stateVersion' => $new_version,
            ]);
        }
    }

    ricetta_json_response(404, ['error' => 'Ruta desconocida.']);
}

$route = ricetta_route_path();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($method === 'GET' || $method === 'HEAD') {
        ricetta_handle_get($route);
    } elseif ($method === 'POST') {
        ricetta_handle_post($route);
    } else {
        ricetta_json_response(405, ['error' => 'Método no permitido.']);
    }
} catch (PDOException $error) {
    ricetta_log($error);
    ricetta_json_response(503, ['error' => 'El servidor está ocupado. Inténtalo de nuevo en unos segundos.']);
} catch (Throwable $error) {
    ricetta_log($error);
    ricetta_json_response(500, ['error' => 'Error interno del servidor.']);
}
