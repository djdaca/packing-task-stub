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
	max_weight DECIMAL(10, 2) UNSIGNED NOT NULL,
	dim_min DECIMAL(10, 2) UNSIGNED NOT NULL,
	dim_mid DECIMAL(10, 2) UNSIGNED NOT NULL,
	dim_max DECIMAL(10, 2) UNSIGNED NOT NULL,
	INDEX idx_packaging_dims_weight (dim_min, dim_mid, dim_max, max_weight)
);

CREATE TABLE IF NOT EXISTS packing_calculation_cache (
	id VARCHAR(64) NOT NULL,
	selected_box_id INT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	FOREIGN KEY (selected_box_id) REFERENCES packaging(id)
);

BEGIN;
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (1, 2.5, 3.0, 1.0, 20, 1.0, 2.5, 3.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (2, 4.0, 4.0, 4.0, 20, 4.0, 4.0, 4.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (3, 2.0, 2.0, 10.0, 20, 2.0, 2.0, 10.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (4, 5.5, 6.0, 7.5, 30, 5.5, 6.0, 7.5);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (5, 9.0, 9.0, 9.0, 30, 9.0, 9.0, 9.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (6, 1.0, 1.0, 1.0, 5, 1.0, 1.0, 1.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (7, 2.0, 3.0, 4.0, 10, 2.0, 3.0, 4.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (8, 3.0, 5.0, 8.0, 15, 3.0, 5.0, 8.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (9, 6.0, 6.0, 12.0, 25, 6.0, 6.0, 12.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (10, 10.0, 12.0, 14.0, 40, 10.0, 12.0, 14.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (11, 12.5, 15.0, 18.0, 60, 12.5, 15.0, 18.0);
INSERT INTO packaging (id, width, height, length, max_weight, dim_min, dim_mid, dim_max) VALUES (12, 20.0, 20.0, 20.0, 80, 20.0, 20.0, 20.0);
COMMIT;

CREATE DATABASE IF NOT EXISTS test_packing;

CREATE TABLE test_packing.packaging LIKE packing.packaging;
CREATE TABLE test_packing.packing_calculation_cache LIKE packing.packing_calculation_cache;

INSERT INTO test_packing.packaging SELECT * FROM packing.packaging;
INSERT INTO test_packing.packing_calculation_cache SELECT * FROM packing.packing_calculation_cache;
