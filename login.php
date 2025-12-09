<?php
declare(strict_types=1);

require __DIR__ . '/security.php';
require __DIR__ . '/db.php';

startSecureSession();

$hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
$serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');
$allowedHosts = array_filter([
    parse_url('http://' . $hostHeader, PHP_URL_HOST),
    parse_url('http://' . $serverName, PHP_URL_HOST),
]);

function respond(array $payload, bool $asJson): void
{
    if ($asJson) {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    foreach (['login_error', 'login_success'] as $key) {
        unset($_SESSION[$key]);
    }

    if (isset($payload['error'])) {
        $_SESSION['login_error'] = (string) $payload['error'];
    }

    if (isset($payload['message'])) {
        $_SESSION['login_success'] = (string) $payload['message'];
    }

    header('Location: index.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['error' => 'Unsupported method'], false);
}

$asJson = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

$submittedToken = $_POST['csrf_token'] ?? null;
if (!validateCsrfToken(is_string($submittedToken) ? $submittedToken : null)) {
    respond(['error' => 'Invalid or missing form token.'], $asJson);
}

if (!validateRequestOrigin($allowedHosts)) {
    respond(['error' => 'Request origin could not be verified.'], $asJson);
}

$email = strtolower(trim((string) ($_POST['login_email'] ?? '')));
$password = (string) ($_POST['login_password'] ?? '');

$users = [
    'barista@example.com' => password_hash('coffeetime123', PASSWORD_DEFAULT),
    'manager@example.com' => password_hash('brewsecure!', PASSWORD_DEFAULT),
];

if ($email === '' || $password === '') {
    respond(['error' => 'Email and password are required.'], $asJson);
}

if (!isset($users[$email]) || !password_verify($password, $users[$email])) {
    respond(['error' => 'Invalid credentials.'], $asJson);
}

$_SESSION['is_authenticated'] = true;
$_SESSION['user_email'] = $email;

respond(['message' => 'Logged in successfully.', 'user' => ['email' => $email]], $asJson);
