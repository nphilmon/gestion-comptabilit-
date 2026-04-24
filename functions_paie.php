<?php
/**
 * Fonctions RH - Gestion des bulletins de paye
 */

require_once __DIR__ . '/functions.php';

function isPaieModuleEnabled(): bool {
    return getParam('module_paie_actif', '0') === '1';
}

function requirePaieModuleEnabled(): void {
    if (!isPaieModuleEnabled()) {
        setFlash('warning', 'Le module de paie est désactivé dans les paramètres.');
        header('Location: ' . BASE_URL);
        exit;
    }
}

function getPaieMonthlyHoursBase(): float {
    return max(0.0, (float) getParam('paie_jours_travail_mensuel', '151.67'));
}

function getPaieDefaultEmployeeChargeRate(): float {
    return max(0.0, (float) getParam('paie_taux_charges_salariales', '22'));
}

function getPaieDefaultEmployerChargeRate(): float {
    return max(0.0, (float) getParam('paie_taux_charges_patronales', '42'));
}

function ensurePaieProfile(int $userId): void {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO paie_profils (user_id, date_entree)
        SELECT id, DATE(created_at) FROM users WHERE id = ?
        ON DUPLICATE KEY UPDATE user_id = user_id');
    $stmt->execute([$userId]);
}

function getPaieProfile(int $userId): ?array {
    ensurePaieProfile($userId);
    $db = getDB();
    $stmt = $db->prepare('SELECT p.*, u.nom, u.email, u.role, u.actif AS user_actif
        FROM paie_profils p
        JOIN users u ON u.id = p.user_id
        WHERE p.user_id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    return $profile ?: null;
}

function getPaieProfiles(bool $includeInactive = false): array {
    $db = getDB();
    $db->exec('INSERT IGNORE INTO paie_profils (user_id, date_entree) SELECT id, DATE(created_at) FROM users');

    $sql = 'SELECT p.*, u.nom, u.email, u.role, u.actif AS user_actif
        FROM paie_profils p
        JOIN users u ON u.id = p.user_id';

    if (!$includeInactive) {
        $sql .= ' WHERE p.actif = 1 AND u.actif = 1';
    }

    $sql .= ' ORDER BY u.nom';
    return $db->query($sql)->fetchAll();
}

function formatPaiePeriod(string $period): string {
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        return $period;
    }
    return formatDateMois($period . '-01');
}

function getPaieStatusLabel(string $status): string {
    return match ($status) {
        'paye' => 'Payé',
        'valide' => 'Validé',
        default => 'Brouillon',
    };
}

function getPaieStatusBadgeClass(string $status): string {
    return match ($status) {
        'paye' => 'success',
        'valide' => 'primary',
        default => 'warning',
    };
}

function calculatePaieBulletinTotals(array $data): array {
    $salaireBase = max(0.0, (float) ($data['salaire_base_brut'] ?? 0));
    $heuresSupp = max(0.0, (float) ($data['heures_supplementaires'] ?? 0));
    $tauxHoraireMajore = max(0.0, (float) ($data['taux_horaire_majore'] ?? 0));
    $prime = (float) ($data['prime'] ?? 0);
    $bonus = (float) ($data['bonus'] ?? 0);
    $indemnites = (float) ($data['indemnites'] ?? 0);
    $retenues = max(0.0, (float) ($data['retenues'] ?? 0));
    $cotisationsSalariales = max(0.0, (float) ($data['cotisations_salariales'] ?? 0));
    $cotisationsPatronales = max(0.0, (float) ($data['cotisations_patronales'] ?? 0));
    $montantHeuresSupp = (float) ($data['montant_heures_supplementaires'] ?? 0);

    if ($montantHeuresSupp <= 0 && $heuresSupp > 0 && $tauxHoraireMajore > 0) {
        $montantHeuresSupp = $heuresSupp * $tauxHoraireMajore;
    }

    $salaireBrut = $salaireBase + $montantHeuresSupp + $prime + $bonus + $indemnites;
    $netImposable = max(0.0, $salaireBrut - $cotisationsSalariales);
    $netAPayer = max(0.0, $netImposable - $retenues);
    $coutTotal = max(0.0, $salaireBrut + $cotisationsPatronales);

    return [
        'montant_heures_supplementaires' => round($montantHeuresSupp, 2),
        'salaire_brut' => round($salaireBrut, 2),
        'net_imposable' => round($netImposable, 2),
        'net_a_payer' => round($netAPayer, 2),
        'cout_total_employeur' => round($coutTotal, 2),
    ];
}

