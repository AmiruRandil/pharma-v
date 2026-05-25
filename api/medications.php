<?php
/**
 * Purpose: Exposes authenticated medication catalogue CRUD endpoints.
 * Author: Pharma V Team
 * Version: 1.0
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../classes/Medication.php';

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
 * Requires a staff session before medication data is returned.
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
        respond(['success' => true, 'data' => Medication::listAll()]);
    }

    if ($action === 'get') {
        $medication = Medication::getById((int) ($_GET['id'] ?? 0));
        $medication ? respond(['success' => true, 'data' => $medication]) : respond(['success' => false, 'message' => 'Medication not found.'], 404);
    }

    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $result = Medication::add($body);
        respond($result, $result['success'] ? 200 : 422);
    }

    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $result = Medication::update((int) ($body['id'] ?? 0), $body);
        respond($result, $result['success'] ? 200 : 422);
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $result = Medication::delete((int) ($body['id'] ?? 0));
        respond($result, $result['success'] ? 200 : 422);
    }

    respond(['success' => false, 'message' => 'Unsupported medications action.'], 400);
} catch (Throwable $exception) {
    error_log('Medications API error: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'Medication service unavailable.'], 500);
}
