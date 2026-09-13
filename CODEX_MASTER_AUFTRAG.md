# Master-Auftrag für Codex: pendenz.com analysieren und Ausbau planen

Arbeite im bestehenden Projekt:

`C:\xampp\htdocs\pendenz.com`

## Ziel

pendenz.com soll zu einem integrierten System für Bauleitung, Baumanagement, Liegenschaftsverwaltung und Liegenschaftsbuchhaltung weiterentwickelt werden.

Das System soll drei Eigentümerbereiche sauber führen:

- Helvetic Immo AG
- Nedim Privat
- Nedjip Privat

Es soll Projekte, Liegenschaften, Gebäude, Wohnungen, Mieter, Firmen, Pendenzen, Termine, Abnahmen, Dokumente, Rechnungen, Bankbewegungen und automatische Benachrichtigungen verbinden. Die zentrale Dateiablage soll über Google Drive funktionieren und zuverlässig mit der Website synchronisiert werden.

## Sehr wichtige Arbeitsregel

Beginne mit einer vollständigen Read-only-Analyse. Verändere zunächst keinen bestehenden Code, keine Datenbank, keine Dateien in Google Drive und keine produktiven Daten.

Erstelle zuerst einen nachvollziehbaren Bericht und einen schrittweisen Umsetzungsplan. Erst nach ausdrücklicher Freigabe darf Phase 0 umgesetzt werden. Führe nie mehrere große Phasen ungeprüft in einem Schritt aus.

Lies zuerst die vorhandene Analyse `PENDENZ_GESAMTANALYSE.md`, falls sie im Projekt bereitgestellt wurde. Prüfe jede Aussage selbst am aktuellen Code und kennzeichne Abweichungen.

## 1. Vollständige technische Inventur

Analysiere:

- gesamte Ordner- und Dateistruktur
- Einstiegspunkte und Routing
- PHP, JavaScript, CSS, Includes, APIs und Tools
- Composer-Abhängigkeiten
- Konfiguration, Umgebungsvariablen und Deployment-Annahmen
- Datenbankzugriff und alle SQL-Abfragen
- vorhandene SQL-Dateien, Migrationen und Schema-Autoinstaller
- produktiv verwendete Tabellen, Spalten, Indizes und Fremdschlüssel
- Uploads, PDFs, Bilder, Dokumente und Dateipfade
- Mailversand, Einladungen und öffentliche Links
- Service Worker, Sync-Center und Offline-Verhalten
- Logging, Audit und Fehlerbehandlung
- vorhandene Tests und sinnvolle Testlücken

Erstelle eine Liste mit:

1. aktiv produktiv verwendeten Dateien,
2. wahrscheinlich veralteten Kopien und Sicherungen,
3. Debug-, Test-, Reparatur- und Migrationsdateien,
4. möglicherweise öffentlich erreichbaren sensiblen Dateien,
5. großen oder stark duplizierten Dateien,
6. Dateien, die gefahrlos erst nach Prüfung außerhalb des Webroots archiviert werden könnten.

Noch nichts löschen oder verschieben.

## 2. Rollen, Rechte und Sicherheit

Ermittle das tatsächliche Rollenmodell und alle Sondertypen, unter anderem:

- superadmin
- admin
- benutzer
- gast
- Eigentümer/Verwalter
- Bauleiter/Projektleiter
- Unternehmer/Handwerker
- Mieter

Prüfe für jede Seite und jeden API-Endpunkt:

- Loginpflicht
- Rollenprüfung
- Projekt-/Mandantenzugriff
- Pendenz-ACL
- CSRF bei Zustandsänderungen
- SQL-Injection-Risiko
- XSS und sichere Ausgabe
- Upload-Prüfung
- öffentliche Tokens und deren Lebensdauer
- Session-Cookies, Session-Fixation und Logout
- Rate Limits für Login, Einladungen, öffentliche Formulare und KI
- Offenlegung von PHP-Fehlern und Serverpfaden
- Schutz von `config.php`, SQL-, Log-, Debug-, Test- und Migrationsdateien

