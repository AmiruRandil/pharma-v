<?php

/**
 * Purpose: Provides JSON login and logout endpoints for staff authentication.
 * Author: Pharma V Team
 * Version: 1.0
 */

declare(strict_types=1);
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../classes/Database.php';

/**
 * Sends a JSON API response and stops execution.
 *
 * @param array<string, mixed> $payload Response body.
 * @param int $statusCode HTTP status code.
 * @return void
 */
function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$action = (string) ($_GET['action'] ?? '');

try {
    if ($action === 'login') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            respond(['success' => false, 'message' => 'Username and password are required.'], 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, username, password_hash, initials FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            respond(['success' => false, 'message' => 'Invalid login details.'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'initials' => (string) $user['initials'],
        ];

        // GDPR: session contains only minimum staff data, never patient data.
        respond(['success' => true, 'message' => 'Login successful.', 'user' => $_SESSION['user']]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        respond(['success' => true, 'message' => 'Logged out.']);
    }

    respond(['success' => false, 'message' => 'Unsupported authentication action.'], 400);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $exception->getMessage(),
        'file'    => $exception->getFile(),
        'line'    => $exception->getLine()
    ]);
    exit;
//    error_log('Auth API error: ' . $exception->getMessage());
//    respond(['success' => false, 'message' => 'Authentication service unavailable.'], 500);
}
