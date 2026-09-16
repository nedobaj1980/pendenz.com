# Übergabe an Antigravity – pendenz.com

Stand: 16.09.2026  
Arbeitsverzeichnis: `C:\\xampp\\htdocs\\pendenz.com`  
Arbeitsbranch: `chatgpt/full-audit-2026-09-15`

Diese Datei dokumentiert die Arbeiten aus dem 14.–16.09.2026 und den aktuellen Stand für die Weiterarbeit.

## Aktueller Git-Stand

Der letzte Commit ist `a96687b` (`Speichere Betreff und Langtext aus gimi-Aktionen`). Danach wurden weitere Korrekturen lokal vorgenommen, die noch nicht committed sind.

Aktuell geändert oder neu:

- `api/pendenzen_inline_save.php`
- `api/pendenzen_save.php`
- `pages/pendenzen.php`
- `tools/liegenschaftsabrechnung/index.php`
- `tools/mietkontrolle/index.php`
- `tools/nebenkostenabrechnung/index.php`
- `includes/pendenz_domain.php` (neu)
- `includes/property_scope.php` (neu)
- `tests/pendenzen-regression.php` (neu)
- `tests/voice-ui-regression.cjs` (neu)

Ein Commit wurde versucht, scheiterte aber an `.git/index.lock: Permission denied`. Die lokalen Änderungen dürfen deshalb nicht verloren gehen; vor dem Weiterarbeiten zuerst `git status` prüfen und bei ausreichenden Rechten committen.

## Finanzen und Immobilienverwaltung

### Bereits committed

Aus den Antigravity-/Audit-Commits wurden unter anderem folgende Bereiche erweitert:

- Finanzübersicht und Kontoverwaltung mit Import, Zuordnung/Auto-Matching, IBAN-Robustheit und überarbeiteter Darstellung.
- Schweizer Liegenschaftsabrechnung mit Navigation und projektbezogenem Einstieg.
- Mietkontrolle und Mieterspiegel als Teil der Immobilienverwaltung.
- Gimi als globaler PropTech-Co-Pilot mit Gemini-Integration, Markdown-Antworten, Live-Projektkontext, Wohnungs-/Mieterinformationen und Drive-/Ordnerkontext.
- Bestätigte KI-Aktionen können Pendenzen erstellen. Betreff/Titel und Langtext werden dabei gespeichert; ungültige Ersteller-IDs werden abgefangen.

Wichtige Commits:

| Commit | Inhalt |
|---|---|
| `b72bd1b` | Finanztools, Bankeinträge und Mietkontrolle überarbeitet |
| `e5830fb` | Kontenimport erweitert |
| `c80a569` | IBAN-Spalte und Robustheit Konto-Verwaltung |
| `17ba68c` | Schweizer Liegenschaftsabrechnung und Navigation |
| `e4b2790` | Drive-Sync, Mieterwechsel, Verträge, Protokolle und Performance |
| `6708076` | Superadmin-Cockpit und PropTech-Tools |
| `e3654fa` | Gimi-Kontext, Gemini-Modelle, Drive-Ordner und Markdown |
| `9acaa43` | Voice-/Mobile-Fixes und gemeinsame AI-CSS-Struktur |

Vollständige Commit-Chronik vom 14.–16.09.2026 (ältester zuerst):

