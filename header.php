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

$navSections = [
    [
        'label' => 'Tableau de bord', 'icon' => 'speedometer2', 'href' => BASE_URL, 'match' => ['index'],
    ],
    [
        'label' => 'Commercial', 'icon' => 'shop', 'match' => ['clients', 'devis', 'factures', 'commandes', 'paiements'],
        'items' => [
            ['label' => 'Clients', 'icon' => 'people', 'href' => BASE_URL . 'clients.php', 'match' => 'clients'],
            ['label' => 'Devis', 'icon' => 'file-earmark-text', 'href' => BASE_URL . 'devis.php', 'match' => 'devis'],
            ['label' => 'Commandes', 'icon' => 'cart-check', 'href' => BASE_URL . 'commandes.php', 'match' => 'commandes'],
            ['label' => 'Factures', 'icon' => 'receipt', 'href' => BASE_URL . 'factures.php', 'match' => 'factures'],
            ['label' => 'Paiements', 'icon' => 'credit-card', 'href' => BASE_URL . 'paiements.php', 'match' => 'paiements'],
        ],
    ],
    [
        'label' => 'Comptabilité', 'icon' => 'journal-bookmark-fill', 'match' => ['comptabilite', 'transactions', 'categories', 'exercices'],
        'items' => [
            ['label' => 'Livres comptables', 'icon' => 'book', 'href' => BASE_URL . 'comptabilite.php', 'match' => 'comptabilite'],
            ['label' => 'Transactions', 'icon' => 'list-ul', 'href' => BASE_URL . 'transactions.php', 'match' => 'transactions'],
            ['label' => 'Catégories', 'icon' => 'tags', 'href' => BASE_URL . 'categories.php', 'match' => 'categories'],
            ['label' => 'Exercices', 'icon' => 'calendar-range', 'href' => BASE_URL . 'exercices.php', 'match' => 'exercices'],
        ],
    ],
    [
        'label' => 'Caisse', 'icon' => 'cart3', 'match' => ['caisse', 'produits', 'inventaire', 'cloture_caisse'],
        'items' => [
            ['label' => 'Caisse', 'icon' => 'cart3', 'href' => BASE_URL . 'caisse.php', 'match' => 'caisse'],
            ['label' => 'Produits & Stock', 'icon' => 'box-seam', 'href' => BASE_URL . 'produits.php', 'match' => 'produits'],
            ['label' => 'Inventaires', 'icon' => 'clipboard-check', 'href' => BASE_URL . 'inventaire.php', 'match' => 'inventaire'],
            ['label' => 'Clôtures', 'icon' => 'lock', 'href' => BASE_URL . 'cloture_caisse.php', 'match' => 'cloture_caisse'],
        ],
    ],
    [
        'label' => 'Rapports', 'icon' => 'bar-chart-line', 'href' => BASE_URL . 'rapports.php', 'match' => ['rapports'],
    ],
];
if (isCpModuleEnabled()) {
    $navSections[] = ['label' => 'Congés payés', 'icon' => 'calendar2-check', 'href' => BASE_URL . 'conges.php', 'match' => ['conges']];
}
if (isPaieModuleEnabled()) {
    $navSections[] = ['label' => 'Paie', 'icon' => 'cash-coin', 'href' => BASE_URL . 'paie.php', 'match' => ['paie']];
}
$navSections[] = ['label' => 'Paramètres', 'icon' => 'gear', 'href' => BASE_URL . 'parametres.php', 'match' => ['parametres']];

function navSectionIsActive(array $section, string $current): bool {
    return in_array($current, $section['match'], true);
}
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link href="<?= BASE_URL ?>assets/style.css?v=<?= e(APP_VERSION) ?>" rel="stylesheet">
    <!--
        Refonte pilote (Tailwind CDN) : chargé UNIQUEMENT sur le nouveau shell +
        tableau de bord. preflight désactivé exprès pour ne jamais toucher le
        rendu Bootstrap des pages pas encore migrées (pas de reset global).
    -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            corePlugins: { preflight: false },
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        brand: {
                            50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd',
                            400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8',
                            800: '#1e40af', 900: '#1e3a8a',
                        },
                    },
                    boxShadow: {
                        card: '0 1px 2px 0 rgba(16,24,40,.05)',
                        'card-hover': '0 4px 12px 0 rgba(16,24,40,.08)',
                    },
                },
            },
        };
    </script>
