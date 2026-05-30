<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Inventory data access and stock control service.
 */
final class Inventory
{
    /**
     * Lists inventory batches with medication names and risk flags.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT i.id, i.medication_id, m.name AS medication_name, i.store_id, i.batch_number, i.quantity,
                    i.expiry_date, i.low_stock_threshold,
                    (i.quantity < i.low_stock_threshold) AS is_low_stock,
                    (i.quantity = 0) AS is_out_of_stock,
                    (i.expiry_date < CURDATE()) AS is_expired
             FROM inventory i
             INNER JOIN medications m ON m.id = i.medication_id
             ORDER BY m.name, i.expiry_date'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Fetches one inventory batch.
     *
     * @param int $id Inventory identifier.
     * @return array<string, mixed>|null
     */
    public static function getById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT i.id, i.medication_id, m.name AS medication_name, i.store_id, i.batch_number, i.quantity,
                    i.expiry_date, i.low_stock_threshold
             FROM inventory i
             INNER JOIN medications m ON m.id = i.medication_id
             WHERE i.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $item = $stmt->fetch();

        return $item ?: null;
    }

    /**
     * Adds a new stock batch for a medication.
     *
     * @param int $medicationId Medication identifier.
     * @param string $batchNumber Batch number supplied by pharmacy staff.
     * @param int $quantity Quantity received.
     * @param string $expiryDate Expiry date in Y-m-d format.
     * @param int $lowStockThreshold Reorder threshold.
     * @return int New inventory identifier.
     */
    public static function add(int $medicationId, string $batchNumber, int $quantity, string $expiryDate, int $lowStockThreshold = 10): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO inventory (medication_id, batch_number, quantity, expiry_date, low_stock_threshold)
             VALUES (:medication_id, :batch_number, :quantity, :expiry_date, :low_stock_threshold)'
        );
        $stmt->execute([
            'medication_id' => $medicationId,
            'batch_number' => $batchNumber,
            'quantity' => $quantity,
            'expiry_date' => $expiryDate,
            'low_stock_threshold' => $lowStockThreshold,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Updates a stock batch after validating it exists.
     *
     * @param int $id Inventory identifier.
     * @param int $medicationId Medication identifier.
     * @param string $batchNumber Batch number.
     * @param int $quantity Current stock quantity.
     * @param string $expiryDate Expiry date in Y-m-d format.
     * @param int $lowStockThreshold Reorder threshold.
     * @return bool True when the batch exists and was updated.
     */
    public static function update(int $id, int $medicationId, string $batchNumber, int $quantity, string $expiryDate, int $lowStockThreshold): bool
    {
        if ($id <= 0 || self::getById($id) === null) {
            return false;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE inventory
             SET medication_id = :medication_id,
                 batch_number = :batch_number,
                 quantity = :quantity,
                 expiry_date = :expiry_date,
                 low_stock_threshold = :low_stock_threshold
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'medication_id' => $medicationId,
            'batch_number' => $batchNumber,
            'quantity' => $quantity,
            'expiry_date' => $expiryDate,
            'low_stock_threshold' => $lowStockThreshold,
        ]);

        return true;
    }

    /**
     * Deletes a stock batch and any alerts that reference that batch.
     *
     * @param int $id Inventory identifier.
     * @return bool True when a batch was deleted.
     */
    public static function delete(int $id): bool
    {
        if ($id <= 0 || self::getById($id) === null) {
            return false;
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            // Alerts reference inventory by loose reference_id, so clear those before deleting the batch.
            $deleteAlerts = $pdo->prepare("DELETE FROM alerts WHERE type IN ('LOW_STOCK', 'EXPIRED') AND reference_id = :id");
            $deleteAlerts->execute(['id' => $id]);

            $deleteStock = $pdo->prepare('DELETE FROM inventory WHERE id = :id');
            $deleteStock->execute(['id' => $id]);
            $pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Inventory delete failed: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * Counts inventory rows that are below their reorder threshold.
     *
     * @return int
     */
    public static function countLowStock(): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM inventory WHERE quantity < low_stock_threshold');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
