┌─────────────────────────────┐
│         Projekt             │  <- feste Einheit, Ausgangspunkt
│  (ID, Name, Status, Bild)   │
└─────────────┬───────────────┘
              │
              ▼
┌─────────────────────────────┐
│     Vorlage / Template      │  <- vordefinierte oder eigene Struktur
│  (ID, Name, MaxDepth, User)│
└─────────────┬───────────────┘
              │
              ▼
┌─────────────────────────────┐
│      Unterkategorie         │  <- rekursiv, max 10 Ebenen
│ (ID, ParentID, Label, Var) │
└───────┬───────────────┬─────┘
        │               │
        ▼               ▼
┌──────────────┐   ┌──────────────┐
│ Teilnehmer / │   │ Unterkategorie│  <- nächste Ebene / Child
│ Rollen       │   │ (rekursiv)    │
│ (ID, Rolle,  │   └──────────────┘
│ UserID)      │
└──────────────┘
        │
        ▼
┌─────────────────────────────┐
│ Favoriten                   │  <- pro User / Team / Gemeinschaft
│ (UnterkategorieID, aktiv)   │
└─────────────┬───────────────┘
              │
              ▼
┌─────────────────────────────┐
│ Pendenzen / Mängelliste     │
│ (UnterkategorieID,          │
│ TeilnehmerID, Status, Text) │
└─────────────────────────────┘


Erklärung der Bausteine

Projekt

Ausgangspunkt für jede Struktur

Feste Einheit, die Unterkategorien, Teilnehmer und Pendenzen organisiert

Vorlage / Template

Kann global oder benutzerspezifisch sein

Definiert die Hierarchie der Unterkategorien

Inspiration / Beispielvorlagen möglich

Unterkategorien

Rekursive Struktur (max. 10 Ebenen)

Variable Labels und interne Variablen

Modular: einzelne Unterkategorien können verschoben oder kopiert werden

Teilnehmer / Rollen

Direkt einer Unterkategorie zugeordnet

Rollen flexibel (z. B. Mieter, Käufer, Mechaniker)

Favoriten

Filtert die Unterkategorien in Dashboards oder Pendenzenlisten

Kann für User, Team oder Gemeinschaft gesetzt werden

Pendenzen / Mängelliste

Dynamisch gefiltert nach Favoriten

Direkt auf Unterkategorie + Teilnehmer bezogen

Anzeige kompakt und übersichtlich

Vorteile dieser Struktur

Modular: Jeder Baustein kann unabhängig bearbeitet oder kopiert werden

Flexibel: Favoriten ermöglichen dynamische Ansichten

Wiederverwendbar: Vorlagen und Unterkategorien sind branchenübergreifend nutzbar

Effizient: Pendenzenliste zeigt nur relevante Elemente

Erweiterbar: Neue Ebenen, Rollen oder Vorlagen können jederzeit hinzugefügt werden

💡 Nächster Schritt:

Wenn du willst, können wir daraus eine konkrete Admin-UI-Skizze machen, mit:

TreeView für Unterkategorien

Favoriten-Sternchen

Dropdowns / Filter für User, Team, Gemeinschaft

Buttons für Kopieren / Einfügen / Teilen von Unterkategorien

Damit hätten wir eine praxisnahe Visualisierung der Bedienoberfläche, die das Puzzle komplett abbildet.

Willst du, dass ich das jetzt erstelle?



*//ja bitte erklär mir nur noch wie wir 
vorgehen werden, welche dateien wir anlegen 
werden, wie sie heissen und was sie machen


Perfekt, dann machen wir eine übersichtliche Roadmap, bevor wir anfangen zu coden. Ich erkläre Schritt für Schritt, welche Dateien wir brauchen, wofür sie da sind und wie sie zusammenarbeiten. So behalten wir den Überblick.

