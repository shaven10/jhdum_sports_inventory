<?php
/**
 * Public JSON feed for live overall rankings, medal tally, and per-event standings.
 * No login required — intended for the public live board.
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $season = getCurrentSeason();
    $overall = computeOverallStandings();
    $sportBlocks = computeSportStandings(null);

    $divisions = [];
    foreach ($overall['by_division'] ?? [] as $group) {
        $medalTally = [];
        foreach ($group['medal_tally'] ?? [] as $r) {
            $medalTally[] = [
                'medal_rank' => (int) ($r['medal_rank'] ?? 0),
                'team_id' => (int) $r['team_id'],
                'team_name' => (string) $r['team_name'],
                'short_name' => (string) ($r['short_name'] ?? $r['team_name']),
                'color' => (string) ($r['color'] ?? '#888888'),
                'gold' => (int) $r['gold'],
                'silver' => (int) $r['silver'],
                'bronze' => (int) $r['bronze'],
                'medal_total' => (int) ($r['medal_total'] ?? ($r['gold'] + $r['silver'] + $r['bronze'])),
                'total' => (int) $r['total'],
            ];
        }

        $standings = [];
        foreach ($group['standings'] ?? [] as $r) {
            $standings[] = [
                'division_rank' => (int) ($r['division_rank'] ?? $r['rank'] ?? 0),
                'team_id' => (int) $r['team_id'],
                'team_name' => (string) $r['team_name'],
                'short_name' => (string) ($r['short_name'] ?? $r['team_name']),
                'color' => (string) ($r['color'] ?? '#888888'),
                'sports' => $r['sports'] ?? [],
                'total' => (int) $r['total'],
                'gold' => (int) $r['gold'],
                'silver' => (int) $r['silver'],
                'bronze' => (int) $r['bronze'],
                'medal_total' => (int) ($r['medal_total'] ?? ($r['gold'] + $r['silver'] + $r['bronze'])),
            ];
        }

        $divisions[] = [
            'division_id' => $group['division_id'],
            'division_key' => (int) ($group['division_key'] ?? 0),
            'division_name' => (string) ($group['division_name'] ?? 'Unassigned'),
            'sport_labels' => $group['sport_labels'] ?? [],
            'event_headers' => $group['event_headers'] ?? [],
            'activated_event_count' => (int) ($group['activated_event_count'] ?? 0),
            'standings' => $standings,
            'medal_tally' => $medalTally,
            'medal_totals' => $group['medal_totals'] ?? ['gold' => 0, 'silver' => 0, 'bronze' => 0, 'all' => 0],
            'champion' => !empty($group['champion']) ? [
                'team_id' => (int) $group['champion']['team_id'],
                'team_name' => (string) $group['champion']['team_name'],
                'color' => (string) ($group['champion']['color'] ?? '#888888'),
                'total' => (int) $group['champion']['total'],
            ] : null,
            'medal_leader' => !empty($group['medal_leader']) ? [
                'team_id' => (int) $group['medal_leader']['team_id'],
                'team_name' => (string) $group['medal_leader']['team_name'],
                'color' => (string) ($group['medal_leader']['color'] ?? '#888888'),
                'medal_total' => (int) ($group['medal_leader']['medal_total'] ?? 0),
            ] : null,
        ];
    }

    $events = [];
    foreach ($sportBlocks as $sid => $block) {
        $sid = (int) $sid;
        $divBlocks = $block['divisions'] ?? [];
        if ($divBlocks === [] && !empty($block['standings'])) {
            $divBlocks = [[
                'division_id' => null,
                'division_key' => 0,
                'division_name' => 'All teams',
                'standings' => $block['standings'],
                'manual_ranks' => !empty($block['manual_ranks']),
            ]];
        }

        $eventDivisions = [];
        foreach ($divBlocks as $divBlock) {
            $rows = [];
            foreach ($divBlock['standings'] ?? [] as $r) {
                if ((int) ($r['played'] ?? 0) === 0 && empty($r['manual_rank'])) {
                    continue;
                }
                $rows[] = [
                    'rank' => (int) ($r['rank'] ?? 0),
                    'team_id' => (int) $r['team_id'],
                    'team_name' => (string) $r['team_name'],
                    'short_name' => (string) ($r['short_name'] ?? $r['team_name']),
                    'color' => (string) ($r['color'] ?? '#888888'),
                    'played' => (int) ($r['played'] ?? 0),
                    'wins' => (int) ($r['wins'] ?? 0),
                    'losses' => (int) ($r['losses'] ?? 0),
                    'draws' => (int) ($r['draws'] ?? 0),
                    'points' => (int) ($r['points'] ?? 0),
                    'placement_points' => (int) ($r['placement_points'] ?? 0),
                    'placement_label' => $r['placement_label'] ?? null,
                    'medal' => $r['medal'] ?? null,
                    'diff' => (int) ($r['diff'] ?? 0),
                    'manual_rank' => !empty($r['manual_rank']),
                ];
            }
            $eventDivisions[] = [
                'division_id' => $divBlock['division_id'] ?? null,
                'division_key' => (int) ($divBlock['division_key'] ?? 0),
                'division_name' => (string) ($divBlock['division_name'] ?? 'Unassigned'),
                'manual_ranks' => !empty($divBlock['manual_ranks']),
                'standings' => $rows,
            ];
        }

        $events[] = [
            'sport_id' => $sid,
            'label' => sportLabel($block['sport']),
            'name' => (string) ($block['sport']['name'] ?? ''),
            'category' => (string) ($block['sport']['category'] ?? ''),
            'manual_ranks' => !empty($block['manual_ranks']),
            'divisions' => $eventDivisions,
        ];
    }

    usort($events, static function (array $a, array $b): int {
        $cmp = strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcasecmp((string) ($a['category'] ?? ''), (string) ($b['category'] ?? ''));
    });

    echo json_encode([
        'ok' => true,
        'updated_at' => date('c'),
        'updated_label' => date('M j, Y g:i:s A'),
        'season' => $season ? [
            'id' => (int) $season['id'],
            'label' => seasonLabel($season),
        ] : null,
        'divisions' => $divisions,
        'events' => $events,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to load live standings.',
        'updated_at' => date('c'),
        'updated_label' => date('M j, Y g:i:s A'),
        'divisions' => [],
        'events' => [],
    ]);
}
