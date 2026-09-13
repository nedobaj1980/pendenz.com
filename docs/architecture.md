# pendenz.com – Architekturübersicht (Kurzfassung)

## Module (High-Level)
- Benutzer
- Projekte
- Pendenzen
- Storage (Ordner/Dateien)
- Vorlagen
- Benachrichtigungen
- Chat

## Zielstruktur (vereinfacht)
app/
  core/           # bootstrap, router, auth, db, helpers
  modules/
    benutzer/     # controller, service, views, sql
    projekte/
    pendenzen/
    storage/
    vorlagen/
    notifications/
    chat/
  shared/         # ui, components, exports
public/           # index.php, assets, uploads
docs/             # diese Datei, module-registry.yaml
scripts/          # audits & cleanup

## Aufräumen (Start)
- Doppel-Dateien: scripts/out/duplicates.csv
- Größte Dateien: scripts/out/top100_sizes.csv
- Unreferenzierte PHPs: scripts/out/maybe_unused_php.txt
- DB-Größe + Indizes: SQL-Queries siehe module-registry.yaml Hinweis
