---
name: voice-pendenz
description: >-
  Procedures for processing voice-dictated or conversational tasks and deficiencies (Pendenzen) in pendenz.com. Use when the user dictates or says "Pendenz per Voice", "Erfasse Pendenz", or describes a task spoken via microphone.
---

# Voice-Pendenzen Erfassung (Gimi Voice)

Dieses Runbook beschreibt, wie gesprochene oder diktierte Pendenzen analysiert und in der Datenbank erfasst werden.

## 1. Erkennung der Entitäten
Wenn der Benutzer eine Aufgabe diktiert (z.B. *«In Romanshorn Wohnung 2 im Bad tropft der Wasserhahn dringend bis Freitag»*):
1. **Projekt**: Aus Namen ableiten (z.B. «Romanshorn» ➔ `projekte.name LIKE '%Romanshorn%'`).
2. **Objekt & Wohnung**: Aus «Wohnung X» oder «Whg X» ableiten und mit `wohnungen.name` oder `wohnungen.id` abgleichen.
3. **Raum**: Raumname («Bad», «Küche», «Schlafzimmer») mit `raeume` der gematchten Wohnung abgleichen.
4. **Wichtigkeit**:
   * *Notfall / Sofort / Wasserschaden* ➔ 5
   * *Dringend / Eilig / Hoch* ➔ 4
   * *Normal / Standard* ➔ 3
   * *Niedrig / Gering* ➔ 2
5. **Frist**:
   * *Freitag / Montag / etc.* ➔ Datum des entsprechenden nächsten Wochentags.
   * *Ende Woche* ➔ Sonntag der aktuellen Woche.
   * *Ende Monat* ➔ Letzter Kalendertag des aktuellen Monats.
6. **Vorgangsart**:
   * Standardmäßig «Mangel» oder «Reparatur» (`pendenzen_arten`).

## 2. API-Schnittstelle
Pendenzen können direkt über `api/voice_pendenz.php` verarbeitet werden:
* `action=parse`: Liefert das strukturierte JSON mit allen erkannten Entitäten.
* `action=save`: Schreibt die Pendenz direkt in die Tabelle `pendenzen`.
