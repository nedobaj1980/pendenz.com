# AI & Developer Handover-Protokoll: pendenz.com

**Projekt:** `pendenz.com`  
**Aktualisierungsdatum:** 13. September 2026  
**Zielgruppe:** Folgemodelle (ChatGPT / GPT-4o / Claude / Codex) und Software-Entwickler  
**Zweck:** Lückenlose Dokumentation aller durchgeführten Arbeiten, des aktuellen Systemzustands, der Datenbank-Architektur, der Google-Drive-Ordnerstruktur, der Liegenschaftsbuchhaltung und der konkreten nächsten Schritte.

---

## 1. Systemübersicht & Technische Grundlagen

### 1.1 Stack & Umgebung
- **Webserver:** Apache 2.4 (XAMPP auf Windows 11)
- **PHP:** PHP 8.2 (CLI-Ausführung immer mit `php -d extension=mysqli <skript.php>`)
- **Datenbank:** MariaDB 10.4.32 / MySQL auf `127.0.0.1:3306`
  - **Datenbankname:** `pendenz_com`
  - **Benutzer:** `root`
  - **Passwort:** *(leer)*
- **Frontend:** Vanilla PHP, HTML5, Vanilla CSS / CSS Custom Properties, Vanilla JavaScript (kein schweres Framework, kein Tailwind).
- **Speicherort:** `c:\xampp\htdocs\pendenz.com`
- **Lokaler Webzugriff:** `http://localhost/pendenz.com/`