| Datum/Zeit | Commit | Kurzinhalt |
|---|---|---|
| 14.09. 12:54 | `b72bd1b` | Finanzen, Bankeinträge und Mietkontrolle |
| 14.09. 15:20 | `0e0055b` | Finanzübersicht, Kontoverwaltung und Darstellung |
| 14.09. 23:32 | `e5830fb` | Finanztools und Kontenimport erweitert |
| 15.09. 00:26 | `c80a569` | IBAN-Spalte und robuste Kontoverwaltung |
| 15.09. 00:33 | `17ba68c` | Schweizer Liegenschaftsabrechnung und Navigation |
| 15.09. 01:05 | `e4b2790` | Immobilienverwaltung mit Drive-Sync, Mieterwechsel, Verträgen und Protokollen |
| 15.09. 01:10 | `cf6bb82` | GEMINI-Regeln und Workspace-Skills für den Copilot |
| 15.09. 01:17 | `2deccfb` | Gimi Voice Assistant und Sprach-Pendenzen |
| 15.09. 01:36 | `6708076` | Gemini-Upgrade, Superadmin-Cockpit und PropTech-Tools |
| 15.09. 07:58 | `e3654fa` | Gimi-Co-Pilot mit Gemini, Drive-Kontext und Markdown |
| 15.09. 16:42 | `9acaa43` | Voice-, Mobile-/iPad-Fixes und `konto_verwaltung` |
| 15.09. 17:04 | `f695111` | Mobile Dashboard, Hero-Bereich und KPI-Karten |
| 15.09. 17:19 | `78dce7a` | Mikrofon-Berechtigung und Safari-/Chrome-Hilfe |
| 15.09. 17:29 | `8299317` | iOS-Safari `webkitSpeechRecognition` synchron gestartet |
| 15.09. 17:41 | `03b93ba` | MediaRecorder und Gemini-Transkription für iOS/Android |
| 15.09. 17:49 | `23b7813` | API-Bootstrap, Session und `table_exists` korrigiert |
| 15.09. 18:01 | `ed0ff8e` | Safari-DOMException, API-Pfade und MIME-Formate korrigiert |
| 15.09. 18:07 | `4a7b1a2` | Smartphone-Tastatur-Diktat mit Live-KI-Analyse |
| 15.09. 20:54 | `7599931` | Spezifikation für Schweizer Nebenkostenabrechnung |
| 15.09. 20:56 | `5001175` | Implementierungsplan für Nebenkostenabrechnung |
| 15.09. 21:05 | `51e1690` | Basis des Schweizer Nebenkostenabrechnungstools |
| 15.09. 21:20 | `b25a987` | Einheitliche Finanz-Tabellen mit Filter, Sortierung und Spaltenwahl |
| 15.09. 21:35 | `96329c5` | Projektspezifische Finanzgruppen |
| 15.09. 21:36 | `59839a1` | Migration für Finanzgruppen |
| 15.09. 21:37 | `10cea9a` | Globale Finanzgruppen-Vorlagen |
| 15.09. 21:38 | `cf696a1` | Global ausgewähltes Projekt in Abrechnungen |
| 15.09. 21:46 | `cba0694` | Gimi-Kontext und bestätigte Aktionen verbessert |
| 15.09. 22:23 | `a96687b` | Betreff und Langtext aus Gimi-Aktionen gespeichert |

### Einheitliche Finanz-Tabellen

`assets/js/finance-tables.js` wird über `includes/header.php` eingebunden. Tabellen auf Finanzseiten erhalten automatisch:

- Volltextfilter
- numerische und deutsche Textsortierung per Spaltenkopf
- Spalten ein-/ausblenden
- gespeicherte Ansicht in `localStorage`
- Zurücksetzen der Ansicht

Getestet mit `tests/finance-tables.test.cjs`.

### Finanzgruppen

Mit `database/migrations/009_finanzgruppen.sql` und `tools/finanzgruppen/index.php` gibt es eigene Gruppen für:

- nur Nebenkosten
- nur Liegenschaftsabrechnung
- beide Werkzeuge
- projektbezogene Gruppen
- globale Vorlagen für alle Projekte

Eine Gruppe kann Umlagefähigkeit, Steuerrelevanz, Steuerklasse, Verteilerschlüssel, Farbe und Reihenfolge definieren. `tools/nebenkostenabrechnung/lib.php` lädt zuerst projektspezifische und danach globale Gruppen.

Aktuell vorhandene Bedienung: Gruppe anlegen und deaktivieren. Bearbeiten/Reaktivieren ist noch ein möglicher Ausbau.

### Nebenkostenabrechnung

Modulpfad: `tools/nebenkostenabrechnung/`

Vorhandene Dateien:

- `bootstrap.php` – idempotentes Anlegen der Tabellen aus Migration 008 und 009
- `lib.php` – Periodenüberschneidung, Verteilung nach Fläche/Einheiten/Personen/Verbrauch/Direkt, Gruppenzuordnung und Berechnung
- `index.php` – Projekt/Jahr, KPIs, Warnungen, Einheitenübersicht und Gruppenlink
- `export.php` – CSV und PDF/HTML-Fallback
- `templates/tenant-statement.html.php`
- `templates/owner-tax-report.html.php`

Migration 008 legt an:

- `nk_kostenarten`
- `nk_abrechnungen`
- `nk_positionen`
- `nk_verteilungen`
- `nk_regeln`

Die Standardkostenarten trennen Schweizer mieterseitige Umlagefähigkeit von eigentümerseitiger Steuerabzugsfähigkeit. Verwaltung, Finanzierung, wertvermehrende Investitionen und private Positionen sind standardmässig nicht mieterseitig umlagefähig.

