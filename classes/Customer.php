<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Customer data access and search logic.
 */
final class Customer
{
    /**
     * Lists customers for staff administration screens.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, nhs_number, title, first_name, last_name, postcode, date_of_birth,
                    allergies, medical_conditions, registered_date
             FROM customers
             ORDER BY registered_date DESC, last_name, first_name
             LIMIT 100'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Searches customers by NHS number, first name, or last name.
     *
     * @param string $term Search term entered by staff.
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $pdo = Database::getConnection();
        $like = '%' . $term . '%';
        $stmt = $pdo->prepare(
            'SELECT id, nhs_number, title, first_name, last_name, postcode, date_of_birth, allergies, medical_conditions
             FROM customers
             WHERE nhs_number LIKE :term1 OR first_name LIKE :term2 OR last_name LIKE :term3
             ORDER BY last_name, first_name
             LIMIT 20'
        );
        $stmt->execute([
            'term1' => $like,
            'term2' => $like,
            'term3' => $like,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Fetches full customer details, including sensitive clinical fields.
     *
     * @param int $id Customer identifier.
     * @return array<string, mixed>|null
     */
    public static function getById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, nhs_number, title, first_name, last_name, address, postcode, date_of_birth,
                    allergies, medical_conditions, registered_date
             FROM customers
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $customer = $stmt->fetch();