### 1.2 Google Drive Integration (Dateisystem-Mount)
- **Mount-Pfad auf Windows:** `G:\Meine Ablage\Helvetic Immo Treuhand\`
- **Fallback / Selbstheilung:** In [includes/fs.php](file:///c:/xampp/htdocs/pendenz.com/includes/fs.php) implementiert (`project_root_path()`). Erkennt automatisch verfügbare Laufwerke (`G:`, `D:`, `C:`) und korrigiert veraltete Pfade in `projekte.root_path` selbstständig.

---

## 2. Zusammenfassung aller durchgeführten Arbeiten (11.09. – 13.09.2026)

### A. Datenbank-Wiederherstellung & Collation-Reparatur
1. **Server-Dump Import (`ch369984_pendenz_com (5).sql`)**:
   - Der Server-Dump schlug initial in XAMPP mit `#1273 - Unknown collation utf8mb4_0900_ai_ci` und doppelten Key-Definitionen fehl.
   - **Lösung:** Dump automatisiert konvertiert (`utf8mb4_0900_ai_ci` ➔ `utf8mb4_general_ci`), Bereinigung von Index-Kollisionen.
   - Vorab-Sicherheitsbackup erstellt unter: [backups/backup_before_server_import_2026-09-11.sql](file:///c:/xampp/htdocs/pendenz.com/backups/backup_before_server_import_2026-09-11.sql).
   - Erfolgreicher Import aller **136 Tabellen**, **41 Wohnungen**, **15 Mieter** und **265 Bankbuchungen**.
2. **Kritische Schema-Erkenntnisse (WICHTIG FÜR GPT)**:
   - In `liegenschafts_konto` zeigt der Fremdschlüssel `fk_lk_mieter` auf `benutzer(id)` (**NICHT** auf `wohnung_mieter(id)`!). Wenn einer Buchung ein Mieter zugeordnet wird, dessen ID nicht in `benutzer` existiert, wirft MySQL einen FK-Constraint-Fehler. Daher prüft der Code stets die Existenz in `benutzer` oder setzt `mieter_id = NULL` bei gleichzeitigem Setzen von `wohnung_id` und `wohnung_label`.
   - In `wohnungen` heißt die Spalte für die Bezeichnung **`name`** (nicht `bezeichnung`).
   - In `objekte` heißt die Spalte für die Bezeichnung **`name`** (mit optionalem `bezeichnung`).
   - In `pendenzen` existiert keine Spalte `plan_id` (wurde in `includes/functions.php` abgesichert, damit fehlende Tabellen oder Spalten keine Fatal Errors werfen).

---

### B. Google Drive Ordnerstruktur & Synchronisation
1. **Resiliente Pfad- und Sync-Engine ([includes/fs.php](file:///c:/xampp/htdocs/pendenz.com/includes/fs.php))**:
   - Google Drive Pfade aller 12 Projekte auf `G:/Meine Ablage/Helvetic Immo Treuhand/...` umgestellt.
   - `fs_list_children_smart_sync()`: Liest Ordner live von Google Drive und synchronisiert sie transparent mit dem DB-Cache `fs_nodes`.
   - Destruktives Pruning (`$pruneDb = true`) wurde deaktiviert: Es werden niemals Datensätze aus der DB gelöscht, nur weil ein Ordner lokal oder im Drive temporär nicht gemountet ist.
2. **Standardisierte Ordnerstruktur generiert**:
   - **Objekt-Ebene (10 Ordner):**
     - `01_Rechnungen` (mit Unterordnern `01_Januar` bis `12_Dezember`)
     - `02_Handwerker_Unterhalt`
     - `03_Versicherungen`
     - `04_Steuern`
     - `05_Allgemein`
     - `06_Bank_Liegenschaftskonto`
     - `07_Mietverträge`
     - `08_Mahnungen`
     - `10_Mietsache`
   - **Wohnungs-Ebene unter `10_Mietsache/<Wohnungsname>/` (5 Standardordner):**
     - `01_Mieter`
     - `02_Bilder`
     - `03_Dokumente`
     - `04_Vertraege`
     - `05_Abnahmen`
3. **Synchronisationsstatus der Projekte**:
   - **Projekt 4 (Nesslau):** 17 Wohnungen + Allgemeinbereiche angelegt, vollständig synchronisiert (354 Nodes in `fs_nodes`).
   - **Projekt 5 (Marbach Bajramoski):** 12 Wohnungen/Einheiten (`1.5 Zi. Wohnung`, `3.5 Zi. Wohnung`, `4.5 Zi. Wohnung`, `Attika`, `Hobbyraum`, `Einzellgarage`, `PP 5 Stk`) angelegt und mit allen 5 Unterordnern synchronisiert (166 Nodes in `fs_nodes`).
   - **Projekt 1 (Romanshorn Arbonerstr.):** Vollständig synchronisiert (110 Nodes in `fs_nodes`).
4. **Abnahmeprotokolle automatische Dateiablage**:
   - In [pages/abnahme_save.php](file:///c:/xampp/htdocs/pendenz.com/pages/abnahme_save.php) und [pages/wohnungsabnahme_save.php](file:///c:/xampp/htdocs/pendenz.com/pages/wohnungsabnahme_save.php):
   - Generierte Abnahme-PDFs werden automatisch im Google Drive Wohnungsordner unter `<Objekt>/10_Mietsache/<Wohnung>/05_Abnahmen/` abgelegt und in `fs_nodes` registriert. Zusätzlich verbleibt eine Web-Vorschau unter `uploads/protocols/`.
5. **Dateimanager ([pages/files.php](file:///c:/xampp/htdocs/pendenz.com/pages/files.php))**:
   - Unterstützt Live-Dateisystem-Anzeige, direkte Ordneranlage (`➕ Neuer Ordner`) und direkten Datei-Upload (`⬆️ Hochladen`) live ins Google Drive. In Chrome erstellte Drive-Dateien sind sofort im Webportal sichtbar.

---

### C. Liegenschaftsbuchhaltung & Kontoverwaltung ([tools/konto_verwaltung/](file:///c:/xampp/htdocs/pendenz.com/tools/konto_verwaltung/))
1. **Auto-Match Engine ([tools/konto_verwaltung/auto_match.php](file:///c:/xampp/htdocs/pendenz.com/tools/konto_verwaltung/auto_match.php))**:
   - Wiederverwendbare Funktion `run_auto_match(mysqli $mysqli, int $pid = 0)`.
   - Durchsucht Buchungstexte nach:
     - Vollständigen Mieternamen (`wohnung_mieter.mieter_name`).
     - Vor- und Nachnamen (Wortlänge >= 4 Zeichen zur Vermeidung von False Positives).
     - Wohnungsbezeichnungen (z. B. `Whg 1.OG`, `Attika`, `3.5 Zi`, `4.5 Zi`).
   - Berücksichtigt FK-Integrität: Weist `mieter_id` nur zu, wenn eine Benutzer-ID in `benutzer` existiert, und setzt zuverlässig `wohnung_id` und `wohnung_label`.
2. **Modernisierung des Bank-Dashboards ([tools/konto_verwaltung/index.php](file:///c:/xampp/htdocs/pendenz.com/tools/konto_verwaltung/index.php))**:
   - **Rollenberechtigung:** Sowohl `superadmin` als auch `admin` freigeschaltet.
   - **KPI Header-Widgets:**
     - Gefiltert / Gesamt-Buchungen
     - Gefilterter Saldo in CHF (grün/rot formatiert)
     - 🟢 **Zugeordnet** (mit 1-Klick Filter auf zugeordnete Buchungen)
     - 🔴 **Unzugeordnet (Offen)** (mit 1-Klick Filter auf offene Buchungen)
   - **Filterleiste:** Filter nach Projekt, Kategorie, Zeitraum, Textsuche und Status (`all`, `matched`, `unmatched`).
   - **Aktionsleiste oben:** Direkte Buttons für `📂 CSV importieren`, `⚡ Auto-Match starten` und `💰 Zur Mietkontrolle`.
   - **Transaktionstabelle:** Zeigt Liegenschaft, Betrag (+/- CHF mit Farb-Badge), Buchungstext, Kategorie, Wohnung-Badge (`🏠`), Mieter-Badge (`👤`) sowie interaktiven Zuweisen-Button (`📌 Zuweisen`).
   - **Batch-Zuweisung:** Mehrfachauswahl von Checkboxen mit Dropdowns für `$allWohnungen` und `$allMieter` zur Massenzuweisung.
   - **1-Klick Interaktives Modal (`#assignModal`):**
     - Klick auf `📌 Zuweisen` öffnet sofort einen zentrierten Dialog mit den Details der Buchung.
     - Enthält Dropdowns für Wohnung und Mieter.
     - **Intelligente Vorbelegung:** Bei Auswahl einer Wohnung wählt JavaScript automatisch den dort hinterlegten Mieter aus.
     - Schnellspeicherung per POST `assign_single` ohne Seitenwechsel-Verlust.
3. **CSV-Import ([tools/konto_verwaltung/import.php](file:///c:/xampp/htdocs/pendenz.com/tools/konto_verwaltung/import.php))**:
   - Unterstützt Schweizer Formate (CHF 1'250.00, PostFinance, Raiffeisen, UBS).
   - Erkennt Spalten für Datum, Betrag, Buchungstext und Haben/Soll.

---

### D. Mietkontrolle Dashboard ([tools/mietkontrolle/](file:///c:/xampp/htdocs/pendenz.com/tools/mietkontrolle/))
1. **Funktionsumfang ([tools/mietkontrolle/index.php](file:///c:/xampp/htdocs/pendenz.com/tools/mietkontrolle/index.php))**:
   - **Monats- & Jahresnavigation:** Vor- und Zurückblättern zwischen Monaten.
   - **Projektfilter:** Filterung nach Liegenschaft/Bauprojekt.
   - **KPI-Übersicht:** Soll-Miete Gesamt, Ist-Eingänge Gesamt, Offener Betrag, Zahlungsquote in %.
   - **Ampelstatus je Wohnung/Mieter:**
     - 🟢 **Bezahlt:** Ist >= Soll
     - 🟡 **Teilzahlung:** 0 < Ist < Soll
     - 🔴 **Offen / Ausstehend:** Ist = 0
     - 🔵 **Überzahlt:** Ist > Soll
   - **Aufklappbare Detailbuchungen:** Klick auf eine Zeile listet alle konkreten Bankbuchungen auf, die zu diesem Mieter in diesem Monat eingegangen sind.
2. **Systemweite Verlinkung (Überall im Portal erreichbar)**:
   - **Superadmin- & Admin-Navigation:** Unter `📈 Finanzen` ➔ `💰 Mietkontrolle & Zahlungen`.
   - **Sidebar-Projektliste:** Jedes Projekt hat direkt neben Mieterspiegel den Link `💰 Mietkontrolle`.
   - **Mieterspiegel ([pages/mieterspiegel.php](file:///c:/xampp/htdocs/pendenz.com/pages/mieterspiegel.php)):** Prominenter Button `💰 Mietkontrolle` im Header oben rechts.
   - **Projekt-Dashboard ([pages/projekt_dashboard.php](file:///c:/xampp/htdocs/pendenz.com/pages/projekt_dashboard.php)):** Button `💰 Mietkontrolle` in der oberen Toolbar.
   - **Finanzen-Kontoauszug ([pages/finanzen.php](file:///c:/xampp/htdocs/pendenz.com/pages/finanzen.php)):** Schnellzugriff-Buttons oben.
   - **Mobile Navigationsleiste:** Finanzen-Icon mit Direktlink zur Mietkontrolle.

---

### E. Frühere Sicherheits- & Stabilitäts-Fixes (Phase 0)
- **Session-Leak eliminiert:** `login_debug.log` bereinigt und Logging von Klartext-Passwörtern/Sessions in `login.php` entfernt.
- **Pendenzen-Schutz:** Unkonditioniertes `UPDATE pendenzen SET public_enabled = 1` in `pages/pendenzen.php` entfernt.
- **API-Auth-Gates:** `api/benutzer_update.php`, `api/benutzer_delete.php`, `api/pendenzen_inline_save.php`, `api/projekt_member_*.php` mit Login- und Rollenprüfungen geschützt.
- **SQL-Injections behoben:** `pages/abnahme.php`, `pages/vertrag_gen.php`, `pages/vertrag_save.php`, `pages/mieterspiegel.php` auf Prepared Statements und typsicheres Casting umgestellt.
- **Broken Pages:** `pages/project_storage.php` (Include-Reihenfolge) und `pages/ordner_vorlagen.php` (Spaltenkorrektur `rel_path`, `sort`) repariert.

---

## 3. Analyse des aktuellen Buchungs- und Mieterbestands (2023 Kontoauszug)

In der Datenbank befinden sich derzeit **265 Bankbuchungen** aus der Datei:
`Konto_CH0680808001916350814_..._Auszug 2023 ohne Details.csv`.

### 3.1 Identifizierte Mietzahlungen (Kreuzlingerstrasse 21, Romanshorn)
Bei der Analyse der Haben-Buchungen (Mieteinzahlungen) wurden folgende regelmäßige Mieter ermittelt:
1. **Roland Nieder:** CHF 1'930.00 / Monat (z. B. Buchung #119, #144, #168, #195, #222)
2. **Wolfgang Kaden:** CHF 2'110.00 / Monat (z. B. Buchung #108, #134, #157, #182, #212)
3. **Ou Biru:** CHF 2'010.00 / Monat (z. B. Buchung #117, #142, #165, #193, #220)
4. **Stéphane Petrovic:** CHF 1'990.00 / Monat (z. B. Buchung #106, #132, #155, #180, #210)
5. **Long Doan:** CHF 1'120.00 / Monat (z. B. Buchung #121, #147, #170, #198, #224)
6. **Valado Fernandez Robert:** CHF 2'090.00 / Monat (z. B. Buchung #104, #130, #153, #178, #208)
7. **Tres Chic Inh. Scialdone:** CHF 790.00 / Monat (z. B. Buchung #123, #149, #172, #200, #226)
8. **Udo Meyer & Priska Zeller:** CHF 350.00 bis 2'060.00 / Monat (z. B. Buchung #111, #137, #160, #186, #215)
9. **Patrick Claude Wirz:** CHF 2'100.00 bis 2'200.00 / Monat (z. B. Buchung #114, #139, #162, #189, #217)

### 3.2 Warum waren diese Buchungen noch nicht gematcht?
In der vom Server importierten Datenbank waren unter `wohnung_mieter` nur Mieter für **Marbach** (11 Einheiten) und **Nesslau** (3 Einheiten) hinterlegt. Die Wohnungen und Mietverhältnisse für **Romanshorn (Kreuzlingerstrasse 21 & Arbonerstrasse 32a)** waren in der Datenbank noch nicht eingepflegt!

---

## 4. Konkrete Roadmap & Nächste Schritte für GPT

Wenn du mit GPT an dieser Stelle weitermachst, folge bitte exakt dieser Reihenfolge:

### Schritt 1: Wohnungen & Mieter für Romanshorn anlegen
Um die 260 offenen Buchungen sofort automatisch zuzuordnen, müssen die Wohnungen und Mieter für Projekt 2 (`02_Kreuzlingerstrasse 21 Romanshorn`) in `wohnungen` und `wohnung_mieter` eingetragen werden.

**SQL-Vorlage zum Ausführen in phpMyAdmin oder per PHP-Skript:**
```sql
-- Objekt ID für Kreuzlingerstrasse 21 ermitteln (Projekt ID 2)
-- 1. Wohnungen anlegen:
INSERT INTO wohnungen (projekt_id, name, flaeche, zimmer, mietzins, nebenkosten, status) VALUES
(2, 'Whg 1.OG links - Nieder', 95, 3.5, 1650.00, 280.00, 'vermietet'),
(2, 'Whg 1.OG rechts - Doan', 55, 2.0, 950.00, 170.00, 'vermietet'),
(2, 'Whg 2.OG links - Kaden', 105, 4.5, 1810.00, 300.00, 'vermietet'),
(2, 'Whg 2.OG rechts - Ou Biru', 98, 3.5, 1730.00, 280.00, 'vermietet'),
(2, 'Whg 3.OG links - Petrovic', 98, 3.5, 1710.00, 280.00, 'vermietet'),
(2, 'Whg 3.OG rechts - Valado', 105, 4.5, 1790.00, 300.00, 'vermietet'),
(2, 'Attikawohnung - Wirz', 120, 4.5, 1900.00, 300.00, 'vermietet'),
(2, 'Gewerbe EG - Tres Chic', 60, 2.0, 690.00, 100.00, 'vermietet'),
(2, 'Wohnung EG - Meyer / Zeller', 95, 3.5, 1760.00, 300.00, 'vermietet');

-- 2. Mieter in wohnung_mieter anlegen (IDs der oben erstellten Wohnungen einsetzen):
INSERT INTO wohnung_mieter (wohnung_id, mieter_name, mietzins_basis, akonto_hk_nk, mietbeginn, status) VALUES
((SELECT id FROM wohnungen WHERE projekt_id=2 AND name LIKE '%Nieder%' LIMIT 1), 'Roland Nieder', 1650.00, 280.00, '2020-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE projekt_id=2 AND name LIKE '%Doan%' LIMIT 1), 'Long Doan', 950.00, 170.00, '2021-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE projekt_id=2 AND name LIKE '%Kaden%' LIMIT 1), 'Wolfgang Kaden', 1810.00, 300.00, '2019-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE projekt_id=2 AND name LIKE '%Ou Biru%' LIMIT 1), 'Ou Biru', 1730.00, 280.00, '2020-05-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE projekt_id=2 AND name LIKE '%Petrovic%' LIMIT 1), 'Stéphane Petrovic', 1710.00, 280.00, '2020-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE projekt_id=2 AND name LIKE '%Valado%' LIMIT 1), 'Valado Fernandez Robert', 1790.00, 300.00, '2018-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE projekt_id=2 AND name LIKE '%Wirz%' LIMIT 1), 'Patrick Claude Wirz', 1900.00, 300.00, '2021-06-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE projekt_id=2 AND name LIKE '%Tres Chic%' LIMIT 1), 'Tres Chic Inh. Scialdone', 690.00, 100.00, '2019-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE projekt_id=2 AND name LIKE '%Meyer%' LIMIT 1), 'Udo Meyer / Priska Zeller', 1760.00, 300.00, '2020-01-01', 'aktiv');
```

### Schritt 2: Auto-Match erneut ausführen
Nach dem Anlegen der Romanshorn-Einheiten:
- Im Webportal unter `http://localhost/pendenz.com/tools/konto_verwaltung/index.php` auf den grünen Button **`⚡ Auto-Match starten`** klicken (oder CLI: `php -d extension=mysqli scratch/test_match_all.php`).
- **Ergebnis:** Nahezu alle 265 Buchungen werden den Einheiten zugeordnet. In der Mietkontrolle `http://localhost/pendenz.com/tools/mietkontrolle/index.php?projekt_id=2` werden alle Monate für 2023 sofort grün (vollständig bezahlt) angezeigt!

### Schritt 3: Ordner auf Google Drive für Romanshorn anlegen
- Skript ausführen, um für die neuen Romanshorn-Wohnungen die Standard-Ordnerstruktur (`01_Mieter`, `02_Bilder`, `03_Dokumente`, `04_Vertraege`, `05_Abnahmen`) auf Google Drive zu erzeugen:
```php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/fs.php';
sync_project_folders($mysqli, 2, false);
```

### Schritt 4: Mandanten-Entflechtung (Phase 1)
Einführung der 3 Eigentümerportfolios:
1. `mandanten` Tabelle anlegen (`id`, `nummer`, `name`, `typ`):
   - Mandant 1 (101): `Helvetic Immo AG`
   - Mandant 2 (102): `Nedim Privat`
   - Mandant 3 (103): `Nedjip Privat`
2. Spalte `mandant_id` in `projekte`, `objekte`, `liegenschafts_konto` und `kv_konten` verknüpfen.
3. Mandanten-Filter in die Header-Navigation integrieren, sodass nach Eigentümer gefiltert werden kann.

---

## 5. Wichtige Dateipfade & Referenzen

| Bereich | Dateipfad | Beschreibung |
|---|---|---|
| **Liegenschaftsbuchhaltung** | `tools/konto_verwaltung/index.php` | Hauptdashboard, KPIs, Filter, Batch-Aktionen & Zuweisungs-Modal |
| **Auto-Match Engine** | `tools/konto_verwaltung/auto_match.php` | Intelligente Erkennung von Mietern & Einheiten in Buchungstexten |
| **Bank CSV Import** | `tools/konto_verwaltung/import.php` | Parser für Bankauszüge (CHF, PostFinance, Raiffeisen, UBS) |
| **Mietkontrolle** | `tools/mietkontrolle/index.php` | Soll/Ist-Vergleich, Zahlungsquoten, Monats-/Jahresnavigation |
| **Dateisystem & Drive** | `includes/fs.php` | Google Drive Pfad-Auflösung, Ordner-Sync und `fs_nodes` Cache |
| **Dateimanager** | `pages/files.php` | Web-Explorer mit Direkt-Upload & Ordnererstellung in Google Drive |
| **Abnahmen Speichern** | `pages/abnahme_save.php` | Generiert PDF und legt es in `<Unit>/05_Abnahmen/` im Drive ab |
| **Mieterspiegel** | `pages/mieterspiegel.php` | Übersicht der Einheiten, Mietzinse, Nebenkosten und Mieterdaten |
| **Navigation Superadmin** | `includes/nav_superadmin.php` | Hauptmenü für Superadministratoren mit Verlinkungen |
| **Navigation Admin** | `includes/nav_admin.php` | Hauptmenü für Administratoren |
| **Datenbank-Konfiguration** | `config.php` | DB-Zugangsdaten, Session-Start, Prefix- und Pfadkonstanten |
| **DB-Sicherheitsbackup** | `backups/backup_before_server_import_2026-09-11.sql` | Reines Backup vor dem Serverimport (1.98 MB) |

---

## 6. Entwickler-Verhaltensregeln für Folgemodelle

1. **Keine Daten löschen bei fehlendem Mount:** Wenn der Google Drive Mount (`G:\`) temporär nicht erreichbar ist, dürfen niemals Datenbankeinträge aus `wohnungen`, `wohnung_mieter` oder `fs_nodes` gelöscht werden.
2. **FK-Integrität bei `liegenschafts_konto`:** Die Spalte `mieter_id` referenziert `benutzer(id)`. Niemals eine ID aus `wohnung_mieter` direkt in `mieter_id` schreiben, wenn kein entsprechender Datensatz in `benutzer` existiert. Bei reinen Mietern ohne Benutzerkonto wird `wohnung_id` und `wohnung_label` gesetzt und `mieter_id = NULL` belassen.
3. **Prepared Statements:** Bei allen neuen SQL-Mutationen ausnahmslos Prepared Statements (`$mysqli->prepare`) verwenden.
4. **Benutzer-Kommunikation:** Gemäß den Benutzerregeln immer auf **Deutsch** antworten und Reparaturen autonom und zielorientiert durchführen.
