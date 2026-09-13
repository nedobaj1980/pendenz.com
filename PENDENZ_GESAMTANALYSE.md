# pendenz.com – Gesamtanalyse und Zielbild

Stand: 10. September 2026

## 1. Ausgangslage

pendenz.com ist bereits deutlich mehr als eine Aufgabenliste. Die Anwendung verbindet heute Bau- und Mängelmanagement, Projekt- und Objektstrukturen, Wohnungen, Mieter, Firmen, Kontakte, Pläne, Dokumente, Termine, Protokolle, Kontoauszüge und einen KI-Assistenten.

Die beabsichtigte Weiterentwicklung ist sinnvoll:

1. Bau- und Pendenzenmanagement für Bauherrschaft und Bauleitung.
2. Liegenschaftsverwaltung für mehrere Eigentümer.
3. Liegenschaftsbuchhaltung mit Rechnungen, Bankbewegungen und Mietkontrolle.
4. Automatische Benachrichtigungen für überfällige Mieten, Rechnungen, Verträge und Pendenzen.
5. Zentrale Dokumentablage in Google Drive, die mit der Website synchron bleibt.

Die wichtigste Voraussetzung ist eine saubere fachliche und technische Grundlage. Im jetzigen Stand sind viele Funktionen vorhanden, aber Datenmodell, Ordnerlogik und Quellcode sind historisch gewachsen und teilweise widersprüchlich.

## 2. Sichtbarer Ist-Zustand

Im Superadmin-Dashboard waren bei der Analyse sichtbar:

- 18 Benutzerkonten
- 12 Projekte bzw. Portfolio-Einträge
- 46 Pendenzen
- Rollen: `gast`, `benutzer`, `admin`, `superadmin`
- getrennte Bereiche für Helvetic Immo AG, Nedim Privat und Nedjip Privat
- Projekte, Objekte, Wohnungen und Räume
- Pendenzen mit Status, Priorität, Zuständigkeit, Start, Ende, Bildern, Dokumenten, Planmarkierungen, öffentlichem Link und PDF-Bericht
- Terminprogramm mit Gantt-Ansicht und Vorgänger-Beziehungen
- Mieter- und Wohnungsspiegel mit Sollmieten, Nebenkosten, Mietverhältnissen und Vermietungsstatus
- Firmen, Personen, BKP-Zuordnung und Mietinteressenten
- Abnahmen, Protokolle und PDF-Designer
- Kontoauszug, CSV-Import und manuelle Buchungen
- interne Benachrichtigungsansicht
- gimi / KI-Kommandozentrale mit gespeichertem Kontext

## 3. Technischer Ist-Zustand

Das lokale Projekt befindet sich unter `C:\xampp\htdocs\pendenz.com`.

### Technologie

- PHP-Anwendung mit MySQL/MariaDB und überwiegend MySQLi
- klassisch aufgebaute PHP-Seiten ohne durchgängiges Framework
- JavaScript und CSS direkt in `assets/` sowie teilweise in großen PHP-Dateien
- Dompdf als einzige Composer-Abhängigkeit
- Session-Login, CSRF-Helfer, Projektmitgliedschaften und Pendenz-ACL vorhanden
- E-Mail-Versand und Einladungsablauf vorhanden
- Service Worker und Sync-Center vorhanden
- ein modularer Umbau unter `app/` wurde begonnen, ist aber nur teilweise umgesetzt

### Umfang und Struktur

Der Bestand enthält ungefähr:

- 485 PHP-Dateien
- 248 SQL-Dateien
- 430 Dateien allein unter `pages/`
- 74 API-Dateien
- 47 Include-Dateien
- 311 Upload-Dateien
- zahlreiche Kopien, Sicherungen, Debug-, Test-, Reparatur- und Migrationsdateien im Anwendungsverzeichnis

Wichtige Bereiche:

```text
pendenz.com/
├── app/                 begonnene modulare Zielstruktur
├── api/                 JSON- und AJAX-Endpunkte
├── assets/              CSS, JavaScript, Bilder
├── database/            Basisschema
├── docs/                kurze Architektur-Notizen
├── includes/            Auth, Rechte, Dateisystem, Mail, Navigation
├── pages/               Hauptteil der Anwendung
├── sql/                 viele einzelne Schemaänderungen
├── tools/               Konto-Verwaltung und Mietkontrolle
├── uploads/             hochgeladene Medien und PDFs
├── vendor/              Dompdf
├── index_*.php          Rollen-Dashboards
└── config.php           Konfiguration und Datenbankverbindung
```

Die dokumentierte Zielstruktur unter `app/core` und `app/modules` ist noch nicht Realität. Der produktive Code liegt weiterhin überwiegend in großen Dateien unter `pages/`, `includes/` und `tools/`.

## 4. Fachliches Datenmodell heute

Die Anwendung kennt bereits viele richtige Bausteine:

```text
Benutzer/Firma
    │
    ├── Projektmitgliedschaften und Rollen
    │
Projekt
    └── Objekt/Gebäude
          └── Wohnung/Einheit
                ├── Räume
                ├── Mieter und Mietverhältnisse
                ├── Dokumente/Bilder
                └── Pendenzen

Pendenz
    ├── Zuständiger Benutzer/Unternehmer
    ├── BKP und Kategorie
    ├── Status und Termine
    ├── Bilder/Dokumente
    ├── Planmarkierungen
    ├── Rückmeldungen/Abnahme
    └── PDF/öffentlicher Link
```

Das Hauptproblem ist, dass `projekte` mehrere Bedeutungen trägt. Normale Bauvorhaben und Eigentümer-Portfolios wie „101_Helvetic Immo AG“, „102_Nedim Privat“ und „103_Nedjip Privat“ stehen auf derselben Ebene. Das erschwert Rechte, Berichte, Buchhaltung und Ablage.

## 5. Ordner- und Google-Drive-System

### Vorhandene Logik

Jedes Projekt besitzt aktuell einen absoluten `root_path`. Die Anwendung liest und verändert Ordner direkt im Dateisystem. Der Code erzeugt für Projekte bzw. Objekte unter anderem:

```text
01_Rechnungen/
  01_Januar/Bezahlt/
  02_Februar/Bezahlt/
  ...
  12_Dezember/Bezahlt/
02_Handwerker_Unterhalt/
03_Versicherungen/
04_Steuern/
05_Allgemein/
06_Bank_Liegenschaftskonto/
07_Mietverträge/
08_Mahnungen/
09_Korrespondenz/
10_Mietsache/
  <Wohnung>/
    01_Mieter/
    02_Bilder/
    03_Dokumente/
    04_Vertraege/
    05_Abnahmen/
```

Die Synchronisation versucht:

1. fehlende Ordner aus der Datenbank anzulegen,
2. neue Wohnungsordner in die Datenbank zu importieren,
3. Namen zwischen Datenbank und Ordnern anzugleichen,
4. optional Datenbank-Wohnungen zu löschen, wenn ihr Ordner fehlt.

### Kritischer Architekturpunkt

Die bestehende „Google Drive“-Anbindung ist keine direkte Google-Drive-API. Sie behandelt Google Drive Desktop als lokales Laufwerk und enthält in mehreren Skripten einen fest eingetragenen Windows-Pfad:

`C:/Users/Nedim/Google Drive-Streaming/Meine Ablage/Helvetic Immo Treuhand`

Das kann auf einem lokalen Windows-PC funktionieren. Die produktive Website läuft jedoch auf einem Linux-Webserver unter `/home/.../public_html`. Dieser Server kann den Windows-Drive-Pfad nicht direkt sehen. Darum ist eine dauerhaft zuverlässige Synchronisation so nicht möglich.

Beim Test waren 27 Drive-Fehler für Einheiten sichtbar. Der konfigurierte lokale Drive-Ordner war auf dem geprüften Rechner ebenfalls nicht vorhanden.

### Erforderliche Zielarchitektur

Für die produktive Website sollte Google Drive über die offizielle Drive API angebunden werden:

- OAuth-Verbindung oder Google-Workspace-Servicekonto
- vorzugsweise Shared Drive für geschäftliche Dokumente
- Speicherung von `drive_file_id` und `drive_folder_id` statt absoluter Windows-Pfade
- Datenbank als führende Quelle für Objekte, Status, Zuordnung und Rechte
- Google Drive als führender Speicher für Dateiinhalte
- Uploads auf der Website werden über eine Warteschlange nach Drive übertragen
- Änderungen in Drive werden über Changes API/Webhook und zusätzlich periodisch eingelesen
- idempotente Jobs, Wiederholungen, Fehlerstatus und Sync-Protokoll
- Prüfsumme, `modifiedTime`, Versionsstand und Konfliktbehandlung
- Soft-Delete und Papierkorb; kein automatisches Löschen von Wohnungen nur wegen eines kurzzeitig fehlenden Drive-Ordners

### Empfohlene fachliche Drive-Struktur

```text
pendenz.com/
├── 01_Helvetic_Immo_AG/
│   └── Liegenschaften/
├── 02_Nedim_Privat/
│   └── Liegenschaften/
└── 03_Nedjip_Privat/
    └── Liegenschaften/
```

Pro Liegenschaft:

```text
<Liegenschaft>/
├── 00_Stammdaten/
├── 01_Finanzen/
│   ├── Eingangsrechnungen/<Jahr>/<Monat>/
│   ├── Bank/<Jahr>/
│   ├── Budgets/
│   └── Abschluesse/
├── 02_Mietwesen/
│   └── <Einheit>/
│       ├── 01_Mieter/
│       ├── 02_Vertraege/
│       ├── 03_Uebergaben_Abnahmen/
│       ├── 04_Korrespondenz/
│       └── 05_Bilder_Dokumente/
├── 03_Unterhalt_Handwerker/
├── 04_Versicherungen/
├── 05_Steuern/
├── 06_Plaene_Grundrisse/
├── 07_Bauprojekte/
├── 08_Korrespondenz/
└── 99_Archiv/
```

Der Zahlungsstatus „offen/bezahlt“ sollte in der Datenbank geführt werden. Ein Verschieben in einen `Bezahlt`-Ordner kann optional gespiegelt werden, darf aber nicht die einzige Statusquelle sein.

## 6. Buchhaltung und Mietkontrolle

### Bereits vorhanden

- Tabelle und Oberfläche für `liegenschafts_konto`
- CSV-Import von Bankbewegungen
- Filter nach Datum, Betrag, Text und Projekt
- manuelle Zuordnung zu Projekt/Liegenschaft und Wohnung
- Kategorien und Zahlungsart
- Mietverhältnisse mit Grundmiete, Nebenkosten, Rabatt und Zeitraum
- monatliche Soll-/Ist-Berechnung je Wohnung
- monatliche Overrides
- einfache Finanzübersicht mit Soll/Haben
- Mietzinsentwicklung auf der Wohnung

### Noch nicht ausreichend

Die bestehende Lösung ist eine Konto- und Mietkontrolle, aber noch keine zusammenhängende Liegenschaftsbuchhaltung. Es fehlen insbesondere:

- Eigentümer/Mandanten als eigene Entität
- saubere Trennung von Bankkonto, Bankbewegung, Rechnung, Zahlung und Beleg
- Lieferantenrechnungen mit Rechnungsnummer, Fälligkeit, Status und Teilzahlungen
- automatische Bankabstimmung und Dublettenprüfung
- Debitorenlauf für monatliche Mieten
- Mahnstufen, Karenztage und Mahnhistorie
- Budget/Ist, Kosten nach Liegenschaft, Objekt, Einheit, BKP und Periode
- periodengerechte Auswertungen und nachvollziehbare Korrekturen
- durchgängige Belegverknüpfung zu Google Drive
- Export für Treuhand bzw. Buchhaltungssoftware
- konsistentes Audit-Protokoll

### Empfohlenes Zielmodell

```text
Eigentümer/Mandant
├── Liegenschaften
│   ├── Gebäude/Objekte
│   │   └── Einheiten/Wohnungen
│   ├── Bankkonten
│   │   └── Bankbewegungen
│   ├── Eingangsrechnungen
│   │   └── Zahlungen und Belege
│   └── Budgets/Kostenstellen
└── Auswertungen

Mietvertrag
├── monatliche Sollstellungen
├── Zahlungseingänge
├── Zuordnungen/Teilzahlungen
└── Mahnfall mit Stufen und Historie
```

