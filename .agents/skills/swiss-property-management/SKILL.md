---
name: swiss-property-management
description: >-
  Standard operating procedures for managing Swiss real estate in pendenz.com, including tenant turnover, rent adjustments, lease contract generation, handover protocols, and Google Drive archiving. Use when working on tenant rolls, leases, turnovers, or property files.
---

# Swiss Property Management Runbook (pendenz.com)

Dieses Runbook beschreibt die genauen Schritte und Workflows für die Liegenschaftsbewirtschaftung nach Schweizer Standard im System.

## 1. Mieterwechsel (Tenant Turnover)
1. **Bestehenden Mieter archivieren**:
   * Den Status in `wohnung_mieter` von `aktiv` auf `ausgezogen` setzen.
   * `enddatum` auf das tatsächliche Auszugsdatum setzen.
2. **Neuen Mieter anlegen**:
   * Neuen Datensatz in `wohnung_mieter` anlegen mit `status = 'aktiv'`.
   * `startdatum`, `mietzins_netto`, `nk_akonto` hinterlegen.
   * Den Mietpreis als Ersteintrag in `wohnung_mietzins_historie` festhalten.
3. **Wohnungsordner auf Google Drive vorbereiten**:
   * Ordner `10_Mietsache/<Einheit>/` mit den Unterordnern `01_Mieter`, `02_Bilder`, `03_Dokumente`, `04_Vertraege`, `05_Abnahmen` sicherstellen.
   * `fs_scan_project($mysqli, $projectId)` aufrufen, um die Ordner in `fs_nodes` zu indexieren.

## 2. Mietzinsanpassung (Rent Adjustment)
1. Wenn der Netto-Mietzins oder das NK-Akonto angepasst wird:
   * Die Spalten `mietzins_netto` und `nk_akonto` in `wohnung_mieter` aktualisieren.
   * Einen Eintrag in `wohnung_mietzins_historie` schreiben mit `gueltig_ab` und Begründung (z.B. Referenzzinssatz, Teuerung, Wertvermehrende Investitionen).
2. Den Mieterspiegel aktualisieren und bei Bedarf direkt auf Google Drive sichern (`Mieterspiegel_<Projekt>_<Datum>.csv`).

## 3. Mietvertrag & Abnahmeprotokoll
1. **Mietvertrag (`pages/vertrag_gen.php` & `vertrag_save.php`)**:
   * Daten aus `wohnung_mieter` und `wohnungen` vorbelegen.
   * PDF generieren und in `10_Mietsache/<Einheit>/04_Vertraege/` ablegen.
2. **Wohnungsabnahme (`pages/wohnungsabnahme_protokoll.php`)**:
   * Zustand aller 215 Raumbereiche prüfen.
   * Mängel markieren: Werden automatisch als Tasks in `pendenzen` übernommen.
   * Protokoll signieren und unter `10_Mietsache/<Einheit>/05_Abnahmen/` archivieren.

## 4. Liegenschaftsabrechnung & Steueroptimierung
1. Bankzahlungen über `import.php` via IBAN der Liegenschaft zuordnen.
2. Im Jahresabschluss (`tools/liegenschaftsabrechnung/index.php`):
   * Effektiven Unterhalt gegen den Pauschalabzug (10% $\le 10$ Jahre, 20% $> 10$ Jahre) prüfen.
   * Die günstigere Variante für Steueramt & Eigentümer ausweisen.
   * Abrechnung per Klick direkt auf Drive archivieren.
