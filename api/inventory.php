<?php
/**
 * Purpose: Exposes authenticated inventory listing, lookup, and stock CRUD endpoints.
 * Author: Pharma V Team
 * Version: 1.0
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../classes/Inventory.php';

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
 * Requires a staff session before stock data is disclosed.
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
    if ($action === 'list') {
        respond(['success' => true, 'data' => Inventory::listAll()]);
    }

    if ($action === 'get') {
        $item = Inventory::getById((int) ($_GET['id'] ?? 0));
        $item ? respond(['success' => true, 'data' => $item]) : respond(['success' => false, 'message' => 'Inventory item not found.'], 404);
    }

    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $medicationId = (int) ($body['medication_id'] ?? 0);
        $batchNumber = trim((string) ($body['batch_number'] ?? ''));
        $quantity = (int) ($body['quantity'] ?? 0);
        $expiryDate = trim((string) ($body['expiry_date'] ?? ''));
        $lowStockThreshold = (int) ($body['low_stock_threshold'] ?? 10);

        if ($medicationId <= 0 || $batchNumber === '' || $quantity <= 0 || $lowStockThreshold < 0 || !validDate($expiryDate)) {
            respond(['success' => false, 'message' => 'Valid medication, batch, quantity, and expiry date are required.'], 422);
        }

        $id = Inventory::add($medicationId, $batchNumber, $quantity, $expiryDate, $lowStockThreshold);
        respond(['success' => true, 'message' => 'Stock batch added.', 'inventory_id' => $id]);
    }

    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $id = (int) ($body['id'] ?? 0);
        $medicationId = (int) ($body['medication_id'] ?? 0);
        $batchNumber = trim((string) ($body['batch_number'] ?? ''));
        $quantity = (int) ($body['quantity'] ?? -1);
        $expiryDate = trim((string) ($body['expiry_date'] ?? ''));
        $lowStockThreshold = (int) ($body['low_stock_threshold'] ?? 0);

        if ($id <= 0 || $medicationId <= 0 || $batchNumber === '' || $quantity < 0 || $lowStockThreshold < 0 || !validDate($expiryDate)) {
            respond(['success' => false, 'message' => 'Valid stock batch details are required.'], 422);
        }

        $ok = Inventory::update($id, $medicationId, $batchNumber, $quantity, $expiryDate, $lowStockThreshold);
        $ok ? respond(['success' => true, 'message' => 'Stock batch updated.']) : respond(['success' => false, 'message' => 'Inventory item not found.'], 404);
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $ok = Inventory::delete((int) ($body['id'] ?? 0));
        $ok ? respond(['success' => true, 'message' => 'Stock batch deleted.']) : respond(['success' => false, 'message' => 'Inventory item not found or could not be deleted.'], 404);
    }

    respond(['success' => false, 'message' => 'Unsupported inventory action.'], 400);
} catch (Throwable $exception) {
    error_log('Inventory API error: ' . $exception->getMessage());
    respond(['success' => false, 'message' => 'Inventory service unavailable.'], 500);
}
