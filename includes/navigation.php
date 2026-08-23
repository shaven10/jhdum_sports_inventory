<?php
/**
 * Role-aware app navigation (desktop dropdowns + mobile offcanvas).
 */

/**
 * @return list<array{type:string,label?:string,href?:string,icon?:string,items?:list<array>}>
 */
function buildAppNavigation(): array
{
    $nav = [];

    $nav[] = [
        'type' => 'link',
        'label' => 'Dashboard',
        'href' => BASE_URL . '/dashboard.php',
        'icon' => 'bi-speedometer2',
    ];

    if (canViewAnnouncements()) {
        $nav[] = [
            'type' => 'link',
            'label' => 'Announcements',
            'href' => BASE_URL . '/announcements/index.php',
            'icon' => 'bi-megaphone',
        ];
    }

    if (canViewInventoryDashboard()) {
        $items = [
            ['label' => 'Equipment', 'href' => BASE_URL . '/equipment/index.php', 'icon' => 'bi-box-seam'],
            ['label' => 'Requests', 'href' => BASE_URL . '/requests/index.php', 'icon' => 'bi-clipboard-check'],
        ];
        if (canManageInventory()) {
            $items[] = ['label' => 'Transactions', 'href' => BASE_URL . '/transactions/index.php', 'icon' => 'bi-arrow-left-right'];
            $items[] = ['label' => 'Maintenance', 'href' => BASE_URL . '/equipment/maintenance.php', 'icon' => 'bi-tools'];
        }
        if (canViewReports() && canManageInventory()) {
            $items[] = ['type' => 'header', 'label' => 'Reports'];
            $items[] = ['label' => 'Inventory Report', 'href' => BASE_URL . '/reports/inventory.php', 'icon' => 'bi-file-earmark-bar-graph'];
            $items[] = ['label' => 'Borrowing Report', 'href' => BASE_URL . '/reports/borrowings.php', 'icon' => 'bi-file-earmark-text'];
            $items[] = ['label' => 'Overdue Report', 'href' => BASE_URL . '/reports/overdue.php', 'icon' => 'bi-exclamation-circle'];
            $items[] = ['label' => 'Damage Report', 'href' => BASE_URL . '/reports/damage.php', 'icon' => 'bi-bandaid'];
            $items[] = ['label' => 'Analytics', 'href' => BASE_URL . '/reports/analytics.php', 'icon' => 'bi-pie-chart'];
        }
        $nav[] = ['type' => 'group', 'label' => 'Inventory', 'icon' => 'bi-box-seam', 'items' => $items];
    }

    if (canViewCompetitionDashboard() && !isPublication()) {
        $items = [
            ['label' => 'Match Results', 'href' => BASE_URL . '/intramurals/matches/index.php', 'icon' => 'bi-list-check'],
        ];
        if (!(isTournamentManager() && !canManageIntramurals())) {
            $items[] = ['label' => 'Overall Standing', 'href' => BASE_URL . '/intramurals/standings/overall.php', 'icon' => 'bi-award'];
        }
        if (canManageIntramurals()) {
            $items[] = ['label' => 'Per-Sport Standings', 'href' => BASE_URL . '/intramurals/standings/index.php', 'icon' => 'bi-bar-chart-steps'];
        }
        if (canManageEventRankings()) {
            $items[] = ['label' => 'Manual Entry of Ranks', 'href' => BASE_URL . '/intramurals/rankings/index.php', 'icon' => 'bi-list-ol'];
        }
        if (isTournamentManager() && !canManageIntramurals()) {
            $items[] = ['type' => 'header', 'label' => 'Reports'];
            $items[] = ['label' => 'Match Results Report', 'href' => BASE_URL . '/intramurals/reports/index.php?type=results', 'icon' => 'bi-file-earmark-text'];
            $items[] = ['label' => 'Team Standings Report', 'href' => BASE_URL . '/intramurals/reports/index.php?type=standings', 'icon' => 'bi-file-earmark-bar-graph'];
            $items[] = ['label' => 'Medal Tally Report', 'href' => BASE_URL . '/intramurals/reports/index.php?type=medals', 'icon' => 'bi-trophy'];
        }
        $nav[] = ['type' => 'group', 'label' => 'Results', 'icon' => 'bi-trophy', 'items' => $items];
    }

    if (canViewIntramurals()) {
        $items = [
            [
                'label' => isCoach() && !canManageIntramurals() ? 'Dashboard' : 'Intramurals Home',
                'href' => BASE_URL . '/intramurals/index.php',
                'icon' => 'bi-speedometer2',
            ],
        ];
        if (canManageIntramurals()) {
            $items[] = ['label' => 'Seasons / Years', 'href' => BASE_URL . '/intramurals/seasons/index.php', 'icon' => 'bi-calendar3'];
        }

        if (isCoach() && !canManageIntramurals()) {
            $items[] = ['type' => 'header', 'label' => 'My Assignments'];
            if (hasCoachAssignments()) {
                $items[] = ['label' => 'My Teams & Events', 'href' => BASE_URL . '/intramurals/teams/index.php', 'icon' => 'bi-shield'];
                $items[] = ['label' => 'Athletes', 'href' => BASE_URL . '/intramurals/athletes/index.php', 'icon' => 'bi-people'];
                $items[] = ['label' => 'Rosters', 'href' => BASE_URL . '/intramurals/roster/index.php', 'icon' => 'bi-person-lines-fill'];
                $items[] = ['label' => 'Import Roster', 'href' => BASE_URL . '/intramurals/roster/import.php', 'icon' => 'bi-upload'];
                $items[] = ['label' => 'Entry Form Gallery', 'href' => BASE_URL . '/intramurals/roster/gallery.php', 'icon' => 'bi-images'];
                $items[] = ['label' => 'Team Athlete List', 'href' => BASE_URL . '/intramurals/roster/team_list.php', 'icon' => 'bi-people'];
            } else {
                $items[] = ['type' => 'text', 'label' => 'No event assignments yet. Ask your unit manager under Teams → Event Coaches.'];
            }
            $items[] = ['label' => 'Sport Guidelines', 'href' => BASE_URL . '/intramurals/sports/guidelines.php', 'icon' => 'bi-journal-text'];
        } else {
            $items[] = ['type' => 'header', 'label' => 'Participants'];
            $items[] = ['label' => 'Athletes', 'href' => BASE_URL . '/intramurals/athletes/index.php', 'icon' => 'bi-people'];
            $items[] = ['label' => 'Teams', 'href' => BASE_URL . '/intramurals/teams/index.php', 'icon' => 'bi-shield'];
            $items[] = ['label' => 'Sports / Events', 'href' => BASE_URL . '/intramurals/sports/index.php', 'icon' => 'bi-trophy'];
            if (canManageIntramurals()) {
                $items[] = ['label' => 'Tournament Managers', 'href' => BASE_URL . '/intramurals/sports/managers.php', 'icon' => 'bi-person-gear'];
                $items[] = ['label' => 'Sport Guidelines', 'href' => BASE_URL . '/intramurals/sports/guidelines.php', 'icon' => 'bi-journal-text'];
            }
            $items[] = ['label' => 'Rosters', 'href' => BASE_URL . '/intramurals/roster/index.php', 'icon' => 'bi-person-lines-fill'];
            if (canManageTeamAthletes() || canManageTeamRoster()) {
                $items[] = ['label' => 'Import Roster', 'href' => BASE_URL . '/intramurals/roster/import.php', 'icon' => 'bi-upload'];
            }
            $items[] = ['label' => 'Entry Form Gallery', 'href' => BASE_URL . '/intramurals/roster/gallery.php', 'icon' => 'bi-images'];
            $items[] = ['label' => 'Team Athlete List', 'href' => BASE_URL . '/intramurals/roster/team_list.php', 'icon' => 'bi-people'];

            $items[] = ['type' => 'header', 'label' => 'Competition'];
            $items[] = ['label' => 'Matches', 'href' => BASE_URL . '/intramurals/matches/index.php', 'icon' => 'bi-calendar-event'];
            if (canGenerateMatches()) {
                $items[] = ['label' => 'Generate Matches', 'href' => BASE_URL . '/intramurals/matches/generate.php', 'icon' => 'bi-magic'];
            }
            $items[] = ['label' => 'Calendar', 'href' => BASE_URL . '/intramurals/matches/calendar.php', 'icon' => 'bi-calendar3'];
            if (canViewStandings()) {
                $items[] = ['label' => 'Standings', 'href' => BASE_URL . '/intramurals/standings/index.php', 'icon' => 'bi-bar-chart-steps'];
                $items[] = ['label' => 'Overall Standing', 'href' => BASE_URL . '/intramurals/standings/overall.php', 'icon' => 'bi-award'];
            }
            if (canManageEventRankings()) {
                $items[] = ['label' => 'Manual Entry of Ranks', 'href' => BASE_URL . '/intramurals/rankings/index.php', 'icon' => 'bi-list-ol'];
            }
            $items[] = ['label' => 'Point System', 'href' => BASE_URL . '/intramurals/points/index.php', 'icon' => 'bi-calculator'];
            $items[] = ['type' => 'header', 'label' => 'Reports'];
            $items[] = ['label' => 'Intramurals Reports', 'href' => BASE_URL . '/intramurals/reports/index.php', 'icon' => 'bi-printer'];
            $items[] = ['label' => 'Certificate of Recognition', 'href' => BASE_URL . '/intramurals/reports/certificates.php', 'icon' => 'bi-award'];
        }
        $nav[] = ['type' => 'group', 'label' => 'Intramurals', 'icon' => 'bi-trophy-fill', 'items' => $items];
    }

    if (isSecretariat()) {
        $nav[] = [
            'type' => 'group',
            'label' => 'Competition',
            'icon' => 'bi-calendar3',
            'items' => [
                ['label' => 'All Matches & Results', 'href' => BASE_URL . '/intramurals/matches/index.php', 'icon' => 'bi-list-check'],
                ['label' => 'Generate Matches', 'href' => BASE_URL . '/intramurals/matches/generate.php', 'icon' => 'bi-magic'],
                ['label' => 'Calendar', 'href' => BASE_URL . '/intramurals/matches/calendar.php', 'icon' => 'bi-calendar3'],
                ['label' => 'Official Rosters', 'href' => BASE_URL . '/intramurals/roster/index.php', 'icon' => 'bi-person-lines-fill'],
                ['label' => 'Entry Form Gallery', 'href' => BASE_URL . '/intramurals/roster/gallery.php', 'icon' => 'bi-images'],
                ['label' => 'Team Athlete List', 'href' => BASE_URL . '/intramurals/roster/team_list.php', 'icon' => 'bi-people'],
                ['type' => 'header', 'label' => 'Standings'],
                ['label' => 'Team Standings', 'href' => BASE_URL . '/intramurals/standings/index.php', 'icon' => 'bi-bar-chart-steps'],
                ['label' => 'Overall Standing', 'href' => BASE_URL . '/intramurals/standings/overall.php', 'icon' => 'bi-award'],
                ['label' => 'Manual Entry of Ranks', 'href' => BASE_URL . '/intramurals/rankings/index.php', 'icon' => 'bi-list-ol'],
                ['type' => 'header', 'label' => 'More'],
                ['label' => 'Reports', 'href' => BASE_URL . '/intramurals/reports/index.php', 'icon' => 'bi-printer'],
                ['label' => 'Certificate of Recognition', 'href' => BASE_URL . '/intramurals/reports/certificates.php', 'icon' => 'bi-award'],
                ['label' => 'Sports / Events', 'href' => BASE_URL . '/intramurals/sports/index.php', 'icon' => 'bi-trophy'],
                ['label' => 'Point System', 'href' => BASE_URL . '/intramurals/points/index.php', 'icon' => 'bi-calculator'],
                ['label' => 'Sport Guidelines', 'href' => BASE_URL . '/intramurals/sports/guidelines.php', 'icon' => 'bi-journal-text'],
            ],
        ];
    } elseif (isPublication()) {
        $nav[] = [
            'type' => 'group',
            'label' => 'Publication',
            'icon' => 'bi-eye',
            'items' => [
                ['label' => 'Dashboard', 'href' => BASE_URL . '/dashboard.php', 'icon' => 'bi-speedometer2'],
                ['label' => 'Match Results', 'href' => BASE_URL . '/intramurals/matches/index.php', 'icon' => 'bi-list-check'],
                ['label' => 'Overall Standing', 'href' => BASE_URL . '/intramurals/standings/overall.php', 'icon' => 'bi-award'],
                ['label' => 'Sports / Events', 'href' => BASE_URL . '/intramurals/sports/index.php', 'icon' => 'bi-trophy'],
            ],
        ];
    } elseif (isTournamentManager() && !canManageIntramurals()) {
        $items = [
            ['label' => 'Matches & Results', 'href' => BASE_URL . '/intramurals/matches/index.php', 'icon' => 'bi-list-check'],
        ];
        if (canGenerateMatches()) {
            $items[] = ['label' => 'Generate Matches', 'href' => BASE_URL . '/intramurals/matches/generate.php', 'icon' => 'bi-magic'];
        }
        $items = array_merge($items, [
            ['label' => 'Calendar', 'href' => BASE_URL . '/intramurals/matches/calendar.php', 'icon' => 'bi-calendar3'],
            ['label' => 'Incident Reports', 'href' => BASE_URL . '/incidents/index.php', 'icon' => 'bi-flag'],
            ['label' => 'Sports / Events', 'href' => BASE_URL . '/intramurals/sports/index.php', 'icon' => 'bi-trophy'],
            ['label' => 'Sport Guidelines', 'href' => BASE_URL . '/intramurals/sports/guidelines.php', 'icon' => 'bi-journal-text'],
        ]);
        $nav[] = ['type' => 'group', 'label' => 'My Events', 'icon' => 'bi-calendar-event', 'items' => $items];
    } elseif (hasRole('unit_manager')) {
        $teamId = (int) (getUserTeamId() ?? 0);
        $items = [];
        if ($teamId) {
            $items[] = ['label' => 'Team Profile', 'href' => BASE_URL . '/intramurals/teams/view.php?id=' . $teamId, 'icon' => 'bi-shield'];
            $items[] = ['label' => 'Event Coaches', 'href' => BASE_URL . '/intramurals/teams/coaches.php?id=' . $teamId, 'icon' => 'bi-people'];
            $items[] = ['label' => 'Add Coach', 'href' => BASE_URL . '/intramurals/teams/coaches.php?id=' . $teamId . '&add_coach=1', 'icon' => 'bi-person-plus'];
        }
        $items = array_merge($items, [
            ['label' => 'Athletes', 'href' => BASE_URL . '/intramurals/athletes/index.php', 'icon' => 'bi-people'],
            ['label' => 'Rosters', 'href' => BASE_URL . '/intramurals/roster/index.php', 'icon' => 'bi-person-lines-fill'],
            ['label' => 'Import Roster', 'href' => BASE_URL . '/intramurals/roster/import.php', 'icon' => 'bi-upload'],
            ['label' => 'Entry Form Gallery', 'href' => BASE_URL . '/intramurals/roster/gallery.php', 'icon' => 'bi-images'],
            ['label' => 'Team Athlete List', 'href' => BASE_URL . '/intramurals/roster/team_list.php', 'icon' => 'bi-people'],
            ['label' => 'Incident Reports', 'href' => BASE_URL . '/incidents/index.php', 'icon' => 'bi-flag'],
            ['label' => 'Sports / Events', 'href' => BASE_URL . '/intramurals/sports/index.php', 'icon' => 'bi-trophy'],
            ['label' => 'Point System', 'href' => BASE_URL . '/intramurals/points/index.php', 'icon' => 'bi-calculator'],
            ['label' => 'Sport Guidelines', 'href' => BASE_URL . '/intramurals/sports/guidelines.php', 'icon' => 'bi-journal-text'],
        ]);
        $nav[] = ['type' => 'group', 'label' => 'My Team', 'icon' => 'bi-shield', 'items' => $items];
    }

    if (isAdmin()) {
        $nav[] = [
            'type' => 'group',
            'label' => 'Admin',
            'icon' => 'bi-shield-lock',
            'items' => [
                ['label' => 'Admin Panel', 'href' => BASE_URL . '/admin/index.php', 'icon' => 'bi-grid-1x2'],
                ['type' => 'header', 'label' => 'People'],
                ['label' => 'Users', 'href' => BASE_URL . '/users/index.php', 'icon' => 'bi-people'],
                ['label' => 'Working Committees', 'href' => BASE_URL . '/admin/committees/index.php', 'icon' => 'bi-person-badge'],
                ['label' => 'Incident Reports', 'href' => BASE_URL . '/admin/incidents/index.php', 'icon' => 'bi-flag'],
                ['type' => 'header', 'label' => 'System'],
                ['label' => 'Announcements', 'href' => BASE_URL . '/admin/announcements/index.php', 'icon' => 'bi-megaphone'],
                ['label' => 'System Settings', 'href' => BASE_URL . '/settings/index.php', 'icon' => 'bi-gear'],
                ['label' => 'Theme Manager', 'href' => BASE_URL . '/settings/theme.php', 'icon' => 'bi-palette'],
                ['label' => 'Equipment Categories', 'href' => BASE_URL . '/settings/categories.php', 'icon' => 'bi-tags'],
                ['label' => 'Courses', 'href' => BASE_URL . '/admin/courses/index.php', 'icon' => 'bi-mortarboard'],
                ['label' => 'Database Tools', 'href' => BASE_URL . '/settings/database.php', 'icon' => 'bi-database-gear'],
                ['label' => 'Audit Logs', 'href' => BASE_URL . '/audit/index.php', 'icon' => 'bi-journal-check'],
                ['type' => 'header', 'label' => 'Competition Admin'],
                ['label' => 'Seasons / Years', 'href' => BASE_URL . '/intramurals/seasons/index.php', 'icon' => 'bi-calendar3'],
                ['label' => 'Roster Lock', 'href' => BASE_URL . '/intramurals/roster/lock.php', 'icon' => 'bi-lock'],
                ['label' => 'Lock Results', 'href' => BASE_URL . '/admin/results_lock.php', 'icon' => 'bi-lock-fill'],
                ['label' => 'TM Ranking Access', 'href' => BASE_URL . '/admin/tm_ranking.php', 'icon' => 'bi-list-check'],
                ['label' => 'Divisions', 'href' => BASE_URL . '/admin/divisions/index.php', 'icon' => 'bi-diagram-3'],
                ['label' => 'Delete Athletes', 'href' => BASE_URL . '/admin/athletes/delete.php', 'icon' => 'bi-person-x'],
                ['label' => 'Team Positions', 'href' => BASE_URL . '/admin/team_positions/index.php', 'icon' => 'bi-list-ol'],
                ['label' => 'Certificate of Recognition', 'href' => BASE_URL . '/intramurals/reports/certificates.php', 'icon' => 'bi-award'],
            ],
        ];
    }

    return $nav;
}

