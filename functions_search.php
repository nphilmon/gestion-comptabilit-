<?php
/**
 * Fonctions de recherche intelligente
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
require_once __DIR__ . '/functions_caisse.php';

function smartNormalize(string $value): string {
    $value = trim(mb_strtolower($value));
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($translit !== false) {
        $value = $translit;
    }
    $value = preg_replace('/[^a-z0-9@\.\-\s]/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function smartTokens(string $value): array {
    $tokens = preg_split('/\s+/', smartNormalize($value)) ?: [];
    $tokens = array_values(array_filter($tokens, static fn($token) => mb_strlen($token) >= 2));
    return array_slice(array_unique($tokens), 0, 8);
}

function searchKeywords(): array {
    return [
        'Clients' => ['client', 'clients', 'societe', 'entreprise', 'contact'],
        'Factures' => ['facture', 'factures', 'impaye', 'impayes', 'retard', 'echeance'],
        'Devis' => ['devis', 'proposition', 'offre'],
        'Commandes' => ['commande', 'commandes', 'achat'],
        'Paiements' => ['paiement', 'paiements', 'reglement', 'encaissement'],
        'Transactions' => ['transaction', 'transactions', 'depense', 'recette', 'banque'],
        'Produits' => ['produit', 'produits', 'stock', 'reference', 'ref'],
    ];
}

function detectSearchContext(string $query): array {
    $normalized = smartNormalize($query);
    $digits = preg_replace('/\D+/', '', $query);
    $tokens = smartTokens($query);
    $keywords = searchKeywords();

    $intents = [];
    foreach ($keywords as $section => $variants) {
        foreach ($variants as $variant) {
            if (str_contains($normalized, $variant)) {
                $intents[] = $section;
                break;
            }
        }
    }

    return [
        'raw' => $query,
        'normalized' => $normalized,
        'tokens' => $tokens,
        'digits' => $digits,
        'is_email' => (bool) filter_var($query, FILTER_VALIDATE_EMAIL),
        'is_phone' => mb_strlen($digits) >= 8,
        'looks_like_doc' => (bool) preg_match('/\b(dev|fac|cmd|inv|quo|doc)[\s\-_]?\d+/i', $query),
        'looks_like_reference' => (bool) preg_match('/[A-Za-z]{2,}[-_\/]?\d+/', $query),
        'intents' => $intents,
        'likely_section' => $intents[0] ?? null,
    ];
}

function fuzzyKeywordSuggestion(array $context): ?array {
    $tokens = $context['tokens'];
    if (empty($tokens)) {
        return null;
    }

    $best = null;
    foreach ($tokens as $token) {
        if (mb_strlen($token) < 4) {
            continue;
        }
        foreach (searchKeywords() as $section => $variants) {
            foreach ($variants as $variant) {
                $distance = levenshtein($token, $variant);
                if ($distance > 0 && $distance <= 2 && ($best === null || $distance < $best['distance'])) {
                    $best = [
                        'token' => $token,
                        'suggestion' => $variant,
                        'section' => $section,
                        'distance' => $distance,
                    ];
                }
            }
        }
    }

    return $best;
}

function scoreSearchResult(array $haystacks, array $context, string $section): int {
    $score = 0;
    $normalizedFields = [];

    foreach ($haystacks as $field) {
        $normalized = smartNormalize((string) $field);
        if ($normalized !== '') {
            $normalizedFields[] = $normalized;
        }
    }

    if (empty($normalizedFields)) {
        return 0;
    }

    $combined = implode(' | ', $normalizedFields);
    $query = $context['normalized'];

    if ($context['likely_section'] === $section) {
        $score += 26;
    } elseif (in_array($section, $context['intents'], true)) {
        $score += 18;
    }

    foreach ($normalizedFields as $field) {
        if ($field === $query) {
            $score += 120;
        } elseif (str_starts_with($field, $query)) {
            $score += 80;
        } elseif (str_contains($field, $query)) {
            $score += 42;
        }
    }

    foreach ($context['tokens'] as $token) {
        if (str_contains($combined, $token)) {
            $score += 10;
        } elseif (mb_strlen($token) >= 4) {
            foreach ($normalizedFields as $field) {
                foreach (preg_split('/[\s\|]+/', $field) ?: [] as $word) {
                    if ($word !== '' && abs(strlen($word) - strlen($token)) <= 2) {
                        $distance = levenshtein($token, $word);
                        if ($distance > 0 && $distance <= 2) {
                            $score += 8;
                            break 2;
                        }
                    }
                }
            }
        }
    }

    if ($context['is_email'] && preg_match('/\b' . preg_quote($query, '/') . '\b/', $combined)) {
        $score += 40;
    }

    if ($context['is_phone'] && $context['digits'] !== '') {
        $digitsCombined = preg_replace('/\D+/', '', $combined);
        if (str_contains($digitsCombined, $context['digits'])) {
            $score += 46;
        }
    }

    if (($context['looks_like_doc'] || $context['looks_like_reference']) && preg_match('/\b' . preg_quote($query, '/') . '\b/', $combined)) {
        $score += 34;
    }

    return $score;
}

function getSmartSearchResults(string $q, array $options = []): array {
    $q = trim($q);
    $results = [];
    $smartSuggestions = [];
    $flatResults = [];
    $totalResults = 0;
    $searchContext = detectSearchContext($q);
    $perSectionLimit = (int) ($options['per_section_limit'] ?? 8);
    $fetchLimit = (int) ($options['fetch_limit'] ?? 25);

    if (mb_strlen($q) < 2) {
        return [
            'results' => [],
            'flat_results' => [],
            'total_results' => 0,
            'smart_suggestions' => [],
            'search_context' => $searchContext,
        ];
    }

    $db = getDB();
    $like = '%' . $q . '%';

    $stmt = $db->prepare("SELECT id, nom, prenom, entreprise, email, telephone, ville FROM clients WHERE nom LIKE ? OR prenom LIKE ? OR entreprise LIKE ? OR email LIKE ? OR telephone LIKE ? OR ville LIKE ? LIMIT {$fetchLimit}");
    $stmt->execute([$like, $like, $like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $label = trim(($r['entreprise'] ?: trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))));
        $score = scoreSearchResult([$label, $r['email'], $r['telephone'], $r['ville']], $searchContext, 'Clients');
        if ($score > 0) {
            $flatResults[] = [
                'section' => 'Clients',
                'score' => $score,
                'label' => $label,
                'detail' => implode(' · ', array_filter([$r['email'], $r['telephone'], $r['ville']])),
                'link' => BASE_URL . 'clients.php?action=voir&id=' . $r['id'],
                'icon' => 'person',
                'color' => 'primary',
            ];
        }
    }

    $stmt = $db->prepare("SELECT f.id, f.numero, f.montant_ttc, f.statut, COALESCE(c.entreprise, CONCAT(c.prenom, ' ', c.nom)) AS client_nom
        FROM factures f
        LEFT JOIN clients c ON f.client_id = c.id
        WHERE f.numero LIKE ? OR c.nom LIKE ? OR c.prenom LIKE ? OR c.entreprise LIKE ? OR f.statut LIKE ?
        LIMIT {$fetchLimit}");
    $stmt->execute([$like, $like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $score = scoreSearchResult([$r['numero'], $r['client_nom'], $r['statut'], (string) $r['montant_ttc']], $searchContext, 'Factures');
        if ($score > 0) {
            $flatResults[] = [
                'section' => 'Factures',
                'score' => $score,
                'label' => $r['numero'] . ' — ' . ($r['client_nom'] ?? ''),
                'detail' => formatMontant((float) $r['montant_ttc']) . ' · ' . ucfirst((string) $r['statut']),
                'link' => BASE_URL . 'factures.php?action=voir&id=' . $r['id'],
                'icon' => 'receipt',
                'color' => 'success',
            ];
        }
    }

    $stmt = $db->prepare("SELECT d.id, d.numero, d.montant_ttc, d.statut, COALESCE(c.entreprise, CONCAT(c.prenom, ' ', c.nom)) AS client_nom
        FROM devis d
        LEFT JOIN clients c ON d.client_id = c.id
        WHERE d.numero LIKE ? OR c.nom LIKE ? OR c.prenom LIKE ? OR c.entreprise LIKE ? OR d.statut LIKE ?
        LIMIT {$fetchLimit}");
    $stmt->execute([$like, $like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $score = scoreSearchResult([$r['numero'], $r['client_nom'], $r['statut'], (string) $r['montant_ttc']], $searchContext, 'Devis');
        if ($score > 0) {
            $flatResults[] = [
                'section' => 'Devis',
                'score' => $score,
                'label' => $r['numero'] . ' — ' . ($r['client_nom'] ?? ''),
                'detail' => formatMontant((float) $r['montant_ttc']) . ' · ' . ucfirst((string) $r['statut']),
                'link' => BASE_URL . 'devis.php?action=voir&id=' . $r['id'],
                'icon' => 'file-earmark-text',
                'color' => 'info',
            ];
        }
    }

    $stmt = $db->prepare("SELECT co.id, co.numero, co.montant_ttc, co.statut, COALESCE(c.entreprise, CONCAT(c.prenom, ' ', c.nom)) AS client_nom
        FROM commandes co
        LEFT JOIN clients c ON co.client_id = c.id
        WHERE co.numero LIKE ? OR c.nom LIKE ? OR c.prenom LIKE ? OR c.entreprise LIKE ? OR co.statut LIKE ?
        LIMIT {$fetchLimit}");
    $stmt->execute([$like, $like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $score = scoreSearchResult([$r['numero'], $r['client_nom'], $r['statut'], (string) $r['montant_ttc']], $searchContext, 'Commandes');
        if ($score > 0) {
            $flatResults[] = [
                'section' => 'Commandes',
                'score' => $score,
                'label' => $r['numero'] . ' — ' . ($r['client_nom'] ?? ''),
                'detail' => formatMontant((float) $r['montant_ttc']) . ' · ' . ucfirst((string) $r['statut']),
                'link' => BASE_URL . 'commandes.php?action=voir&id=' . $r['id'],
                'icon' => 'cart-check',
                'color' => 'warning',
            ];
        }
    }

    $stmt = $db->prepare("SELECT p.id, p.date_paiement, p.montant, p.mode_paiement, f.id AS facture_id, f.numero AS facture_numero,
        COALESCE(c.entreprise, CONCAT(c.prenom, ' ', c.nom)) AS client_nom
        FROM paiements p
        LEFT JOIN factures f ON p.facture_id = f.id
        LEFT JOIN clients c ON f.client_id = c.id
        WHERE f.numero LIKE ? OR c.nom LIKE ? OR c.prenom LIKE ? OR c.entreprise LIKE ? OR p.mode_paiement LIKE ?
        LIMIT {$fetchLimit}");
    $stmt->execute([$like, $like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $score = scoreSearchResult([$r['facture_numero'], $r['client_nom'], $r['mode_paiement'], (string) $r['montant']], $searchContext, 'Paiements');
        if ($score > 0) {
            $flatResults[] = [
                'section' => 'Paiements',
                'score' => $score,
                'label' => ($r['facture_numero'] ?: 'Paiement') . ' — ' . ($r['client_nom'] ?? 'Client'),
                'detail' => formatDate((string) $r['date_paiement']) . ' · ' . formatMontant((float) $r['montant']) . ' · ' . ($r['mode_paiement'] ?: 'Mode non renseigné'),
                'link' => BASE_URL . 'factures.php?action=voir&id=' . (int) ($r['facture_id'] ?? 0),
                'icon' => 'credit-card',
                'color' => 'primary',
            ];
        }
    }

    $stmt = $db->prepare("SELECT t.id, t.description, t.montant, t.type, t.date_transaction, c.nom AS categorie_nom
        FROM transactions t
        LEFT JOIN categories c ON t.categorie_id = c.id
        WHERE t.description LIKE ? OR c.nom LIKE ?
        LIMIT {$fetchLimit}");
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $score = scoreSearchResult([$r['description'], $r['categorie_nom'], $r['type'], (string) $r['montant']], $searchContext, 'Transactions');
        if ($score > 0) {
            $flatResults[] = [
                'section' => 'Transactions',
                'score' => $score,
                'label' => $r['description'],
                'detail' => formatDate((string) $r['date_transaction']) . ' · ' . formatMontant((float) $r['montant']) . (!empty($r['categorie_nom']) ? ' · ' . $r['categorie_nom'] : ''),
                'link' => BASE_URL . 'transactions.php',
                'icon' => $r['type'] === 'recette' ? 'arrow-up-circle' : 'arrow-down-circle',
                'color' => $r['type'] === 'recette' ? 'success' : 'danger',
            ];
        }
    }

    $stmt = $db->prepare("SELECT id, nom, reference, prix_vente, stock_actuel FROM produits WHERE nom LIKE ? OR reference LIKE ? LIMIT {$fetchLimit}");
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $score = scoreSearchResult([$r['nom'], $r['reference'], (string) $r['prix_vente'], (string) $r['stock_actuel']], $searchContext, 'Produits');
        if ($score > 0) {
            $flatResults[] = [
                'section' => 'Produits',
                'score' => $score,
                'label' => $r['nom'],
                'detail' => trim(($r['reference'] ? 'Réf: ' . $r['reference'] . ' · ' : '') . 'Prix: ' . formatMontant((float) $r['prix_vente']) . ' · Stock: ' . (int) $r['stock_actuel']),
                'link' => BASE_URL . 'produits.php?action=modifier&id=' . $r['id'],
                'icon' => 'box-seam',
                'color' => 'warning',
            ];
        }
    }

    usort($flatResults, static function(array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    foreach ($flatResults as $item) {
        $section = $item['section'];
        if (!isset($results[$section])) {
            $results[$section] = [];
        }
        if (count($results[$section]) < $perSectionLimit) {
            $results[$section][] = $item;
            $totalResults++;
        }
    }

    uasort($results, static function(array $a, array $b): int {
        return (($b[0]['score'] ?? 0) <=> ($a[0]['score'] ?? 0));
    });

    if ($searchContext['likely_section']) {
        $smartSuggestions[] = [
            'label' => 'Intention détectée',
            'text' => 'La recherche semble orientée vers ' . mb_strtolower($searchContext['likely_section']) . '.',
            'link' => null,
            'button' => null,
        ];
    }

    $keywordCorrection = fuzzyKeywordSuggestion($searchContext);
    if ($keywordCorrection) {
        $smartSuggestions[] = [
            'label' => 'Correction suggérée',
            'text' => 'Tu voulais peut-être dire « ' . $keywordCorrection['suggestion'] . ' ».',
            'link' => BASE_URL . 'recherche.php?q=' . urlencode(str_replace($keywordCorrection['token'], $keywordCorrection['suggestion'], $q)),
            'button' => 'Corriger',
        ];
    }

    if (preg_match('/\b(impaye|impayes|retard|relance)\b/i', $q)) {
        $smartSuggestions[] = [
            'label' => 'Raccourci métier',
            'text' => 'Ouvrir directement les factures en retard pour traiter les relances.',
            'link' => BASE_URL . 'factures.php?statut=en_retard',
            'button' => 'Voir les retards',
        ];
    }

    if (preg_match('/\b(stock|rupture|seuil)\b/i', $q)) {
        $smartSuggestions[] = [
            'label' => 'Raccourci métier',
            'text' => 'Aller au suivi de stock pour vérifier les articles faibles ou en rupture.',
            'link' => BASE_URL . 'produits.php',
            'button' => 'Ouvrir le stock',
        ];
    }

    if (preg_match('/\b(tva|ca|chiffre|rapport|marge|resultat)\b/i', $q)) {
        $smartSuggestions[] = [
            'label' => 'Analyse conseillée',
            'text' => 'Cette recherche ressemble à une demande d’analyse. Les rapports peuvent être plus adaptés que la simple recherche textuelle.',
            'link' => BASE_URL . 'rapports.php',
            'button' => 'Voir les rapports',
        ];
    }

    if (!empty($flatResults[0])) {
        $smartSuggestions[] = [
            'label' => 'Meilleure correspondance',
            'text' => $flatResults[0]['label'],
            'link' => $flatResults[0]['link'],
            'button' => 'Ouvrir',
        ];
    }

    return [
        'results' => $results,
        'flat_results' => $flatResults,
        'total_results' => $totalResults,
        'smart_suggestions' => $smartSuggestions,
        'search_context' => $searchContext,
    ];
}
