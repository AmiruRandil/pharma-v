<?php
/**
 * Purpose: Implements dispensing business rules, audit logging, and prescription reports.
 * Author: Pharma V Team
 * Version: 1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Customer.php';
require_once __DIR__ . '/Medication.php';
require_once __DIR__ . '/Alert.php';

/**
 * Prescription business service.
 */
final class Prescription
{
    /**
     * Dispenses a prescription after validating patient, medication, ID checks, and stock.
     *
     * @param array<string, mixed> $data Dispense request body.
     * @param array<string, mixed> $user Authenticated staff details.
     * @return array<string, mixed>
     */
    public static function dispense(array $data, array $user): array
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $medicationId = (int) ($data['medication_id'] ?? 0);
        $dosage = trim((string) ($data['dosage'] ?? ''));
        $quantity = (int) ($data['quantity_prescribed'] ?? 0);
        $prescribedDate = trim((string) ($data['prescribed_date'] ?? ''));
        $confirmedIdCheck = filter_var($data['confirmed_id_check'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($customerId <= 0 || $medicationId <= 0 || $dosage === '' || $quantity <= 0 || !self::isDate($prescribedDate)) {
            return ['success' => false, 'message' => 'Please provide valid customer, medication, dosage, quantity, and date.'];
        }

        $customer = Customer::getById($customerId);
        if ($customer === null) {
            return ['success' => false, 'message' => 'Customer was not found.'];
        }

        $medication = Medication::getById($medicationId);
        if ($medication === null) {
            return ['success' => false, 'message' => 'Medication was not found.'];
        }

        $allergyConflict = self::findAllergyConflict($customerId, (string) ($medication['allergy_tags'] ?? ''));
        if ($allergyConflict !== null) {
            return [
                'success' => false,
                'message' => 'Allergy safety check failed: customer allergy "' . $allergyConflict . '" conflicts with ' . $medication['name'] . '.',
            ];
        }

        $age = self::calculateAge((string) $customer['date_of_birth']);
        if (($medication['min_age'] ?? null) !== null && $age < (int) $medication['min_age']) {
            return [
                'success' => false,
                'message' => 'Age restriction failed: customer is ' . $age . ' and ' . $medication['name'] . ' requires age ' . $medication['min_age'] . ' or above.',
            ];
        }

        if (($medication['max_dispense_qty'] ?? null) !== null && $quantity > (int) $medication['max_dispense_qty']) {
            return [
                'success' => false,
                'message' => 'Quantity limit exceeded: maximum dispense quantity for ' . $medication['name'] . ' is ' . $medication['max_dispense_qty'] . '.',
            ];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // Controlled and sensitive medicines must have an explicit staff ID confirmation before dispensing.
            if ((bool) $medication['requires_id_check'] && !$confirmedIdCheck) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ID check confirmation is required before dispensing this medication.'];
            }

            $stockStmt = $pdo->prepare(
                'SELECT id, quantity, low_stock_threshold, batch_number
                 FROM inventory
                 WHERE medication_id = :medication_id AND quantity > 0 AND expiry_date >= CURDATE()
                 ORDER BY expiry_date ASC, id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stockStmt->execute(['medication_id' => $medicationId]);
            $stock = $stockStmt->fetch();

            if (!$stock || (int) $stock['quantity'] < $quantity) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Insufficient non-expired stock is available for this medication.'];
            }

            $remaining = (int) $stock['quantity'] - $quantity;
            $updateStock = $pdo->prepare('UPDATE inventory SET quantity = :quantity WHERE id = :id');
            $updateStock->execute(['quantity' => $remaining, 'id' => (int) $stock['id']]);

            $insertPrescription = $pdo->prepare(
                "INSERT INTO prescriptions
                    (customer_id, medication_id, prescribed_date, dosage, quantity_prescribed, status, dispensed_date, dispensed_by)
                 VALUES
                    (:customer_id, :medication_id, :prescribed_date, :dosage, :quantity_prescribed, 'dispensed', NOW(), :dispensed_by)"
            );
            $insertPrescription->execute([
                'customer_id' => $customerId,
                'medication_id' => $medicationId,
                'prescribed_date' => $prescribedDate,
                'dosage' => $dosage,
                'quantity_prescribed' => $quantity,
                'dispensed_by' => (string) $user['initials'],
            ]);
            $prescriptionId = (int) $pdo->lastInsertId();

            // GDPR: dispense_log records minimum staff identifier and action needed for accountability.
            $logStmt = $pdo->prepare(
                'INSERT INTO dispense_log (prescription_id, user_id, action) VALUES (:prescription_id, :user_id, :action)'
            );
            $logStmt->execute([
                'prescription_id' => $prescriptionId,
                'user_id' => (int) $user['id'],
                'action' => 'DISPENSED',
            ]);

            if ((bool) $medication['requires_id_check']) {
                Alert::create(
                    'ID_CHECK',
                    'ID check confirmed for ' . $customer['first_name'] . ' ' . $customer['last_name'] . ' before dispensing ' . $medication['name'] . '.',
                    $prescriptionId
                );
            }

            if ($remaining < (int) $stock['low_stock_threshold']) {
                Alert::create(
                    'LOW_STOCK',
                    'Low stock warning for ' . $medication['name'] . ' batch ' . $stock['batch_number'] . '. Remaining quantity: ' . $remaining . '.',
                    (int) $stock['id']
                );
            }

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Prescription dispensed successfully.',
                'prescription_id' => $prescriptionId,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Dispense failed: ' . $exception->getMessage());
            return ['success' => false, 'message' => 'Unable to dispense prescription. Please contact system support.'];
        }
    }

