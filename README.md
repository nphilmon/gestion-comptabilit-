# Gestion Comptable Pro v3.0.1.1

Application web de gestion comptable et commerciale pour auto-entrepreneurs, micro-entreprises et PME françaises. Fonctionne comme un **expert-comptable virtuel** avec gestion complète des documents commerciaux, caisse, comptabilité, ressources humaines et rapports.

## Fonctionnalités

### Commercial
- **Factures** — Création, suivi des paiements, statuts (brouillon, envoyée, payée, en retard…), génération PDF
- **Devis** — Création, conversion en facture ou commande, suivi de validité
- **Commandes** — Gestion complète, conversion en facture
- **Clients** — Fiche client avec recherche SIREN/SIRET via l'API Entreprise (gouvernement)
- **Facturation électronique** — Préparation et export structuré (Factur-X / UBL / CII) en vue de la réforme e-invoicing, suivi du statut de transmission par plateforme (PDP)

### Comptabilité
- **Transactions** — Saisie des recettes et dépenses
- **Exercices comptables** — Gestion des périodes fiscales
- **Rapports** — Tableaux de bord, bilans, exports
- **Intelligence** — Dashboard prédictif, alertes proactives, trésorerie prévisionnelle, auto-catégorisation

### Ressources humaines
- **Congés payés** — Acquisition automatique (jours ouvrables/ouvrés), soldes par collaborateur, demandes avec validation, **jours de fractionnement du congé principal** (Code du travail L3141-23)
- **Paie** — Saisie et génération des bulletins de paye, calcul du **Montant Net Social** (mention obligatoire depuis le 01/01/2024), export PDF

### Caisse
- **Point de vente** — Interface de caisse avec recherche produit
- **Tickets de caisse** — Génération et impression
- **Clôture de caisse** — Bilan journalier
- **Produits & Inventaire** — Gestion du stock avec alertes

### Système
- **Authentification** — Connexion sécurisée, inscription, gestion des rôles (admin/comptable)
- **Sécurité** — CSRF, bcrypt, CSP headers, anti-bruteforce, timeout de session, journal d'activité
- **Multi-régimes** — Micro-entreprise, auto-entrepreneur, réel simplifié/normal, IR/IS (TVA configurable)
- **Export CSV** — Export de toutes les données (clients, factures, transactions…)
- **Recherche globale** — Recherche unifiée dans tout le système
- **Génération PDF** — Factures, devis, commandes, bulletins de paye via FPDF

## Prérequis

- **PHP** 8.1+
- **MySQL** 5.7+ / MariaDB 10.3+
- **WAMP**, XAMPP ou serveur Apache/Nginx équivalent
- Extensions PHP : `pdo_mysql`, `mbstring`, `curl`
- **Composer** — optionnel, uniquement pour exécuter les tests automatisés (non requis en production)

## Installation

1. **Cloner le projet** dans le dossier web de votre serveur :
   ```
   cd C:\wamp64\www
   git clone <url> "gestion comptabilité"
   ```

2. **Créer la base de données** MySQL :
   ```sql
   CREATE DATABASE gestion_compta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **Importer le schéma** (dans l'ordre) :
   ```
   database/schema.sql               # Schéma complet : commercial, caisse, RH, paramétrage
   database/migration_multi_regimes.sql
   ```
   Les migrations `migration_commercial.sql` et `migration_caisse.sql` sont auto-appliquées par l'application au premier chargement si les tables correspondantes sont absentes (utile en cas de mise à jour d'une installation existante). `migration_cp.sql`, `migration_paie.sql` et `migration_indemnite_sante_ancv.sql` sont conservées pour référence historique — leurs tables/colonnes sont déjà créées directement par `schema.sql` et par les migrations automatiques de `config.php`.

4. **Configurer** la connexion si nécessaire via les variables d'environnement suivantes :
   ```powershell
   $env:GESTION_COMPTA_DB_HOST='localhost'
   $env:GESTION_COMPTA_DB_NAME='gestion_compta'
   $env:GESTION_COMPTA_DB_USER='root'
   $env:GESTION_COMPTA_DB_PASS=''
   $env:GESTION_COMPTA_BASE_URL='/gestion%20comptabilit%C3%A9/'
   ```
   Si votre hébergement ne transmet pas les variables d'environnement à PHP (voir section cPanel ci-dessous), copiez `config.local.php.example` en `config.local.php` et renseignez-y directement ces valeurs.

5. **Accéder à l'application** :
   ```
   http://localhost/gestion%20comptabilit%C3%A9/
   ```

6. **Premier lancement** — L'assistant de configuration (`setup.php`) vous guidera pour créer le compte administrateur, renseigner les informations de l'entreprise et choisir le régime fiscal. Il se verrouille automatiquement dès qu'un compte administrateur existe.

## Structure du projet

```
├── config.php                 # Configuration BDD, environnement et constantes
├── functions.php              # Auth, sécurité, utilitaires, calculs fiscaux
├── functions_commercial.php   # CRUD clients, factures, devis, commandes
├── functions_caisse.php       # CRUD produits, opérations caisse
├── functions_cp.php           # Congés payés : soldes, acquisition, fractionnement
├── functions_paie.php         # Paie : bulletins, Montant Net Social
├── functions_search.php       # Recherche globale
├── functions_intelligence.php # Dashboard prédictif, alertes, KPIs
├── header.php / footer.php    # Layout, navbar, CSP headers
├── commercial_header.php      # Sous-navigation du module commercial
│
├── index.php                  # Tableau de bord / Dashboard
├── login.php / register.php / logout.php
├── setup.php                  # Assistant de configuration
├── profil.php                 # Profil utilisateur
├── utilisateurs.php           # Gestion des utilisateurs (admin)
│
├── factures.php / devis.php / commandes.php / clients.php / paiements.php
├── export_einvoice.php        # Export facturation électronique (Factur-X/UBL/CII)
│
├── caisse.php / produits.php / inventaire.php
├── ticket_caisse.php / cloture_caisse.php
│
├── transactions.php / comptabilite.php / exercices.php / rapports.php / categories.php
│
├── conges.php                 # Module RH — congés payés
├── paie.php                   # Module RH — bulletins de paye
├── saisie_bulletin_paie.php   # Saisie et aperçu temps réel d'un bulletin
├── bulletin_paie_pdf.php      # Génération PDF du bulletin
│
├── recherche.php / recherche_suggestions.php
├── export_csv.php / pdf_generator.php / api_entreprise.php
├── parametres.php             # Paramètres de l'application
│
├── assets/
│   └── style.css              # Thème personnalisé
├── database/
│   ├── schema.sql              # Schéma principal (à jour)
│   ├── migration_multi_regimes.sql
│   ├── migration_commercial.sql
│   ├── migration_caisse.sql
│   ├── migration_cp.sql              # Historique, superseded par schema.sql
│   ├── migration_paie.sql            # Historique, superseded par schema.sql
│   └── migration_indemnite_sante_ancv.sql
├── lib/
│   └── fpdf/                  # Bibliothèque FPDF
└── tests/                     # Tests unitaires PHPUnit (dev uniquement, non déployé en prod)
    ├── bootstrap.php
    └── Unit/
