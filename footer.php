    </main><!-- /.app-content -->

    <footer class="py-4 footer-blue border-top">
        <div class="container-fluid">
            <div class="row align-items-center text-center text-md-start">
                <div class="col-md-4">
                    <small><i class="bi bi-briefcase-fill"></i> <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></small>
                </div>
                <div class="col-md-4 text-center">
                    <small><?= e(getParam('nom_entreprise', 'Mon Activité')) ?></small>
                </div>
                <div class="col-md-4 text-md-end">
                    <small><i class="bi bi-shield-check"></i> Connexion sécurisée · <?= date('Y') ?></small>
                </div>
            </div>
        </div>
    </footer>

    <div class="modal fade" id="newsModal" tabindex="-1" aria-labelledby="newsModalLabel" aria-hidden="true" data-version="<?= e(APP_VERSION) ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content news-modal">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <div class="small text-uppercase text-primary fw-bold mb-1">Nouveauté v<?= e(APP_VERSION) ?></div>
                        <h5 class="modal-title" id="newsModalLabel"><i class="bi bi-stars text-warning"></i> Votre application évolue</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body pt-2">
                    <p class="text-muted">Voici les dernières améliorations disponibles :</p>
                    <ul class="news-list">
                        <li><i class="bi bi-shield-check text-success"></i> Sécurité renforcée : CSRF, rate limiting, rôles et installation protégée</li>
                        <li><i class="bi bi-receipt text-primary"></i> Interface modernisée pour les factures, devis et commandes</li>
                        <li><i class="bi bi-building-check text-info"></i> Recherche SIREN / SIRET et configuration métier améliorées</li>
                        <li><i class="bi bi-github text-dark"></i> Version prête pour une publication GitHub plus propre</li>
                    </ul>
                    <div class="alert alert-info border-0 mb-0">
                        <i class="bi bi-lightbulb"></i> Pensez à vérifier vos paramètres d'entreprise et à tester votre compte admin avant mise en ligne.
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Plus tard</button>
                    <button type="button" class="btn btn-primary" id="dismissNewsPermanently">J'ai compris</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt" crossorigin="anonymous"></script>
    <script src="<?= BASE_URL ?>assets/app.js"></script>
</body>
</html>
