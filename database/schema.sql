-- JHCSC Dumingag Campus Sports Equipment Inventory System
-- Database Schema

CREATE DATABASE IF NOT EXISTS jhcsc_sports_inventory
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE jhcsc_sports_inventory;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    student_id VARCHAR(20) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    role ENUM('admin', 'coordinator', 'staff', 'student') NOT NULL DEFAULT 'student',
    avatar VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Equipment categories
CREATE TABLE IF NOT EXISTS equipment_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Equipment inventory
CREATE TABLE IF NOT EXISTS equipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    barcode VARCHAR(50) DEFAULT NULL UNIQUE,
    quantity_total INT NOT NULL DEFAULT 0,
    quantity_available INT NOT NULL DEFAULT 0,
    quantity_borrowed INT NOT NULL DEFAULT 0,
    quantity_reserved INT NOT NULL DEFAULT 0,
    quantity_damaged INT NOT NULL DEFAULT 0,
    quantity_maintenance INT NOT NULL DEFAULT 0,
    `condition` ENUM('excellent', 'good', 'fair', 'poor', 'damaged') NOT NULL DEFAULT 'good',
    location VARCHAR(100) DEFAULT 'Sports Office',
    image VARCHAR(255) DEFAULT NULL,
    low_stock_threshold INT NOT NULL DEFAULT 3,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES equipment_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Borrowing requests
CREATE TABLE IF NOT EXISTS borrowing_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(20) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    equipment_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    borrow_date DATE NOT NULL,
    return_date DATE NOT NULL,
    purpose ENUM('pe_class', 'training', 'tournament', 'practice', 'event', 'other') NOT NULL,
    purpose_details TEXT,
    status ENUM('pending', 'approved', 'rejected', 'cancelled', 'checked_out', 'returned', 'overdue') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE RESTRICT,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Check-out and return transactions
CREATE TABLE IF NOT EXISTS borrowing_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    transaction_type ENUM('checkout', 'return') NOT NULL,
    quantity INT NOT NULL,
    processed_by INT NOT NULL,
    borrower_verified TINYINT(1) DEFAULT 0,
    condition_on_return ENUM('excellent', 'good', 'fair', 'poor', 'damaged') DEFAULT NULL,
    damage_notes TEXT,
    transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (request_id) REFERENCES borrowing_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Damage reports
CREATE TABLE IF NOT EXISTS damage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    request_id INT DEFAULT NULL,
    reported_by INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    damage_type ENUM('minor', 'major', 'lost', 'stolen') NOT NULL,
    description TEXT NOT NULL,
    status ENUM('reported', 'under_review', 'resolved', 'written_off') NOT NULL DEFAULT 'reported',
    resolution_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME DEFAULT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE RESTRICT,
    FOREIGN KEY (request_id) REFERENCES borrowing_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Maintenance schedule
CREATE TABLE IF NOT EXISTS maintenance_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    scheduled_date DATE NOT NULL,
    maintenance_type ENUM('routine', 'repair', 'inspection', 'cleaning') NOT NULL,
    description TEXT,
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    completed_at DATETIME DEFAULT NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'info',
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Audit logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    old_values JSON DEFAULT NULL,
    new_values JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Login history
CREATE TABLE IF NOT EXISTS login_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT,
    status ENUM('success', 'failed') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- System settings
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    setting_type ENUM('string', 'integer', 'boolean', 'json') NOT NULL DEFAULT 'string',
    description TEXT,
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Insert default categories
INSERT INTO equipment_categories (name, description) VALUES
('Basketball', 'Basketball equipment and accessories'),
('Volleyball', 'Volleyball equipment and accessories'),
('Badminton', 'Badminton rackets, shuttlecocks, and nets'),
('Athletics', 'Track and field equipment'),
('Football', 'Football/soccer equipment'),
('Table Tennis', 'Table tennis paddles, balls, and tables'),
('Swimming', 'Swimming gear and accessories'),
('General Sports', 'General sports equipment and accessories');

-- Insert default admin user (password: admin123)
INSERT INTO users (username, email, password, first_name, last_name, role) VALUES
('admin', 'admin@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'System', 'Administrator', 'admin'),
('coordinator', 'coordinator@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Sports', 'Coordinator', 'coordinator'),
('staff', 'staff@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Sports', 'Staff', 'staff'),
('student', 'student@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Juan', 'Dela Cruz', 'student');

-- Insert sample equipment
INSERT INTO equipment (category_id, name, description, barcode, quantity_total, quantity_available, `condition`, location) VALUES
(1, 'Basketball (Official Size)', 'Spalding official size basketball', 'BB-001', 20, 20, 'good', 'Sports Storage Room A'),
(1, 'Basketball Hoop Net', 'Standard basketball net replacement', 'BB-002', 10, 10, 'good', 'Sports Storage Room A'),
(2, 'Volleyball (Official)', 'Mikasa official volleyball', 'VB-001', 15, 15, 'good', 'Sports Storage Room B'),
(2, 'Volleyball Net', 'Standard volleyball net with poles', 'VB-002', 5, 5, 'good', 'Sports Storage Room B'),
(3, 'Badminton Racket', 'Yonex badminton racket', 'BD-001', 30, 30, 'good', 'Sports Storage Room C'),
(3, 'Shuttlecock (Pack of 12)', 'Feather shuttlecock pack', 'BD-002', 25, 25, 'good', 'Sports Storage Room C'),
(4, 'Running Spikes', 'Track running spikes various sizes', 'AT-001', 12, 12, 'good', 'Sports Storage Room D'),
(4, 'Shot Put (4kg)', 'Standard 4kg shot put', 'AT-002', 6, 6, 'good', 'Sports Storage Room D'),
(5, 'Football/Soccer Ball', 'Adidas football size 5', 'FB-001', 10, 10, 'good', 'Sports Storage Room A'),
(6, 'Table Tennis Paddle', 'Butterfly table tennis paddle', 'TT-001', 20, 20, 'good', 'Sports Storage Room C');

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('max_borrow_days', '14', 'integer', 'Maximum number of days for borrowing'),
('default_borrow_days', '7', 'integer', 'Default borrowing period in days'),
('max_borrow_items', '5', 'integer', 'Maximum items a user can borrow at once'),
('low_stock_threshold', '3', 'integer', 'Alert when stock falls below this number'),
('require_approval', '1', 'boolean', 'Require coordinator approval for borrowing requests'),
('notification_email', '1', 'boolean', 'Enable email notifications'),
('overdue_reminder_days', '1', 'integer', 'Days before due date to send reminder'),
('campus_name', 'JHCSC Dumingag Campus', 'string', 'Campus name'),
('borrowing_policy', 'All borrowed equipment must be returned in the same condition. Late returns may result in borrowing privileges being suspended.', 'string', 'Borrowing policy text');