function buildPaieDraftFromProfile(array $profile, string $period): array {
    $salaireBase = max(0.0, (float) ($profile['salaire_base_brut'] ?? 0));
    $chargesSalariales = round($salaireBase * getPaieDefaultEmployeeChargeRate() / 100, 2);
    $chargesPatronales = round($salaireBase * getPaieDefaultEmployerChargeRate() / 100, 2);

    $draft = [
        'periode' => $period,
        'date_paiement' => date('Y-m-t', strtotime($period . '-01')),
        'salaire_base_brut' => round($salaireBase, 2),
        'heures_travaillees' => getPaieMonthlyHoursBase(),
        'heures_supplementaires' => 0.0,
        'taux_horaire_majore' => max(0.0, (float) ($profile['taux_horaire'] ?? 0)),
        'montant_heures_supplementaires' => 0.0,
        'prime' => 0.0,
        'bonus' => 0.0,
        'indemnites' => 0.0,
        'retenues' => 0.0,
        'cotisations_salariales' => $chargesSalariales,
        'cotisations_patronales' => $chargesPatronales,
        'mode_paiement' => 'virement',
    ];

    return $draft + calculatePaieBulletinTotals($draft);
}

function getPaieLatestPeriods(int $limit = 12): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT DISTINCT periode FROM bulletins_paie ORDER BY periode DESC LIMIT ' . max(1, (int) $limit));
    $stmt->execute();
    return array_map(static fn(array $row): string => $row['periode'], $stmt->fetchAll());
}

function getPaieDashboardStats(?string $period = null): array {
    $db = getDB();
    $period = $period && preg_match('/^\d{4}-\d{2}$/', $period) ? $period : date('Y-m');
    $db->exec('INSERT IGNORE INTO paie_profils (user_id, date_entree) SELECT id, DATE(created_at) FROM users');

    $employees = (int) $db->query('SELECT COUNT(*) FROM paie_profils p JOIN users u ON u.id = p.user_id WHERE p.actif = 1 AND u.actif = 1')->fetchColumn();

    $stmt = $db->prepare('SELECT
            COUNT(*) AS bulletins_count,
            SUM(CASE WHEN statut = "brouillon" THEN 1 ELSE 0 END) AS drafts_count,
            COALESCE(SUM(net_a_payer), 0) AS net_total,
            COALESCE(SUM(cout_total_employeur), 0) AS employer_total
        FROM bulletins_paie
        WHERE periode = ?');
    $stmt->execute([$period]);
    $row = $stmt->fetch() ?: [];

    return [
        'period' => $period,
        'employees' => $employees,
        'bulletins_count' => (int) ($row['bulletins_count'] ?? 0),
        'drafts_count' => (int) ($row['drafts_count'] ?? 0),
        'net_total' => (float) ($row['net_total'] ?? 0),
        'employer_total' => (float) ($row['employer_total'] ?? 0),
    ];
}

function getPaieBulletins(array $filters = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['user_id'])) {
        $where[] = 'b.user_id = ?';
        $params[] = (int) $filters['user_id'];
    }

    if (!empty($filters['periode']) && preg_match('/^\d{4}-\d{2}$/', (string) $filters['periode'])) {
        $where[] = 'b.periode = ?';
        $params[] = $filters['periode'];
    }

    if (!empty($filters['statut'])) {
        $where[] = 'b.statut = ?';
        $params[] = $filters['statut'];
    }

    $sql = 'SELECT b.*, u.nom AS user_nom, p.poste, p.matricule
        FROM bulletins_paie b
        JOIN users u ON u.id = b.user_id
        LEFT JOIN paie_profils p ON p.user_id = b.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY b.periode DESC, u.nom ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getPaieBulletin(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT b.*, u.nom AS user_nom, u.email AS user_email, p.poste, p.matricule, p.iban, p.numero_securite_sociale
        FROM bulletins_paie b
        JOIN users u ON u.id = b.user_id
        LEFT JOIN paie_profils p ON p.user_id = b.user_id
        WHERE b.id = ?');
    $stmt->execute([$id]);
    $bulletin = $stmt->fetch();
    return $bulletin ?: null;
}
