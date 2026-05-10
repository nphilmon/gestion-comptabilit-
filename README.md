# Gestion Comptable Pro v2.1.1

Application web de gestion comptable et commerciale pour auto-entrepreneurs, micro-entreprises et PME françaises. Fonctionne comme un **expert-comptable virtuel** avec gestion complète des documents commerciaux, caisse, comptabilité et rapports.

## Fonctionnalités

### Commercial
- **Factures** — Création, suivi des paiements, statuts (brouillon, envoyée, payée, en retard…), génération PDF
- **Devis** — Création, conversion en facture ou commande, suivi de validité
- **Commandes** — Gestion complète, conversion en facture
- **Clients** — Fiche client avec recherche SIREN/SIRET via l'API Entreprise (gouvernement)

### Comptabilité
- **Transactions** — Saisie des recettes et dépenses
- **Exercices comptables** — Gestion des périodes fiscales
- **Rapports** — Tableaux de bord, bilans, exports

### Caisse
- **Point de vente** — Interface de caisse avec recherche produit
- **Tickets de caisse** — Génération et impression
- **Clôture de caisse** — Bilan journalier
- **Produits & Inventaire** — Gestion du stock avec alertes

### Système
- **Authentification** — Connexion sécurisée, inscription, gestion des rôles (admin/comptable)
- **Sécurité** — CSRF, bcrypt, CSP headers, anti-bruteforce, timeout de session, journal d'activité
- **Multi-régimes** — Micro-entreprise, auto-entrepreneur, réel simplifié (TVA configurable)
- **Export CSV** — Export de toutes les données (clients, factures, transactions…)
- **Recherche globale** — Recherche unifiée dans tout le système
- **Génération PDF** — Factures, devis, commandes via FPDF

## Prérequis

- **PHP** 8.1+
- **MySQL** 5.7+ / MariaDB 10.3+
- **WAMP**, XAMPP ou serveur Apache/Nginx équivalent
- Extensions PHP : `pdo_mysql`, `mbstring`, `curl`

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