</head>
<body class="tw-shell bg-gray-50 text-gray-900 antialiased">
    <div class="tw-app">
        <!-- Backdrop mobile (sidebar) -->
        <div id="sidebarBackdrop" class="fixed inset-0 z-30 bg-gray-900/40 lg:hidden hidden" data-sidebar-backdrop></div>

        <!-- SIDEBAR -->
        <aside id="appSidebar" class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-out bg-white border-r border-gray-200 flex flex-col" data-sidebar>
            <div class="h-16 flex items-center gap-2.5 px-5 border-b border-gray-100 shrink-0">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-white shrink-0">
                    <i class="bi bi-briefcase-fill"></i>
                </span>
                <div class="min-w-0">
                    <div class="text-sm font-bold leading-tight text-gray-900 truncate"><?= e(APP_NAME) ?></div>
                    <div class="text-xs text-gray-400 truncate"><?= e(getParam('nom_entreprise', 'Mon Activité')) ?></div>
                </div>
                <button type="button" class="ml-auto lg:hidden text-gray-400 hover:text-gray-600" data-sidebar-close aria-label="Fermer le menu">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                <?php foreach ($navSections as $section):
                    $active = navSectionIsActive($section, $page_courante);
                    $hasItems = !empty($section['items']);
                ?>
                <?php if (!$hasItems): ?>
                    <a href="<?= $section['href'] ?>"
                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors <?= $active ? 'bg-brand-50 text-brand-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>">
                        <i class="bi bi-<?= $section['icon'] ?> text-base <?= $active ? 'text-brand-600' : 'text-gray-400' ?>"></i>
                        <?= e($section['label']) ?>
                    </a>
                <?php else: ?>
                    <details class="group" <?= $active ? 'open' : '' ?>>
                        <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors <?= $active ? 'bg-brand-50 text-brand-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>">
                            <i class="bi bi-<?= $section['icon'] ?> text-base <?= $active ? 'text-brand-600' : 'text-gray-400' ?>"></i>
                            <span class="flex-1"><?= e($section['label']) ?></span>
                            <i class="bi bi-chevron-down text-xs text-gray-400 transition-transform group-open:rotate-180"></i>
                        </summary>
                        <div class="mt-1 ml-4 space-y-0.5 border-l border-gray-100 pl-4">
                            <?php foreach ($section['items'] as $item): $itemActive = $page_courante === $item['match']; ?>
                            <a href="<?= $item['href'] ?>"
                               class="flex items-center gap-2.5 rounded-md px-3 py-1.5 text-sm transition-colors <?= $itemActive ? 'bg-brand-50 text-brand-700 font-semibold' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' ?>">
                                <i class="bi bi-<?= $item['icon'] ?> text-sm <?= $itemActive ? 'text-brand-600' : 'text-gray-400' ?>"></i>
                                <?= e($item['label']) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <div class="border-t border-gray-100 p-3">
                <a href="<?= BASE_URL ?>profil.php" class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-gray-600 hover:bg-gray-50">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-500 shrink-0">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-gray-800"><?= e($currentUser['nom'] ?? '') ?></span>
                        <span class="block truncate text-xs text-gray-400"><?= e(ucfirst($currentUser['role'] ?? '')) ?></span>
                    </span>
                </a>
            </div>
        </aside>

        <!-- ZONE PRINCIPALE -->
        <div class="lg:pl-64 min-h-screen flex flex-col">
            <!-- TOPBAR -->
            <header class="navbar sticky top-0 z-20 h-16 bg-white border-b border-gray-200 flex items-center gap-3 px-4 sm:px-6">
                <button type="button" class="lg:hidden text-gray-500 hover:text-gray-700" data-sidebar-open aria-label="Ouvrir le menu">
                    <i class="bi bi-list text-2xl"></i>
                </button>

                <form class="relative flex-1 max-w-md js-smart-search" action="<?= BASE_URL ?>recherche.php" method="GET" autocomplete="off">
                    <div class="relative">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text"
                               class="w-full rounded-lg border border-gray-200 bg-gray-50 py-2 pl-9 pr-3 text-sm text-gray-700 placeholder:text-gray-400 focus:bg-white focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-100 transition"
                               name="q" placeholder="Rechercher..." id="searchGlobal"
                               data-search-input="desktop"
                               value="<?= e($_GET['q'] ?? '') ?>">
                    </div>
                    <div class="smart-search-panel absolute left-0 right-0 top-full mt-1 z-30" data-search-panel="desktop" hidden></div>
                </form>

                <div class="ml-auto flex items-center gap-2">
                    <div class="dropdown">
                        <button class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-200 transition"
                                type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-plus-circle-fill"></i>
                            <span class="hidden sm:inline">Créer</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg border border-gray-100 rounded-xl p-1.5 mt-2">
                            <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>devis.php?action=nouveau"><i class="bi bi-file-earmark-text text-primary"></i> Nouveau devis</a></li>
                            <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>factures.php?action=nouvelle"><i class="bi bi-receipt text-success"></i> Nouvelle facture</a></li>
                            <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>commandes.php?action=nouvelle"><i class="bi bi-cart-check text-info"></i> Nouvelle commande</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>clients.php?action=ajouter"><i class="bi bi-person-plus"></i> Nouveau client</a></li>
                            <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>transactions.php?action=ajouter"><i class="bi bi-plus-lg"></i> Nouvelle transaction</a></li>
                            <?php if (isCpModuleEnabled()): ?>
                            <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>conges.php?action=demande"><i class="bi bi-calendar2-plus"></i> Demande de CP</a></li>
                            <?php endif; ?>
                            <?php if (isPaieModuleEnabled()): ?>
                            <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>paie.php"><i class="bi bi-cash-stack"></i> Nouveau bulletin de paie</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>caisse.php?action=liste"><i class="bi bi-cart3 text-warning"></i> Ouvrir la caisse</a></li>
                        </ul>
                    </div>

                    <div class="dropdown">
                        <button class="flex items-center gap-2 rounded-lg border border-gray-200 pl-1.5 pr-2.5 py-1.5 text-sm text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-100 transition"
                                type="button" data-bs-toggle="dropdown">
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-gray-500">
                                <i class="bi bi-person-fill"></i>
                            </span>
                            <span class="hidden sm:inline font-medium max-w-[9rem] truncate"><?= e($currentUser['nom'] ?? '') ?></span>
                            <i class="bi bi-chevron-down text-xs text-gray-400"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg border border-gray-100 rounded-xl p-0 mt-2 min-w-210 overflow-hidden">
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
            </header>

            <!-- Recherche mobile (repliée sous la topbar) -->
            <form class="d-xxl-none position-relative app-navbar-search--mobile js-smart-search px-4 sm:px-6 pt-3 lg:hidden" action="<?= BASE_URL ?>recherche.php" method="GET" autocomplete="off">
                <div class="relative">
                    <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text"
                           class="w-full rounded-lg border border-gray-200 bg-gray-50 py-2 pl-9 pr-3 text-sm text-gray-700 placeholder:text-gray-400 focus:bg-white focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-100 transition"
                           name="q" placeholder="Rechercher..." id="searchGlobalMobile"
                           data-search-input="mobile"
                           value="<?= e($_GET['q'] ?? '') ?>">
                </div>
                <div class="smart-search-panel" data-search-panel="mobile" hidden></div>
            </form>

            <main class="flex-1 px-4 sm:px-6 py-6 app-content">
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
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="flex items-center gap-1.5 text-sm text-gray-500">
                        <li><a href="<?= BASE_URL ?>" class="hover:text-brand-600"><i class="bi bi-house"></i></a></li>
                        <?php if (isset($bc['parent'], $parentMap[$bc['parent']])): ?>
                        <li class="text-gray-300">/</li>
                        <li class="text-gray-400"><?= $parentMap[$bc['parent']]['label'] ?></li>
                        <?php endif; ?>
                        <li class="text-gray-300">/</li>
                        <li class="font-medium text-gray-700"><i class="bi bi-<?= $bc['icon'] ?>"></i> <?= $bc['label'] ?></li>
                    </ol>
                </nav>
                <?php endif; ?>

                <?php $flash = getFlash(); if ($flash): ?>
                    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show rounded-xl" role="alert">
                        <?= e($flash['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
