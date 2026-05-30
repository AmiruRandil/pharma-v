<?php

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
 * Requires a staff session before expiry checks run.
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

try {
    if (($_GET['action'] ?? '') === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $created = Alert::createExpiredStockAlerts();
        respond(['success' => true, 'message' => 'Expiry check completed.', 'created_alerts' => $created]);
    }

    respond(['success' => false, 'message' => 'Unsupported expiry check action.'], 400);
} catch (Throwable $exception) {
    error_log('Expiry check API error: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'Expiry check service unavailable.'], 500);
}
