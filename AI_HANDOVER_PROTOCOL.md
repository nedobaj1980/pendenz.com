# AI & Developer Handover-Protokoll: pendenz.com

**Projekt:** `pendenz.com`  
**Aktualisierungsdatum:** 14. September 2026  
**Zielgruppe:** Folgemodelle (ChatGPT / GPT-4o / Claude / Codex) und Software-Entwickler  
**Zweck:** Lückenlose Dokumentation aller durchgeführten Arbeiten, des aktuellen Systemzustands, der Datenbank-Architektur, der Google-Drive-Ordnerstruktur, der Liegenschaftsbuchhaltung und der konkreten nächsten Schritte.

---

## 1. Systemübersicht & Technische Grundlagen

### 1.1 Stack & Umgebung
- **Webserver:** Apache 2.4 (XAMPP auf Windows 11)
- **PHP:** PHP 8.2 (CLI-Ausführung immer mit `C:\php\php.exe` oder Apache-Webzugriff)
- **Datenbank:** MariaDB 10.4.32 / MySQL auf `127.0.0.1:3306`
  - **Datenbankname:** `pendenz_com`
  - **Benutzer:** `root`
  - **Passwort:** *(leer)*
- **Frontend:** Vanilla PHP, HTML5, Vanilla CSS / CSS Custom Properties, Vanilla JavaScript (kein Framework, kein Tailwind).
- **Speicherort:** `c:\xampp\htdocs\pendenz.com`
- **Lokaler Webzugriff:** `http://localhost/pendenz.com/`