1️⃣ Admin-Bereich: Vorlagen & Unterkategorien
Datei	Zweck / Inhalt
vorlagen.php	Übersichtsseite: Liste aller Vorlagen (eigene + Beispielvorlagen). Optionen: Bearbeiten, Kopieren, Löschen, Teilen.
vorlage_bearbeiten.php	Vorlage editieren: TreeView der Unterkategorien, Drag & Drop, Unterkategorie hinzufügen, verschieben, löschen.
unterkategorie_bearbeiten.php	Optional einzelne Unterkategorie bearbeiten, z. B. Label ändern, Position oder Parent ändern, Rollen zuweisen.
teilnehmer_zuteilen.php	Teilnehmer / Rollen zuweisen: Auswahl der Benutzer/Teams/Gemeinschaften pro Unterkategorie.
vorlage_teilen.php	Vorlage freigeben / empfehlen: Titel + Beschreibung eingeben, freigegebene Struktur für andere sichtbar machen, nur Labels/Struktur, keine persönlichen Daten.
2️⃣ Pendenzen / Mängel
Datei	Zweck / Inhalt
pendenzen.php	Hauptliste: Zeigt Pendenzen/Mängel. Filter nach Favoriten, Unterkategorie, Teilnehmer, Status.
pendenz_neu.php	Neue Pendenz erstellen: Dropdown/TreeView für Unterkategorie + Teilnehmer, Text, Status.
favoriten_verwalten.php	Favoriten pro Benutzer / Team / Gemeinschaft festlegen. Sternchen / Checkbox neben Unterkategorien im TreeView.
3️⃣ UI / Komponenten
Datei	Zweck / Inhalt
treeview.js	Drag & Drop, Auf-/Zuklappen der Unterkategorien, dynamische Anzeige.
favoriten.js	Sternchen klicken → Favorit speichern / entfernen, Filter-Logik auf Dashboard oder Pendenzenliste.
vorlage_export.js	Export der Struktur (JSON), Import in andere Projekte möglich.
4️⃣ Datenbank / Backend
Datei	Zweck / Inhalt
struktur_vorlagen.sql	Tabellen: struktur_vorlagen, unterkategorien, teilnehmer, favoriten, projekt_vorlagen.
struktur_functions.php	PHP-Funktionen: Unterkategorien laden, TreeView bauen, Favoriten speichern, Teilnehmer zuweisen.
struktur_api.php	Ajax-Endpunkte: Favorit setzen, Unterkategorie verschieben, Teilnehmer zuweisen, Vorlage teilen.
5️⃣ Ablauf / Zusammenspiel

Vorlagen erstellen / bearbeiten (vorlagen.php + vorlage_bearbeiten.php)

Nutzer erstellt Unterkategorien, weist Rollen zu, speichert Vorlage.

Favoriten setzen (favoriten_verwalten.php)

Nutzer wählt relevante Unterkategorien → Filter in Pendenzenliste.

Pendenzen erstellen / verwalten (pendenzen.php + pendenz_neu.php)

Auswahl Unterkategorie + Teilnehmer erfolgt direkt

Filter nach Favoriten reduziert die Klickpfade

Vorlage teilen / inspirieren (vorlage_teilen.php)

Andere Nutzer können Struktur kopieren → eigene Vorlage erstellen

TreeView & UI-Logik (treeview.js, favoriten.js)

Drag & Drop, Auf-/Zuklappen, Filter, Favoriten-Interaktion

💡 Vorteil dieser Aufteilung:

Klar definierte Verantwortlichkeiten: Jede Datei hat eine Aufgabe

Skalierbar & erweiterbar: Neue Features oder Branchen können einfach integriert werden

UI & Backend sauber getrennt: TreeView, Favoriten, Export / Import, Ajax-Endpunkte

Wenn du willst, können wir als nächstes die Dateien Schritt für Schritt aufbauen – beginnend mit der Vorlagen-Übersicht (vorlagen.php) und der TreeView-Logik.

Willst du, dass wir damit starten?