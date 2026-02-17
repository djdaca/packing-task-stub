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
	dim_min DECIMAL(10, 2) GENERATED ALWAYS AS (
		CASE
			WHEN width <= height AND width <= length THEN width
			WHEN height <= width AND height <= length THEN height
			ELSE length
		END
	) STORED,
	dim_mid DECIMAL(10, 2) GENERATED ALWAYS AS (
		CASE
			WHEN width <= height AND height <= length THEN height
			WHEN width <= length AND length <= height THEN length
			WHEN height <= width AND width <= length THEN width
			WHEN height <= length AND length <= width THEN length
			WHEN length <= width AND width <= height THEN width
			ELSE height
		END
	) STORED,
	dim_max DECIMAL(10, 2) GENERATED ALWAYS AS (
		CASE
			WHEN width >= height AND width >= length THEN width
			WHEN height >= width AND height >= length THEN height
			ELSE length
		END
	) STORED,
	volume DECIMAL(20, 6) GENERATED ALWAYS AS (width * height * length) STORED,
	INDEX idx_packaging_dims_weight_volume_id (dim_min, dim_mid, dim_max, max_weight, volume, id),
	INDEX idx_packaging_volume_id (volume, id)
);

CREATE TABLE IF NOT EXISTS packing_calculation_cache (
	id VARCHAR(64) NOT NULL,
	selected_box_id INT UNSIGNED NOT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	FOREIGN KEY (selected_box_id) REFERENCES packaging(id)
);

BEGIN;
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (1, 2.5, 3.0, 1.0, 20);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (2, 4.0, 4.0, 4.0, 20);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (3, 2.0, 2.0, 10.0, 20);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (4, 5.5, 6.0, 7.5, 30);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (5, 9.0, 9.0, 9.0, 30);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (6, 1.0, 1.0, 1.0, 5);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (7, 2.0, 3.0, 4.0, 10);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (8, 3.0, 5.0, 8.0, 15);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (9, 6.0, 6.0, 12.0, 25);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (10, 10.0, 12.0, 14.0, 40);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (11, 12.5, 15.0, 18.0, 60);
INSERT INTO packaging (id, width, height, length, max_weight) VALUES (12, 20.0, 20.0, 20.0, 80);
COMMIT;

CREATE DATABASE IF NOT EXISTS test_packing;

CREATE TABLE test_packing.packaging LIKE packing.packaging;
CREATE TABLE test_packing.packing_calculation_cache LIKE packing.packing_calculation_cache;

INSERT INTO test_packing.packaging (id, width, height, length, max_weight)
SELECT id, width, height, length, max_weight
FROM packing.packaging;
INSERT INTO test_packing.packing_calculation_cache SELECT * FROM packing.packing_calculation_cache;