```

## Stack technique

| Composant       | Technologie                          |
|-----------------|---------------------------------------|
| Backend         | PHP 8 (vanilla, sans framework)      |
| Base de données | MySQL / MariaDB via PDO              |
| Frontend        | Bootstrap 5.3.3, Bootstrap Icons     |
| PDF             | FPDF                                 |
| Graphiques      | Chart.js 4.4.4                       |
| API externe     | API Recherche Entreprises (gouv.fr)  |
| Tests           | PHPUnit 11 (dépendance de dev, non requise en production) |

## Tests

Les fonctions de calcul sensibles (fiscalité, lignes de documents commerciaux, paie, congés payés) sont extraites sous forme de fonctions pures et couvertes par des tests unitaires.

```bash
composer install
vendor/bin/phpunit
```

## Sécurité

- Mots de passe hachés avec `password_hash()` (bcrypt)
- Protection CSRF sur tous les formulaires
- En-têtes de sécurité : CSP, X-Frame-Options, X-Content-Type-Options
- Protection anti-bruteforce en base (`rate_limits`)
- Timeout de session automatique (30 min)
- Journal d'activité des connexions
- Requêtes préparées PDO (protection SQL injection)
- Contrôles de rôles sur les pages sensibles (admin / comptable / lecteur)
- Erreurs PHP jamais affichées aux visiteurs par défaut (`APP_ENV=production`), journalisées à la place
- `install.php`, `config.php`, `tests/`, `vendor/`, `composer.json/lock`, `phpunit.xml` bloqués via `.htaccess`

## Publication sur GitHub

1. Initialiser le dépôt local :
   ```bash
   git init
   git add .
   git commit -m "feat: release Gestion Comptable Pro v3.0.1.1"
   ```
2. Créer un dépôt vide sur GitHub
3. Lier puis pousser :
   ```bash
   git branch -M main
   git remote add origin https://github.com/VOTRE-UTILISATEUR/VOTRE-REPO.git
   git push -u origin main
   ```

## Mise en production sur cPanel

1. **Créer la base MySQL** dans cPanel (`MySQL Databases`), puis **importer** `database/schema.sql` et `database/migration_multi_regimes.sql` via `phpMyAdmin`.
2. **Déployer les fichiers** dans `public_html/` ou un sous-dossier dédié — inutile d'inclure `tests/`, `vendor/`, `composer.json`, `composer.lock`, `phpunit.xml` (dev uniquement ; ces chemins sont de toute façon bloqués par `.htaccess`).
3. **Configurer la connexion à la base**, deux méthodes possibles :
   - Variables d'environnement via `.htaccess` (exemple fourni dans le fichier) — **fonctionne uniquement si PHP tourne en mode Apache/DSO**. Sur un compte en **PHP-FPM** (fréquent dans *MultiPHP Manager*), les directives `SetEnv` ne sont **pas** transmises à `getenv()`.
   - Copier `config.local.php.example` en `config.local.php` (à la racine, non versionné) et y renseigner directement `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `BASE_URL` — fonctionne quel que soit le mode d'exécution PHP, recommandé en cas de doute.
4. **Vérifier la version PHP** dans *MultiPHP Manager* : 8.1 minimum, extensions `pdo_mysql`, `mbstring`, `curl` activées.
5. **Vérifier** que le domaine utilise bien `https://` (les cookies de session sont automatiquement sécurisés si HTTPS est détecté).
6. **Premier lancement** — se rendre sur `setup.php` pour créer le compte administrateur ; il se verrouille de lui-même dès qu'un administrateur existe, inutile de le bloquer manuellement.

> Pour un déploiement Git via cPanel, liez le dépôt GitHub puis publiez le contenu du dépôt vers `public_html`.

## Licence

Projet privé — Tous droits réservés.
