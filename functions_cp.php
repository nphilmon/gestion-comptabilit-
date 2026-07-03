<?php
/**
 * Fonctions RH - Congés payés
 */

require_once __DIR__ . '/functions.php';

function isCpModuleEnabled(): bool {
    return getParam('module_cp_actif', '0') === '1';
}

function cpStorageBasePath(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'collaborateurs';
}

function cpStorageBaseRelativePath(): string {
    return 'uploads/collaborateurs';
}

function sanitizeFolderSegment(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'collaborateur';
}

function requireCpModuleEnabled(): void {
    if (!isCpModuleEnabled()) {
        setFlash('warning', 'Le module congés payés est désactivé dans les paramètres.');
        header('Location: ' . BASE_URL);
        exit;
    }
}

function getCpCountingMode(): string {
    $mode = getParam('cp_mode_decompte', 'ouvrables');
    return in_array($mode, ['ouvrables', 'ouvres'], true) ? $mode : 'ouvrables';
}

function getCpReferencePeriod(?string $anchorDate = null): array {
    $anchor = $anchorDate ? new DateTimeImmutable($anchorDate) : new DateTimeImmutable('today');
    $startMonth = max(1, min(12, (int) getParam('cp_reference_start_month', '6')));
    $startDay = max(1, min(28, (int) getParam('cp_reference_start_day', '1')));
    $candidate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $anchor->format('Y'), $startMonth, $startDay));

    if ($anchor < $candidate) {
        $candidate = $candidate->modify('-1 year');
    }

    $end = $candidate->modify('+1 year -1 day');

    return [
        'start' => $candidate->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'label' => 'Du ' . formatDate($candidate->format('Y-m-d')) . ' au ' . formatDate($end->format('Y-m-d')),
    ];
}

function getFrenchPublicHolidays(int $year): array {
    $easter = easter_date($year);
    $easterDate = (new DateTimeImmutable())->setTimestamp($easter);

    $dates = [
        sprintf('%04d-01-01', $year),
        sprintf('%04d-05-01', $year),
        sprintf('%04d-05-08', $year),
        sprintf('%04d-07-14', $year),
        sprintf('%04d-08-15', $year),
        sprintf('%04d-11-01', $year),
        sprintf('%04d-11-11', $year),
        sprintf('%04d-12-25', $year),
        $easterDate->modify('+1 day')->format('Y-m-d'),
        $easterDate->modify('+39 days')->format('Y-m-d'),
        $easterDate->modify('+50 days')->format('Y-m-d'),
    ];

    return array_fill_keys($dates, true);
}

function calculateCpLeaveDays(string $startDate, string $endDate, ?string $mode = null): float {
    if ($startDate === '' || $endDate === '') {
        return 0.0;
    }

    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    if ($end < $start) {
        return 0.0;
    }

    $mode = $mode ?: getCpCountingMode();
    $days = 0.0;
    $holidays = [];

    for ($current = $start; $current <= $end; $current = $current->modify('+1 day')) {
        $year = (int) $current->format('Y');
        if (!isset($holidays[$year])) {
            $holidays[$year] = getFrenchPublicHolidays($year);
        }

        $dateKey = $current->format('Y-m-d');
        $weekday = (int) $current->format('N');
        $isHoliday = isset($holidays[$year][$dateKey]);

        if ($isHoliday) {
            continue;
        }

        if ($mode === 'ouvrables') {
            if ($weekday !== 7) {
                $days += 1;
            }
        } else {
            if ($weekday < 6) {
                $days += 1;
            }
        }
    }

    return round($days, 2);
}

function ensureCpProfile(int $userId): void {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO cp_profils (user_id, date_entree) SELECT id, DATE(created_at) FROM users WHERE id = ? ON DUPLICATE KEY UPDATE user_id = user_id');
    $stmt->execute([$userId]);
}

function getCpCollaboratorFolderName(array $profile): string {
    $label = $profile['nom'] ?? ('user-' . (int) $profile['user_id']);
    return sprintf('%03d-%s', (int) $profile['user_id'], sanitizeFolderSegment($label));
}

