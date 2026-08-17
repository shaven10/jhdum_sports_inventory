<?php
/**
 * Working committees (public listing + admin CRUD helpers).
 */

/** @return array<string, string> */
function workingCommitteeCategoryOptions(): array
{
    return [
        'overall' => 'Overall Committees',
        'sporting_events' => 'Sporting Events',
        'socio_cultural' => 'Socio-Cultural Committees',
    ];
}

function workingCommitteeCategoryLabel(string $category): string
{
    $options = workingCommitteeCategoryOptions();
    return $options[$category] ?? 'Other';
}

function normalizeWorkingCommitteeCategory(string $category): string
{
    $category = strtolower(trim($category));
    return array_key_exists($category, workingCommitteeCategoryOptions()) ? $category : 'overall';
}

/**
 * Strip trailing annotation tags like " - elardo" / " -chatto" while keeping real hyphenated surnames.
 */
function cleanCommitteePersonName(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    if ($name === '') {
        return '';
    }
    // Strip trailing annotation tags like " - elardo" / " - maryjuliet"
    if (preg_match('/^(.+?)\s+-\s*[a-z][a-z0-9._]*$/u', $name, $m)) {
        return trim($m[1]);
    }
    return $name;
}

function ensureWorkingCommitteesSchema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    $tableStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');

    $tableStmt->execute(['working_committees']);
    if ((int) $tableStmt->fetchColumn() === 0) {
        $db->exec("CREATE TABLE working_committees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            category ENUM('overall', 'sporting_events', 'socio_cultural') NOT NULL DEFAULT 'overall',
            description TEXT DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_working_committee_name (name),
            INDEX idx_wc_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $colStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $colStmt->execute(['working_committees', 'category']);
        if ((int) $colStmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE working_committees
                ADD COLUMN category ENUM('overall', 'sporting_events', 'socio_cultural') NOT NULL DEFAULT 'overall' AFTER name,
                ADD INDEX idx_wc_category (category)");
        }
    }

    $tableStmt->execute(['working_committee_members']);
    if ((int) $tableStmt->fetchColumn() === 0) {
        $db->exec("CREATE TABLE working_committee_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            committee_id INT NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            position_title VARCHAR(120) DEFAULT NULL,
            organization VARCHAR(150) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_wc_member_committee (committee_id),
            FOREIGN KEY (committee_id) REFERENCES working_committees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    seedSportingEventWorkingCommittees();
    seedSocioCulturalWorkingCommittees();
    seedOverallWorkingCommittees();
}

const WORKING_COMMITTEE_SEED_FLAG = 'working_committees_seeded';
const WORKING_COMMITTEE_SOCIO_SEED_FLAG = 'working_committees_socio_seeded';
const WORKING_COMMITTEE_OVERALL_SEED_FLAG = 'working_committees_overall_seeded';

function workingCommitteesSeedDone(): bool
{
    $stmt = getDB()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([WORKING_COMMITTEE_SEED_FLAG]);
    return (string) $stmt->fetchColumn() === '1';
}

function workingCommitteesSocioSeedDone(): bool
{
    $stmt = getDB()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([WORKING_COMMITTEE_SOCIO_SEED_FLAG]);
    return (string) $stmt->fetchColumn() === '1';
}

function workingCommitteesOverallSeedDone(): bool
{
    $stmt = getDB()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([WORKING_COMMITTEE_OVERALL_SEED_FLAG]);
    return (string) $stmt->fetchColumn() === '1';
}

function markWorkingCommitteesSeeded(): void
{
    $db = getDB();
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
        VALUES (?, '1', 'boolean', 'Working committee document already imported')
        ON DUPLICATE KEY UPDATE setting_value = '1'")
        ->execute([WORKING_COMMITTEE_SEED_FLAG]);
}

function markWorkingCommitteesSocioSeeded(): void
{
    $db = getDB();
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
        VALUES (?, '1', 'boolean', 'Socio-cultural working committees already imported')
        ON DUPLICATE KEY UPDATE setting_value = '1'")
        ->execute([WORKING_COMMITTEE_SOCIO_SEED_FLAG]);
}

function markWorkingCommitteesOverallSeeded(): void
{
    $db = getDB();
    $db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
        VALUES (?, '1', 'boolean', 'Overall working committees already imported')
        ON DUPLICATE KEY UPDATE setting_value = '1'")
        ->execute([WORKING_COMMITTEE_OVERALL_SEED_FLAG]);
}

/**
 * Insert missing committees/members from a catalog. Does not revive deleted rows once
 * the matching one-time seed flag has been set (unless $force is true).
 *
 * @param list<array{name:string,description:?string,sort_order?:int,members?:list<array<string,mixed>>}> $catalog
 */
function importWorkingCommitteeCatalog(array $catalog, string $category, bool $force = false): int
{
    $category = normalizeWorkingCommitteeCategory($category);
    $db = getDB();
    $created = 0;
    $findCommittee = $db->prepare('SELECT id FROM working_committees WHERE name = ? LIMIT 1');
    $insertCommittee = $db->prepare('INSERT INTO working_committees (name, category, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
    $findMember = $db->prepare('SELECT id FROM working_committee_members WHERE committee_id = ? AND full_name = ? AND COALESCE(position_title, \'\') = ? LIMIT 1');
    $insertMember = $db->prepare('INSERT INTO working_committee_members
        (committee_id, full_name, position_title, organization, sort_order, is_active)
        VALUES (?, ?, ?, ?, ?, 1)');

    foreach ($catalog as $index => $item) {
        $name = (string) $item['name'];
        $findCommittee->execute([$name]);
        $committeeId = (int) $findCommittee->fetchColumn();
        if ($committeeId <= 0) {
            $insertCommittee->execute([
                $name,
                $category,
                $item['description'] ?? null,
                (int) ($item['sort_order'] ?? ($index + 1)),
            ]);
            $committeeId = (int) $db->lastInsertId();
            $created++;
        }
        if ($committeeId <= 0) {
            continue;
        }

        foreach (($item['members'] ?? []) as $memberIndex => $member) {
            $fullName = cleanCommitteePersonName((string) ($member['full_name'] ?? ''));
            if ($fullName === '') {
                continue;
            }
            $position = trim((string) ($member['position_title'] ?? ''));
            $findMember->execute([$committeeId, $fullName, $position]);
            if ((int) $findMember->fetchColumn() > 0) {
                continue;
            }
            $insertMember->execute([
                $committeeId,
                $fullName,
                $position !== '' ? $position : null,
                !empty($member['organization']) ? (string) $member['organization'] : null,
                (int) ($member['sort_order'] ?? ($memberIndex + 1)),
            ]);
        }
    }

    return $created;
}

/**
 * Import sporting-event committees from the official working committee document.
 *
 * Runs once only, so committees deleted by the admin are not resurrected on the next
 * page load. Pass $force = true for the explicit "restore document defaults" action.
 */
function seedSportingEventWorkingCommittees(bool $force = false): int
{
    static $ranThisRequest = false;
    if ($ranThisRequest && !$force) {
        return 0;
    }
    $ranThisRequest = true;

    $db = getDB();

    if (!$force) {
        if (workingCommitteesSeedDone()) {
            return 0;
        }
        // Installed before this flag existed: keep whatever the admin has now.
        if ((int) $db->query('SELECT COUNT(*) FROM working_committees')->fetchColumn() > 0) {
            markWorkingCommitteesSeeded();
            return 0;
        }
    }

    $created = importWorkingCommitteeCatalog(sportingEventWorkingCommitteeSeedData(), 'sporting_events', $force);
    markWorkingCommitteesSeeded();

    return $created;
}

/**
 * Import socio-cultural committees from the official document (one-time unless forced).
 */
function seedSocioCulturalWorkingCommittees(bool $force = false): int
{
    static $ranThisRequest = false;
    if ($ranThisRequest && !$force) {
        return 0;
    }
    $ranThisRequest = true;

    if (!$force && workingCommitteesSocioSeedDone()) {
        return 0;
    }

    $created = importWorkingCommitteeCatalog(socioCulturalWorkingCommitteeSeedData(), 'socio_cultural', $force);
    markWorkingCommitteesSocioSeeded();

    return $created;
}

/**
 * Import overall committees from the official document (one-time unless forced).
 */
function seedOverallWorkingCommittees(bool $force = false): int
{
    static $ranThisRequest = false;
    if ($ranThisRequest && !$force) {
        return 0;
    }
    $ranThisRequest = true;

    if (!$force && workingCommitteesOverallSeedDone()) {
        return 0;
    }

    $created = importWorkingCommitteeCatalog(overallWorkingCommitteeSeedData(), 'overall', $force);
    markWorkingCommitteesOverallSeeded();

    return $created;
}

/**
 * @return list<array{name:string,description:?string,sort_order:int,members:list<array{full_name:string,position_title:string,organization?:string,sort_order?:int}>}>
 */
function sportingEventWorkingCommitteeSeedData(): array
{
    $m = static function (string $name, string $position, int $order = 0): array {
        return [
            'full_name' => $name,
            'position_title' => $position,
            'sort_order' => $order,
        ];
    };

    return [
        [
            'name' => 'VOLLEYBALL Men',
            'description' => null,
            'sort_order' => 1,
            'members' => [
                $m('TBD (Paid Referee)', 'Chief Referee', 1),
                $m('Zenon A. Matos, Jr.', 'Asst. Referee', 2),
                $m('Melesa S. Galvez', 'Scorer', 3),
                $m('Lovely L. Daluag-Gorre', 'Linesman', 4),
                $m('Gloryfe E. Almodal', 'Linesman', 5),
            ],
        ],
        [
            'name' => 'VOLLEYBALL Women',
            'description' => null,
            'sort_order' => 2,
            'members' => [
                $m('TBD (Paid Referee)', 'Chief Referee', 1),
                $m('Ronilo S. Bustamante', 'Asst. Referee', 2),
                $m('Lanilyn P. Bustamante', 'Scorer', 3),
                $m('Jennifer T. Bestudio', 'Linesman', 4),
                $m('Jessie E. Tormis-Idias', 'Linesman', 5),
            ],
        ],
        [
            'name' => 'SEPAK TAKRAW Men',
            'description' => null,
            'sort_order' => 3,
            'members' => [
                $m('Orchie E. Tampos', 'Chief Referee', 1),
                $m('Alma P. Sumbe', 'Scorer', 2),
                $m('Myrna P. Perigo', 'Member', 3),
            ],
        ],
        [
            'name' => 'ESPORTS Men & Women',
            'description' => 'Game: MLBB / CODM',
            'sort_order' => 4,
            'members' => [
                $m('Nelson V. Idias', 'Venue Manager', 1),
                $m('Rob Kellean B. Barluado', 'Tournament Manager', 2),
                $m('Flora Mae S. Cogalito', 'Member', 3),
                $m('Jeward C. Dago-oc', 'Member', 4),
                $m('Ronette Chris D. Bilbao', 'Member', 5),
            ],
        ],
        [
            'name' => 'TABLE TENNIS (Single/Double) Men',
            'description' => null,
            'sort_order' => 5,
            'members' => [
                $m('Danny Lou C. Candia', 'Chief Referee', 1),
                $m('Queeny Rose C. Baluyos', 'Member', 2),
                $m('Rhea T. Mandaya', 'Scorer', 3),
            ],
        ],
        [
            'name' => 'TABLE TENNIS (Single/Double) Women',
            'description' => null,
            'sort_order' => 6,
            'members' => [
                $m('Helen Grace L. Elardo', 'Chief Referee', 1),
                $m('Krichelle O. Villarubia', 'Scorer', 2),
            ],
        ],
        [
            'name' => 'BADMINTON (Single/Double) Men & Women',
            'description' => null,
            'sort_order' => 7,
            'members' => [
                $m('Mary Juliet Doño', 'Chief Referee', 1),
                $m('Mariza S. Balatero', 'Scorer', 2),
                $m('Rhivee Mae H. Conol', 'Member', 3),
                $m('Lorejean E. Pangasian', 'Member', 4),
                $m('Myrel Shane C. Abayon', 'Member', 5),
                $m('Lara Jane A. Espina', 'Member', 6),
            ],
        ],
        [
            'name' => 'LAWN TENNIS (Single/Double) Men & Women',
            'description' => null,
            'sort_order' => 8,
            'members' => [
                $m('Niñobel Canencia', 'Chief Referee', 1),
                $m('Joyce Capangpangan', 'Scorer', 2),
                $m('Irene B. Labadisos', 'Member', 3),
                $m('Michelle M. Mangubat', 'Member', 4),
            ],
        ],
        [
            'name' => 'PICKLEBALL (Double/Mix) Men & Women',
            'description' => null,
            'sort_order' => 9,
            'members' => [
                $m('Vincent John Chatto', 'Chief Referee', 1),
                $m('Prexy Jeede B. Tangalin', 'Scorer', 2),
                $m('Romafe P. Matos', 'Member', 3),
                $m('Mayflor E. Gose', 'Member', 4),
                $m('Dirb Boy Sebrero', 'Member', 5),
            ],
        ],
        [
            'name' => 'ATHLETICS Men & Women',
            'description' => null,
            'sort_order' => 10,
            'members' => [
                $m('Jezreel G. Bacalso', 'Chief Referee', 1),
                $m('Judith Bastasa', 'Timekeeper', 2),
                $m('April N. Libot', 'Member', 3),
                $m('Junry M. Mangubat', 'Member', 4),
                $m('Mariel Jun B. Sanchez', 'Member', 5),
                $m('Jessa Mae A. Gomez', 'Member', 6),
                $m('Marielou C. Lasmarias', 'Member', 7),
                $m('DRRMO', 'Member', 8),
                $m('Clinic', 'Member', 9),
            ],
        ],
        [
            'name' => 'CHESS Men & Women',
            'description' => null,
            'sort_order' => 11,
            'members' => [
                $m('Carlo Giovanni D. Bascon', 'Arbiter', 1),
                $m('Paulino R. Tagaylo', 'Arbiter', 2),
                $m('Allan Z. Caw-it', 'Arbiter', 3),
            ],
        ],
        [
            'name' => 'BASEBALL Men',
            'description' => null,
            'sort_order' => 12,
            'members' => [
                $m('TBD (Paid Umpire)', 'Chief Umpire', 1),
                $m('Lyka A. Cuevas', 'Scorer', 2),
                $m('Precious V. Gitalan', 'Member', 3),
                $m('Grape A. Paquibo', 'Member', 4),
                $m('Jerry B. Duco', 'Member', 5),
            ],
        ],
        [
            'name' => 'SOFTBALL Women',
            'description' => null,
            'sort_order' => 13,
            'members' => [
                $m('Hannah Batucan', 'Chief Umpire', 1),
                $m('Allen Day Mori', 'Scorer', 2),
                $m('Larr Anthony C. Gutang', 'Member', 3),
                $m('Maria Shednilyn A. Sordilla', 'Member', 4),
            ],
        ],
        [
            'name' => 'FRISBEE Mix',
            'description' => null,
            'sort_order' => 14,
            'members' => [
                $m('Eric Lauron', 'Event Manager', 1),
                $m('Rosaflor G. Canencia', 'Scorer', 2),
                $m('Denmark R. Villa', 'Member', 3),
                $m('Roldan L. Cultura', 'Member', 4),
            ],
        ],
        [
            'name' => 'BASKETBALL Competition',
            'description' => '5x5 / 3x3 Men & Women',
            'sort_order' => 15,
            'members' => [
                $m('Sports In charge', 'Event Manager', 1),
                $m('Nicaster D. Ambalong, Jr.', 'Tournament Manager', 2),
                $m('Mark D. Sildora', 'Member', 3),
                $m('Win Marc C. Cabilan', 'Member', 4),
                $m('TBD', 'Official Announcer', 5),
                $m('BAP', 'Officiating Officials', 6),
                $m('BAP', 'Scorer', 7),
                $m('BAP', 'Timekeeper', 8),
            ],
        ],
    ];
}

/**
 * @return list<array{name:string,description:?string,sort_order:int,members:list<array{full_name:string,position_title:string,organization?:string,sort_order?:int}>}>
 */
function socioCulturalWorkingCommitteeSeedData(): array
{
    $m = static function (string $name, string $position, int $order = 0): array {
        return [
            'full_name' => $name,
            'position_title' => $position,
            'sort_order' => $order,
        ];
    };

    return [
        [
            'name' => 'Opening Program',
            'description' => "Venue: Open Stage\nAugust 24, 2026 @ 8am\nPart 1. Opening Program\nPart 2. Power Dance Competition",
            'sort_order' => 1,
            'members' => [
                $m('Roldan Cultura', 'Event Manager (Part 1)', 1),
                $m('Khyrthz Marco Salaguste', 'Event Manager (Part 2)', 2),
                $m('All SSC Officers', 'Member', 3),
                $m('Dirb Boy Sebrero', 'EMCEE', 4),
            ],
        ],
        [
            'name' => 'Visual Arts',
            'description' => "August 24, 2026 @ 1pm\nVenue: BEED Rooms & Open Grounds",
            'sort_order' => 2,
            'members' => [
                $m('Ruther Bianan', 'Event Manager', 1),
                $m('Richard Lumocas', 'Asst.', 2),
                $m('Katreen Glimada', 'Member', 3),
                $m('Maricel Fuentes', 'Member', 4),
            ],
        ],
        [
            'name' => 'Literary Arts',
            'description' => "August 24, 2026 @ 1pm\nVenue: Research Office\nExtemporaneous Speaking, Storytelling, Dagliang Talumpati, Pagkukuwento",
            'sort_order' => 3,
            'members' => [
                $m('Rey Pepito', 'Event Manager', 1),
                $m('Rosehur Alumbro', 'Asst.', 2),
                $m('Lara Jane Espina', 'Member', 3),
                $m('Lorejean Pangasian', 'Member', 4),
                $m('Sarah Mae Angga', 'EMCEE', 5),
            ],
        ],
        [
            'name' => 'Quiz Bowl',
            'description' => "August 25, 2026 @ 8am\nVenue: AVR",
            'sort_order' => 4,
            'members' => [
                $m('Dirb Boy Sebrero', 'Event Manager', 1),
                $m('Win Marc Cabilan', 'Asst.', 2),
                $m('Venus Avenido', 'Quizmaster', 3),
                $m('Mariell Jun Sanchez', 'Member', 4),
                $m('Erma Ambalong', 'Member', 5),
                $m('Myrna Perigo', 'Member', 6),
            ],
        ],
        [
            'name' => 'Music',
            'description' => "August 25, 2026 @ 1pm\nVenue: Function Hall\nSolo (Pop), Duet (Pop), Kundiman",
            'sort_order' => 5,
            'members' => [
                $m('Elberth Rey Dela Cruz', 'Event Manager', 1),
                $m('Gilmore Velasco', 'Asst.', 2),
                $m('Mayflor Gose', 'Member', 3),
                $m('Lanilyn Bustamante', 'Member', 4),
            ],
        ],
        [
            'name' => 'Dance Arts and DanceSport',
            'description' => "Dance Arts (Folk, Street, Contemporary)\nDanceSport (Modern Standard, Latin American, Third Kind)",
            'sort_order' => 6,
            'members' => [
                $m('Dyamera Gose', 'Event Manager', 1),
                $m('Khyrthz Marco Salaguste', 'Asst.', 2),
                $m('BPEd Students', 'Member', 3),
                $m('ROTC', 'Member', 4),
            ],
        ],
    ];
}

/**
 * @return list<array{name:string,description:?string,sort_order:int,members:list<array{full_name:string,position_title:string,organization?:string,sort_order?:int}>}>
 */
function overallWorkingCommitteeSeedData(): array
{
    $m = static function (string $name, string $position, int $order = 0, ?string $organization = null): array {
        return [
            'full_name' => $name,
            'position_title' => $position,
            'organization' => $organization,
            'sort_order' => $order,
        ];
    };

    return [
        [
            'name' => 'STEERING COMMITTEE',
            'description' => null,
            'sort_order' => 1,
            'members' => [
                $m('Moises Glenn G. Tangalin, Ed.D.', 'Chairperson', 1),
                $m('Evelyn M. Daguplo, MBA', 'Vice Chairperson', 2),
                $m('Armando S. Buyco, MSCrim', 'Vice Chairperson', 3),
                $m('Harvey M. Tangalin', 'Vice Chairperson', 4),
                $m('Mariza S. Balatero', 'Vice Chairperson', 5),
                $m('Vincent John Chatto', 'Member', 6, 'SCS'),
                $m('Xyrin C. Moñeza', 'Member', 7, 'BSISM'),
                $m('Abundio G. Cabahug, Jr.', 'Member', 8, 'BSA'),
                $m('Sheila L. Bascon', 'Member', 9, 'BSED Math'),
                $m('Buena D. Calunsag, Ed.D.', 'Member', 10, 'BPED'),
                $m('Eleonor N. Ocay, Ph.D.', 'Member', 11, 'Registrar'),
                $m('Eleuteria O. Villarubia', 'Member', 12, 'HR'),
                $m('Mark E. Patalinghug, Ph.D.', 'Member', 13, 'APD'),
            ],
        ],
        [
            'name' => 'PROGRAM, CERTIFICATES, AWARDS AND INVITATIONS',
            'description' => null,
            'sort_order' => 2,
            'members' => [
                $m('Vincent John L. Chatto', 'Chair', 1),
                $m('Richard P. Lumocas', 'V-Chair', 2),
                $m('All SCS Faculty', 'Member', 3),
                $m('Nelson V. Idias', 'Member', 4),
                $m('Myrel Shane C. Abayon', 'Member', 5),
                $m('Lorejean E. Pangasian', 'Member', 6),
                $m('SCC Officers', 'Member', 7),
            ],
        ],
        [
            'name' => 'MARKETING AND MEDIA',
            'description' => null,
            'sort_order' => 3,
            'members' => [
                $m('Jackie J. Valderama', 'Chair', 1),
                $m('School Publication Office', 'Member', 2),
            ],
        ],
        [
            'name' => 'TABULATORS',
            'description' => 'Socio-Cultural Competitions',
            'sort_order' => 4,
            'members' => [
                $m('Eric G. Lauron', 'Chair', 1, 'SCS'),
                $m('Rhivee Mae H. Conol', 'V-Chair', 2, 'HS'),
                $m('Sheila L. Bascon', 'Member', 3, 'STE'),
                $m('Haneylyn L. Cagod', 'Member', 4, 'SoCJE'),
                $m('Janice G. Repaso', 'Member', 5, 'SAFES'),
            ],
        ],
        [
            'name' => 'SECRETARIAT',
            'description' => null,
            'sort_order' => 5,
            'members' => [
                $m('Chrisia Mae M. Soria', 'Chair', 1),
                $m('Jona C. Capangpangan', 'V-Chair', 2),
                $m('Karen Joy G. Watin', 'Member', 3),
                $m('Kriza Mae D. Bagalanon', 'Member', 4),
                $m('Chris John C. Cogalito', 'Member', 5),
            ],
        ],
        [
            'name' => 'COMMUNICATIONS',
            'description' => null,
            'sort_order' => 6,
            'members' => [
                $m('Darlin Jane T. Balbastro', 'Chair', 1),
                $m('Allen Day S. Mori', 'Member', 2),
                $m('SSC Officers', 'Member', 3),
            ],
        ],
        [
            'name' => 'VENUE AND PLAYING COURT PREPARATION',
            'description' => 'Sports Competition',
            'sort_order' => 7,
            'members' => [
                $m('Evelyn M. Daguplo', 'Chair', 1),
                $m('Eliezer A. Ocay', 'V-Chair', 2),
                $m('Noel S. Veneracion', 'Member', 3),
                $m('Jade Mark A. Rupinta', 'Member', 4),
                $m('Nelson T. Macot', 'Member', 5),
                $m('Rolando C. Dela Torre', 'Member', 6),
                $m('Male COS/Field Workers', 'Member', 7),
            ],
        ],
        [
            'name' => 'DECORATION',
            'description' => 'Opening Program',
            'sort_order' => 8,
            'members' => [
                $m('Evelyn M. Daguplo', 'Chair', 1),
                $m('Vincent John L. Chatto', 'V-Chair', 2),
                $m('SSC & CSB Officers', 'Member', 3),
            ],
        ],
        [
            'name' => 'HEALTH AND MEDICAL SERVICES',
            'description' => null,
            'sort_order' => 9,
            'members' => [
                $m('Krystelle Joy U. Tangalin', 'Chair', 1),
                $m('Junry M. Mangubat', 'V-Chair', 2),
                $m('Mark D. Sildora', 'Member', 3),
                $m('Jennie Ree B. Pan', 'Member', 4),
                $m('DRRMO', 'Member', 5),
            ],
        ],
        [
            'name' => 'PEACE AND ORDER',
            'description' => null,
            'sort_order' => 10,
            'members' => [
                $m('Vicente E. Ebisa', 'Chair', 1),
                $m('All Security Guards', 'Member', 2),
            ],
        ],
        [
            'name' => 'CLEANLINESS AND SANITATION',
            'description' => null,
            'sort_order' => 11,
            'members' => [
                $m('Gemma B. Delicana', 'Chair', 1),
                $m('Orlando T. Hentica', 'Member', 2),
                $m('Benilda K. De Jose', 'Member', 3),
                $m('Noel E. Adaza', 'Member', 4),
                $m('John C. Macaranas', 'Member', 5),
            ],
        ],
        [
            'name' => 'SNACKS AND MEALS',
            'description' => null,
            'sort_order' => 12,
            'members' => [
                $m('Buena D. Calunsag', 'Chair', 1),
                $m('BPED Students', 'Member', 2),
            ],
        ],
        [
            'name' => 'OVERALL EVENT MANAGER',
            'description' => null,
            'sort_order' => 13,
            'members' => [
                $m('Marjorey C. Cabigas', 'Socio-Cultural Events', 1),
                $m('Steven J. Tres Reyes', 'Sports Competitions', 2),
            ],
        ],
        [
            'name' => 'SPECIAL ARBITRARY COMMITTEE',
            'description' => null,
            'sort_order' => 14,
            'members' => [
                $m('Moises Glenn G. Tangalin, Ed.D.', 'Chairperson', 1),
                $m('Mark E. Patalinghug, Ph.D.', 'Vice Chairperson', 2),
                $m('Xyrin C. Moñeza, MSCrim', 'Vice Chairperson', 3),
                $m('Tournament Managers', 'Member', 4),
                $m('Tournament Officials', 'Member', 5),
                $m('Team Managers', 'Member', 6),
                $m('Sports In-charge', 'Member', 7),
                $m('Socio-Cultural In-charge', 'Member', 8),
            ],
        ],
    ];
}

/** @return list<array<string,mixed>> */
function getWorkingCommittees(bool $activeOnly = true, ?string $category = null): array
{
    ensureWorkingCommitteesSchema();
    $db = getDB();
    $sql = 'SELECT c.*,
            (SELECT COUNT(*) FROM working_committee_members m WHERE m.committee_id = c.id' .
        ($activeOnly ? ' AND m.is_active = 1' : '') .
        ') AS member_count
        FROM working_committees c';
    $where = [];
    $params = [];
    if ($activeOnly) {
        $where[] = 'c.is_active = 1';
    }
    if ($category !== null && $category !== '' && $category !== 'all') {
        $where[] = 'c.category = ?';
        $params[] = normalizeWorkingCommitteeCategory($category);
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY FIELD(c.category, \'overall\', \'sporting_events\', \'socio_cultural\'), c.sort_order ASC, c.name ASC';
    if ($params) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    return $db->query($sql)->fetchAll();
}

function getWorkingCommitteeById(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    ensureWorkingCommitteesSchema();
    $stmt = getDB()->prepare('SELECT * FROM working_committees WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function getWorkingCommitteeMembers(int $committeeId, bool $activeOnly = true): array
{
    if ($committeeId <= 0) {
        return [];
    }
    ensureWorkingCommitteesSchema();
    $sql = 'SELECT * FROM working_committee_members WHERE committee_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, full_name ASC';
    $stmt = getDB()->prepare($sql);
    $stmt->execute([$committeeId]);
    return $stmt->fetchAll();
}

function getWorkingCommitteeMemberById(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    ensureWorkingCommitteesSchema();
    $stmt = getDB()->prepare('SELECT * FROM working_committee_members WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Active committees grouped for the public page.
 *
 * @return list<array{category:string,label:string,committees:list<array{committee:array,members:list<array>}>}>
 */
function getPublicWorkingCommitteesGrouped(): array
{
    $committees = getWorkingCommittees(true);
    $buckets = [];
    foreach (workingCommitteeCategoryOptions() as $key => $label) {
        $buckets[$key] = [
            'category' => $key,
            'label' => $label,
            'committees' => [],
        ];
    }

    foreach ($committees as $committee) {
        $key = normalizeWorkingCommitteeCategory((string) ($committee['category'] ?? 'overall'));
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'category' => $key,
                'label' => workingCommitteeCategoryLabel($key),
                'committees' => [],
            ];
        }
        $buckets[$key]['committees'][] = [
            'committee' => $committee,
            'members' => getWorkingCommitteeMembers((int) $committee['id'], true),
        ];
    }

    return array_values(array_filter($buckets, static fn(array $b) => $b['committees'] !== []));
}

/**
 * @deprecated Use getPublicWorkingCommitteesGrouped()
 * @return list<array{committee: array, members: list<array>}>
 */
function getPublicWorkingCommittees(): array
{
    $flat = [];
    foreach (getPublicWorkingCommitteesGrouped() as $group) {
        foreach ($group['committees'] as $item) {
            $flat[] = $item;
        }
    }
    return $flat;
}
