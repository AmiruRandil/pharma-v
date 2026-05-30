<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Medication data access service.
 */
final class Medication
{
    /**
     * Lists all medications available for prescribing.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT m.id, m.name, m.description, m.requires_id_check, m.min_age, m.max_dispense_qty,
                    COALESCE(GROUP_CONCAT(ma.allergy_name ORDER BY ma.allergy_name SEPARATOR ', '), '') AS allergy_tags
             FROM medications m
             LEFT JOIN medication_allergies ma ON ma.medication_id = m.id
             GROUP BY m.id, m.name, m.description, m.requires_id_check, m.min_age, m.max_dispense_qty
             ORDER BY m.name"
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Fetches a single medication record.
     *
     * @param int $id Medication identifier.
     * @return array<string, mixed>|null
     */
    public static function getById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT m.id, m.name, m.description, m.requires_id_check, m.min_age, m.max_dispense_qty,
                    COALESCE(GROUP_CONCAT(ma.allergy_name ORDER BY ma.allergy_name SEPARATOR ', '), '') AS allergy_tags
             FROM medications m
             LEFT JOIN medication_allergies ma ON ma.medication_id = m.id
             WHERE m.id = :id
             GROUP BY m.id, m.name, m.description, m.requires_id_check, m.min_age, m.max_dispense_qty"
        );
        $stmt->execute(['id' => $id]);
        $medication = $stmt->fetch();

        return $medication ?: null;
    }

    /**
     * Creates a medication catalogue entry.
     *
     * @param array<string, mixed> $data Medication input.
     * @return array<string, mixed>
     */
    public static function add(array $data): array
    {
        $validation = self::validate($data);
        if ($validation !== null) {
            return $validation;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO medications (name, description, requires_id_check, min_age, max_dispense_qty)
             VALUES (:name, :description, :requires_id_check, :min_age, :max_dispense_qty)'
        );
        $stmt->execute(self::normalise($data));
        $medicationId = (int) $pdo->lastInsertId();
        self::setMedicationAllergyTags($medicationId, (string) ($data['allergy_tags'] ?? ''));

        return [
            'success' => true,
            'message' => 'Medication added successfully.',
            'medication_id' => $medicationId,
        ];
    }

    /**
     * Updates a medication catalogue entry.
     *
     * @param int $id Medication identifier.
     * @param array<string, mixed> $data Medication input.
     * @return array<string, mixed>
     */
    public static function update(int $id, array $data): array
    {
        if ($id <= 0 || self::getById($id) === null) {
            return ['success' => false, 'message' => 'Medication not found.'];
        }

        $validation = self::validate($data);
        if ($validation !== null) {
            return $validation;
        }

        $params = self::normalise($data);
        $params['id'] = $id;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE medications
             SET name = :name,
                 description = :description,
                 requires_id_check = :requires_id_check,
                 min_age = :min_age,
                 max_dispense_qty = :max_dispense_qty
             WHERE id = :id'
        );
        $stmt->execute($params);
        self::setMedicationAllergyTags($id, (string) ($data['allergy_tags'] ?? ''));

        return ['success' => true, 'message' => 'Medication updated successfully.'];
    }

    /**
     * Deletes a medication only when no stock or prescriptions reference it.
     *
     * @param int $id Medication identifier.
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        if ($id <= 0 || self::getById($id) === null) {
            return ['success' => false, 'message' => 'Medication not found.'];
        }

        // Business rule: catalogue rows used by stock or prescriptions must remain for audit and reporting.
        if (self::referenceCount($id, 'inventory') > 0 || self::referenceCount($id, 'prescriptions') > 0) {
            return ['success' => false, 'message' => 'Medication is used by stock or prescriptions and cannot be deleted.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM medications WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return ['success' => true, 'message' => 'Medication deleted successfully.'];
    }

    /**
     * Replaces structured allergy tags for a medication.
     *
     * @param int $medicationId Medication identifier.
     * @param string $allergyTags Comma-separated allergy tags.
     * @return void
     */
    public static function setMedicationAllergyTags(int $medicationId, string $allergyTags): void
    {
        $pdo = Database::getConnection();
        $delete = $pdo->prepare('DELETE FROM medication_allergies WHERE medication_id = :medication_id');
        $delete->execute(['medication_id' => $medicationId]);

        $tags = self::parseAllergyTags($allergyTags);
        if ($tags === []) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO medication_allergies (medication_id, allergy_name) VALUES (:medication_id, :allergy_name)'
        );

        foreach ($tags as $tag) {
            $insert->execute([
                'medication_id' => $medicationId,
                'allergy_name' => $tag,
            ]);
        }
    }

    /**
     * Validates medication input.
     *
     * @param array<string, mixed> $data Medication input.
     * @return array<string, mixed>|null Error payload or null when valid.
     */
    private static function validate(array $data): ?array
    {
        if (trim((string) ($data['name'] ?? '')) === '' || trim((string) ($data['description'] ?? '')) === '') {
            return ['success' => false, 'message' => 'Medication name and description are required.'];
        }

        if (strlen(trim((string) $data['name'])) > 120) {
            return ['success' => false, 'message' => 'Medication name is too long.'];
        }

        foreach (['min_age', 'max_dispense_qty'] as $field) {
            if (($data[$field] ?? '') !== '' && $data[$field] !== null && (int) $data[$field] < 0) {
                return ['success' => false, 'message' => 'Medication age and quantity limits must be zero or greater.'];
            }
        }

        return null;
    }

    /**
     * Normalises medication input for prepared statements.
     *
     * @param array<string, mixed> $data Medication input.
     * @return array<string, mixed>
     */
    private static function normalise(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'description' => trim((string) $data['description']),
            'requires_id_check' => filter_var($data['requires_id_check'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'min_age' => self::nullableUnsignedInt($data['min_age'] ?? null),
            'max_dispense_qty' => self::nullableUnsignedInt($data['max_dispense_qty'] ?? null),
        ];
    }

    /**
     * Converts empty input to null or returns an unsigned integer.
     *
     * @param mixed $value Raw value.
     * @return int|null
     */
    private static function nullableUnsignedInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * Parses comma-separated allergy tags into lowercase structured tags.
     *
     * @param string $allergyTags Comma-separated medication allergy tags.
     * @return array<int, string>
     */
    private static function parseAllergyTags(string $allergyTags): array
    {
        $tags = [];
        foreach (explode(',', $allergyTags) as $rawTag) {
            $tag = strtolower(trim($rawTag));
            if ($tag === '') {
                continue;
            }
            $tags[$tag] = $tag;
        }

        return array_values($tags);
    }

    /**
     * Counts medication references in known dependent tables.
     *
     * @param int $id Medication identifier.
     * @param string $table Table name from a controlled internal list.
     * @return int
     */
    private static function referenceCount(int $id, string $table): int
    {
        $allowedTables = ['inventory', 'prescriptions'];
        if (!in_array($table, $allowedTables, true)) {
            return 0;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE medication_id = :id");
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn();
    }
}