Kernstatus für Rechnungen: `entwurf`, `geprüft`, `freigegeben`, `teilbezahlt`, `bezahlt`, `überfällig`, `storniert`.

## 7. Automatische Benachrichtigungen

Eine Benachrichtigungsanzeige, Tabellen für Benachrichtigungen und Statusmeldungen aus dem Pendenz-Workflow sind vorhanden. Es wurde jedoch kein durchgängiger Scheduler gefunden, der täglich überfällige Mieten, Rechnungen oder Pendenzen prüft und zuverlässig Nachrichten versendet. Außerdem verwendet der Bestand zwei unterschiedliche Tabellen: `notifications` und `user_notifications`.

Benötigt wird eine zentrale Regel- und Versandlogik:

- Ereignisse: Pendenz zugewiesen, Status geändert, Rückmeldung eingereicht
- Zeitregeln: Pendenz bald fällig/überfällig, Rechnung bald fällig/überfällig
- Mietregeln: Miete nach Fälligkeit und Karenz nicht vollständig bezahlt
- Vertragsregeln: Mietvertrag, Garantie oder Versicherung läuft aus
- Kanäle: In-App und E-Mail; SMS/WhatsApp erst später und optional
- Vorlagen pro Eigentümer und Ereignis
- Empfänger und Eskalationskette
- Ruhezeiten, Frequenzbegrenzung und Zusammenfassung
- Versandwarteschlange, Wiederholungen, Fehlerprotokoll und Zustellhistorie
- jede automatisch erzeugte Mahnung zunächst als Entwurf, bis der Ablauf fachlich freigegeben wurde

Beispiel Mietregel:

```text
Am 5. Kalendertag des Monats:
Sollstellungen des Monats prüfen
→ Zahlung vollständig: keine Aktion
→ Restbetrag > 0: interne Warnung
→ nach weiteren 5 Tagen: Mahnungsentwurf erzeugen
→ nach Freigabe: E-Mail senden und PDF/Beleg in Drive ablegen
```

## 8. Auffälligkeiten und Risiken

### Sofort zu beheben

1. `pages/project_storage.php` bricht produktiv mit `Call to undefined function require_login()` ab. Die Seite bindet die Authentifizierung nicht korrekt ein.
2. `pages/ordner_vorlagen.php` bricht mit `Unknown column 'name' in 'order clause'` ab. Code und Datenbankschema sind nicht synchron.
3. `protokoll_manager.php` und `audit.php` lieferten bei der Browserprüfung keine nutzbare Oberfläche.
4. PHP-Fehler und interne Serverpfade werden im Browser angezeigt. `display_errors` darf produktiv nicht aktiv sein.
5. Die Drive-Skripte enthalten einen fest codierten Windows-Pfad und sind auf dem Webserver nicht portabel.
6. Die optionale Sync-Funktion kann Wohnungen aus der Datenbank löschen, wenn Ordner fehlen. Das ist bei Cloud-Verbindungsfehlern gefährlich.

### Strukturelle Risiken

- sehr viele Kopien, Sicherungen, Debug- und Reparaturdateien liegen im Webprojekt
- das Webroot ist nicht sauber auf `public/` begrenzt
- `.htaccess` deaktiviert Verzeichnislisten, sperrt aber sensible PHP-, SQL-, Log-, Konfigurations- und Wartungsdateien nicht systematisch
- Rollen- und Projektprüfungen existieren, werden aber nicht durchgehend auf allen Seiten und Endpunkten erzwungen
- mehrere konkurrierende Tabellen und Statusmodelle, zum Beispiel `notifications`/`user_notifications` und verschiedene Pendenzstatus
- Basisschema, Migrationen und produktive Datenbank sind auseinandergewachsen
- die einzige Composer-Bibliothek ist Dompdf; eine offizielle Google-API-Bibliothek und ein Job-System fehlen
- große PHP-Dateien mit UI, SQL und Geschäftslogik in einer Datei erschweren sichere Änderungen
- Konfigurationen, Testdateien und einmalige Migrationen liegen teilweise im erreichbaren Projektbaum

