<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../classes/Customer.php';
require_once __DIR__ . '/../classes/Inventory.php';
require_once __DIR__ . '/../classes/Prescription.php';
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
 * Requires a staff session before report data is disclosed.
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
$action = (string) ($_GET['action'] ?? '');

try {
    if ($action === 'dashboard_summary') {
        respond(['success' => true, 'data' => [
            'total_customers' => Customer::countAll(),
            'prescriptions_today' => Prescription::countToday(),
            'low_stock_items' => Inventory::countLowStock(),
            'unacknowledged_alerts' => Alert::countUnacknowledged(),
        ]]);
    }

    if ($action === 'prescriptions_by_date') {
        $from = (string) ($_GET['from'] ?? '');
        $to = (string) ($_GET['to'] ?? '');
        if (!validDate($from) || !validDate($to)) {
            respond(['success' => false, 'message' => 'Valid from and to dates are required.'], 422);
        }
        respond(['success' => true, 'data' => Prescription::reportByDate($from, $to)]);
    }

    if ($action === 'prescriptions_by_customer') {
        respond(['success' => true, 'data' => Prescription::reportByCustomer((int) ($_GET['customer_id'] ?? 0))]);
    }

    if ($action === 'prescriptions_by_medication') {
        respond(['success' => true, 'data' => Prescription::reportByMedication((int) ($_GET['medication_id'] ?? 0))]);
    }

    if ($action === 'inventory_status') {
        respond(['success' => true, 'data' => Inventory::listAll()]);
    }

    respond(['success' => false, 'message' => 'Unsupported reports action.'], 400);
} catch (Throwable $exception) {
    error_log('Reports API error: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'Reports service unavailable.'], 500);
}