function renderDesktopNav(array $nav): void
{
    foreach ($nav as $entry) {
        if (($entry['type'] ?? '') === 'link') {
            echo '<li class="nav-item">';
            echo '<a class="nav-link" href="' . sanitize($entry['href']) . '">';
            echo '<i class="bi ' . sanitize($entry['icon'] ?? '') . '"></i> ' . sanitize($entry['label']);
            echo '</a></li>';
            continue;
        }

        if (($entry['type'] ?? '') !== 'group') {
            continue;
        }

        echo '<li class="nav-item dropdown">';
        echo '<a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" data-bs-auto-close="outside">';
        echo '<i class="bi ' . sanitize($entry['icon'] ?? '') . '"></i> ' . sanitize($entry['label']);
        echo '</a><ul class="dropdown-menu dropdown-menu-nav">';

        $first = true;
        foreach ($entry['items'] ?? [] as $item) {
            $itemType = $item['type'] ?? 'link';
            if ($itemType === 'header') {
                if (!$first) {
                    echo '<li><hr class="dropdown-divider"></li>';
                }
                echo '<li><h6 class="dropdown-header">' . sanitize($item['label']) . '</h6></li>';
            } elseif ($itemType === 'text') {
                echo '<li><span class="dropdown-item-text text-muted small">' . sanitize($item['label']) . '</span></li>';
            } else {
                $icon = !empty($item['icon']) ? '<i class="bi ' . sanitize($item['icon']) . '"></i> ' : '';
                echo '<li><a class="dropdown-item" href="' . sanitize($item['href']) . '">' . $icon . sanitize($item['label']) . '</a></li>';
            }
            $first = false;
        }

        echo '</ul></li>';
    }
}