function ensureCpStorageBase(): void {
    $basePath = cpStorageBasePath();
    if (!is_dir($basePath)) {
        mkdir($basePath, 0775, true);
    }
}

function ensureCpCollaboratorFolder(int $userId): array {
    $profile = getCpProfile($userId);
    if (!$profile) {
        throw new RuntimeException('Profil collaborateur introuvable.');
    }

    ensureCpStorageBase();

    $folderName = getCpCollaboratorFolderName($profile);
    $absolutePath = cpStorageBasePath() . DIRECTORY_SEPARATOR . $folderName;

    if (!is_dir($absolutePath)) {
        mkdir($absolutePath, 0775, true);
    }

    return [
        'folder_name' => $folderName,
        'absolute_path' => $absolutePath,
        'relative_path' => cpStorageBaseRelativePath() . '/' . $folderName,
        'exists' => is_dir($absolutePath),
    ];
}

function getCpCollaboratorFolderInfo(int $userId): ?array {
    $profile = getCpProfile($userId);
    if (!$profile) {
        return null;
    }

    $folderName = getCpCollaboratorFolderName($profile);
    $absolutePath = cpStorageBasePath() . DIRECTORY_SEPARATOR . $folderName;

    return [
        'folder_name' => $folderName,
        'absolute_path' => $absolutePath,
        'relative_path' => cpStorageBaseRelativePath() . '/' . $folderName,
        'exists' => is_dir($absolutePath),
    ];
}

function ensureCpFoldersForAllProfiles(): array {
    $folders = [];
    foreach (getCpProfiles(true) as $profile) {
        $folders[(int) $profile['user_id']] = ensureCpCollaboratorFolder((int) $profile['user_id']);
    }
    return $folders;
}