Prüfe besonders, warum nur wenige Dateien `require_role()` oder `require_project_access()` aufrufen und ob Rechte an anderer Stelle zuverlässig erzwungen werden.

## 3. Fachliches Modell entflechten

Analysiere die aktuellen Beziehungen zwischen:

- Eigentümer/Mandant
- Portfolio
- Liegenschaft
- Bauprojekt
- Gebäude/Objekt
- Wohnung/Einheit
- Raum
- Benutzer/Firma
- Mietinteressent
- Mieter und Mietvertrag
- Pendenz
- Abnahme/Protokoll
- Datei/Ordner
- Bankkonto/Bankbewegung

Erstelle ein Ist-ER-Diagramm und ein empfohlenes Ziel-ER-Diagramm.

Das Zielmodell muss Bauprojekte von Eigentümern und Liegenschaften trennen. Die Einträge `101_Helvetic Immo AG`, `102_Nedim Privat` und `103_Nedjip Privat` sind heute als Projekte sichtbar, sollen fachlich aber als Eigentümer-/Mandantenbereiche modelliert werden. Entwickle einen verlustfreien Migrationsvorschlag.

## 4. Google-Drive-Architektur

Prüfe die gesamte aktuelle Dateisystemlogik, besonders:

- `includes/fs.php`
- `pages/project_storage.php`
- `pages/files.php`
- `pages/storage_manager.php`
- `pages/quick_folder_editor.php`
- `pages/ordner_vorlagen.php`
- `pages/sync_drive_real.php`
- `pages/sync_v2.php`
- `scan_drive.php`
- Felder wie `root_path`, `storage_type`, `folder_name`, `ordner_path` und `ordner_id`

Die vorhandene Lösung verwendet Google Drive Desktop als lokales Dateisystem und enthält fest codierte Windows-Pfade. Die produktive Website läuft auf einem Linux-Webserver. Entwirf deshalb eine echte Integration über die offizielle Google Drive API.

Die Zielarchitektur muss enthalten:

- OAuth oder Servicekonto; vergleiche beide Optionen für diesen konkreten Betrieb
- Empfehlung für persönliches Drive oder Shared Drive
- Speicherung stabiler Google-Drive-Datei- und Ordner-IDs
- Tabellen für Verbindung, Dateien, Verknüpfungen, Jobs, Sync-Status und Konflikte
- Website-Upload nach Drive
- Erfassung von Änderungen aus Drive über Changes API/Webhook und periodischen Fallback
- idempotente Jobs, Retry mit Backoff, Dead-Letter-Status und manuelles Wiederholen
- Versions- und Konfliktbehandlung
- Soft-Delete und Wiederherstellung
- Berechtigungsmodell
- Migration vorhandener Dateien ohne Dubletten
- Sync-Dashboard je Eigentümer, Liegenschaft und Datei

Die Datenbank soll fachliche Metadaten und Status führen. Google Drive soll den Dateiinhalt führen. Ein fehlender oder vorübergehend nicht erreichbarer Ordner darf nie automatisch eine Wohnung, Rechnung oder andere Fachdaten löschen.

Plane die Migration auf folgende logische Struktur:

```text
pendenz.com/
├── 01_Helvetic_Immo_AG/Liegenschaften/
├── 02_Nedim_Privat/Liegenschaften/
└── 03_Nedjip_Privat/Liegenschaften/
```

Pro Liegenschaft:

```text
00_Stammdaten
01_Finanzen/Eingangsrechnungen/<Jahr>/<Monat>
01_Finanzen/Bank/<Jahr>
01_Finanzen/Budgets
01_Finanzen/Abschluesse
02_Mietwesen/<Einheit>/01_Mieter
02_Mietwesen/<Einheit>/02_Vertraege
02_Mietwesen/<Einheit>/03_Uebergaben_Abnahmen
02_Mietwesen/<Einheit>/04_Korrespondenz
02_Mietwesen/<Einheit>/05_Bilder_Dokumente
03_Unterhalt_Handwerker
04_Versicherungen
05_Steuern
06_Plaene_Grundrisse
07_Bauprojekte
08_Korrespondenz
99_Archiv
```

Prüfe die Struktur gegen die vorhandenen Ordner und schlage eine Zuordnungstabelle für die Migration vor. Verschiebe während der Analyse nichts.

## 5. Liegenschaftsbuchhaltung

Prüfe besonders:

- `pages/finanzen.php`
- `tools/konto_verwaltung/`
- `tools/mietkontrolle/`
- `includes/rent.php`
- Tabellen rund um `liegenschafts_konto`, Konten, Buchungen, Mieten, Mietverhältnisse und Overrides

Das Ziel ist eine nachvollziehbare Verwaltung pro Eigentümer und Liegenschaft:

- Bankkonten und wiederholbarer CSV-Import
- Dublettenerkennung
- Lieferanten und Eingangsrechnungen
- Rechnungsnummer, Datum, Fälligkeit, Betrag, Steuer, Kategorie/BKP und Kostenstelle
- Status: Entwurf, geprüft, freigegeben, teilbezahlt, bezahlt, überfällig, storniert
- Teilzahlungen und Restbetrag
- direkter Beleglink zu Google Drive
- automatische und manuelle Zuordnung von Bankbewegungen
- Mietsoll je Vertrag und Monat
- Zahlungseingänge, Teilzahlungen und offene Mieten
- Budget/Ist je Eigentümer, Liegenschaft, Objekt, Einheit, BKP und Zeitraum
- Monats- und Jahresauswertung
- Export für Treuhand/Buchhaltungssoftware
- unveränderbare Audit-Historie für Buchungsänderungen

Nutze Geldbeträge als DECIMAL und entwirf saubere Buchungs- und Stornologik. Prüfe, welche Funktionen als Verwaltungshilfe dienen und welche Anforderungen für eine formelle Schweizer Buchhaltung zusätzlich fachlich geklärt werden müssen.

## 6. Automatische Benachrichtigungen und Mahnungen

Analysiere die aktuellen Tabellen und Dateien für `notifications`, `user_notifications`, E-Mail und den Pendenz-Workflow. Vereinheitliche das Konzept im Zielentwurf.

Plane eine zentrale Regelengine für:

- neue oder neu zugewiesene Pendenz
- Statusänderung und Rückmeldung
- Pendenz bald fällig und überfällig
- Rechnung bald fällig und überfällig
- Miete nach Karenzfrist nicht vollständig bezahlt
- Mietvertrag, Garantie oder Versicherung läuft aus
- Drive-Synchronisation dauerhaft fehlgeschlagen

Jede Regel braucht:

- Ereignis oder Zeitplan
- Bedingungen
- Empfänger
- Kanal: In-App/E-Mail, später optional SMS/WhatsApp
- Vorlage pro Eigentümer
- Frequenzbegrenzung und Eskalationsstufe
- Versandwarteschlange
- Zustellstatus, Fehler und Wiederholungen
- Audit-Eintrag

Automatische Mahnungen sollen zunächst als Entwurf erzeugt werden. Entwirf einen Freigabeprozess, bevor E-Mails oder PDFs an Mieter gesendet werden.

## 7. Bau- und Pendenzenmanagement

Prüfe:

- Pendenz-Cockpit und Listenprofile
- schnelle Erfassung
- BKP- und Mieter-/Vermieter-Kategorien
- Verantwortliche und Firmen
- Priorität, Start, Fälligkeit, Dauer und Vorgänger
- Planmarkierungen
- Bilder, Dokumente, Rückmeldungen und Bestätigung
- externe/public Links und Uploadrechte
- PDF-Berichte und Designer
- Gantt-Terminprogramm
- Abnahmen und Protokolle
- mobile Baustellennutzung