function renderMobileNav(array $nav): void
{
    foreach ($nav as $entry) {
        if (($entry['type'] ?? '') === 'link') {
            echo '<a class="mobile-nav-link" href="' . sanitize($entry['href']) . '">';
            echo '<i class="bi ' . sanitize($entry['icon'] ?? '') . '"></i><span>' . sanitize($entry['label']) . '</span>';
            echo '</a>';
            continue;
        }

        if (($entry['type'] ?? '') !== 'group') {
            continue;
        }

        echo '<div class="mobile-nav-section">';
        echo '<div class="mobile-nav-section-title"><i class="bi ' . sanitize($entry['icon'] ?? '') . '"></i> ' . sanitize($entry['label']) . '</div>';
        foreach ($entry['items'] ?? [] as $item) {
            $itemType = $item['type'] ?? 'link';
            if ($itemType === 'header') {
                echo '<div class="mobile-nav-subheader">' . sanitize($item['label']) . '</div>';
            } elseif ($itemType === 'text') {
                echo '<div class="mobile-nav-note">' . sanitize($item['label']) . '</div>';
            } else {
                $icon = !empty($item['icon']) ? '<i class="bi ' . sanitize($item['icon']) . '"></i>' : '<i class="bi bi-dot"></i>';
                echo '<a class="mobile-nav-link" href="' . sanitize($item['href']) . '">' . $icon . '<span>' . sanitize($item['label']) . '</span></a>';
            }
        }
        echo '</div>';
    }
}
