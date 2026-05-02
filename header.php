<?php
/**
 * Template Header
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
require_once __DIR__ . '/functions_cp.php';
require_once __DIR__ . '/functions_paie.php';

sendSecurityHeaders();
requireLogin();
$currentUser = getCurrentUser();

$page_courante = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titre ?? APP_NAME) ?> - <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link rel="shortcut icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-xxl navbar-dark navbar-blue app-navbar-sticky">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= BASE_URL ?>">
                <i class="bi bi-briefcase-fill"></i> <?= APP_NAME ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#navbarPanel" aria-controls="navbarPanel">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="offcanvas offcanvas-end offcanvas-xxl app-navbar-panel" tabindex="-1" id="navbarPanel" aria-labelledby="navbarPanelLabel">
                <div class="offcanvas-header app-navbar-panel__header">
                    <div class="app-navbar-panel__brand" id="navbarPanelLabel">
                        <i class="bi bi-briefcase-fill"></i>
                        <div>
                            <strong><?= APP_NAME ?></strong>
                            <small><?= e(getParam('nom_entreprise', 'Mon Activité')) ?></small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fermer"></button>
                </div>

                <div class="offcanvas-body app-navbar-collapse">
                    <form class="d-xxl-none position-relative app-navbar-search app-navbar-search--mobile js-smart-search" action="<?= BASE_URL ?>recherche.php" method="GET" autocomplete="off">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" 
                                   name="q" placeholder="Rechercher..." id="searchGlobalMobile"
                                   data-search-input="mobile"
                                   value="<?= e($_GET['q'] ?? '') ?>">
                            <button class="btn" type="submit">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div class="smart-search-panel" data-search-panel="mobile" hidden></div>
                    </form>

                    <ul class="navbar-nav me-auto app-navbar-nav">
                    <!-- Tableau de bord -->
                    <li class="nav-item">
                        <a class="nav-link <?= $page_courante === 'index' ? 'active' : '' ?>" href="<?= BASE_URL ?>">
                            <i class="bi bi-speedometer2"></i> Tableau de bord
                        </a>
                    </li>

                    <!-- COMMERCIAL -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array($page_courante, ['clients', 'devis', 'factures', 'commandes', 'paiements']) ? 'active' : '' ?>" 
                           href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-shop"></i> Commercial
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'clients' ? 'active' : '' ?>" href="<?= BASE_URL ?>clients.php">
                                    <i class="bi bi-people"></i> Clients
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'devis' ? 'active' : '' ?>" href="<?= BASE_URL ?>devis.php">
                                    <i class="bi bi-file-earmark-text"></i> Devis
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'commandes' ? 'active' : '' ?>" href="<?= BASE_URL ?>commandes.php">
                                    <i class="bi bi-cart-check"></i> Commandes
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'factures' ? 'active' : '' ?>" href="<?= BASE_URL ?>factures.php">
                                    <i class="bi bi-receipt"></i> Factures
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'paiements' ? 'active' : '' ?>" href="<?= BASE_URL ?>paiements.php">
                                    <i class="bi bi-credit-card"></i> Paiements
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- COMPTABILITÉ -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array($page_courante, ['comptabilite', 'transactions', 'categories', 'exercices']) ? 'active' : '' ?>" 
                           href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-journal-bookmark-fill"></i> Comptabilité
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'comptabilite' ? 'active' : '' ?>" href="<?= BASE_URL ?>comptabilite.php">
                                    <i class="bi bi-book"></i> Livres comptables
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'transactions' ? 'active' : '' ?>" href="<?= BASE_URL ?>transactions.php">
                                    <i class="bi bi-list-ul"></i> Transactions
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'categories' ? 'active' : '' ?>" href="<?= BASE_URL ?>categories.php">
                                    <i class="bi bi-tags"></i> Catégories
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'exercices' ? 'active' : '' ?>" href="<?= BASE_URL ?>exercices.php">
                                    <i class="bi bi-calendar-range"></i> Exercices
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- CAISSE & STOCK -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array($page_courante, ['caisse', 'produits', 'inventaire', 'cloture_caisse']) ? 'active' : '' ?>" 
                           href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-cart3"></i> Caisse
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'caisse' ? 'active' : '' ?>" href="<?= BASE_URL ?>caisse.php">
                                    <i class="bi bi-cart3"></i> Caisse
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'produits' ? 'active' : '' ?>" href="<?= BASE_URL ?>produits.php">
                                    <i class="bi bi-box-seam"></i> Produits & Stock
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'inventaire' ? 'active' : '' ?>" href="<?= BASE_URL ?>inventaire.php">
                                    <i class="bi bi-clipboard-check"></i> Inventaires
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?= $page_courante === 'cloture_caisse' ? 'active' : '' ?>" href="<?= BASE_URL ?>cloture_caisse.php">
                                    <i class="bi bi-lock"></i> Clôtures
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="<?= BASE_URL ?>caisse.php?action=stats">
                                    <i class="bi bi-bar-chart"></i> Statistiques caisse
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- RAPPORTS -->
                    <li class="nav-item">
                        <a class="nav-link <?= $page_courante === 'rapports' ? 'active' : '' ?>" href="<?= BASE_URL ?>rapports.php">
                            <i class="bi bi-bar-chart-line"></i> Rapports
                        </a>
                    </li>

                    <?php if (isCpModuleEnabled()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $page_courante === 'conges' ? 'active' : '' ?>" href="<?= BASE_URL ?>conges.php">
                            <i class="bi bi-calendar2-check"></i> Congés payés
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (isPaieModuleEnabled()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $page_courante === 'paie' ? 'active' : '' ?>" href="<?= BASE_URL ?>paie.php">
                            <i class="bi bi-cash-coin"></i> Paie
                        </a>
                    </li>
                    <?php endif; ?>

                    <!-- PARAMÈTRES -->
                    <li class="nav-item">
                        <a class="nav-link <?= $page_courante === 'parametres' ? 'active' : '' ?>" href="<?= BASE_URL ?>parametres.php">
                            <i class="bi bi-gear"></i> Paramètres
                        </a>
                    </li>
                    </ul>

                    <!-- Quick actions -->
                    <div class="navbar-nav me-2 app-navbar-quick">
                        <div class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="bi bi-plus-circle-fill"></i> Créer
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>devis.php?action=nouveau"><i class="bi bi-file-earmark-text text-primary"></i> Nouveau devis</a></li>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>factures.php?action=nouvelle"><i class="bi bi-receipt text-success"></i> Nouvelle facture</a></li>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>commandes.php?action=nouvelle"><i class="bi bi-cart-check text-info"></i> Nouvelle commande</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>clients.php?action=ajouter"><i class="bi bi-person-plus"></i> Nouveau client</a></li>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>transactions.php?action=ajouter"><i class="bi bi-plus-lg"></i> Nouvelle transaction</a></li>
                                <?php if (isCpModuleEnabled()): ?>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>conges.php?action=demande"><i class="bi bi-calendar2-plus"></i> Demande de CP</a></li>
                                <?php endif; ?>
                                <?php if (isPaieModuleEnabled()): ?>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>paie.php"><i class="bi bi-cash-stack"></i> Nouveau bulletin de paie</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>caisse.php?action=liste"><i class="bi bi-cart3 text-warning"></i> Ouvrir la caisse</a></li>
                            </ul>
                        </div>
                    </div>

                    <!-- Recherche globale -->
                    <form class="d-none d-xxl-flex me-2 position-relative app-navbar-search js-smart-search" action="<?= BASE_URL ?>recherche.php" method="GET" autocomplete="off">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" 
                                   name="q" placeholder="Rechercher..." id="searchGlobal"
                                   data-search-input="desktop"
                                   value="<?= e($_GET['q'] ?? '') ?>">
                            <button class="btn" type="submit">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div class="smart-search-panel" data-search-panel="desktop" hidden></div>
                    </form>

                    <div class="navbar-text text-light d-flex align-items-center gap-2 app-navbar-user">
                        <div class="dropdown">
                            <a class="btn btn-sm btn-outline-light dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="bi bi-person-circle"></i> <?= e($currentUser['nom'] ?? '') ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow-lg p-0" style="min-width: 210px;">
                                <li class="user-info-block">
                                    <div class="fw-semibold"><?= e($currentUser['nom'] ?? '') ?></div>
                                    <small class="text-muted"><?= e($currentUser['email'] ?? '') ?></small>
                                    <div class="mt-1"><span class="badge bg-<?= ($currentUser['role'] ?? '') === 'admin' ? 'danger' : (($currentUser['role'] ?? '') === 'comptable' ? 'primary' : 'secondary') ?>"><?= e(ucfirst($currentUser['role'] ?? '')) ?></span></div>
                                </li>
                                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                                <li><a class="dropdown-item py-2" href="<?= BASE_URL ?>utilisateurs.php"><i class="bi bi-people"></i> Utilisateurs</a></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item py-2" href="<?= BASE_URL ?>profil.php"><i class="bi bi-person-gear"></i> Mon profil</a></li>
                                <li><hr class="dropdown-divider my-0"></li>
                                <li>
                                    <form method="post" action="<?= BASE_URL ?>logout.php" class="m-0">
                                        <?= csrfField() ?>
                                        <button type="submit" class="dropdown-item py-2 text-danger border-0 bg-transparent w-100 text-start">
                                            <i class="bi bi-box-arrow-left"></i> Déconnexion
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <main class="container-fluid mt-3 app-content">
        <?php
        // Fil d'Ariane automatique
        $breadcrumbMap = [
            'index' => ['label' => 'Tableau de bord', 'icon' => 'speedometer2'],
            'clients' => ['label' => 'Clients', 'icon' => 'people', 'parent' => 'commercial'],
            'devis' => ['label' => 'Devis', 'icon' => 'file-earmark-text', 'parent' => 'commercial'],
            'factures' => ['label' => 'Factures', 'icon' => 'receipt', 'parent' => 'commercial'],
            'commandes' => ['label' => 'Commandes', 'icon' => 'cart-check', 'parent' => 'commercial'],
            'paiements' => ['label' => 'Paiements', 'icon' => 'credit-card', 'parent' => 'commercial'],
            'transactions' => ['label' => 'Transactions', 'icon' => 'arrow-left-right', 'parent' => 'comptabilite'],
            'categories' => ['label' => 'Catégories', 'icon' => 'tags', 'parent' => 'comptabilite'],
            'exercices' => ['label' => 'Exercices', 'icon' => 'calendar-range', 'parent' => 'comptabilite'],
            'comptabilite' => ['label' => 'Grand livre', 'icon' => 'journal-text', 'parent' => 'comptabilite'],
            'caisse' => ['label' => 'Caisse', 'icon' => 'cart3'],
            'produits' => ['label' => 'Produits', 'icon' => 'box-seam', 'parent' => 'caisse_parent'],
            'inventaire' => ['label' => 'Inventaires', 'icon' => 'clipboard-check', 'parent' => 'caisse_parent'],
            'cloture_caisse' => ['label' => 'Clôture caisse', 'icon' => 'lock', 'parent' => 'caisse_parent'],
            'rapports' => ['label' => 'Rapports', 'icon' => 'bar-chart-line'],
            'conges' => ['label' => 'Congés payés', 'icon' => 'calendar2-check'],
            'paie' => ['label' => 'Bulletins de paye', 'icon' => 'cash-coin'],
            'parametres' => ['label' => 'Paramètres', 'icon' => 'gear'],
            'utilisateurs' => ['label' => 'Utilisateurs', 'icon' => 'people'],
            'profil' => ['label' => 'Mon profil', 'icon' => 'person-gear'],
            'recherche' => ['label' => 'Recherche', 'icon' => 'search'],
            'setup' => ['label' => 'Configuration', 'icon' => 'gear-wide-connected'],
            'register' => ['label' => 'Inscription', 'icon' => 'person-plus'],
        ];
        $parentMap = [
            'commercial' => ['label' => 'Commercial', 'link' => null],
            'comptabilite' => ['label' => 'Comptabilité', 'link' => null],
            'caisse_parent' => ['label' => 'Caisse & Stock', 'link' => null],
        ];
        if ($page_courante !== 'index' && isset($breadcrumbMap[$page_courante])):
            $bc = $breadcrumbMap[$page_courante];
        ?>
        <nav aria-label="breadcrumb" class="mb-2">
            <ol class="breadcrumb mb-0" style="font-size: 0.85rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>"><i class="bi bi-house"></i></a></li>
                <?php if (isset($bc['parent'], $parentMap[$bc['parent']])): ?>
                <li class="breadcrumb-item text-muted"><?= $parentMap[$bc['parent']]['label'] ?></li>
                <?php endif; ?>
                <li class="breadcrumb-item active"><i class="bi bi-<?= $bc['icon'] ?>"></i> <?= $bc['label'] ?></li>
            </ol>
        </nav>
        <?php endif; ?>

        <?php $flash = getFlash(); if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