Die fachliche Spezifikation und der Plan stehen in:

- `docs/superpowers/specs/2026-09-15-nebenkostenabrechnung-design.md`
- `docs/superpowers/plans/2026-09-15-nebenkostenabrechnung.md`

Der aktuelle Code ist eine funktionierende Basis, aber noch nicht die vollständige Spezifikation: Mietverhältnisse, Akontozahlungen, Ein-/Auszüge, Leerstand, Regelpersistenz und Freigabestatus müssen noch vollständig in die Berechnung und Speicherung integriert werden.

## Projekt-, Objekt-, Wohnungs- und Raumlogik

`includes/property_scope.php` klassifiziert vorhandene `wohnungen`-Datensätze anhand ihres Namens als:

- `residential`
- `commercial`
- `common` (z. B. Treppenhaus, Tiefgarage, Umgebung, Spielplatz, Hauswartung)
- `parking`
- `other`

Mietkontrolle und Nebenkostenabrechnung verwenden nur Wohn-/Gewerbeeinheiten. Die Liegenschaftsabrechnung zählt Allgemeinräume und Parkplätze nicht als Mietwohnungen. Das ist bewusst eine Namensheuristik und sollte später durch ein persistiertes Einheitentyp-Feld ersetzt oder ergänzt werden.

`includes/pendenz_domain.php` enthält gemeinsame Pendenzenregeln:

- Status-Aliase werden auf gültige interne Werte normalisiert (`open`/`todo` → `offen`, `in Bearbeitung`/`in_arbeit` → `in_bearbeitung`, `completed` → `erledigt` usw.).
- Projekt → Objekt → Wohnung → Raum wird geprüft.
- Eine Wohnung aus einem anderen Projekt wird beim Speichern abgewiesen.

Diese Regeln sind in `api/pendenzen_save.php`, `api/pendenzen_inline_save.php` und `pages/pendenzen.php` angeschlossen.

Zusätzlich gilt auf `pages/pendenzen.php`: Ein expliziter URL-Kontext wie `?projekt_id=3&wohnung_id=109` gewinnt immer gegen gespeicherte Listenprofile. Dadurch wird beim Öffnen einer konkreten Liegenschaft nicht versehentlich eine andere Projektansicht angezeigt.

## Gimi und Voice

### Globaler Gimi-Co-Pilot

Betroffene Kerndateien:

- `api/ai_query.php`
- `api/ai_confirm.php`
- `app/modules/ai/AiService.php`
- `includes/footer.php`
- `assets/css/ai_assistant.css`
- `assets/js/ai_assistant.js`

Der Kontext kann Projekt, Wohnung, URL-Pfad, aktive Mieter, Mietzins/Nebenkosten, offene Pendenzen und Drive-Dateien aus `fs_nodes` berücksichtigen. Eine bestätigte `CREATE_PENDENZ`-Aktion schreibt Projekt, Wohnung, Titel, Priorität, Frist und Ersteller in die Datenbank.

### Voice-Pendenz

Betroffene Kerndateien:

- `api/voice_pendenz.php`
- `pages/pendenzen.php`
- `index_superadmin.php`
- `api/_bootstrap.php`

Der Parser erkennt aus freiem Text möglichst:

- Projekt/Liegenschaft
- Objekt
- Wohnung
- Raum
- Vorgangsart
- Zuständigkeit
- Priorität
- Frist
- Titel und Beschreibung

Leere Eingaben werden mit einer klaren API-Antwort abgewiesen. Audio kann bei vorhandener Gemini-Konfiguration als Base64 an Gemini Flash zur Transkription gegeben werden.

### Aktuelle Safari-Lösung

Die iPhone-Tastatur diktiert in ein fokussiertes normales `<textarea>`. Deshalb wird auf iOS/iPadOS der rote Mikrofonknopf nicht mehr als Website-Mikrofon verwendet, sondern:

1. das Textfeld fokussiert,
2. die iPhone-Tastatur öffnet,
3. der Benutzer drückt das Mikrofon der iPhone-Tastatur,
4. iOS schreibt den Text direkt in das Feld,
5. das vorhandene `input`-Event startet die Gimi-Analyse.

Der obere blaue Hinweisblock wurde aus dem Voice-Dialog entfernt. Auf Chrome/anderen Desktop-Browsern bleibt der direkte Recorder erhalten. Der direkte Recorder wurde zusätzlich angepasst:

- sichere API-URL mit `new URL(..., window.location.href)` und Fallback
- unterstützte MIME-Typen werden geprüft
- kein Safari-inkompatibles `mediaRecorder.start(500)` mehr
- Verarbeitung wartet auf `MediaRecorder.onstop`, damit kurze Safari-Aufnahmen nicht leer bleiben
- Öffnen des Dialogs fordert nicht mehr automatisch Mikrofonzugriff an
- Seite setzt `Cache-Control: no-store`/`Pragma: no-cache`, damit Safari alte Inline-Skripte nicht behält

Für die aktuelle Safari-Änderung muss auf dem Host nur `pages/pendenzen.php` aktualisiert werden. Testdateien aus `tests/` gehören nicht auf den Host.

## Verifikation

Zuletzt erfolgreich ausgeführt:

```text
C:/php/php.exe tests/pendenzen-regression.php    -> OK
node tests/voice-ui-regression.cjs               -> OK
node tests/finance-tables.test.cjs               -> OK
C:/php/php.exe tests/nebenkosten-calculation.php -> OK
C:/php/php.exe tests/nebenkosten-groups.php      -> OK
C:/php/php.exe -l pages/pendenzen.php            -> No syntax errors
git diff --check                                  -> sauber (nur CRLF-Hinweise)
```

`tests/nebenkosten-schema.php` führt `nk_bootstrap()` aus und kann lokal Tabellen/Seed-Daten anlegen. Vor einem Einsatz gegen eine produktive Datenbank zuerst die gewünschte Migration und das Backup klären.

Die lokale Browserprüfung mit Chrome hat den Voice-Dialog ohne den blauen Block geöffnet. Texteingabe mit

```text
Romanshorn Wohnung 3 Wasserhahn tropft dringend bis Freitag
```

wurde analysiert und zeigte Projekt, Wohnung, Dringlichkeit, Frist und den Titel `Wasserhahn tropft`.

Eine echte iPhone-Safari-Aufnahme konnte aus der Desktop-Umgebung nicht vollständig simuliert werden; der iOS-Pfad ist deshalb nach dem Upload auf dem iPhone zu prüfen.

## Offene Punkte für Antigravity

1. **Live-Liegenschaftsabrechnung:** Beim direkten Aufruf von `https://pendenz.com/tools/liegenschaftsabrechnung/index.php` wurde ein produktiver Fehler gesehen: `Incorrect DATE value: '0000-00-00'` in Zeile 86. Alle SQL-Vergleiche mit Null-Daten müssen auf `NULL`/gültige Datumswerte umgestellt werden.
2. **Safari-Upload prüfen:** Die aktuelle lokale `pages/pendenzen.php` erneut hochladen, Safari-Tab komplett schliessen und den iOS-Tastaturpfad testen.
3. **Voice-Projektauflösung:** Bei einem nur mit `Romanshorn` bezeichneten Text sind mehrere Projekte möglich. Für eindeutige Zuordnung Strasse/Projektname oder den aktuell ausgewählten Projektkontext priorisieren.
4. **Nebenkosten fachlich vervollständigen:** Akonto, Mietperioden, Leerstand, Flächen-/Personenänderungen, Belegverknüpfung, Freigabe und echte Speicherung gemäss Spezifikation ergänzen.
5. **Einheitentypen:** Die Namensheuristik für `Treppenhaus`, `Parkplätze`, `Allgemein` usw. langfristig durch ein explizites Datenfeld absichern.
6. **Git:** Die uncommitted Audit- und Safari-Änderungen nach Prüfung in einem fokussierten Commit sichern.

## Startpunkt für die Weiterarbeit

```text
Arbeite in C:\\xampp\\htdocs\\pendenz.com auf Branch chatgpt/full-audit-2026-09-15 weiter.
Lies zuerst docs/ANTIGRAVITY_HANDOVER_2026-09-16.md und prüfe git status.
Sichere die uncommitted Änderungen, führe die aufgeführten Tests aus und behebe zuerst den bekannten Fehler
"Incorrect DATE value: '0000-00-00'" in tools/liegenschaftsabrechnung/index.php.
Danach die aktuelle pages/pendenzen.php auf den Host übertragen und den iPhone-Safari-Tastatur-Diktatpfad testen.
Keine produktiven Pendenzen oder Finanzbuchungen ohne ausdrücklichen Testfall anlegen.
```
