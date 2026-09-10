-- Intramurals module tables (run on existing installations)
USE jhcsc_sports_inventory;

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
    UNIQUE KEY uq_team_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intramural_sports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category ENUM('men', 'women', 'mixed') NOT NULL DEFAULT 'men',
    scoring_method ENUM('points', 'sets', 'games', 'time') NOT NULL DEFAULT 'points',
    rules TEXT,
    schedule_notes TEXT,
    win_points INT NOT NULL DEFAULT 3,
    draw_points INT NOT NULL DEFAULT 1,
    loss_points INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sport_name_category (name, category)
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
    athlete_id INT NOT NULL,
    sport_id INT NOT NULL,
    team_id INT NOT NULL,
    event_category VARCHAR(100) DEFAULT NULL,
    jersey_number VARCHAR(10) DEFAULT NULL,
    position VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_athlete_sport (athlete_id, sport_id),
    FOREIGN KEY (athlete_id) REFERENCES intramural_athletes(id) ON DELETE CASCADE,
    FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intramural_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
    FOREIGN KEY (sport_id) REFERENCES intramural_sports(id) ON DELETE CASCADE,
    FOREIGN KEY (team_a_id) REFERENCES intramural_teams(id) ON DELETE RESTRICT,
    FOREIGN KEY (team_b_id) REFERENCES intramural_teams(id) ON DELETE RESTRICT,
    FOREIGN KEY (winner_team_id) REFERENCES intramural_teams(id) ON DELETE SET NULL,
    FOREIGN KEY (forfeit_team_id) REFERENCES intramural_teams(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO intramural_teams (name, short_name, color, department) VALUES
('Blue Eagles', 'Blue', '#1a5276', 'College of Education'),
('Red Lions', 'Red', '#c0392b', 'College of Arts and Sciences'),
('Green Tigers', 'Green', '#1e8449', 'College of Agriculture'),
('Gold Falcons', 'Gold', '#d68910', 'College of Business');

INSERT INTO intramural_sports (name, description, category, scoring_method, rules, win_points) VALUES
('Basketball', '5-on-5 basketball tournament', 'men', 'points', 'Standard FIBA rules. Games are 4 quarters.', 3),
('Basketball', '5-on-5 basketball tournament', 'women', 'points', 'Standard FIBA rules. Games are 4 quarters.', 3),
('Volleyball', 'Indoor volleyball', 'men', 'sets', 'Best of 5 sets. Rally scoring.', 3),
('Volleyball', 'Indoor volleyball', 'women', 'sets', 'Best of 5 sets. Rally scoring.', 3),
('Chess', 'Individual/team chess', 'men', 'games', 'Standard FIDE rules. Team based on board wins.', 3),
('Chess', 'Individual/team chess', 'women', 'games', 'Standard FIDE rules. Team based on board wins.', 3),
('Table Tennis', 'Singles and doubles', 'men', 'games', 'Best of 5 games to 11 points.', 3),
('Table Tennis', 'Singles and doubles', 'women', 'games', 'Best of 5 games to 11 points.', 3);
