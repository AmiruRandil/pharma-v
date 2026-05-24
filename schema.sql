CREATE DATABASE IF NOT EXISTS `pms_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pms_db`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `dispense_log`;
DROP TABLE IF EXISTS `alerts`;
DROP TABLE IF EXISTS `prescriptions`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `medication_allergies`;
DROP TABLE IF EXISTS `customer_allergies`;
DROP TABLE IF EXISTS `medications`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `initials` VARCHAR(5) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nhs_number` VARCHAR(20) NOT NULL UNIQUE,
  `title` VARCHAR(10) NOT NULL,
  `first_name` VARCHAR(80) NOT NULL,
  `last_name` VARCHAR(80) NOT NULL,
  `address` TEXT NOT NULL,
  `postcode` VARCHAR(10) NOT NULL,
  `date_of_birth` DATE NOT NULL,
  `allergies` TEXT NOT NULL,
  `medical_conditions` TEXT NOT NULL,
  `registered_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `medications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `description` TEXT NOT NULL,
  `requires_id_check` BOOLEAN NOT NULL DEFAULT FALSE,
  `min_age` INT UNSIGNED DEFAULT NULL,
  `max_dispense_qty` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `customer_allergies` (
  `customer_id` INT UNSIGNED NOT NULL,
  `allergy_name` VARCHAR(120) NOT NULL,
  PRIMARY KEY (`customer_id`, `allergy_name`),
  CONSTRAINT `fk_customer_allergies_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `medication_allergies` (
  `medication_id` INT UNSIGNED NOT NULL,
  `allergy_name` VARCHAR(120) NOT NULL,
  PRIMARY KEY (`medication_id`, `allergy_name`),
  CONSTRAINT `fk_medication_allergies_medication` FOREIGN KEY (`medication_id`) REFERENCES `medications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `inventory` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `medication_id` INT UNSIGNED NOT NULL,
  `store_id` INT NOT NULL DEFAULT 1,
  `batch_number` VARCHAR(60) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 0,
  `expiry_date` DATE NOT NULL,
  `low_stock_threshold` INT NOT NULL DEFAULT 10,
  PRIMARY KEY (`id`),
  INDEX `idx_inventory_medication` (`medication_id`),
  CONSTRAINT `fk_inventory_medication` FOREIGN KEY (`medication_id`) REFERENCES `medications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `prescriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `medication_id` INT UNSIGNED NOT NULL,
  `prescribed_date` DATE NOT NULL,
  `dosage` VARCHAR(120) NOT NULL,
  `quantity_prescribed` INT NOT NULL,
  `status` ENUM('pending','dispensed','rejected') NOT NULL DEFAULT 'pending',
  `dispensed_date` DATETIME DEFAULT NULL,
  `dispensed_by` VARCHAR(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_prescriptions_customer` (`customer_id`),
  INDEX `idx_prescriptions_medication` (`medication_id`),
  CONSTRAINT `fk_prescription_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prescription_medication` FOREIGN KEY (`medication_id`) REFERENCES `medications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `alerts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('ID_CHECK','LOW_STOCK','EXPIRED') NOT NULL,
  `message` TEXT NOT NULL,
  `reference_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledged` BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (`id`),
  INDEX `idx_alerts_reference` (`reference_id`),
  INDEX `idx_alerts_acknowledged` (`acknowledged`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `dispense_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `prescription_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(80) NOT NULL,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_dispense_log_prescription` (`prescription_id`),
  INDEX `idx_dispense_log_user` (`user_id`),
  CONSTRAINT `fk_dispense_log_prescription` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dispense_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`username`, `password_hash`, `initials`) VALUES
('admin', '$2y$12$DHKBmYpijFnIO1z71iHl5uqmndZ2AfW.xcA48saNlIHGaU5pp9Z1y', 'AD'),
('staff1', '$2y$12$SQFNg9mH3Z1AqkwRr1j1EuhQK7uUN4j/EQHohOarIDHwxT/PgiXQK', 'ST');

INSERT INTO `customers` (`nhs_number`, `title`, `first_name`, `last_name`, `address`, `postcode`, `date_of_birth`, `allergies`, `medical_conditions`, `registered_date`) VALUES
('NHS0001111', 'Mr', 'John', 'Smith', '12 High Street, Manchester', 'M1 1AA', '1980-05-10', 'Penicillin', 'Hypertension', '2024-01-05 09:30:00'),
('NHS0002222', 'Mrs', 'Sarah', 'Jones', '34 Station Road, Liverpool', 'L2 2BB', '1975-09-22', 'None recorded', 'Type 2 Diabetes', '2024-02-20 11:15:00'),
('NHS0003333', 'Miss', 'Emily', 'Clark', '8 Park Lane, Leeds', 'LS3 4CC', '1990-01-12', 'Latex', 'Asthma', '2024-03-18 14:45:00'),
('NHS0004444', 'Mr', 'David', 'Patel', '16 Oak Avenue, Birmingham', 'B4 4DD', '1985-12-05', 'Sulfa drugs', 'Arthritis', '2024-04-01 08:10:00'),
('NHS0005555', 'Ms', 'Lisa', 'Brown', '45 Elm Street, Bristol', 'BS5 5EE', '1992-07-19', 'None recorded', 'High cholesterol', '2024-04-25 16:00:00'),
('NHS0006666', 'Mr', 'Paul', 'Green', '27 Willow Road, London', 'SW6 6FF', '1970-03-30', 'Aspirin', 'Heart disease', '2024-05-04 10:20:00'),
('NHS0007777', 'Mrs', 'Anna', 'White', '11 Church Road, Sheffield', 'S7 7GG', '1988-11-02', 'Nuts', 'Eczema', '2024-05-12 12:40:00'),
('NHS0008888', 'Miss', 'Rachel', 'Black', '3 River Street, Cardiff', 'CF10 8HH', '1995-06-21', 'Penicillin', 'IBS', '2024-06-08 09:00:00'),
('NHS0009999', 'Mr', 'Mark', 'Lewis', '22 Hill Road, Newcastle', 'NE9 9JJ', '1983-02-11', 'None recorded', 'None recorded', '2024-06-15 13:30:00'),
('NHS0010000', 'Ms', 'Jane', 'Turner', '90 Market Street, Glasgow', 'G1 0KK', '1978-08-08', 'Ibuprofen', 'COPD', '2024-06-22 15:05:00');

INSERT INTO `medications` (`name`, `description`, `requires_id_check`, `min_age`, `max_dispense_qty`) VALUES
('Paracetamol 500mg', 'Standard pain relief tablet.', FALSE, NULL, 100),
('Amoxicillin 250mg', 'Oral antibiotic for bacterial infections.', FALSE, NULL, 60),
('Salbutamol inhaler', 'Bronchodilator for asthma relief.', FALSE, NULL, 2),
('Diazepam 5mg', 'Controlled benzodiazepine requiring ID check.', TRUE, 18, 28),
('Codeine Phosphate 30mg', 'Controlled pain relief requiring ID check.', TRUE, 12, 30);

INSERT INTO `customer_allergies` (`customer_id`, `allergy_name`) VALUES
(1, 'penicillin'),
(3, 'latex'),
(4, 'sulfa drugs'),
(6, 'aspirin'),
(7, 'nuts'),
(8, 'penicillin'),
(10, 'ibuprofen');

INSERT INTO `medication_allergies` (`medication_id`, `allergy_name`) VALUES
(2, 'penicillin'),
(5, 'codeine');

-- Example seed statements for future data:
-- INSERT INTO `customer_allergies` (`customer_id`, `allergy_name`) VALUES (1, 'penicillin');
-- INSERT INTO `medication_allergies` (`medication_id`, `allergy_name`) VALUES (2, 'penicillin');
-- UPDATE `medications` SET `min_age` = 18, `max_dispense_qty` = 28 WHERE `name` = 'Diazepam 5mg';

INSERT INTO `inventory` (`medication_id`, `store_id`, `batch_number`, `quantity`, `expiry_date`, `low_stock_threshold`) VALUES
(1, 1, 'PAR-2026-A', 120, '2026-12-31', 15),
(2, 1, 'AMX-2026-B', 30, '2026-09-15', 10),
(3, 1, 'SAL-2025-C', 8, '2025-11-05', 10),
(4, 1, 'DIA-2026-D', 20, '2026-08-20', 5),
(5, 1, 'COD-2024-E', 5, '2024-06-30', 5);
