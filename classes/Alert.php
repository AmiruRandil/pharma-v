<?php
/**
 * Purpose: Manages pharmacy operational alerts and acknowledgement workflow.
 * Author: Pharma V Team
 * Version: 1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Alert data access and risk notification service.
 */
final class Alert
{
    /**
     * Lists all unacknowledged alerts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unacknowledged(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, type, message, reference_id, created_at, acknowledged
             FROM alerts
             WHERE acknowledged = FALSE
             ORDER BY created_at DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Lists every alert for audit and reporting review.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, type, message, reference_id, created_at, acknowledged
             FROM alerts
             ORDER BY created_at DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Acknowledges an alert after staff review.
     *
     * @param int $alertId Alert identifier.
     * @return bool True when an alert was updated.
     */
    public static function acknowledge(int $alertId): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE alerts SET acknowledged = TRUE WHERE id = :id');
        $stmt->execute(['id' => $alertId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Creates an alert record.
     *
     * @param string $type Alert type.
     * @param string $message Staff-facing alert message.
     * @param int $referenceId Related prescription or inventory identifier.
     * @return int New alert identifier.
     */
    public static function create(string $type, string $message, int $referenceId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO alerts (type, message, reference_id) VALUES (:type, :message, :reference_id)'
        );
        $stmt->execute([
            'type' => $type,
            'message' => $message,
            'reference_id' => $referenceId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Counts unacknowledged alerts for dashboard metrics.
     *
     * @return int
     */
    public static function countUnacknowledged(): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM alerts WHERE acknowledged = FALSE');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Inserts EXPIRED alerts for expired stock batches when no open expired alert exists.
     *
     * @return int Number of alerts created.
     */
    public static function createExpiredStockAlerts(): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT i.id, i.batch_number, i.expiry_date, m.name AS medication_name
             FROM inventory i
             INNER JOIN medications m ON m.id = i.medication_id
             WHERE i.expiry_date < CURDATE()
               AND NOT EXISTS (
                   SELECT 1 FROM alerts a
                   WHERE a.type = 'EXPIRED' AND a.reference_id = i.id AND a.acknowledged = FALSE
               )"
        );
        $stmt->execute();
        $expiredItems = $stmt->fetchAll();
        $created = 0;

        foreach ($expiredItems as $item) {
            // Expired stock is a patient safety risk and must be visible until acknowledged.
            self::create(
                'EXPIRED',
                'Expired stock: ' . $item['medication_name'] . ' batch ' . $item['batch_number'] . ' expired on ' . $item['expiry_date'] . '.',
                (int) $item['id']
            );
            $created++;
        }

        return $created;
    }
}