Vereinheitliche die konkurrierenden Statusmodelle. Erstelle eine klare Statusmaschine mit erlaubten Übergängen, Verantwortlichkeiten und Audit-Ereignissen.

## 8. Bekannte Fehler reproduzieren und einordnen

Noch nicht beheben, sondern Ursache, betroffene Dateien und sicheren Lösungsvorschlag dokumentieren:

1. `pages/project_storage.php`: `Call to undefined function require_login()`.
2. `pages/ordner_vorlagen.php`: `Unknown column 'name' in 'order clause'` bei `ordner_vorlagen_nodes`.
3. Projekt 4 meldet 27 fehlende Drive-Ordner.
4. `protokoll_manager.php` und `audit.php` zeigten keine nutzbare Ausgabe.
5. Produktive PHP-Fehler zeigen interne Serverpfade.
6. Sync-Code kann bei `pruneDb=true` Wohnungen löschen, wenn Ordner fehlen.
7. Hardcodierte Windows-Pfade in Drive-Skripten.
8. Unterschiedliche Tabellen und Modelle für Benachrichtigungen.
9. Basisschema, Einzelmigrationen, Auto-Installer und produktive Struktur scheinen voneinander abzuweichen.

## 9. Erwartete Ergebnisse der Analyse

Lege unter `docs/analysis/` folgende Dateien an, ohne Produktivcode zu verändern:

1. `01-system-inventory.md` – aktive Module, Routen, Dateien und Abhängigkeiten
2. `02-current-data-model.md` – Ist-Tabellen und ER-Diagramm
3. `03-security-audit.md` – Befunde nach Kritikalität mit Dateistellen
4. `04-storage-drive-audit.md` – aktueller Datenfluss und Zielarchitektur
5. `05-accounting-gap-analysis.md` – Ist/Fehlt/Zielmodell
6. `06-notification-gap-analysis.md` – Ereignisse, Regeln, Scheduler und Versand
7. `07-migration-plan.md` – Daten-, Datei- und Schema-Migration mit Rollback
8. `08-implementation-roadmap.md` – kleine, überprüfbare Phasen
9. `09-test-plan.md` – Unit-, Integrations-, Rollen-, Sync- und Browsertests
10. `10-open-decisions.md` – nur Fragen, die wirklich eine Entscheidung des Eigentümers benötigen

Zusätzlich:

- Mermaid-Diagramm für Ist- und Zielarchitektur
- Matrix Seite/API × Rolle × erlaubte Aktion
- Tabelle aktuelles Schema × erwartetes Schema × notwendige Migration
- Risikoliste mit `kritisch`, `hoch`, `mittel`, `niedrig`
- Aufwandsschätzung pro Phase in kleinen/mittleren/großen Arbeitspaketen
- konkrete Definition of Done für jede Phase

## 10. Arbeitsweise und Sicherheitsgrenzen

- keine Änderungen während der Analyse
- keine destruktiven Datenbankbefehle
- keine produktiven E-Mails oder Benachrichtigungen senden
- keine Drive-Dateien verschieben, umbenennen oder löschen
- keine Zugangsdaten, Tokens oder persönliche Daten in Berichte schreiben
- keine bestehenden Uploads oder Backups löschen
- keine automatische Schemaänderung durch bloßes Aufrufen einer Seite
- zuerst feststellen, welche lokale Version dem produktiven Stand entspricht
- jede angenommene Aussage mit Datei, Funktion, Tabelle oder reproduzierbarer Beobachtung belegen
- Unsicherheiten ausdrücklich kennzeichnen

Beende die erste Arbeitsphase nach den Analyse-Dokumenten und dem Umsetzungsplan. Gib dann eine kurze Zusammenfassung der wichtigsten Befunde und frage nach Freigabe für genau Phase 0.