    /**
     * Counts prescriptions dispensed today.
     *
     * @return int
     */
    public static function countToday(): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM prescriptions WHERE DATE(dispensed_date) = CURDATE()');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Reports prescriptions dispensed between dates.
     *
     * @param string $from Start date.
     * @param string $to End date.
     * @return array<int, array<string, mixed>>
     */
    public static function reportByDate(string $from, string $to): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(self::baseReportSql() . ' WHERE p.prescribed_date BETWEEN :from_date AND :to_date ORDER BY p.prescribed_date DESC');
        $stmt->execute(['from_date' => $from, 'to_date' => $to]);

        return $stmt->fetchAll();
    }

    /**
     * Reports prescriptions for a customer.
     *
     * @param int $customerId Customer identifier.
     * @return array<int, array<string, mixed>>
     */
    public static function reportByCustomer(int $customerId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(self::baseReportSql() . ' WHERE p.customer_id = :customer_id ORDER BY p.prescribed_date DESC');
        $stmt->execute(['customer_id' => $customerId]);

        return $stmt->fetchAll();
    }

    /**
     * Reports prescriptions for a medication.
     *
     * @param int $medicationId Medication identifier.
     * @return array<int, array<string, mixed>>
     */
    public static function reportByMedication(int $medicationId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(self::baseReportSql() . ' WHERE p.medication_id = :medication_id ORDER BY p.prescribed_date DESC');
        $stmt->execute(['medication_id' => $medicationId]);

        return $stmt->fetchAll();
    }

    /**
     * Returns common report SQL body.
     *
     * @return string
     */
    private static function baseReportSql(): string
    {
        return "SELECT p.id, p.prescribed_date, p.dosage, p.quantity_prescribed, p.status, p.dispensed_date,
                       p.dispensed_by, c.nhs_number, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       m.name AS medication_name
                FROM prescriptions p
                INNER JOIN customers c ON c.id = p.customer_id
                INNER JOIN medications m ON m.id = p.medication_id";
    }

    /**
     * Finds the first matching allergy tag between a customer and medication.
     *
     * @param int $customerId Customer identifier.
     * @param string $medicationAllergyTags Comma-separated medication allergy tags.
     * @return string|null Matching allergy tag, or null when safe.
     */
    private static function findAllergyConflict(int $customerId, string $medicationAllergyTags): ?string
    {
        $customerTags = Customer::getAllergyTags($customerId);
        if ($customerTags === [] || trim($medicationAllergyTags) === '') {
            return null;
        }

        $customerLookup = array_fill_keys(array_map('strtolower', $customerTags), true);
        foreach (explode(',', $medicationAllergyTags) as $rawTag) {
            $tag = strtolower(trim($rawTag));
            if ($tag !== '' && isset($customerLookup[$tag])) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Calculates a customer's age from their date of birth.
     *
     * @param string $dateOfBirth Date of birth in Y-m-d format.
     * @return int Age in full years.
     */
    private static function calculateAge(string $dateOfBirth): int
    {
        $birthDate = new DateTime($dateOfBirth);
        $today = new DateTime('today');

        return (int) $birthDate->diff($today)->y;
    }

    /**
     * Validates a date string using ISO format.
     *
     * @param string $date Date string.
     * @return bool
     */
    private static function isDate(string $date): bool
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);

        return $parsed instanceof DateTime && $parsed->format('Y-m-d') === $date;
    }
}