## 9. Empfohlene Reihenfolge

### Phase 0 – Inventur und Absicherung

- vollständiges Read-only-Audit von Code, Datenbank und produktiver Konfiguration
- Backup von Datenbank und Dateien prüfen
- produktive Fehleranzeige abschalten, sichere Logs einrichten
- öffentliche Angriffsfläche und ungeschützte Endpunkte schließen
- defekte Seiten reparieren
- kanonisches Datenbankschema und Migrationssystem festlegen
- automatisierte Smoke-Tests für Login, Rollen und Kernseiten

### Phase 1 – Fachliches Fundament

- Eigentümer/Mandant, Liegenschaft, Gebäude, Einheit und Bauprojekt trennen
- vorhandene Projekte 101/102/103 kontrolliert migrieren
- Rechte je Mandant, Liegenschaft, Projekt und Einheit definieren
- gemeinsame Dokument- und Audit-Verknüpfung einführen

### Phase 2 – Google Drive

- Google-Verbindung und Ziel-Drive festlegen
- Drive-IDs und Sync-Tabellen einführen
- sichere Einweg-Migration der vorhandenen Ordner
- Website → Drive und Drive → Website implementieren
- Konflikte, Wiederholungen und Sync-Dashboard ergänzen

### Phase 3 – Buchhaltung

- Rechnungen, Belege, Zahlungen, Bankkonten und Kostenstellen einführen
- Bankimport robust und wiederholbar machen
- automatische Zuordnung mit manueller Prüfung
- Miet-Sollstellungen, Zahlungsausgleich und offene Posten aufbauen
- Dashboards und Exporte ergänzen

### Phase 4 – Automatisierung

- zentrale Ereignis- und Regelengine
- Scheduler/Cronjob und Versandwarteschlange
- Benachrichtigungs- und Mahnvorlagen
- Eskalationen und Zustellprotokoll

### Phase 5 – Ausbau des Bauleitersystems

- Pendenz-Workflow vereinheitlichen
- Protokolle, Abnahmen und Berichte stabilisieren
- Terminabhängigkeiten und mobile Baustellennutzung optimieren
- gimi nur über klar definierte, protokollierte Aktionen auf Daten zugreifen lassen

## 10. Abnahmekriterien für das Zielsystem

- Helvetic Immo AG, Nedim Privat und Nedjip Privat sind fachlich und in den Rechten sauber getrennt.
- Jede Liegenschaft besitzt Gebäude, Einheiten, Mietverträge, Bankkonten, Rechnungen und Pendenzen.
- Jede Datei besitzt eine stabile Drive-ID und eine eindeutige fachliche Zuordnung.
- Uploads über die Website erscheinen zuverlässig in Drive; Drive-Änderungen erscheinen innerhalb eines definierten Zeitfensters auf der Website.
- Wiederholte Synchronisation erzeugt keine Dubletten.
- Eine unterbrochene Drive-Verbindung löscht keine fachlichen Daten.
- Jede Rechnung zeigt Beleg, Liegenschaft, Lieferant, Fälligkeit, Zahlungen und Restbetrag.
- Jede Wohnung zeigt monatlich Soll, Ist, Differenz und Mahnstatus.
- Überfällige Mieten und Pendenzen erzeugen genau eine nachvollziehbare Benachrichtigung pro Regelstufe.
- Alle automatischen und manuellen Änderungen sind im Audit-Protokoll nachvollziehbar.
- Superadmin, Admin, Eigentümer/Verwalter, Bauleiter, Unternehmer, Mieter und Gast sehen nur die vorgesehenen Daten und Aktionen.

## 11. Schlussfolgerung

Die bestehende Anwendung enthält bereits einen wertvollen Funktionskern. Ein kompletter Neubau ist nicht automatisch nötig. Vor weiteren Einzel­funktionen sollte der Bestand jedoch stabilisiert und in ein klares fachliches Modell überführt werden. Besonders die Drive-Anbindung darf nicht weiter auf lokalen Windows-Pfaden beruhen. Danach können Buchhaltung und automatische Benachrichtigungen kontrolliert auf dem vorhandenen Pendenz-, Objekt- und Mietersystem aufgebaut werden.
