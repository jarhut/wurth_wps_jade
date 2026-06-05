-- 1. Setup/Recreate clean tables matching final requirements
DROP TABLE IF EXISTS claim_items;
DROP TABLE IF EXISTS claims;
DROP TABLE IF EXISTS users;

-- 2. Create the Users Table with explicit authorization roles
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    account_no VARCHAR(50) NOT NULL,
    role ENUM('claimant', 'finance') DEFAULT 'claimant',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Create the Claims Table with approval log workflows
CREATE TABLE claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_name VARCHAR(255) NOT NULL,
    status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
    total_aed DECIMAL(10, 2) DEFAULT 0.00,
    finance_comments TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 4. Create the Claim Items Table matching exact Excel columns
CREATE TABLE claim_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    category VARCHAR(100) NOT NULL,
    cost_type_name VARCHAR(150) NOT NULL,
    cost_type_nr VARCHAR(20) NOT NULL,
    pillar_name VARCHAR(100) NOT NULL,
    expense_date DATE NOT NULL,
    description TEXT NOT NULL,
    country VARCHAR(100) NOT NULL,
    receipt_no VARCHAR(50),
    original_amount DECIMAL(10, 2) NOT NULL,
    original_currency VARCHAR(3) NOT NULL,
    exchange_rate DECIMAL(10, 6) NOT NULL,
    aed_amount DECIMAL(10, 2) NOT NULL,
    receipt_path TEXT DEFAULT NULL,
    FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE
);

-- 5. Seed Test Profiles (BCRYPT hash matches "Password123")
INSERT INTO users (email, password_hash, first_name, last_name, account_no, role) 
VALUES 
('claimant1@wurth-wps.com', '$2y$10$vK3D1x9BclM.V087P/3JeuK.z6M3rR7r8S0p6p0R.H7h6F.uG7Kqq', 'John', 'Doe', 'AE12345678901234567890', 'claimant'),
('finance1@wurth-wps.com', '$2y$10$vK3D1x9BclM.V087P/3JeuK.z6M3rR7r8S0p6p0R.H7h6F.uG7Kqq', 'Sarah', 'Smith', 'AE09876543210987654321', 'finance');