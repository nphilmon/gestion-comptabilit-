/**
 * Gestion Comptabilité - Scripts
 */

document.addEventListener('DOMContentLoaded', function() {

    // === Graphique mensuel (dashboard) ===
    const chartMensuelEl = document.getElementById('chartMensuel');
    if (chartMensuelEl && typeof dataMensuel !== 'undefined') {
        new Chart(chartMensuelEl, {
            type: 'bar',
            data: {
                labels: labelsMensuel,
                datasets: [
                    {
                        label: 'Recettes',
                        data: dataMensuel.map(d => d.recettes),
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: '#28a745',
                        borderWidth: 1,
                        borderRadius: 4,
                    },
                    {
                        label: 'Dépenses',
                        data: dataMensuel.map(d => d.depenses),
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: '#dc3545',
                        borderWidth: 1,
                        borderRadius: 4,
                    },
                    {
                        label: 'Solde',
                        data: dataMensuel.map(d => d.solde),
                        type: 'line',
                        borderColor: '#0dcaf0',
                        backgroundColor: 'rgba(13, 202, 240, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': ' + 
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
                            }
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 45,
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
                    backgroundColor: dataRecettes.map(d => d.color),
                    borderWidth: 2,
                    borderColor: '#fff',
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.label + ': ' + 
                                    new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(ctx.raw);
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
                    backgroundColor: dataDepenses.map(d => d.color),
                    borderWidth: 2,
                    borderColor: '#fff',
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.label + ': ' + 
                                    new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(ctx.raw);
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
});
