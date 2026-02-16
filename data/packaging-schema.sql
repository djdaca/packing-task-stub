-- Vytvoření uživatele 'packing' s omezenými právy
CREATE USER IF NOT EXISTS 'packing'@'%' IDENTIFIED BY 'packing';
GRANT SELECT, INSERT, UPDATE, DELETE ON packing.* TO 'packing'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON test_packing.* TO 'packing'@'%';
FLUSH PRIVILEGES;

USE packing;

CREATE TABLE IF NOT EXISTS packaging (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	width DECIMAL(10, 2) UNSIGNED NOT NULL,
	height DECIMAL(10, 2) UNSIGNED NOT NULL,
	length DECIMAL(10, 2) UNSIGNED NOT NULL,
	max_weight INT UNSIGNED NOT NULL
);

CREATE TABLE IF NOT EXISTS packing_calculation_cache (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	input_hash VARCHAR(64) NOT NULL,
	selected_box_id INT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL,
	updated_at DATETIME NOT NULL,
	UNIQUE KEY uniq_input_hash (input_hash),
	FOREIGN KEY (selected_box_id) REFERENCES packaging(id)
);

BEGIN;
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (1, 2.5, 3.0, 1.0, 20);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (2, 4.0, 4.0, 4.0, 20);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (3, 2.0, 2.0, 10.0, 20);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (4, 5.5, 6.0, 7.5, 30);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (5, 9.0, 9.0, 9.0, 30);
COMMIT;

CREATE DATABASE IF NOT EXISTS test_packing;

-- Klonování tabulek (struktura)
CREATE TABLE test_packing.packaging LIKE packing.packaging;
CREATE TABLE test_packing.packing_calculation_cache LIKE packing.packing_calculation_cache;

-- Klonování dat
INSERT INTO test_packing.packaging SELECT * FROM packing.packaging;
INSERT INTO test_packing.packing_calculation_cache SELECT * FROM packing.packing_calculation_cache;
