/**
 * Gestion Comptable Pro - Scripts
 */

document.addEventListener('DOMContentLoaded', function() {

    // =============================================================
    // DARK MODE — toggle persistant via localStorage
    // =============================================================
    const darkToggleBtn  = document.getElementById('darkModeToggle');
    const darkModeIcon   = document.getElementById('darkModeIcon');
    const themeKey       = 'gestionCompta.theme';

    function applyTheme(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            if (darkModeIcon) {
                darkModeIcon.classList.remove('bi-moon-stars');
                darkModeIcon.classList.add('bi-sun');
            }
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (darkModeIcon) {
                darkModeIcon.classList.remove('bi-sun');
                darkModeIcon.classList.add('bi-moon-stars');
            }
        }
    }

    // Sync icon with current theme on page load
    applyTheme(localStorage.getItem(themeKey) || 'light');

    if (darkToggleBtn) {
        darkToggleBtn.addEventListener('click', function() {
            const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            const next    = current === 'dark' ? 'light' : 'dark';
            localStorage.setItem(themeKey, next);
            applyTheme(next);
        });
    }

    // === Chart.js — Global defaults ===
    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = "'Inter', 'Roboto', system-ui, sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.font.weight = '500';
        Chart.defaults.color = '#64748B';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
        Chart.defaults.plugins.legend.labels.padding = 16;
        Chart.defaults.plugins.tooltip.backgroundColor = '#1E293B';
        Chart.defaults.plugins.tooltip.titleFont = { size: 13, weight: '600', family: "'Inter', sans-serif" };
        Chart.defaults.plugins.tooltip.bodyFont = { size: 12, family: "'Inter', sans-serif" };
        Chart.defaults.plugins.tooltip.padding = { top: 10, bottom: 10, left: 14, right: 14 };
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.boxPadding = 6;
        Chart.defaults.scale.grid = { color: 'rgba(226,232,240,0.6)', drawBorder: false };
        Chart.defaults.scale.border = { display: false };
        Chart.defaults.scale.ticks.padding = 8;
    }

    // === Graphique mensuel (dashboard) ===
    const chartMensuelEl = document.getElementById('chartMensuel');
    if (chartMensuelEl && typeof dataMensuel !== 'undefined') {
        const ctx = chartMensuelEl.getContext('2d');
        const gradientRecettes = ctx.createLinearGradient(0, 0, 0, 320);
        gradientRecettes.addColorStop(0, 'rgba(22,163,74,0.85)');
        gradientRecettes.addColorStop(1, 'rgba(22,163,74,0.55)');
        const gradientDepenses = ctx.createLinearGradient(0, 0, 0, 320);
        gradientDepenses.addColorStop(0, 'rgba(220,38,38,0.8)');
        gradientDepenses.addColorStop(1, 'rgba(220,38,38,0.5)');

        new Chart(chartMensuelEl, {
            type: 'bar',
            data: {
                labels: labelsMensuel,
                datasets: [
                    {
                        label: 'Recettes',
                        data: dataMensuel.map(d => d.recettes),
                        backgroundColor: gradientRecettes,
                        borderColor: 'rgba(22,163,74,0.9)',
                        borderWidth: 0,
                        borderRadius: 6,
                        borderSkipped: false,
                        barPercentage: 0.65,
                        categoryPercentage: 0.7,
                    },
                    {
                        label: 'Dépenses',
                        data: dataMensuel.map(d => d.depenses),
                        backgroundColor: gradientDepenses,
                        borderColor: 'rgba(220,38,38,0.9)',
                        borderWidth: 0,
                        borderRadius: 6,
                        borderSkipped: false,
                        barPercentage: 0.65,
                        categoryPercentage: 0.7,
                    },
                    {
                        label: 'Solde',
                        data: dataMensuel.map(d => d.solde),
                        type: 'line',
                        borderColor: '#2563EB',
                        backgroundColor: function(context) {
                            const chart = context.chart;
                            const {ctx: c, chartArea} = chart;
                            if (!chartArea) return 'rgba(37,99,235,0.08)';
                            const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                            gradient.addColorStop(0, 'rgba(37,99,235,0.12)');
                            gradient.addColorStop(1, 'rgba(37,99,235,0.01)');
                            return gradient;
                        },
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#2563EB',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointHoverBorderWidth: 3,
                        borderWidth: 2.5,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { font: { size: 11, weight: '600' }, boxWidth: 8, boxHeight: 8 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ' ' + ctx.dataset.label + ': ' +
                                    new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(ctx.raw);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(val) {
                                return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(val);
                            },
                            font: { size: 11 }
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 0,
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    }

    // === Graphique recettes par catégorie ===
    const chartRecettesEl = document.getElementById('chartRecettes');
    if (chartRecettesEl && typeof dataRecettes !== 'undefined' && dataRecettes.length > 0) {
        new Chart(chartRecettesEl, {
            type: 'doughnut',
            data: {
                labels: dataRecettes.map(d => d.label),
                datasets: [{
                    data: dataRecettes.map(d => d.value),
                    backgroundColor: dataRecettes.map(d => d.color || '#94A3B8'),
                    borderWidth: 3,
                    borderColor: '#fff',
                    hoverBorderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11, weight: '500' }, padding: 12, boxWidth: 10, boxHeight: 10 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round(ctx.raw / total * 100) : 0;
                                return ' ' + ctx.label + ': ' +
                                    new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(ctx.raw) +
                                    ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // === Graphique dépenses par catégorie ===
    const chartDepensesEl = document.getElementById('chartDepenses');
    if (chartDepensesEl && typeof dataDepenses !== 'undefined' && dataDepenses.length > 0) {
        new Chart(chartDepensesEl, {
            type: 'doughnut',
            data: {
                labels: dataDepenses.map(d => d.label),
                datasets: [{
                    data: dataDepenses.map(d => d.value),
                    backgroundColor: dataDepenses.map(d => d.color || '#94A3B8'),
                    borderWidth: 3,
                    borderColor: '#fff',
                    hoverBorderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11, weight: '500' }, padding: 12, boxWidth: 10, boxHeight: 10 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round(ctx.raw / total * 100) : 0;
                                return ' ' + ctx.label + ': ' +
                                    new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(ctx.raw) +
                                    ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // === Filtre catégories selon type (formulaire transactions) ===
    const typeSelect = document.getElementById('typeSelect');
    const categorieSelect = document.getElementById('categorieSelect');
    if (typeSelect && categorieSelect) {
        function filtrerCategories() {
            const type = typeSelect.value;
            const options = categorieSelect.querySelectorAll('option[data-type]');
            options.forEach(opt => {
                opt.style.display = opt.dataset.type === type ? '' : 'none';
                if (opt.dataset.type !== type && opt.selected) {
                    opt.selected = false;
                    categorieSelect.value = '';
                }
            });
        }
        typeSelect.addEventListener('change', filtrerCategories);
        filtrerCategories(); // Initial filter
    }

    // =============================================================
    // CAISSE — Recherche produit (autocomplétion)
    // =============================================================
    const rechercheProduit = document.getElementById('rechercheProduit');
    const resultsProduit = document.getElementById('resultsProduit');
    const produitIdHidden = document.getElementById('produitIdHidden');
    const designationProduit = document.getElementById('designationProduit');
    const prixProduit = document.getElementById('prixProduit');
    const tvaProduit = document.getElementById('tvaProduit');

    if (rechercheProduit && resultsProduit) {
        let debounceTimer;
        rechercheProduit.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const q = this.value.trim();
            if (q.length < 1) { resultsProduit.style.display = 'none'; return; }
            debounceTimer = setTimeout(() => {
                fetch('caisse.php?action=api_produits&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        resultsProduit.innerHTML = '';
                        if (data.length === 0) {
                            resultsProduit.innerHTML = '<div class="list-group-item text-muted">Aucun produit trouvé</div>';
                        }
                        data.forEach(p => {
                            const item = document.createElement('a');
                            item.href = '#';
                            item.className = 'list-group-item list-group-item-action py-1';
                            let stockInfo = p.gestion_stock ? ' <small class="text-muted">(stock: ' + p.stock_actuel + ')</small>' : '';
                            item.innerHTML = '<strong>' + escapeHtml(p.nom) + '</strong>' + stockInfo + ' — ' + parseFloat(p.prix_vente).toFixed(2) + ' €';
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                produitIdHidden.value = p.id;
                                designationProduit.value = p.nom;
                                prixProduit.value = parseFloat(p.prix_vente).toFixed(2);
                                if (tvaProduit) tvaProduit.value = p.taux_tva;
                                rechercheProduit.value = '';
                                resultsProduit.style.display = 'none';
                            });
                            resultsProduit.appendChild(item);
                        });
                        resultsProduit.style.display = 'block';
                    })
                    .catch(() => { resultsProduit.style.display = 'none'; });
            }, 250);
        });

        // Cacher les résultats au clic ailleurs
        document.addEventListener('click', function(e) {
            if (!rechercheProduit.contains(e.target) && !resultsProduit.contains(e.target)) {
                resultsProduit.style.display = 'none';
            }
        });

        // Quand on tape dans désignation, réinitialiser le produit_id (article libre)
        if (designationProduit) {
            designationProduit.addEventListener('input', function() {
                produitIdHidden.value = '';
            });
        }
    }

    // =============================================================
    // RECHERCHE INTELLIGENTE — Suggestions live + historique
    // =============================================================
    const smartSearchForms = document.querySelectorAll('.js-smart-search');
    const searchHistoryKey = 'gestionCompta.searchHistory';

    function loadSearchHistory() {
        try {
            return JSON.parse(window.localStorage.getItem(searchHistoryKey) || '[]');
        } catch (error) {
            return [];
        }
    }

    function saveSearchHistory(query) {
        const value = (query || '').trim();
        if (value.length < 2) return;
        const history = loadSearchHistory().filter(item => item.toLowerCase() !== value.toLowerCase());
        history.unshift(value);
        window.localStorage.setItem(searchHistoryKey, JSON.stringify(history.slice(0, 6)));
    }

    function renderSmartSearchPanel(panel, payload, inputValue) {
        const suggestions = payload?.suggestions || [];
        const smart = payload?.smart || [];
        const history = loadSearchHistory().filter(item => item.toLowerCase() !== inputValue.toLowerCase());
        let html = '';

        if (inputValue.trim().length < 2 && history.length > 0) {
            html += `
                <div class="smart-search-panel__section">
                    <div class="smart-search-panel__title">
                        <span>Historique récent</span>
                        <span>${history.length}</span>
                    </div>
                    <div class="smart-search-panel__list">
                        ${history.slice(0, 5).map(item => `
                            <a href="recherche.php?q=${encodeURIComponent(item)}" class="smart-search-item">
                                <span class="smart-search-item__icon"><i class="bi bi-clock-history"></i></span>
                                <span class="smart-search-item__body">
                                    <span class="smart-search-item__label">${escapeHtml(item)}</span>
                                    <span class="smart-search-item__detail">Relancer cette recherche</span>
                                </span>
                            </a>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        if (smart.length > 0) {
            html += `
                <div class="smart-search-panel__section">
                    <div class="smart-search-panel__title">
                        <span>Assistant</span>
                        <span>${smart.length}</span>
                    </div>
                    <div class="smart-search-smart">
                        ${smart.map(item => `
                            <a href="${escapeHtml(item.link || ('recherche.php?q=' + encodeURIComponent(inputValue)))}" class="smart-search-smart__item">
                                <i class="bi bi-stars text-primary"></i>
                                <span class="smart-search-item__body">
                                    <span class="smart-search-item__label">${escapeHtml(item.label)}</span>
                                    <span class="smart-search-item__detail">${escapeHtml(item.text)}</span>
                                </span>
                            </a>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        if (suggestions.length > 0) {
            html += `
                <div class="smart-search-panel__section">
                    <div class="smart-search-panel__title">
                        <span>Résultats suggérés</span>
                        <span>${payload.total || suggestions.length}</span>
                    </div>
                    <div class="smart-search-panel__list">
                        ${suggestions.map(item => `
                            <a href="${escapeHtml(item.link)}" class="smart-search-item" data-search-item="1">
                                <span class="smart-search-item__icon"><i class="bi bi-${escapeHtml(item.icon)}"></i></span>
                                <span class="smart-search-item__body">
                                    <span class="smart-search-item__label">${escapeHtml(item.label)}</span>
                                    <span class="smart-search-item__detail">${escapeHtml(item.detail)}</span>
                                </span>
                                <span class="smart-search-item__meta">${escapeHtml(item.section)}</span>
                            </a>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        if (!html) {
            html = `<div class="smart-search-empty">Tape au moins 2 caractères pour lancer une recherche intelligente.</div>`;
        }

        panel.innerHTML = html;
        panel.hidden = false;
    }

    smartSearchForms.forEach(function(form) {
        const input = form.querySelector('input[name="q"]');
        const panel = form.querySelector('.smart-search-panel');
        if (!input || !panel) return;

        let debounceTimer;
        let activeIndex = -1;

        function closePanel() {
            panel.hidden = true;
            activeIndex = -1;
        }

        function highlightActiveItem() {
            const items = panel.querySelectorAll('[data-search-item="1"], .smart-search-panel__list .smart-search-item');
            items.forEach((item, index) => item.classList.toggle('is-active', index === activeIndex));
        }

        function fetchSuggestions() {
            const q = input.value.trim();
            clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(function() {
                if (q.length < 2) {
                    renderSmartSearchPanel(panel, { suggestions: [], smart: [] }, q);
                    return;
                }

                fetch('recherche_suggestions.php?q=' + encodeURIComponent(q))
                    .then(response => response.json())
                    .then(payload => renderSmartSearchPanel(panel, payload, q))
                    .catch(() => renderSmartSearchPanel(panel, { suggestions: [], smart: [] }, q));
            }, 180);
        }

        input.addEventListener('focus', function() {
            renderSmartSearchPanel(panel, { suggestions: [], smart: [] }, input.value.trim());
            if (input.value.trim().length >= 2) {
                fetchSuggestions();
            }
        });

        input.addEventListener('input', fetchSuggestions);

        input.addEventListener('keydown', function(event) {
            const items = panel.querySelectorAll('a.smart-search-item, a.smart-search-smart__item');
            if (!items.length || panel.hidden) return;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = (activeIndex + 1) % items.length;
                highlightActiveItem();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = activeIndex <= 0 ? items.length - 1 : activeIndex - 1;
                highlightActiveItem();
            } else if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
                event.preventDefault();
                window.location.href = items[activeIndex].getAttribute('href');
            } else if (event.key === 'Escape') {
                closePanel();
            }
        });

        form.addEventListener('submit', function() {
            saveSearchHistory(input.value);
        });

        document.addEventListener('click', function(event) {
            if (!form.contains(event.target)) {
                closePanel();
            }
        });
    });

    // === Pop-up nouveautés ===
    const newsModalEl = document.getElementById('newsModal');
    if (newsModalEl && typeof bootstrap !== 'undefined') {
        const version = newsModalEl.dataset.version || 'default';
        const storageKey = 'gestionCompta.newsSeen.' + version;
        const modal = new bootstrap.Modal(newsModalEl);
        const dismissBtn = document.getElementById('dismissNewsPermanently');

        if (!window.localStorage.getItem(storageKey)) {
            window.setTimeout(() => modal.show(), 650);
        }

        if (dismissBtn) {
            dismissBtn.addEventListener('click', function() {
                window.localStorage.setItem(storageKey, '1');
                modal.hide();
            });
        }
    }

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    // =============================================================
    // MATERIAL DESIGN — Ripple Effect
    // =============================================================

    // ─── Helper monétaire pour graphiques ──
    const fmtEur = (val) => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(val);
    const fmtEur2 = (val) => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(val);
    const moisLabels = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];

    // =============================================================
    // INTELLIGENCE — Graphique projection CA
    // =============================================================
    const chartProjectionEl = document.getElementById('chartProjection');
    if (chartProjectionEl && typeof dataProjection !== 'undefined') {
        const pCtx = chartProjectionEl.getContext('2d');
        const valeurs = Object.values(dataProjection);
        const projValues = valeurs.map((v, i) => (i + 1) <= projectionMoisCourant ? v : null);
        const projFuture = valeurs.map((v, i) => (i + 1) > projectionMoisCourant ? projectionMoyenne : null);
        if (projectionMoisCourant > 0 && projectionMoisCourant < 12) {
            projFuture[projectionMoisCourant - 1] = valeurs[projectionMoisCourant - 1];
        }

        const gradReel = pCtx.createLinearGradient(0, 0, 0, 280);
        gradReel.addColorStop(0, 'rgba(22,163,74,0.85)');
        gradReel.addColorStop(1, 'rgba(22,163,74,0.5)');

        new Chart(chartProjectionEl, {
            type: 'bar',
            data: {
                labels: moisLabels,
                datasets: [
                    {
                        label: 'CA réel',
                        data: projValues,
                        backgroundColor: gradReel,
                        borderWidth: 0,
                        borderRadius: 6,
                        borderSkipped: false,
                        barPercentage: 0.6,
                    },
                    {
                        label: 'Projection',
                        data: projFuture,
                        backgroundColor: 'rgba(37,99,235,0.18)',
                        borderColor: '#2563EB',
                        borderWidth: 1.5,
                        borderRadius: 6,
                        borderSkipped: false,
                        borderDash: [4, 4],
                        barPercentage: 0.6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    tooltip: {
                        callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + fmtEur2(ctx.raw) }
                    },
                    legend: { position: 'top', align: 'end', labels: { font: { size: 11, weight: '600' }, boxWidth: 8, boxHeight: 8 } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: val => fmtEur(val), font: { size: 11 } } },
                    x: { ticks: { font: { size: 11 }, maxRotation: 0 } }
                }
            }
        });
    }

    // =============================================================
    // INTELLIGENCE — Graphique trésorerie prévisionnelle
    // =============================================================
    const chartTresorerieEl = document.getElementById('chartTresorerie');
    if (chartTresorerieEl && typeof dataTresorerie !== 'undefined') {
        const tresoLabels = dataTresorerie.map(d => d.label);
        const tresoValues = dataTresorerie.map(d => d.solde);

        new Chart(chartTresorerieEl, {
            type: 'line',
            data: {
                labels: tresoLabels,
                datasets: [{
                    label: 'Solde projeté',
                    data: tresoValues,
                    borderColor: '#2563EB',
                    backgroundColor: function(ctx) {
                        const chart = ctx.chart;
                        const {ctx: c, chartArea} = chart;
                        if (!chartArea) return 'rgba(37,99,235,0.08)';
                        const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        gradient.addColorStop(0, 'rgba(37,99,235,0.15)');
                        gradient.addColorStop(0.6, 'rgba(37,99,235,0.04)');
                        gradient.addColorStop(1, 'rgba(37,99,235,0.0)');
                        return gradient;
                    },
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: tresoValues.map(v => v >= 0 ? '#16A34A' : '#DC2626'),
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2.5,
                    pointHoverBorderWidth: 3,
                    borderWidth: 2.5,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    tooltip: {
                        callbacks: { label: ctx => ' Solde: ' + fmtEur2(ctx.raw) }
                    },
                    legend: { display: false }
                },
                scales: {
                    y: {
                        ticks: { callback: val => fmtEur(val), font: { size: 11 } },
                    },
                    x: { ticks: { font: { size: 11 }, maxRotation: 0 } }
                }
            }
        });
    }

    // =============================================================
    // INTELLIGENCE — Auto-catégorisation transaction
    // =============================================================
    const descriptionInput = document.getElementById('descriptionInput');
    const suggestionBox = document.getElementById('suggestionCategorie');
    const suggestionNom = document.getElementById('suggestionNom');
    const suggestionConfiance = document.getElementById('suggestionConfiance');
    const appliquerBtn = document.getElementById('appliquerSuggestion');

    if (descriptionInput && suggestionBox && categorieSelect) {
        let suggestTimer;
        let lastSuggestionCatId = null;

        descriptionInput.addEventListener('input', function() {
            clearTimeout(suggestTimer);
            const desc = this.value.trim();
            if (desc.length < 3) {
                suggestionBox.classList.add('d-none');
                return;
            }
            suggestTimer = setTimeout(() => {
                const type = typeSelect ? typeSelect.value : 'depense';
                fetch('transactions.php?api=suggest_categorie&description=' + encodeURIComponent(desc) + '&type=' + encodeURIComponent(type))
                    .then(r => r.json())
                    .then(data => {
                        if (data.suggestion && data.suggestion.categorie_id) {
                            lastSuggestionCatId = data.suggestion.categorie_id;
                            suggestionNom.textContent = data.suggestion.categorie_nom;
                            suggestionConfiance.textContent = data.suggestion.confiance + '% confiance';
                            suggestionBox.classList.remove('d-none');
                        } else {
                            suggestionBox.classList.add('d-none');
                        }
                    })
                    .catch(() => suggestionBox.classList.add('d-none'));
            }, 400);
        });

        if (appliquerBtn) {
            appliquerBtn.addEventListener('click', function() {
                if (lastSuggestionCatId && categorieSelect) {
                    categorieSelect.value = lastSuggestionCatId;
                    suggestionBox.classList.add('d-none');
                }
            });
        }
    }

    // =============================================================
    // MATERIAL DESIGN — Ripple Effect (original)
    // =============================================================
    function addRipple(e) {
        const btn = e.currentTarget;
        const circle = document.createElement('span');
        const diameter = Math.max(btn.clientWidth, btn.clientHeight);
        const radius = diameter / 2;
        const rect = btn.getBoundingClientRect();

        circle.style.width = circle.style.height = diameter + 'px';
        circle.style.left = (e.clientX - rect.left - radius) + 'px';
        circle.style.top = (e.clientY - rect.top - radius) + 'px';
        circle.classList.add('ripple-circle');

        const existing = btn.querySelector('.ripple-circle');
        if (existing) existing.remove();

        btn.appendChild(circle);
        setTimeout(() => circle.remove(), 600);
    }

    // Apply ripple to all buttons and interactive elements
    document.querySelectorAll('.btn, .nav-link, .dropdown-item, .list-group-item-action').forEach(function(el) {
        el.classList.add('md-ripple');
        el.addEventListener('click', addRipple);
    });

    // =============================================================
    // MATERIAL DESIGN — Elevated Navbar on scroll
    // =============================================================
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        const syncNavbarScrollState = function() {
            navbar.classList.toggle('app-navbar-scrolled', window.scrollY > 10);
        };

        syncNavbarScrollState();
        window.addEventListener('scroll', syncNavbarScrollState, { passive: true });
    }
});
