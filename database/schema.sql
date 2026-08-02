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
    role ENUM('admin', 'coordinator', 'staff', 'unit_manager', 'coach', 'tabulator', 'student') NOT NULL DEFAULT 'student',
    team_id INT DEFAULT NULL,
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
('borrowing_policy', 'All borrowed equipment must be returned in the same condition. Late returns may result in borrowing privileges being suspended.', 'string', 'Borrowing policy text'),
('theme_preset', 'jhcsc_blue', 'string', 'Active theme preset'),
('theme_primary', '#1a5276', 'string', 'Custom primary color'),
('theme_secondary', '#2e86c1', 'string', 'Custom secondary color'),
('theme_accent', '#f39c12', 'string', 'Custom accent color'),
('theme_body_bg', '#f4f6f9', 'string', 'Custom body background'),
('theme_card_bg', '#ffffff', 'string', 'Custom card background'),
('theme_text', '#2c3e50', 'string', 'Custom text color'),
('theme_login_gradient', 'linear-gradient(135deg, #1a5276 0%, #2e86c1 50%, #3498db 100%)', 'string', 'Login page gradient');

-- =====================
-- Intramurals Module
-- =====================

CREATE TABLE IF NOT EXISTS intramural_seasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    year_label VARCHAR(40) NOT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_season_year_label (year_label)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intramural_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    short_name VARCHAR(30) DEFAULT NULL,
    color VARCHAR(20) DEFAULT '#1a5276',
    logo VARCHAR(255) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    coach_name VARCHAR(100) DEFAULT NULL,
    unit_manager_id INT DEFAULT NULL,
    coach_user_id INT DEFAULT NULL,
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team_name (name),
    FOREIGN KEY (unit_manager_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (coach_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intramural_point_schemes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    points_1 INT NOT NULL DEFAULT 10,
    points_2 INT NOT NULL DEFAULT 7,
    points_3 INT NOT NULL DEFAULT 5,
    points_4 INT NOT NULL DEFAULT 3,
    points_5 INT NOT NULL DEFAULT 2,
    points_6 INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intramural_sports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category ENUM('men', 'women', 'mixed') NOT NULL DEFAULT 'mixed',
    scoring_method ENUM('points', 'sets', 'games', 'time') NOT NULL DEFAULT 'points',
    rules TEXT,
    schedule_notes TEXT,
    tournament_format ENUM('round_robin', 'single_elimination', 'double_elimination', 'group_knockout', 'custom') NOT NULL DEFAULT 'round_robin',
    format_notes TEXT,
    win_points INT NOT NULL DEFAULT 3,
    draw_points INT NOT NULL DEFAULT 1,
    loss_points INT NOT NULL DEFAULT 0,
    point_scheme_id INT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sport_name_category (name, category),
    FOREIGN KEY (point_scheme_id) REFERENCES intramural_point_schemes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intramural_athletes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    athlete_code VARCHAR(20) NOT NULL UNIQUE,
    student_id VARCHAR(30) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    gender ENUM('male', 'female', 'other') NOT NULL DEFAULT 'male',
    birthdate DATE DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    year_level VARCHAR(20) DEFAULT NULL,
    team_id INT DEFAULT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_athlete_student_id (student_id),
    FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intramural_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    athlete_id INT NOT NULL,
    sport_id INT NOT NULL,
    team_id INT NOT NULL,
    event_category VARCHAR(100) DEFAULT NULL,
    jersey_number VARCHAR(10) DEFAULT NULL,
    position VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_athlete_sport_season (athlete_id, sport_id, season_id),
    FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE RESTRICT,
    FOREIGN KEY (athlete_id) REFERENCES intramural_athletes(id) ON DELETE CASCADE,
    FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- One coach account per team + event + season
CREATE TABLE IF NOT EXISTS intramural_event_coaches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    team_id INT NOT NULL,
    sport_id INT NOT NULL,
    coach_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team_sport_season_coach (team_id, sport_id, season_id),
    FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE RESTRICT,
    FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE CASCADE,
    FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
    FOREIGN KEY (coach_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intramural_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT NOT NULL,
    sport_id INT NOT NULL,
    team_a_id INT NOT NULL,
    team_b_id INT NOT NULL,
    scheduled_at DATETIME NOT NULL,
    venue VARCHAR(150) DEFAULT NULL,
    referee_name VARCHAR(100) DEFAULT NULL,
    status ENUM('scheduled', 'ongoing', 'completed', 'cancelled', 'forfeit') NOT NULL DEFAULT 'scheduled',
    score_a INT DEFAULT NULL,
    score_b INT DEFAULT NULL,
    winner_team_id INT DEFAULT NULL,
    forfeit_team_id INT DEFAULT NULL,
    notes TEXT,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (season_id) REFERENCES intramural_seasons(id) ON DELETE RESTRICT,
    FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
    FOREIGN KEY (team_a_id) REFERENCES intramural_teams(id) ON DELETE RESTRICT,
    FOREIGN KEY (team_b_id) REFERENCES intramural_teams(id) ON DELETE RESTRICT,
    FOREIGN KEY (winner_team_id) REFERENCES intramural_teams(id) ON DELETE SET NULL,
    FOREIGN KEY (forfeit_team_id) REFERENCES intramural_teams(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO intramural_seasons (name, year_label, start_date, end_date, description, is_active) VALUES
('Intramurals', CONCAT(YEAR(CURDATE()), '-', YEAR(CURDATE()) + 1), NULL, NULL, 'Default intramurals season', 1);

INSERT INTO intramural_teams (name, short_name, color, department) VALUES
('Blue Eagles', 'Blue', '#1a5276', 'College of Education'),
('Red Lions', 'Red', '#c0392b', 'College of Arts and Sciences'),
('Green Tigers', 'Green', '#1e8449', 'College of Agriculture'),
('Gold Falcons', 'Gold', '#d68910', 'College of Business');

INSERT INTO intramural_point_schemes (name, description, points_1, points_2, points_3, points_4, points_5, points_6) VALUES
('Major Team Sports', 'Basketball, Volleyball, Sepak Takraw, Esports, Baseball, Softball, Frisbee, Mass Power Dance', 10, 7, 5, 3, 2, 1),
('Racket & Dance Sports', 'Badminton, Table Tennis, Pickleball, Lawn Tennis, Dance Sports', 8, 6, 4, 3, 2, 1),
('Athletics & Chess', 'Athletics and Chess', 6, 5, 4, 3, 2, 1);

INSERT INTO intramural_sports (name, description, category, scoring_method, point_scheme_id, win_points) VALUES
('Basketball 5x5', '5-on-5 basketball', 'mixed', 'points', 1, 3),
('Basketball 3x3', '3-on-3 basketball', 'mixed', 'points', 1, 3),
('Volleyball', 'Indoor volleyball', 'mixed', 'sets', 1, 3),
('Sepak Takraw', 'Sepak takraw tournament', 'mixed', 'sets', 1, 3),
('MLBB/CODM', 'Mobile Legends / Call of Duty Mobile', 'mixed', 'games', 1, 3),
('Badminton', 'Badminton singles/doubles', 'mixed', 'games', 2, 3),
('Table Tennis', 'Table tennis', 'mixed', 'games', 2, 3),
('Pickleball', 'Pickleball', 'mixed', 'games', 2, 3),
('Lawn Tennis', 'Lawn tennis', 'mixed', 'games', 2, 3),
('Athletics', 'Track and field', 'mixed', 'points', 3, 3),
('Chess', 'Chess', 'mixed', 'games', 3, 3),
('Baseball', 'Baseball', 'mixed', 'points', 1, 3),
('Softball', 'Softball', 'mixed', 'points', 1, 3),
('Frisbee', 'Ultimate frisbee', 'mixed', 'points', 1, 3),
('Dance Sports', 'Dance sports', 'mixed', 'points', 2, 3),
('Mass Power Dance', 'Mass power dance', 'mixed', 'points', 1, 3);

-- Link users.team_id after teams exist
ALTER TABLE users
    ADD CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE SET NULL;

-- Sample unit managers and coaches (password reset by install.php to admin123)
INSERT INTO users (username, email, password, first_name, last_name, department, role, team_id) VALUES
('um_blue', 'um_blue@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Unit', 'Manager Blue', 'College of Education', 'unit_manager', 1),
('coach_blue', 'coach_blue@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Coach', 'Blue', 'College of Education', 'coach', 1),
('um_red', 'um_red@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Unit', 'Manager Red', 'College of Arts and Sciences', 'unit_manager', 2),
('coach_red', 'coach_red@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Coach', 'Red', 'College of Arts and Sciences', 'coach', 2),
('um_green', 'um_green@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Unit', 'Manager Green', 'College of Agriculture', 'unit_manager', 3),
('coach_green', 'coach_green@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Coach', 'Green', 'College of Agriculture', 'coach', 3),
('um_gold', 'um_gold@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Unit', 'Manager Gold', 'College of Business', 'unit_manager', 4),
('coach_gold', 'coach_gold@jhcsc.edu.ph', '$2y$10$NKHgE07F2acQPzEmaEB9FeaBB/2Y2e/acncWDo0U19wF7F7u3KK.y', 'Coach', 'Gold', 'College of Business', 'coach', 4);

UPDATE intramural_teams SET unit_manager_id = (SELECT id FROM users WHERE username = 'um_blue'), coach_user_id = (SELECT id FROM users WHERE username = 'coach_blue'), coach_name = 'Coach Blue' WHERE id = 1;
UPDATE intramural_teams SET unit_manager_id = (SELECT id FROM users WHERE username = 'um_red'), coach_user_id = (SELECT id FROM users WHERE username = 'coach_red'), coach_name = 'Coach Red' WHERE id = 2;
UPDATE intramural_teams SET unit_manager_id = (SELECT id FROM users WHERE username = 'um_green'), coach_user_id = (SELECT id FROM users WHERE username = 'coach_green'), coach_name = 'Coach Green' WHERE id = 3;
UPDATE intramural_teams SET unit_manager_id = (SELECT id FROM users WHERE username = 'um_gold'), coach_user_id = (SELECT id FROM users WHERE username = 'coach_gold'), coach_name = 'Coach Gold' WHERE id = 4;