function getCpProfile(int $userId): ?array {
    ensureCpProfile($userId);
    $db = getDB();
    $stmt = $db->prepare('SELECT cp.*, u.nom, u.email, u.role, u.actif AS user_actif
        FROM cp_profils cp
        JOIN users u ON u.id = cp.user_id
        WHERE cp.user_id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    return $profile ?: null;
}

function getCpProfiles(bool $includeInactive = false): array {
    $db = getDB();
    $db->exec('INSERT IGNORE INTO cp_profils (user_id, date_entree) SELECT id, DATE(created_at) FROM users');

    $sql = 'SELECT cp.*, u.nom, u.email, u.role, u.actif AS user_actif
            FROM cp_profils cp
            JOIN users u ON u.id = cp.user_id';

    if (!$includeInactive) {
        $sql .= ' WHERE cp.actif = 1 AND u.actif = 1';
    }

    $sql .= ' ORDER BY u.nom';
    return $db->query($sql)->fetchAll();
}

function getCpAdjustmentsTotal(int $userId, ?array $period = null): float {
    $db = getDB();
    $sql = 'SELECT COALESCE(SUM(jours), 0) FROM cp_ajustements WHERE user_id = ?';
    $params = [$userId];

    if ($period) {
        $sql .= ' AND date_mouvement BETWEEN ? AND ?';
        $params[] = $period['start'];
        $params[] = $period['end'];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function getCpTakenDays(int $userId, ?array $period = null): float {
    $db = getDB();
    $sql = 'SELECT COALESCE(SUM(jours_demandes), 0) FROM cp_demandes WHERE user_id = ? AND statut = "valide"';
    $params = [$userId];

    if ($period) {
        $sql .= ' AND date_debut <= ? AND date_fin >= ?';
        $params[] = $period['end'];
        $params[] = $period['start'];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function calculateCpAcquiredDays(array $profile, ?string $asOfDate = null): float {
    $period = getCpReferencePeriod($asOfDate);
    $asOf = $asOfDate ? new DateTimeImmutable($asOfDate) : new DateTimeImmutable('today');
    $periodStart = new DateTimeImmutable($period['start']);
    $periodEnd = new DateTimeImmutable($period['end']);

    if (empty($profile['date_entree'])) {
        return 0.0;
    }

    $entryDate = new DateTimeImmutable($profile['date_entree']);
    $effectiveStart = $entryDate > $periodStart ? $entryDate : $periodStart;
    $effectiveEnd = $asOf < $periodEnd ? $asOf : $periodEnd;

    if ($effectiveEnd < $effectiveStart) {
        return 0.0;
    }

    $workedDays = (int) $effectiveStart->diff($effectiveEnd)->format('%a') + 1;
    $rate = (float) getParam('cp_acquisition_rate', '2.5');
    $cap = (float) getParam('cp_annual_cap', '30');
    $base = ($workedDays / 30) * $rate;

    $periodDays = ((int) $periodStart->diff($periodEnd)->format('%a')) + 1;
    $bonus = (float) ($profile['jours_supplementaires_annuels'] ?? 0);
    if ($bonus > 0 && $periodDays > 0) {
        $base += ($workedDays / $periodDays) * $bonus;
    }

    return round(min($cap + $bonus, $base), 2);
}

function isCpFractionnementEnabled(): bool {
    return getParam('cp_fractionnement_actif', '1') === '1';
}

/**
 * Jours de congés supplémentaires pour fractionnement du congé principal
 * (Code du travail L3141-23) : si au moins 3 jours du congé principal sont
 * pris hors de la période du 1er mai au 31 octobre, le salarié a droit à 2
 * jours ouvrables supplémentaires ; s'il en prend 1 ou 2, à 1 jour.
 *
 * Approximation : cette fonction ne distingue pas le congé principal (24
 * jours ouvrables maximum) de la 5e semaine — faute de ce marquage dans les
 * demandes de congés, toutes les demandes validées de la période sont prises
 * en compte, plafonnées à 24 jours ouvrables au total. Fonction pure, sans
 * accès base de données, pour permettre des tests unitaires.
 *
 * @param array $demandesValidees Liste ordonnée chronologiquement de
 *   ['date_debut' => 'Y-m-d', 'date_fin' => 'Y-m-d', 'jours_demandes' => float, 'mode_decompte' => 'ouvrables'|'ouvres']
 */
function calculerJoursFractionnement(array $demandesValidees): float {
    $congePrincipalRestant = 24.0;
    $joursHorsSaison = 0.0;

    foreach ($demandesValidees as $demande) {
        if ($congePrincipalRestant <= 0) {
            break;
        }

        $mode = in_array($demande['mode_decompte'] ?? '', ['ouvrables', 'ouvres'], true)
            ? $demande['mode_decompte']
            : 'ouvrables';
        $totalJoursDemande = (float) ($demande['jours_demandes'] ?? 0);
        if ($totalJoursDemande <= 0) {
            continue;
        }

        $joursImputes = min($totalJoursDemande, $congePrincipalRestant);
        $congePrincipalRestant -= $joursImputes;

        $joursDansSaison = 0.0;
        foreach (array_unique([
            (int) date('Y', strtotime($demande['date_debut'])),
            (int) date('Y', strtotime($demande['date_fin'])),
        ]) as $annee) {
            $saisonDebut = max($demande['date_debut'], sprintf('%04d-05-01', $annee));
            $saisonFin = min($demande['date_fin'], sprintf('%04d-10-31', $annee));
            if ($saisonDebut <= $saisonFin) {
                $joursDansSaison += calculateCpLeaveDays($saisonDebut, $saisonFin, $mode);
            }
        }

        $joursHorsSaisonDemande = max(0.0, $totalJoursDemande - $joursDansSaison);
        if ($joursImputes < $totalJoursDemande) {
            // La demande dépasse le solde de congé principal restant : on ne
            // compte que la part imputable au congé principal, au prorata.
            $joursHorsSaisonDemande *= $joursImputes / $totalJoursDemande;
        }

        $joursHorsSaison += $joursHorsSaisonDemande;
    }

    if ($joursHorsSaison >= 3) {
        return 2.0;
    }
    if ($joursHorsSaison >= 1) {
        return 1.0;
    }
    return 0.0;
}

function getCpValidatedRequestsForPeriod(int $userId, array $period): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT date_debut, date_fin, jours_demandes, mode_decompte
        FROM cp_demandes
        WHERE user_id = ? AND statut = "valide" AND date_debut <= ? AND date_fin >= ?
        ORDER BY date_debut ASC, id ASC');
    $stmt->execute([$userId, $period['end'], $period['start']]);
    return $stmt->fetchAll();
}

function getCpBalanceForUser(int $userId, ?string $asOfDate = null): ?array {
    $profile = getCpProfile($userId);
    if (!$profile) {
        return null;
    }

    $period = getCpReferencePeriod($asOfDate);
    $acquired = calculateCpAcquiredDays($profile, $asOfDate);
    $adjustments = getCpAdjustmentsTotal($userId, $period);
    $taken = getCpTakenDays($userId, $period);
    $fractionnement = isCpFractionnementEnabled()
        ? calculerJoursFractionnement(getCpValidatedRequestsForPeriod($userId, $period))
        : 0.0;
    $initial = (float) ($profile['solde_initial'] ?? 0);
    $available = round($initial + $acquired + $adjustments + $fractionnement - $taken, 2);

    return [
        'profile' => $profile,
        'period' => $period,
        'initial' => $initial,
        'acquired' => $acquired,
        'adjustments' => $adjustments,
        'fractionnement' => $fractionnement,
        'taken' => $taken,
        'available' => $available,
    ];
}

function getCpEmployeeBalances(?string $asOfDate = null): array {
    $balances = [];
    foreach (getCpProfiles() as $profile) {
        $balance = getCpBalanceForUser((int) $profile['user_id'], $asOfDate);
        if ($balance) {
            $balances[] = $balance;
        }
    }
    return $balances;
}

function getCpRequests(array $filters = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['user_id'])) {
        $where[] = 'd.user_id = ?';
        $params[] = (int) $filters['user_id'];
    }
    if (!empty($filters['statut'])) {
        $where[] = 'd.statut = ?';
        $params[] = $filters['statut'];
    }

    $sql = 'SELECT d.*, u.nom AS user_nom, u.email AS user_email, t.nom AS traite_par_nom
            FROM cp_demandes d
            JOIN users u ON u.id = d.user_id
            LEFT JOIN users t ON t.id = d.traite_par
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY d.created_at DESC, d.id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getCpAdjustments(array $filters = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['user_id'])) {
        $where[] = 'a.user_id = ?';
        $params[] = (int) $filters['user_id'];
    }

    $sql = 'SELECT a.*, u.nom AS user_nom, c.nom AS created_by_nom
            FROM cp_ajustements a
            JOIN users u ON u.id = a.user_id
            LEFT JOIN users c ON c.id = a.created_by
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY a.date_mouvement DESC, a.id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function hasCpRequestOverlap(int $userId, string $startDate, string $endDate, ?int $excludeId = null): bool {
    $db = getDB();
    $sql = 'SELECT COUNT(*) FROM cp_demandes
            WHERE user_id = ?
              AND statut IN ("en_attente", "valide")
              AND date_debut <= ?
              AND date_fin >= ?';
    $params = [$userId, $endDate, $startDate];

    if ($excludeId) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function getCpDashboardStats(): array {
    $today = date('Y-m-d');
    $db = getDB();
    $balances = getCpEmployeeBalances();
    $pending = (int) $db->query("SELECT COUNT(*) FROM cp_demandes WHERE statut = 'en_attente'")->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM cp_demandes WHERE statut = 'valide' AND date_debut <= ? AND date_fin >= ?");
    $stmt->execute([$today, $today]);
    $onLeave = (int) $stmt->fetchColumn();

    return [
        'employees' => count($balances),
        'pending' => $pending,
        'on_leave_today' => $onLeave,
        'available_total' => round(array_sum(array_map(static fn($item) => $item['available'], $balances)), 2),
    ];
}