        return $customer ?: null;
    }

    /**
     * Creates a new customer registration record.
     *
     * @param array<string, mixed> $data Customer input from the API request.
     * @return array<string, mixed>
     */
    public static function add(array $data): array
    {
        $validation = self::validate($data);
        if ($validation !== null) {
            return $validation;
        }

        if (self::nhsNumberExists((string) $data['nhs_number'])) {
            return ['success' => false, 'message' => 'NHS number already exists.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO customers
                (nhs_number, title, first_name, last_name, address, postcode, date_of_birth, allergies, medical_conditions, registered_date)
             VALUES
                (:nhs_number, :title, :first_name, :last_name, :address, :postcode, :date_of_birth, :allergies, :medical_conditions, NOW())'
        );
        $stmt->execute(self::normalise($data));
        $customerId = (int) $pdo->lastInsertId();
        self::setAllergyTags($customerId, (string) ($data['allergies'] ?? ''));

        return [
            'success' => true,
            'message' => 'Customer registered successfully.',
            'customer_id' => $customerId,
        ];
    }

    /**
     * Updates an existing customer record.
     *
     * @param int $id Customer identifier.
     * @param array<string, mixed> $data Customer input from the API request.
     * @return array<string, mixed>
     */
    public static function update(int $id, array $data): array
    {
        if ($id <= 0 || self::getById($id) === null) {
            return ['success' => false, 'message' => 'Customer not found.'];
        }

        $validation = self::validate($data);
        if ($validation !== null) {
            return $validation;
        }

        if (self::nhsNumberExists((string) $data['nhs_number'], $id)) {
            return ['success' => false, 'message' => 'NHS number already belongs to another customer.'];
        }

        $params = self::normalise($data);
        $params['id'] = $id;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE customers
             SET nhs_number = :nhs_number,
                 title = :title,
                 first_name = :first_name,
                 last_name = :last_name,
                 address = :address,
                 postcode = :postcode,
                 date_of_birth = :date_of_birth,
                 allergies = :allergies,
                 medical_conditions = :medical_conditions
             WHERE id = :id'
        );
        $stmt->execute($params);
        self::setAllergyTags($id, (string) ($data['allergies'] ?? ''));

        return ['success' => true, 'message' => 'Customer updated successfully.'];
    }

    /**
     * Replaces structured allergy tags for a customer from comma-separated clinical text.
     *
     * @param int $customerId Customer identifier.
     * @param string $allergiesText Comma-separated allergy text.
     * @return void
     */
    public static function setAllergyTags(int $customerId, string $allergiesText): void
    {
        $pdo = Database::getConnection();
        $delete = $pdo->prepare('DELETE FROM customer_allergies WHERE customer_id = :customer_id');
        $delete->execute(['customer_id' => $customerId]);

        $tags = self::parseAllergyTags($allergiesText);
        if ($tags === []) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO customer_allergies (customer_id, allergy_name) VALUES (:customer_id, :allergy_name)'
        );

        foreach ($tags as $tag) {
            $insert->execute([
                'customer_id' => $customerId,
                'allergy_name' => $tag,
            ]);
        }
    }

    /**
     * Returns structured allergy tags for a customer.
     *
     * @param int $customerId Customer identifier.
     * @return array<int, string>
     */
    public static function getAllergyTags(int $customerId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT allergy_name FROM customer_allergies WHERE customer_id = :customer_id ORDER BY allergy_name'
        );
        $stmt->execute(['customer_id' => $customerId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Deletes a customer only when no prescriptions exist for that customer.
     *
     * @param int $id Customer identifier.
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        if ($id <= 0 || self::getById($id) === null) {
            return ['success' => false, 'message' => 'Customer not found.'];
        }

        // Business rule: do not remove a patient record that has prescription history, because audit records matter.
        if (self::prescriptionCount($id) > 0) {
            return ['success' => false, 'message' => 'Customer has prescription history and cannot be deleted in this prototype.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM customers WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return ['success' => true, 'message' => 'Customer deleted successfully.'];
    }

    /**
     * Counts all registered customers for dashboard metrics.
     *
     * @return int
     */
    public static function countAll(): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Validates customer data before insert or update.
     *
     * @param array<string, mixed> $data Customer input.
     * @return array<string, mixed>|null Error payload or null when valid.
     */
    private static function validate(array $data): ?array
    {
        $required = ['nhs_number', 'title', 'first_name', 'last_name', 'address', 'postcode', 'date_of_birth'];
        foreach ($required as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                return ['success' => false, 'message' => 'Please complete all required customer fields.'];
            }
        }

        $date = DateTime::createFromFormat('Y-m-d', (string) $data['date_of_birth']);
        if (!$date || $date->format('Y-m-d') !== (string) $data['date_of_birth']) {
            return ['success' => false, 'message' => 'Date of birth must be a valid YYYY-MM-DD date.'];
        }

        if ($date > new DateTime('today')) {
            return ['success' => false, 'message' => 'Date of birth cannot be in the future.'];
        }

        if (strlen(trim((string) $data['nhs_number'])) > 20 || strlen(trim((string) $data['postcode'])) > 10) {
            return ['success' => false, 'message' => 'NHS number or postcode is too long.'];
        }

        return null;
    }

    /**
     * Normalises customer input for prepared statements.
     *
     * @param array<string, mixed> $data Customer input.
     * @return array<string, string>
     */
    private static function normalise(array $data): array
    {
        return [
            'nhs_number' => strtoupper(trim((string) $data['nhs_number'])),
            'title' => trim((string) $data['title']),
            'first_name' => trim((string) $data['first_name']),
            'last_name' => trim((string) $data['last_name']),
            'address' => trim((string) $data['address']),
            'postcode' => strtoupper(trim((string) $data['postcode'])),
            'date_of_birth' => trim((string) $data['date_of_birth']),
            'allergies' => trim((string) ($data['allergies'] ?? 'None recorded')),
            'medical_conditions' => trim((string) ($data['medical_conditions'] ?? 'None recorded')),
        ];
    }

    /**
     * Parses comma-separated allergy text into lowercase structured tags.
     *
     * @param string $allergiesText Allergy text from the customer form.
     * @return array<int, string>
     */
    private static function parseAllergyTags(string $allergiesText): array
    {
        $ignore = ['none', 'none recorded', 'no known allergies', 'nka', 'nil'];
        $tags = [];
        foreach (explode(',', $allergiesText) as $rawTag) {
            $tag = strtolower(trim($rawTag));
            if ($tag === '' || in_array($tag, $ignore, true)) {
                continue;
            }
            $tags[$tag] = $tag;
        }

        return array_values($tags);
    }

    /**
     * Checks whether an NHS number already exists.
     *
     * @param string $nhsNumber NHS number.
     * @param int|null $excludeId Customer ID to exclude during updates.
     * @return bool
     */
    private static function nhsNumberExists(string $nhsNumber, ?int $excludeId = null): bool
    {
        $pdo = Database::getConnection();
        if ($excludeId === null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE nhs_number = :nhs_number');
            $stmt->execute(['nhs_number' => strtoupper(trim($nhsNumber))]);

            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE nhs_number = :nhs_number AND id <> :id');
        $stmt->execute(['nhs_number' => strtoupper(trim($nhsNumber)), 'id' => $excludeId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Counts prescriptions for a customer before destructive customer actions.
     *
     * @param int $id Customer identifier.
     * @return int
     */
    private static function prescriptionCount(int $id): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM prescriptions WHERE customer_id = :id');
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn();
    }
}
