<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../classes/Prescription.php';

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
 * Requires a staff session before prescribing operations run.
 *
 * @return void
 */
function requireAuth(): void
{
    if (empty($_SESSION['user'])) {
        respond(['success' => false, 'message' => 'Authentication required.'], 401);
    }
}

/**
 * Validates ISO date strings.
 *
 * @param string $date Date string.
 * @return bool
 */
function validDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);

    return $parsed instanceof DateTime && $parsed->format('Y-m-d') === $date;
}

requireAuth();

try {
    if (($_GET['action'] ?? '') === 'history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        foreach (['from', 'to'] as $dateField) {
            $date = trim((string) ($_GET[$dateField] ?? ''));
            if ($date !== '' && !validDate($date)) {
                respond(['success' => false, 'message' => 'History date filters must be valid YYYY-MM-DD dates.'], 422);
            }
        }

        respond(['success' => true, 'data' => Prescription::history($_GET)]);
    }

    if (($_GET['action'] ?? '') === 'dispense' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $result = Prescription::dispense($body, $_SESSION['user']);
        respond($result, $result['success'] ? 200 : 422);
    }

    respond(['success' => false, 'message' => 'Unsupported prescriptions action.'], 400);
} catch (Throwable $exception) {
    error_log('Prescriptions API error: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'Prescription service unavailable.'], 500);
}
