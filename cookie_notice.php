<?php
/**
 * Bandeau d'information cookies — inclus en fin de <body> sur les pages
 * publiques et dans footer.php (application connectée).
 *
 * Purement informatif : l'application ne dépose qu'un cookie de session
 * strictement nécessaire à l'authentification, exempté de consentement
 * préalable par la CNIL. Pas de choix accepter/refuser à proposer puisqu'il
 * n'y a aucun cookie non essentiel. Le fait d'avoir vu ce bandeau est
 * mémorisé en localStorage (pas en cookie), pour ne pas ajouter un dépôt
 * persistant que ce bandeau lui-même n'annoncerait pas.
 */
?>
<div id="cookieNotice" class="cookie-notice" role="status" aria-live="polite" hidden>
    <div class="cookie-notice__text">
        <i class="bi bi-cookie"></i>
        Ce site utilise uniquement un cookie technique nécessaire à la connexion (aucun cookie de mesure d'audience
        ou de publicité). <a href="<?= BASE_URL ?>mentions-legales.php#cookies">En savoir plus</a>.
    </div>
    <button type="button" id="cookieNoticeDismiss" class="cookie-notice__btn">J'ai compris</button>
</div>
<style>
    .cookie-notice {
        position: fixed;
        left: 1rem;
        right: 1rem;
        bottom: 1rem;
        z-index: 1080;
        max-width: 640px;
        margin: 0 auto;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.75rem 1rem;
        background: #1E3A5F;
        color: #FFFFFF;
        border-radius: 12px;
        padding: 0.9rem 1.1rem;
        box-shadow: 0 4px 16px rgba(0,0,0,0.25);
        font-family: 'Inter', 'Roboto', system-ui, sans-serif;
        font-size: 0.85rem;
        line-height: 1.5;
    }
    .cookie-notice__text { flex: 1 1 260px; }
    .cookie-notice__text a { color: #93C5FD; text-decoration: underline; }
    .cookie-notice__btn {
        flex: 0 0 auto;
        background: #2563EB;
        color: #FFFFFF;
        border: none;
        border-radius: 9999px;
        padding: 0.5rem 1.1rem;
        font-size: 0.85rem;
        font-weight: 500;
        cursor: pointer;
        white-space: nowrap;
    }
    .cookie-notice__btn:hover { background: #1d4ed8; }
</style>
<script>
(function () {
    var KEY = 'cookie_notice_seen_v1';
    var el = document.getElementById('cookieNotice');
    if (!el) return;
    try {
        if (!localStorage.getItem(KEY)) {
            el.hidden = false;
        }
    } catch (e) {
        // localStorage indisponible (navigation privée stricte, etc.) : on affiche quand même.
        el.hidden = false;
    }
    var btn = document.getElementById('cookieNoticeDismiss');
    if (btn) {
        btn.addEventListener('click', function () {
            el.hidden = true;
            try { localStorage.setItem(KEY, '1'); } catch (e) {}
        });
    }
})();
</script>
