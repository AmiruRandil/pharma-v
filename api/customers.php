<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../classes/Customer.php';

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

/**
 * Requires a staff session before patient data is disclosed.
 *
 * @return void
 */
function requireAuth(): void
{
    if (empty($_SESSION['user'])) {
        respond(['success' => false, 'message' => 'Authentication required.'], 401);
    }
}

requireAuth();
$action = (string) ($_GET['action'] ?? '');

try {
    if ($action === 'list') {
        respond(['success' => true, 'data' => Customer::listAll()]);
    }

    if ($action === 'search') {
        respond(['success' => true, 'data' => Customer::search((string) ($_GET['term'] ?? ''))]);
    }

    if ($action === 'get') {
        $customer = Customer::getById((int) ($_GET['id'] ?? 0));
        $customer ? respond(['success' => true, 'data' => $customer]) : respond(['success' => false, 'message' => 'Customer not found.'], 404);
    }

    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $result = Customer::add($body);
        respond($result, $result['success'] ? 200 : 422);
    }

    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $result = Customer::update((int) ($body['id'] ?? 0), $body);
        respond($result, $result['success'] ? 200 : 422);
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $result = Customer::delete((int) ($body['id'] ?? 0));
        respond($result, $result['success'] ? 200 : 422);
    }

    respond(['success' => false, 'message' => 'Unsupported customers action.'], 400);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $exception->getMessage(),
        'file'    => $exception->getFile(),
        'line'    => $exception->getLine()
    ]);
    exit;
//    error_log('Customers API error: ' . $exception->getMessage());
//    respond(['success' => false, 'message' => 'Customer service unavailable.'], 500);
}
