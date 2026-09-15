# Nebenkostenabrechnung und Eigentümer-Steuerübersicht

## Ziel

Ein lokales Modul `tools/nebenkostenabrechnung/` erstellt jährliche Schweizer Nebenkostenabrechnungen für Mietverhältnisse und stellt parallel eine getrennte Eigentümer-/Steuerübersicht bereit. Beide Auswertungen verwenden dieselben Buchungen aus `liegenschafts_konto`, dürfen aber Umlagefähigkeit und steuerliche Abzugsfähigkeit nicht vermischen.

## Fachliche Leitplanken

- Mieterseitig werden nur vertraglich vereinbarte und tatsächlich angefallene Betriebskosten berücksichtigt. Kosten für Verwaltung, Finanzierung, wertvermehrende Investitionen und allgemeine Instandsetzung werden als nicht umlagefähig markiert.
- Die Abrechnung arbeitet periodengenau und berücksichtigt Akontozahlungen, Ein-/Auszüge sowie Leerstand. Jeder Betrag bleibt bis zum Beleg und zur Originalbuchung rückverfolgbar.
- Verteilerschlüssel sind pro Kostenart konfigurierbar: Wohnfläche, Anzahl Einheiten, Personen, Verbrauch oder direktes Einzelobjekt.
- Eigentümerseitig werden Unterhalt, wertvermehrende Investitionen, Verwaltung, Finanzierung und private/nicht abzugsfähige Positionen getrennt ausgewiesen. Pauschal- und Effektivmethode werden nebeneinander berechnet; kantonale Regeln und Privat-/Geschäftsvermögen bleiben konfigurierbar.
- Das UI zeigt einen klaren Hinweis, dass steuerliche Abzüge kantonal und fallbezogen geprüft werden müssen. Die ESTV-Tabelle weist unterschiedliche Pauschalsätze und Einschränkungen je Kanton aus.

## Nutzerfluss

1. Liegenschaft und Abrechnungsjahr wählen.
2. Buchungen laden und Kostenarten prüfen oder korrigieren.
3. Umlagefähige Kosten und Verteilerschlüssel bestätigen.
4. Akonto- und Mietverhältnis-Daten aus `wohnung_mieter` übernehmen.
5. Mieterabrechnungen je Einheit berechnen und als PDF/CSV ausgeben.
6. Eigentümer-/Steuerübersicht mit Effektiv-/Pauschalvergleich erzeugen.
7. Ergebnis optional im bestehenden Liegenschaftsordner archivieren; keine automatische Verbuchung in produktive Konten.

## Datenmodell

Neue Tabellen werden per Migration ergänzt:

- `nk_kostenarten`: Bezeichnung, Kategorie, umlagefähig, steuerlich abzugsfähig, Standard-Verteilerschlüssel, aktiv.
- `nk_abrechnungen`: Projekt, Jahr, Status, Zeitraum, Erstellungs- und Freigabedaten.
- `nk_positionen`: Abrechnung, Originalbuchung, Kostenart, Betrag, Umlageanteil, Steuerklassifikation, Notiz.
- `nk_verteilungen`: Abrechnung, Wohnung/Mietverhältnis, Anteil, Akonto, Ergebnis.
- `nk_regeln`: projekt- oder kostenartspezifische Verteilerschlüssel und kantonale Steuereinstellungen.

## Ausgaben

- Druckoptimierte HTML/PDF-Abrechnung pro Mieter mit Kostenaufstellung, Schlüssel, Akonto, Saldo und Belegperiode.
- Eigentümer-/Steuerbericht mit Kategorien, Belegsumme, effektivem Abzug, Pauschalvergleich und Warnhinweisen.
- CSV-Export für Detailprüfung und Weiterverarbeitung.

## Fehler- und Sicherheitsverhalten

- Fehlende Mietfläche, Akonto- oder Periodendaten werden als blockierende Prüfwarnung angezeigt; es wird keine stille Schätzung vorgenommen.
- Originalbuchungen werden nicht verändert. Korrekturen erfolgen als Abrechnungsposition oder Regel.
- Exporte und Drive-Archivierung erfolgen nur nach explizitem Klick.

## Testumfang

- Kostenarten- und Umlageregeln mit bekannten Beispielwerten.
- Ein-/Auszug und Leerstand innerhalb einer Periode.
- Akonto-Guthaben und Nachzahlung.
- Eigentümer-Effektiv-/Pauschalvergleich mit kantonaler Einstellung.
- PDF/CSV-Erzeugung sowie lokale Browserprüfung der Hauptansichten.
