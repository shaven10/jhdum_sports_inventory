<?php
/**
 * Seed sample athletes and official roster registrations for every active team.
 *
 * Usage (from project root or this folder):
 *   php database/run_seed_rosters.php
 *
 * Idempotent: uses athlete_code prefix SEED-T{teamId}- and skips existing
 * athletes/registrations with the same keys.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

$db = getDB();

$seasonId = null;
try {
    $season = $db->query('SELECT id FROM intramural_seasons WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$season) {
        $season = $db->query('SELECT id FROM intramural_seasons ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    }
    $seasonId = $season ? (int) $season['id'] : null;
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read seasons: {$e->getMessage()}\n");
    exit(1);
}

if (!$seasonId) {
    fwrite(STDERR, "No intramural season found. Create/activate a season first.\n");
    exit(1);
}

$teams = $db->query('SELECT id, name, short_name, department FROM intramural_teams WHERE is_active = 1 ORDER BY id')->fetchAll();
$sports = $db->query('SELECT id, name, category, players_per_event FROM intramural_sports ORDER BY name, category')->fetchAll();

if (!$teams) {
    fwrite(STDERR, "No active teams found.\n");
    exit(1);
}
if (!$sports) {
    fwrite(STDERR, "No sports/events found.\n");
    exit(1);
}

$maxSlots = 12;
foreach ($sports as $sport) {
    $maxSlots = max($maxSlots, (int) ($sport['players_per_event'] ?? 0) ?: 12);
}
// Cap pool size so athletics (25) is covered without exploding row counts.
$maxSlots = max(12, min($maxSlots, 30));

$firstNamesMale = [
    'Juan', 'Miguel', 'Carlo', 'Andre', 'Paolo', 'Luis', 'Marco', 'Rafael', 'Diego', 'Gabriel',
    'Nathan', 'Ethan', 'Joshua', 'Daniel', 'Christian', 'Francis', 'Adrian', 'Vincent', 'Kevin', 'Ryan',
    'Jose', 'Pedro', 'Ramon', 'Alvin', 'Bryan', 'Cedric', 'Dennis', 'Eric', 'Floyd', 'Gino',
];
$firstNamesFemale = [
    'Maria', 'Angela', 'Sofia', 'Camille', 'Andrea', 'Bianca', 'Katrina', 'Patricia', 'Michelle', 'Jasmine',
    'Nina', 'Olivia', 'Paula', 'Queenie', 'Rachel', 'Samantha', 'Therese', 'Ursula', 'Valerie', 'Wendy',
    'Ana', 'Bea', 'Carla', 'Diana', 'Elena', 'Faith', 'Grace', 'Hannah', 'Isabel', 'Julia',
];
$lastNames = [
    'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Gonzales', 'Ramos',
    'Lopez', 'Diaz', 'Rivera', 'Aquino', 'Castro', 'Navarro', 'Domingo', 'Villanueva', 'Fernandez', 'Gutierrez',
    'Perez', 'Morales', 'Salazar', 'Del Rosario', 'Padilla', 'Santiago', 'Lim', 'Tan', 'Chua', 'Sy',
];
$yearLevels = athleteYearLevelOptions();
$yearLevels = array_values(array_filter($yearLevels, fn($y) => !in_array($y, ['5th Year', 'Graduate'], true)));
$courses = athleteCourseOptions();
$positions = ['Player', 'Starter', 'Reserve', 'Captain', 'Guard', 'Forward', 'Wing', 'Setter'];

$teamCourseMap = static function (array $team) use ($courses): array {
    $haystack = strtolower(trim(($team['name'] ?? '') . ' ' . ($team['short_name'] ?? '') . ' ' . ($team['department'] ?? '')));
    $groups = [
        'education' => ['Bachelor of Elementary Education (BEEd)', 'Bachelor of Secondary Education (BSEd)', 'Bachelor of Physical Education (BPEd)', 'Bachelor of Early Childhood Education (BECEd)', 'Bachelor of Technology and Livelihood Education (BTLEd)'],
        'computer' => ['Bachelor of Science in Information Technology (BSIT)', 'Bachelor of Science in Computer Science (BSCS)'],
        'agriculture' => ['Bachelor of Science in Agriculture (BSA)', 'Bachelor of Science in Forestry (BSF)', 'Bachelor of Science in Environmental Science (BSES)', 'Bachelor of Agricultural Technology (BAT)'],
        'criminology' => ['Bachelor of Science in Criminology (BSCrim)'],
        'business' => ['Bachelor of Science in Business Administration (BSBA)', 'Bachelor of Science in Accountancy (BSA)', 'Bachelor of Science in Hospitality Management (BSHM)', 'Bachelor of Science in Tourism Management (BSTM)'],
    ];
    $keywords = [
        'education' => ['ste', 'teacher', 'education', 'phoenix'],
        'computer' => ['scs', 'codex', 'computer', 'it ', 'information'],
        'agriculture' => ['safes', 'bulls', 'agriculture', 'forestry', 'agri'],
        'criminology' => ['socje', 'dragon', 'criminology', 'criminal'],
        'business' => ['business', 'accountancy', 'hospitality', 'tourism'],
    ];
    foreach ($keywords as $group => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return $groups[$group];
            }
        }
    }
    return $courses;
};

$insertAthlete = $db->prepare(
    'INSERT INTO intramural_athletes
        (athlete_code, student_id, first_name, last_name, gender, birthdate, department, year_level, team_id, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
);
$updateAthleteCourse = $db->prepare(
    'UPDATE intramural_athletes SET department = ?, year_level = ? WHERE id = ?'
);
$findAthlete = $db->prepare('SELECT id FROM intramural_athletes WHERE athlete_code = ? LIMIT 1');
$insertReg = $db->prepare(
    'INSERT IGNORE INTO intramural_registrations
        (season_id, athlete_id, sport_id, team_id, event_category, jersey_number, position)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$athletesCreated = 0;
$registrationsCreated = 0;
$athletesReused = 0;

echo "Season ID: {$seasonId}\n";
echo "Teams: " . count($teams) . " · Sports: " . count($sports) . " · Pool size per gender: {$maxSlots}\n\n";

$db->beginTransaction();
try {
    foreach ($teams as $team) {
        $teamId = (int) $team['id'];
        $teamName = $team['name'];
        $teamCourses = $teamCourseMap($team);
        $short = preg_replace('/[^A-Za-z0-9]/', '', (string) ($team['short_name'] ?: substr($teamName, 0, 4))) ?: ('T' . $teamId);

        echo "Seeding {$teamName}...\n";

        $maleIds = [];
        $femaleIds = [];

        foreach (['male' => $firstNamesMale, 'female' => $firstNamesFemale] as $gender => $firstNames) {
            for ($i = 1; $i <= $maxSlots; $i++) {
                $code = sprintf('SEED-T%d-%s-%02d', $teamId, $gender === 'male' ? 'M' : 'F', $i);
                $course = $teamCourses[($i - 1) % count($teamCourses)];
                $yearLevel = $yearLevels[($i - 1) % count($yearLevels)];
                $findAthlete->execute([$code]);
                $existingId = $findAthlete->fetchColumn();
                if ($existingId) {
                    $athleteId = (int) $existingId;
                    $updateAthleteCourse->execute([$course, $yearLevel, $athleteId]);
                    $athletesReused++;
                } else {
                    $fn = $firstNames[($i - 1) % count($firstNames)];
                    $ln = $lastNames[($i + $teamId) % count($lastNames)];
                    // Keep names varied when wrapping the name lists.
                    if ($i > count($firstNames)) {
                        $fn .= $i;
                    }
                    $studentId = sprintf('SEED-%s-%s%02d', strtoupper($short), $gender === 'male' ? 'M' : 'F', $i);
                    $birthYear = 2002 + (($i + $teamId) % 6);
                    $birthMonth = (($i * 3) % 12) + 1;
                    $birthDay = (($i * 5) % 27) + 1;
                    $birthdate = sprintf('%04d-%02d-%02d', $birthYear, $birthMonth, $birthDay);

                    $insertAthlete->execute([
                        $code,
                        $studentId,
                        $fn,
                        $ln,
                        $gender,
                        $birthdate,
                        $course,
                        $yearLevel,
                        $teamId,
                    ]);
                    $athleteId = (int) $db->lastInsertId();
                    $athletesCreated++;
                }

                if ($gender === 'male') {
                    $maleIds[] = $athleteId;
                } else {
                    $femaleIds[] = $athleteId;
                }
            }
        }

        foreach ($sports as $sport) {
            $sportId = (int) $sport['id'];
            $slots = (int) ($sport['players_per_event'] ?? 0) ?: 12;
            $slots = max(1, min($slots, $maxSlots));
            $category = strtolower((string) $sport['category']);

            if ($category === 'women') {
                $pool = $femaleIds;
            } elseif ($category === 'mixed') {
                $pool = [];
                for ($i = 0; $i < $slots; $i++) {
                    $pool[] = ($i % 2 === 0) ? $maleIds[$i % count($maleIds)] : $femaleIds[$i % count($femaleIds)];
                }
            } else {
                $pool = $maleIds;
            }

            for ($i = 0; $i < $slots; $i++) {
                $athleteId = $pool[$i] ?? $pool[$i % count($pool)];
                $jersey = (string) ($i + 1);
                $position = $positions[$i % count($positions)];
                $insertReg->execute([
                    $seasonId,
                    $athleteId,
                    $sportId,
                    $teamId,
                    ucfirst($category),
                    $jersey,
                    $position,
                ]);
                if ($insertReg->rowCount() > 0) {
                    $registrationsCreated++;
                }
            }
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Seed failed: {$e->getMessage()}\n");
    exit(1);
}

$athleteTotal = (int) $db->query('SELECT COUNT(*) FROM intramural_athletes WHERE is_active = 1')->fetchColumn();
$regTotal = (int) $db->query('SELECT COUNT(*) FROM intramural_registrations WHERE season_id = ' . (int) $seasonId)->fetchColumn();

echo "\nDone.\n";
echo "Athletes created: {$athletesCreated}\n";
echo "Athletes updated with course values: {$athletesReused}\n";
echo "Registrations created: {$registrationsCreated}\n";
echo "Active athletes now: {$athleteTotal}\n";
echo "Season registrations now: {$regTotal}\n";
echo "Open Entry Form Gallery: /sports_inventory/intramurals/roster/gallery.php\n";