### 1.2 Google Drive Integration (Dateisystem-Mount)
- **Mount-Pfad auf Windows:** `G:\Meine Ablage\Helvetic Immo Treuhand\`
- **Fallback / Selbstheilung:** In [includes/fs.php](file:///c:/xampp/htdocs/pendenz.com/includes/fs.php) implementiert (`project_root_path()`). Erkennt automatisch verfügbare Laufwerke (`G:`, `D:`, `C:`) und korrigiert veraltete Pfade in `projekte.root_path` selbstständig.

---

## 2. Zusammenfassung aller durchgeführten Arbeiten (11.09. – 14.09.2026)

### A. Datenbank-Wiederherstellung & Collation-Reparatur
1. **Server-Dump Import (`ch369984_pendenz_com (5).sql`)**:
   - Der Server-Dump schlug initial in XAMPP mit `#1273 - Unknown collation utf8mb4_0900_ai_ci` und doppelten Key-Definitionen fehl.
   - **Lösung:** Dump automatisiert konvertiert (`utf8mb4_0900_ai_ci` ➔ `utf8mb4_general_ci`), Bereinigung von Index-Kollisionen.
   - Vorab-Sicherheitsbackup erstellt unter: [backups/backup_before_server_import_2026-09-11.sql](file:///c:/xampp/htdocs/pendenz.com/backups/backup_before_server_import_2026-09-11.sql).
   - Erfolgreicher Import aller **136 Tabellen**, **41 Wohnungen**, **15 Mieter** und **265 Bankbuchungen**.
2. **Kritische Schema-Erkenntnisse (WICHTIG FÜR FOLGEMODELLE)**:
   - In `liegenschafts_konto` zeigt der Fremdschlüssel `fk_lk_mieter` auf `benutzer(id)` (**NICHT** auf `wohnung_mieter(id)`!). Wenn einer Buchung ein Mieter zugeordnet wird, dessen ID nicht in `benutzer` existiert, wirft MySQL einen FK-Constraint-Fehler. Daher prüft der Code stets die Existenz in `benutzer` oder setzt `mieter_id = NULL` bei gleichzeitigem Setzen von `wohnung_id` und `wohnung_label`.
   - In `wohnungen` heißt die Spalte für die Bezeichnung **`name`** (nicht `bezeichnung`).
   - In `wohnungen` existieren die Spalten **`mietzins_netto_soll`** und **`mietzins_nk_soll`** (nicht `mietzins` oder `nebenkosten`).
   - In `wohnungen` existiert **KEINE** Spalte `projekt_id`. Wohnungen gehören zu Objekten (`wohnungen.objekt_id` ➔ `objekte.id`), und `objekte` referenziert das Projekt (`objekte.projekt_id` ➔ `projekte.id`). Joins zu Projekten müssen immer über `objekte` laufen:
     ```sql
     LEFT JOIN objekte o ON w.objekt_id = o.id 
     LEFT JOIN projekte p ON o.projekt_id = p.id
     ```
   - In `objekte` heißt die Spalte für die Bezeichnung **`name`** (mit optionalem `bezeichnung`).
   - In `kv_konten` besitzt die Spalte `iban` einen Unique Index `uniq_iban`. Mehrere `NULL`-Werte sind zulässig, aber leere Strings `''` werfen Duplikat-Fehler. Leere IBANs müssen immer als `NULL` gespeichert werden.
   - In `pendenzen` existiert keine Spalte `plan_id` (wurde in `includes/functions.php` abgesichert).

---

### B. Dedizierte Bankkonten je Liegenschaft (`kv_konten`)
Gemäß der zentralen Anforderung *"es gibt pro liegenschaft immer ein anderes konto"*:
1. **Initialisierung aller Liegenschaftskonten**:
   - Für alle 8 aktiven Liegenschaften/Projekte (IDs 1 bis 8) existiert ein dediziertes Konto in `kv_konten` (verknüpft über `projekt_id` und `liegenschaft_id`).
   - Das Konto #4 wurde dem Projekt #3 (`03_Alte Amriswilerstrase 5 Romanshorn`) mit der IBAN `CH06 8080 8001 9163 5081 4` (Raiffeisen) zugeordnet.
   - Alle 265 bestehenden Banktransaktionen in `liegenschafts_konto` sind mit `konto_id = 4`, `konto_nr = 'CH06 8080 8001 9163 5081 4'`, `projekt_id = 3`, `liegenschaft_id = 3` verknüpft.
2. **Liegenschafts- und Bankkonto Status-Banner**:
   - In [tools/konto_verwaltung/index.php](file:///c:/xampp/htdocs/pendenz.com/tools/konto_verwaltung/index.php) und [tools/mietkontrolle/index.php](file:///c:/xampp/htdocs/pendenz.com/tools/mietkontrolle/index.php) wird oben stets das aktive Bankkonto mit Bankinstitut und formatierter IBAN angezeigt.
3. **Bankkonten-Modal (`#kontenModal`)**:
   - Ermöglicht das Ansehen und Bearbeiten von Bankkonten, IBAN und Bankname für jede Liegenschaft direkt im Interface.
4. **Synchronisierter CSV-Upload ([tools/konto_verwaltung/import.php](file:///c:/xampp/htdocs/pendenz.com/tools/konto_verwaltung/import.php))**:
   - Liegenschaft und zugeordnetes Bankkonto werden im Upload-Formular über ein Dropdown ausgewählt und synchronisieren sich gegenseitig via JavaScript.
   - Enthält der Dateiname des CSV-Auszugs eine IBAN (z.B. `CH0680808001916350814...csv`), werden Liegenschaft und Konto sofort automatisch vorausgewählt.

---

### C. Strikte Jahresansicht & Multi-Year CSV-Imports ("pro Jahr")
Gemäß der Anforderung *"auch wenn es imports geben wird die jahre drüber imports gibt, die sachen sollten immer pro jahr angezeigt werden"*:
1. **Multi-Year CSV-Vorschau ([tools/konto_verwaltung/import.php](file:///c:/xampp/htdocs/pendenz.com/tools/konto_verwaltung/import.php))**:
   - Analysiert hochgeladene CSV-Dateien und gruppiert die Daten automatisch nach den enthaltenen Buchungsjahren (z.B. 2022, 2023, 2024).
   - Zeigt ein interaktives Jahres-Dashboard mit Anzahl Buchungen, Eingängen (+ CHF) und Ausgängen (- CHF) je Jahr.
   - Interaktive Filter-Pills erlauben das Filtern der Vorschautabelle nach einzelnen Jahren vor dem finalen Speichern.
   - Alle Buchungen werden mit exaktem Buchungsdatum und FK-sicherer Mieter-/Wohnungs-Zuweisung gespeichert.
2. **Konto-Verwaltung Jahres-Tabs ([tools/konto_verwaltung/index.php](file:///c:/xampp/htdocs/pendenz.com/tools/konto_verwaltung/index.php))**:
   - Prominente Jahres-Tabs: `[2026] [2025] [2024] [2023] [🌐 Alle Jahre]`.
   - Schnellauswahl der Monate (`[Jan] [Feb] ... [Dez]`).
   - Alle Finanz-KPIs (Buchungsanzahl, Einnahmen, Ausgaben, Saldo, Zugeordnet, Offen) werden strikt auf das ausgewählte Jahr berechnet.
   - 12-Monatsübersicht des gewählten Jahres zeigt Monat für Monat Einnahmen, Ausgaben, Saldo und offene Posten.
3. **Mietkontrolle Jahres- & Matrix-Ansicht ([tools/mietkontrolle/index.php](file:///c:/xampp/htdocs/pendenz.com/tools/mietkontrolle/index.php))**:
   - **Jahres-Tabs & 12-Monats-Schnellwahltasten:** Beim Öffnen mit `?jahr=2023` wird sofort das Jahr 2023 mit dem neuesten aktiven Buchungsmonat geöffnet.
   - **Ganzjahres-Matrix (`view=year`):** Zeigt alle 12 Monate des gewählten Jahres nebeneinander für jede Wohnung/Einheit mit Statusanzeige (🟢 Bezahlt, 🟡 Teilzahlung, 🔴 Offen) und Ganzjahres-Saldo.
   - Direkte Verlinkung zwischen Konto-Verwaltung und Mietkontrolle unter Beibehaltung von Liegenschaft und Jahr.

---

### D. Behobene Fatal Errors & Stabilitätsverbesserungen
1. **`Unknown column 'w.projekt_id'` in `tools/konto_verwaltung/index.php:521`**:
   - Joins über `objekte` korrigiert (`LEFT JOIN objekte o ON w.objekt_id = o.id LEFT JOIN projekte p ON o.projekt_id = p.id`).
2. **`Unknown column 'k.buchungsdatum' in 'where clause'` in `tools/konto_verwaltung/index.php:689`**:
   - Tabelle `liegenschafts_konto` fehlte der Alias `k` im Suggestions-Query `$sugSql` und im Selection-Query `$sqlSel`. Behoben durch `FROM liegenschafts_konto k`.
3. **`Cannot redeclare` Schutz**:
   - Alle Hilfsfunktionen in `index.php`, `import.php` und `mietkontrolle/index.php` (`kv_table_has_column`, `table_exists`, `hasColumn`, `ensure_liegenschafts_konto`, `buildKontoUrl`, `h`) mit `if (!function_exists(...))` abgesichert.

---

## 3. Konkrete nächste Schritte für Folge-Entwickler / GPT

### Schritt 1: Wohnungen & Mieter für Romanshorn (Kreuzlingerstr. 21 / Amriswilerstr. 5) anlegen
In der DB fehlen für Projekt 2 und 3 noch die Wohnungs- und Mieterdatensätze. Sobald diese eingetragen sind, ordnet die Auto-Match Engine alle 265 Buchungen mit 1 Klick zu.

**Korrekte SQL-Vorlage:**
```sql
-- 1. Wohnungen anlegen (Objekt ID 2 für Kreuzlingerstr. 21):
INSERT INTO wohnungen (objekt_id, name, flaeche, zimmer, mietzins_netto_soll, mietzins_nk_soll, status) VALUES
(2, 'Whg 1.OG links - Nieder', 95.00, 3.5, 1650.00, 280.00, 'vermietet'),
(2, 'Whg 1.OG rechts - Doan', 55.00, 2.0, 950.00, 170.00, 'vermietet'),
(2, 'Whg 2.OG links - Kaden', 105.00, 4.5, 1810.00, 300.00, 'vermietet'),
(2, 'Whg 2.OG rechts - Ou Biru', 98.00, 3.5, 1730.00, 280.00, 'vermietet'),
(2, 'Whg 3.OG links - Petrovic', 98.00, 3.5, 1710.00, 280.00, 'vermietet'),
(2, 'Whg 3.OG rechts - Valado', 105.00, 4.5, 1790.00, 300.00, 'vermietet'),
(2, 'Attikawohnung - Wirz', 120.00, 4.5, 1900.00, 300.00, 'vermietet'),
(2, 'Gewerbe EG - Tres Chic', 60.00, 2.0, 690.00, 100.00, 'vermietet'),
(2, 'Wohnung EG - Meyer / Zeller', 95.00, 3.5, 1760.00, 300.00, 'vermietet');

-- 2. Mieter in wohnung_mieter anlegen:
INSERT INTO wohnung_mieter (wohnung_id, mieter_name, mietzins_netto, nk_akonto, startdatum, status) VALUES
((SELECT id FROM wohnungen WHERE objekt_id=2 AND name LIKE '%Nieder%' LIMIT 1), 'Roland Nieder', 1650.00, 280.00, '2020-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE objekt_id=2 AND name LIKE '%Doan%' LIMIT 1), 'Long Doan', 950.00, 170.00, '2021-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE objekt_id=2 AND name LIKE '%Kaden%' LIMIT 1), 'Wolfgang Kaden', 1810.00, 300.00, '2019-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE objekt_id=2 AND name LIKE '%Ou Biru%' LIMIT 1), 'Ou Biru', 1730.00, 280.00, '2020-05-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE objekt_id=2 AND name LIKE '%Petrovic%' LIMIT 1), 'Stéphane Petrovic', 1710.00, 280.00, '2020-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE objekt_id=2 AND name LIKE '%Valado%' LIMIT 1), 'Valado Fernandez Robert', 1790.00, 300.00, '2018-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE objekt_id=2 AND name LIKE '%Wirz%' LIMIT 1), 'Patrick Claude Wirz', 1900.00, 300.00, '2021-06-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE objekt_id=2 AND name LIKE '%Tres Chic%' LIMIT 1), 'Tres Chic Inh. Scialdone', 690.00, 100.00, '2019-01-01', 'aktiv'),
((SELECT id FROM wohnungen WHERE objekt_id=2 AND name LIKE '%Meyer%' LIMIT 1), 'Udo Meyer / Priska Zeller', 1760.00, 300.00, '2020-01-01', 'aktiv');
```

### Schritt 2: Auto-Match ausführen
- Im Webportal unter `tools/konto_verwaltung/index.php` auf **`⚡ Auto-Match starten`** klicken.
- Alle Mietzahlungen 2023 werden den Einheiten zugeordnet und in der Mietkontrolle `tools/mietkontrolle/index.php?projekt_id=2&jahr=2023` sofort grün angezeigt.

---

## 4. Wichtige Dateipfade & Referenzen

| Bereich | Dateipfad | Beschreibung |
|---|---|---|
| **Liegenschaftsbuchhaltung** | `tools/konto_verwaltung/index.php` | Hauptdashboard, Jahres-Tabs, Monatsübersicht, Bankkonto-Banner & `#kontenModal` |
| **Auto-Match Engine** | `tools/konto_verwaltung/auto_match.php` | Intelligente Erkennung von Mietern & Einheiten in Buchungstexten |
| **Bank CSV Import** | `tools/konto_verwaltung/import.php` | Multi-Year CSV Parser, Jahres-Vorschau, Bankkonto-Sync, FK-sichere Zuweisung |
| **Mietkontrolle** | `tools/mietkontrolle/index.php` | Jahres-Tabs, Monats-Pills, Monats-Detail & 12-Monats Ganzjahres-Matrix |
| **Dateisystem & Drive** | `includes/fs.php` | Google Drive Pfad-Auflösung, Ordner-Sync und `fs_nodes` Cache |
| **Dateimanager** | `pages/files.php` | Web-Explorer mit Direkt-Upload & Ordnererstellung in Google Drive |
| **Abnahmen Speichern** | `pages/abnahme_save.php` | Generiert PDF und legt es in `<Unit>/05_Abnahmen/` im Drive ab |
| **Mieterspiegel** | `pages/mieterspiegel.php` | Übersicht der Einheiten, Mietzinse, Nebenkosten und Mieterdaten |

---

## 5. Entwickler-Verhaltensregeln für Folgemodelle

1. **Keine Daten löschen bei fehlendem Mount:** Wenn der Google Drive Mount (`G:\`) temporär nicht erreichbar ist, dürfen niemals Datenbankeinträge aus `wohnungen`, `wohnung_mieter` oder `fs_nodes` gelöscht werden.
2. **FK-Integrität bei `liegenschafts_konto`:** Die Spalte `mieter_id` referenziert `benutzer(id)`. Niemals eine ID aus `wohnung_mieter` direkt in `mieter_id` schreiben, wenn kein entsprechender Datensatz in `benutzer` existiert. Bei reinen Mietern ohne Benutzerkonto wird `wohnung_id` und `wohnung_label` gesetzt und `mieter_id = NULL` belassen.
3. **Prepared Statements:** Bei allen neuen SQL-Mutationen ausnahmslos Prepared Statements (`$mysqli->prepare`) verwenden.
4. **Benutzer-Kommunikation:** Gemäß den Benutzerregeln immer auf **Deutsch** antworten und Reparaturen autonom und zielorientiert durchführen.