3. **Importer le schéma** et les migrations (dans l'ordre) :
   ```
   database/schema.sql
   database/migration_multi_regimes.sql
   database/migration_commercial.sql
   database/migration_caisse.sql
   ```

4. **Configurer** la connexion si nécessaire via les variables d'environnement suivantes :
   ```powershell
   $env:GESTION_COMPTA_DB_HOST='localhost'
   $env:GESTION_COMPTA_DB_NAME='gestion_compta'
   $env:GESTION_COMPTA_DB_USER='root'
   $env:GESTION_COMPTA_DB_PASS=''
   $env:GESTION_COMPTA_BASE_URL='/gestion%20comptabilit%C3%A9/'
   ```

5. **Accéder à l'application** :
   ```
   http://localhost/gestion%20comptabilit%C3%A9/
   ```

6. **Premier lancement** — L'assistant de configuration (`setup.php`) vous guidera pour créer le compte administrateur, renseigner les informations de l'entreprise et choisir le régime fiscal.

## Structure du projet

```
├── config.php                 # Configuration BDD et constantes
├── functions.php              # Auth, sécurité, utilitaires
├── functions_commercial.php   # CRUD clients, factures, devis, commandes
├── functions_caisse.php       # CRUD produits, opérations caisse
├── header.php                 # Navbar, breadcrumbs, CSP headers
├── footer.php                 # Pied de page
│
├── index.php                  # Tableau de bord / Dashboard
├── login.php                  # Connexion
├── register.php               # Inscription
├── setup.php                  # Assistant de configuration
├── profil.php                 # Profil utilisateur
├── utilisateurs.php           # Gestion des utilisateurs (admin)
│
├── factures.php               # Gestion des factures
├── devis.php                  # Gestion des devis
├── commandes.php              # Gestion des commandes
├── clients.php                # Gestion des clients
├── paiements.php              # Suivi des paiements
│
├── caisse.php                 # Point de vente
├── produits.php               # Gestion des produits
├── inventaire.php             # Inventaire du stock
├── ticket_caisse.php          # Tickets de caisse
├── cloture_caisse.php         # Clôture journalière
│
├── transactions.php           # Saisie comptable
├── comptabilite.php           # Vue comptabilité
├── exercices.php              # Exercices comptables
├── rapports.php               # Rapports et bilans
├── categories.php             # Catégories de transactions
│
├── recherche.php              # Recherche globale
├── export_csv.php             # Export CSV
├── pdf_generator.php          # Génération PDF (FPDF)
├── api_entreprise.php         # Proxy API SIREN/SIRET
├── parametres.php             # Paramètres de l'application
│
├── assets/
│   └── style.css              # Thème bleu personnalisé
├── database/
│   ├── schema.sql             # Schéma principal
│   ├── migration_multi_regimes.sql
│   ├── migration_commercial.sql
│   └── migration_caisse.sql
└── lib/
    └── fpdf/                  # Bibliothèque FPDF
```

## Stack technique

| Composant       | Technologie                          |
|-----------------|--------------------------------------|
| Backend         | PHP 8 (vanilla, sans framework)      |
| Base de données | MySQL / MariaDB via PDO              |
| Frontend        | Bootstrap 5.3.3, Bootstrap Icons     |
| PDF             | FPDF                                 |
| Graphiques      | Chart.js 4.4.4                       |
| API externe     | API Recherche Entreprises (gouv.fr)  |

## Sécurité

- Mots de passe hachés avec `password_hash()` (bcrypt)
- Protection CSRF sur tous les formulaires
- En-têtes de sécurité : CSP, X-Frame-Options, X-Content-Type-Options
- Protection anti-bruteforce en base (`rate_limits`)
- Timeout de session automatique (30 min)
- Journal d'activité des connexions
- Requêtes préparées PDO (protection SQL injection)
- Contrôles de rôles sur les pages sensibles (admin / comptable / lecteur)
- Script `install.php` verrouillé pour une utilisation locale uniquement

## Publication sur GitHub

1. Initialiser le dépôt local :
   ```bash
   git init
   git add .
   git commit -m "feat: release Gestion Comptable Pro v2.1.1"
   ```
2. Créer un dépôt vide sur GitHub
3. Lier puis pousser :
   ```bash
   git branch -M main
   git remote add origin https://github.com/VOTRE-UTILISATEUR/VOTRE-REPO.git
   git push -u origin main
   ```

## Mise en production sur cPanel

1. **Créer la base MySQL** dans cPanel (`MySQL Databases`).
2. **Importer** `database/schema.sql` puis les migrations via `phpMyAdmin`.
3. **Déployer les fichiers** dans `public_html/` ou un sous-dossier dédié.
4. **Configurer** les variables serveur ou adapter `.htaccess` / `config.php` :
   ```apache
   SetEnv GESTION_COMPTA_DB_HOST localhost
   SetEnv GESTION_COMPTA_DB_NAME votre_base
   SetEnv GESTION_COMPTA_DB_USER votre_user
   SetEnv GESTION_COMPTA_DB_PASS votre_mot_de_passe
   SetEnv GESTION_COMPTA_BASE_URL /
   ```
5. **Vérifier** que le domaine utilise bien `https://`.
6. **Supprimer ou bloquer** tout accès public à `install.php` après import.

> Pour un déploiement Git via cPanel, liez le dépôt GitHub puis publiez le contenu du dépôt vers `public_html`.

## Changelog

### v2.1.1 (2026-05-10)

- **Mode sombre** — Bascule d'un clic via le bouton 🌙 dans la barre de navigation ; préférence persistante entre les sessions (localStorage).
- **Performance** — `getParam()` charge désormais tous les paramètres en **une seule requête SQL** (preload + cache statique), éliminant les N appels individuels par requête HTTP.
- **Pagination des transactions** — La liste des transactions affiche 50 lignes par page avec navigation complète (première, précédente, numéros, suivante, dernière), évitant le chargement de l'intégralité du livre comptable.
- **Export CSV amélioré** — Le fichier `transactions.csv` contient maintenant les colonnes **Type**, **Client/Fournisseur**, **Référence** et **Notes** (colonnes manquantes en v2.1.0).
- **Sécurité — journalisation IP** — `logActivity()` utilise `getClientIp()` (validation proxy-safe) au lieu de `$_SERVER['REMOTE_ADDR']` brut.

### v2.1.0

- Factures électroniques (Factur-X / UBL), module Congés payés, module Paie
- Recherche SIREN/SIRET, génération PDF, interface Material Design 3

## Licence

Projet privé — Tous droits réservés.