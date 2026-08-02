-- RBAC: unit_manager + coach roles, team assignment
USE jhcsc_sports_inventory;

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'coordinator', 'staff', 'unit_manager', 'coach', 'student') NOT NULL DEFAULT 'student';

ALTER TABLE users
    ADD COLUMN team_id INT DEFAULT NULL AFTER role,
    ADD CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES intramural_teams(id) ON DELETE SET NULL;

ALTER TABLE intramural_teams
    ADD COLUMN unit_manager_id INT DEFAULT NULL AFTER coach_name,
    ADD COLUMN coach_user_id INT DEFAULT NULL AFTER unit_manager_id,
    ADD CONSTRAINT fk_team_unit_manager FOREIGN KEY (unit_manager_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_team_coach_user FOREIGN KEY (coach_user_id) REFERENCES users(id) ON DELETE SET NULL;
