# Exploitation

## Logs

- Journal applicatif : `storage/logs/app.log`
- Journal des erreurs PHP : `storage/logs/php-error.log`

## Sauvegarde MySQL

```bash
mysqldump -h "$GESTION_COMPTA_DB_HOST" -u "$GESTION_COMPTA_DB_USER" -p"$GESTION_COMPTA_DB_PASS" "$GESTION_COMPTA_DB_NAME" > backup-gestion-compta.sql
```

## Restauration MySQL

```bash
mysql -h "$GESTION_COMPTA_DB_HOST" -u "$GESTION_COMPTA_DB_USER" -p"$GESTION_COMPTA_DB_PASS" "$GESTION_COMPTA_DB_NAME" < backup-gestion-compta.sql
```

## Vérifications après incident

1. Contrôler `storage/logs/php-error.log`
2. Contrôler `storage/logs/app.log`
3. Relancer `php tests/run.php`
4. Vérifier la syntaxe PHP avec `find . -name '*.php' -print0 | xargs -0 -n1 php -l`
