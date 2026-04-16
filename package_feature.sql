-- Package feature schema rollout
-- Date: 2026-04-15

CREATE TABLE IF NOT EXISTS test_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_code VARCHAR(50) NOT NULL UNIQUE,
    package_name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    total_base_price DECIMAL(10,2) DEFAULT 0.00,
    package_price DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    discount_percent DECIMAL(5,2) DEFAULT 0.00,
    status ENUM('active','inactive') DEFAULT 'active',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS package_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    test_id INT NOT NULL,
    base_test_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    package_test_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE CASCADE,
    FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE,
    UNIQUE KEY unique_package_test (package_id, test_id)
);

ALTER TABLE bill_items
    MODIFY COLUMN test_id INT NULL,
    ADD COLUMN package_id INT DEFAULT NULL,
    ADD COLUMN is_package TINYINT(1) DEFAULT 0,
    ADD COLUMN item_type ENUM('test','package') DEFAULT 'test',
    ADD COLUMN package_name VARCHAR(255) DEFAULT NULL,
    ADD COLUMN package_discount DECIMAL(10,2) DEFAULT 0.00;

ALTER TABLE bill_items
    ADD CONSTRAINT fk_bill_items_package
    FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS bill_package_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_id INT NOT NULL,
    bill_item_id INT NOT NULL,
    package_id INT NOT NULL,
    test_id INT NOT NULL,
    test_name VARCHAR(255) NOT NULL,
    base_test_price DECIMAL(10,2) DEFAULT 0.00,
    package_test_price DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
    FOREIGN KEY (bill_item_id) REFERENCES bill_items(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE CASCADE,
    FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
);

CREATE INDEX idx_package_name ON test_packages(package_name);
CREATE INDEX idx_package_status ON test_packages(status);
CREATE INDEX idx_bill_items_package ON bill_items(package_id);
CREATE INDEX idx_bill_package_items_package ON bill_package_items(package_id);
