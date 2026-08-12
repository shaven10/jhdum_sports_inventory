<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!tableExists($db, 'intramural_point_schemes')) {
    echo "Creating intramural_point_schemes...\n";
    $db->exec("CREATE TABLE intramural_point_schemes (
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
    ) ENGINE=InnoDB");
}

$schemes = [
    ['Major Team Sports', 'Basketball, Volleyball, Sepak Takraw, Esports, Baseball, Softball, Frisbee, Mass Power Dance', 10, 7, 5, 3, 2, 1],
    ['Racket & Dance Sports', 'Badminton, Table Tennis, Pickleball, Lawn Tennis, Dance Sports', 8, 6, 4, 3, 2, 1],
    ['Athletics & Chess', 'Athletics and Chess', 6, 5, 4, 3, 2, 1],
];

$schemeIds = [];
foreach ($schemes as $s) {
    $check = $db->prepare('SELECT id FROM intramural_point_schemes WHERE name = ?');
    $check->execute([$s[0]]);
    $id = $check->fetchColumn();
    if (!$id) {
        $stmt = $db->prepare('INSERT INTO intramural_point_schemes (name, description, points_1, points_2, points_3, points_4, points_5, points_6) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute($s);
        $id = (int) $db->lastInsertId();
        echo "Created scheme: {$s[0]}\n";
    } else {
        $stmt = $db->prepare('UPDATE intramural_point_schemes SET description=?, points_1=?, points_2=?, points_3=?, points_4=?, points_5=?, points_6=? WHERE id=?');
        $stmt->execute([$s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7], $id]);
        echo "Updated scheme: {$s[0]}\n";
    }
    $schemeIds[$s[0]] = (int) $id;
}

if (!columnExists($db, 'intramural_sports', 'point_scheme_id')) {
    echo "Adding intramural_sports.point_scheme_id...\n";
    $db->exec('ALTER TABLE intramural_sports ADD COLUMN point_scheme_id INT DEFAULT NULL AFTER loss_points');
    try {
        $db->exec('ALTER TABLE intramural_sports ADD CONSTRAINT fk_sport_point_scheme FOREIGN KEY (point_scheme_id) REFERENCES intramural_point_schemes(id) ON DELETE SET NULL');
    } catch (PDOException $e) {
        echo "FK note: " . $e->getMessage() . "\n";
    }
}

// Ensure event list exists (upsert by name+category — men and women for each sport)
$eventNames = [
    ['Basketball 5x5', 'Major Team Sports'],
    ['Basketball 3x3', 'Major Team Sports'],
    ['Volleyball', 'Major Team Sports'],
    ['Sepak Takraw', 'Major Team Sports'],
    ['MLBB/CODM', 'Major Team Sports'],
    ['Badminton', 'Racket & Dance Sports'],
    ['Table Tennis', 'Racket & Dance Sports'],
    ['Pickleball', 'Racket & Dance Sports'],
    ['Lawn Tennis', 'Racket & Dance Sports'],
    ['Athletics', 'Athletics & Chess'],
    ['Chess', 'Athletics & Chess'],
    ['Baseball', 'Major Team Sports'],
    ['Softball', 'Major Team Sports'],
    ['Frisbee', 'Major Team Sports'],
    ['Dance Sports', 'Racket & Dance Sports'],
    ['Mass Power Dance', 'Major Team Sports'],
];

$events = [];
foreach ($eventNames as [$name, $schemeName]) {
    foreach (['men', 'women'] as $category) {
        $events[] = [$name, $category, $schemeName];
    }
}

foreach ($events as [$name, $category, $schemeName]) {
    $schemeId = $schemeIds[$schemeName];
    $find = $db->prepare('SELECT id FROM intramural_sports WHERE name = ? AND category = ?');
    $find->execute([$name, $category]);
    $sportId = $find->fetchColumn();
    if ($sportId) {
        $db->prepare('UPDATE intramural_sports SET point_scheme_id = ? WHERE id = ?')->execute([$schemeId, $sportId]);
        echo "Linked sport: $name\n";
    } else {
        // Try match by name only (any category)
        $find2 = $db->prepare('SELECT id FROM intramural_sports WHERE name = ? LIMIT 1');
        $find2->execute([$name]);
        $sportId = $find2->fetchColumn();
        if ($sportId) {
            $db->prepare('UPDATE intramural_sports SET point_scheme_id = ? WHERE id = ?')->execute([$schemeId, $sportId]);
            echo "Linked existing sport: $name\n";
        } else {
            $db->prepare('INSERT INTO intramural_sports (name, description, category, scoring_method, point_scheme_id, win_points, draw_points, loss_points) VALUES (?, ?, ?, ?, ?, 3, 1, 0)')
                ->execute([$name, $name . ' intramurals event', $category, 'points', $schemeId]);
            echo "Created sport: $name\n";
        }
    }
}

// Map older basketball/volleyball/chess/table tennis names if present
$legacyMap = [
    'Basketball' => 'Major Team Sports',
    'Volleyball' => 'Major Team Sports',
    'Chess' => 'Athletics & Chess',
    'Table Tennis' => 'Racket & Dance Sports',
];
foreach ($legacyMap as $name => $schemeName) {
    $db->prepare('UPDATE intramural_sports SET point_scheme_id = ? WHERE name = ? AND (point_scheme_id IS NULL OR point_scheme_id = 0)')
        ->execute([$schemeIds[$schemeName], $name]);
}

// Default any remaining sports to Major Team Sports
$db->prepare('UPDATE intramural_sports SET point_scheme_id = ? WHERE point_scheme_id IS NULL')
    ->execute([$schemeIds['Major Team Sports']]);

echo "DONE\n";
