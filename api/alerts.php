<?php
/**
 * Purpose: Exposes authenticated alert listing and acknowledgement endpoints.
 * Author: Pharma V Team
 * Version: 1.0
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../classes/Alert.php';

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
 * Requires a staff session before operational alerts are disclosed.
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
    if ($action === 'unacknowledged') {
        respond(['success' => true, 'data' => Alert::unacknowledged()]);
    }

    if ($action === 'all') {
        respond(['success' => true, 'data' => Alert::all()]);
    }

    if ($action === 'acknowledge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $ok = Alert::acknowledge((int) ($body['alert_id'] ?? 0));
        $ok ? respond(['success' => true, 'message' => 'Alert acknowledged.']) : respond(['success' => false, 'message' => 'Alert not found.'], 404);
    }

    respond(['success' => false, 'message' => 'Unsupported alerts action.'], 400);
} catch (Throwable $exception) {
    error_log('Alerts API error: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'Alert service unavailable.'], 500);
}
