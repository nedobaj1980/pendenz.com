# PROJECT_ANALYSIS – technischer Ist-Zustand des internen Adminsystems

Stand: 11.09.2026 · Projekt: `C:\xampp\htdocs\pendenz.com`

## 1. Auftrag, Quellen und Aussagegrenzen

Diese Analyse beschreibt den vorhandenen lokalen Code und die lokale Datenbank, mit Schwerpunkt auf dem eingeloggten Bereich. Sie ist keine Umsetzung, Migration oder Freigabe zum produktiven Betrieb. Ausschließlich diese Datei wurde für diesen Auftrag erstellt. Bestehende Anwendungsdateien wurden nicht verändert; keine Anwendungsseite, Migration, Reparatur, Synchronisation oder Versandfunktion wurde ausgeführt.

Die zwei erwähnten Unterlagen wurden als `CODEX_MASTER_AUFTRAG.md` und `PENDENZ_GESAMTANALYSE.md` identifiziert. Sie enthalten auch Zielvorstellungen und frühere Beobachtungen; diese sind **nicht automatisch Ist-Zustand**. Zusätzlich wurden `docs/architecture.md`, `docs/module-registry.yaml` und vorhandene Artefakte unter `docs/analysis/` herangezogen. Der frühere Auftrag, mehrere Dokumente und Umsetzungspläne zu erstellen, wurde nicht ausgeführt: Maßgeblich ist der aktuelle Auftrag, nur diese Datei zu erstellen.

Prüfmethoden:

- Quelltextinventur, Suche nach SQL, Includes, Routen, Rollenprüfungen, Schreibaktionen und Querverweisen; zentrale Abläufe zusätzlich im Codekontext gelesen.
- Aktuelle Schemaabfragen über eine separate MySQLi-Verbindung mit `START TRANSACTION READ ONLY` und abschließendem `ROLLBACK`. Die Verbindungsparameter wurden aus dem Konfigurationstext gelesen; `config.php` wurde dabei **nicht ausgeführt**. Abgefragt wurden Metadaten und aggregierte Zählungen, keine persönlichen Datensätze oder Geheimnisse.
- PHP-Syntaxprüfung mit `php -n -l`, ohne Ausführung der Anwendung. Ergebnis im Verifikationsabschnitt.
- Mobile Darstellung statisch anhand von CSS, DOM und JavaScript beurteilt. Kein Browser-Login und keine visuelle Laufzeitabnahme, weil bereits GET-Aufrufe Daten und Dateien verändern können.

Begriffe: **bestätigt** bezeichnet einen aktuellen Code-/Schemabefund; **Risiko** eine daraus abgeleitete Auswirkung, deren praktische Ausnutzbarkeit nicht getestet wurde; **historisch** kennzeichnet frühere Bestandsmessungen. Ein identischer Stand des Live-Servers ist nicht nachgewiesen. Apache-VHost, AllowOverride, externe Cron-/Windows-Aufgaben, SMTP-Erreichbarkeit, Drive-Mount und produktive Datenbank wurden nicht geprüft. Die Dateiregister nennen Routenkandidaten, nicht bewiesene produktive Nutzung.

## 2. Architektur und zentrale Ergebnisse

Die Hauptanwendung ist eine klassische PHP-/MariaDB-Anwendung mit eigenständig aufrufbaren PHP-Seiten. Geschäftsfunktionen, SQL, HTML, CSS und JavaScript sind vielfach in derselben großen Datei vereint. `pages/` enthält die eigentliche Administration. Der Ordner `admin/` enthält derzeit keine Dateien und ist **nicht** der technische Einstiegspunkt des Adminsystems.

`index.php` lädt `index_public.php`. Es gibt keinen durchgängigen Frontcontroller und keine global auf jeder Route erzwungene Autorisierung. Die entsprechende Rewrite-Regel in `.htaccess` ist auskommentiert. `public/index.php` ist eine ältere alternative Startseite, kein belegtes eigenständiges Webroot.

`app/core/autoload.php`, `app/modules/pendenzen/Service.php`, `ServiceFallback.php` und `app/modules/ai/AiService.php` bilden einen begonnenen modularen Ansatz. Insbesondere die aktuelle Hauptliste `pages/pendenzen.php` baut ihre SQL-Abfrage selbst; die Existenz eines Pendenzservices bedeutet nicht, dass dessen Isolation überall greift.

Wesentliche Ergebnisse:

1. Vier globale Rollen existieren im Datenbankschema, daneben Projektrollen und mehrere fachliche Personenklassifikationen. Ein konsistentes, mandantenübergreifend durchgesetztes Berechtigungssystem ist nicht vorhanden.
2. Die Hauptliste setzt bei jedem Laden bestehende Pendenzen auf öffentlich und erlaubt externe Ansicht/Uploads. Die Detailseite kann öffentlich aktivierte Datensätze allein über ihre numerische ID anzeigen.
3. Mehrere direkte API-/Seitenaktionen ändern Benutzer, Mitgliedschaften, Pendenzen und Protokolle ohne wirksame Authentifizierung oder Objektberechtigung.
4. Schema, alte Basisschemata und aktive Schreibwege widersprechen sich. Besonders betroffen: Status, Zuständigkeit, Projektmitgliedschaften, Passwortreset und Benachrichtigungen.
5. Die Dateiverwaltung verbindet mehrere DB-Modelle mit lokalen Dateipfaden. Die als Drive bezeichneten Funktionen arbeiten mit einem lokal eingebundenen Dateisystem; ein durchgängiger Drive-API-Sync ist nicht vorhanden.
6. gimi kann aus Antworttexten echte Pendenzen anlegen. Die Chatbesitzprüfung ist vorhanden; eine entsprechende Projektberechtigungsprüfung vor der Anlage fehlt.
7. Mietwesen und Kontoverwaltung besitzen weitere eigenständige Schreibwege. Besonders relevant sind ungeschützte Mietvertragsaktionen, voneinander abweichende Mietermodelle und eine Superadminprüfung, die in der Kontoverwaltung erst **nach zwei Schreibaktionen** erfolgt. Die vertiefte Prüfung dazu steht in Abschnitt 21.

### 2.1 Laufzeit und Abhängigkeiten

| Bestandteil | Ist-Zustand / Fundstelle |
|---|---|
| PHP | MySQLi dominiert; PDO-Erwartungen in einzelnen älteren APIs passen nicht zum Bootstrap. PHP-8-Funktionen im Code. |
| Datenbank | Lokal MariaDB `10.4.32`, aktuell 138 Tabellen. |
| SQL-Modus | `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`; kein strenger Transaktionsmodus. Ungültige ENUM-Werte können deshalb zu leeren Werten werden. |
| Composer | `composer.json`: Dompdf `^3.1`; `composer.lock`: 3.1.0 und transitive PDF-/HTML-/CSS-Abhängigkeiten. Kein installierter PHPMailer im Composer-Manifest. |
| Frontend | Eigenes JS/CSS, Inline-Skripte, Frappe Gantt, html2pdf, Browserbibliotheken/CDN-Einbindungen. Kein einheitlicher SPA-Router. |
| Konfiguration | `config.php`: lokale/Online-Erkennung über Host bzw. CLI, DB-Verbindung, URL-Helfer, Session, Zeitzone `Europe/Zurich`, Fehlerlogging. CLI wird immer als lokal behandelt. |
| Betrieb | Viele absolute Windows-/localhost-Pfade, z. B. `index_admin.php`, `api/dashboard_prefs.php`, Drive-Skripte. Portabilität auf Linux nicht durchgehend gegeben. |
| Sicherheitsschichten | `includes/auth.php`, `csrf*.php`, `csp.php`, `audit.php`; Einbindung und tatsächliche Aufrufe variieren je Route. |

## 3. Login, Sessions und Startseiten

### 3.1 Tatsächliche Loginroute

Der zentrale Login ist `login.php`, nicht `pages/login.php`.

Das Eingabefeld akzeptiert E-Mail oder Telefonnummer; Telefonnummern werden zusätzlich ohne bestimmte Trennzeichen verglichen. Das aktuelle Debuglogging schreibt Session-ID und Cookies in `login_debug.log`, also potenziell Sitzungsgeheimnisse in eine Datei im Projektroot.

`login.php` lädt `config.php`, `includes/functions.php` und `includes/auth.php`. Die Anmeldung sucht in `benutzer`, prüft ein Passwort gegen `passwort_hash` bzw. `passwort` und ruft bei Erfolg `finalize_successful_login()` auf. `verify_password_flex()` akzeptiert neben Hashes auch einen direkten Klartextvergleich, wenn der gespeicherte Wert nicht als Passwort-Hash erkannt wird. Das ist ein tatsächlich vorhandener Kompatibilitätspfad, keine Empfehlung.

`includes/auth.php::finalize_successful_login()` setzt die Session, regeneriert die Session-ID, aktualisiert Loginzeitpunkte und schreibt ein `user_events`-Ereignis. Fehler beim Ereignis-/Zeitstempelschreiben werden teilweise abgefangen, damit der Login fortgesetzt wird.

| Globale Rolle | Standardziel nach Login |
|---|---|
| `superadmin` | `index_superadmin.php` |
| `admin` | `index_admin.php` |
| `benutzer` | `index_private.php` |
| `gast` / sonstige | `index_public.php` |

**Ausnahme:** Ein zuvor in `$_SESSION['redirect_after_login']` gespeichertes zulässiges GET-Ziel hat Vorrang. `require_login()` merkt solche Ziele vor; `redirect_after_login_or_default()` verbraucht den Eintrag. Der Sanitizer soll fremde absolute URLs und `/api/` ausschließen. Beim Deployment im Domainroot ist `_site_prefix()` leer; die Prüfung ist dann weniger streng als im Unterverzeichnis und insbesondere für protokollrelative Ziele gesondert zu prüfen.

Alternative/alte Wege:

- `pages/login.php` erwartet Funktionen wie `db_one()`, `login()`, `csrf_check()` und leitet zu `pages/dashboard.php`; diese Helfer werden durch seinen aktuellen Includegraph nicht vollständig bereitgestellt.
- `login_do.php` ist ein unvollständiger Platzhalter mit `$user` ohne Passwortprüfung und einem nicht bereitgestellten `login_success_redirect()`.
- `force_login.php` setzt Debug-Sessionwerte. Es verwendet teilweise andere Schlüssel (`user_role`, `user_name`) als das normale Authsystem; daher nicht pauschal als vollständiger Loginersatz zu bewerten, aber als gefährliche Testdatei im Webroot.
- `register.php`, `pages/setup.php`, `create_superadmin.php`, `pages/set_password.php`, `pages/password_reset*.php` bilden zusätzliche Konto-/Installationspfade und gehören zur Angriffsfläche.

### 3.2 Sessionvertrag

Zentrale Schlüssel sind `user_id`, `rolle`, `name`, `email`. Hinzu kommen Projekt-/UI-Zustände wie `current_project_id`, `active_profile_id`, Navigationseinstellungen und CSRF-Tokens. Die reale Superadminrolle kann über `simulate_role`, `simulate_user_id` und `simulate_name` simuliert werden (`simulate.php`, `includes/auth.php`, Navigation).

`config.php` konfiguriert `PENDENZ_SESSID`, Cookie-Pfad `/`, HttpOnly, SameSite=Lax und Secure abhängig von der erkannten HTTPS-Verbindung. `.htaccess` und `.user.ini` setzen ebenfalls Sessionname/Pfad. **Viele Dateien starten jedoch bereits vor `config.php` die Session.** In diesen Fällen wird dessen Cookiekonfiguration übersprungen; das tatsächliche Verhalten hängt zusätzlich von PHP-/Apache-Konfiguration und Include-Reihenfolge ab.

`require_login()` prüft bei bestehender Session `benutzer.is_blocked` und zerstört die Session eines gesperrten Kontos. Nicht jede Route ruft diese Funktion auf: direkte Sessionprüfungen und `is_logged_in()` erzwingen diesen Sperrcheck nicht. `require_role()` prüft die rohe Sessionrolle und ruft bei bereits eingeloggten Sessions nicht nochmals `require_login()` auf. `current_role()` berücksichtigt dagegen Simulation. Daraus entstehen unterschiedliche Rechte in Simulation, Navigation und direkten Aktionen.

`logout.php` startet und zerstört die Session und leitet zu `login.php`. Es gibt keinen expliziten vollständigen Cookie-/Browsercache-/IndexedDB-Reset. `pages/logout.php` ist ein alter Helper-basierter Weg. Kein durchgängig implementierter eigener Idle-/Absolute-Timeout, keine erkennbare MFA und kein zentraler Rate-Limiter im geprüften Loginablauf. Kontostart/-enddatum und Soft-Delete werden nicht durch einen universellen Sessionguard erzwungen.

## 4. Admin- und Superadminbereich: Dateien und Navigation

### 4.1 Einstieg und gemeinsame Oberfläche

| Bereich | Beteiligte Dateien | Aufgabe |
|---|---|---|
| Adminstart | `index_admin.php`, `includes/nav_admin.php` | Rollenprüfung Admin/Superadmin; einfache Startseite mit Modul-Links, teilweise absolute lokale Pfade. |
| Superadminstart | `index_superadmin.php`, `includes/nav_superadmin.php` | Explizit Superadmin; globale Zähler, jüngste Daten, Konto-/Importübersicht und Schnellbearbeitung. |
| Benutzerstart | `index_private.php`, `includes/nav_benutzer.php` | Fünf jüngste Projekte und fünf anstehende Pendenzen; Mitgliedschaftsfilter für normale Benutzer. |
| Navigation/Layout | `includes/nav_dispatch.php`, `nav_auto.php`, `nav.php`, `header.php`, `footer.php`, `layout.php` | Rollennavigation, Top-/Seitennavigation, gemeinsame Assets und Widgets. |
| Projektcockpit | `pages/projekt_dashboard.php`, `projekt_detail.php`, `projekt_baum.php` | Struktur, Projektordner, Dashboardkarten, Objekt-/Wohnungsbezug. |
| Pendenzcockpit | `pages/pendenzen.php`, `pendenzen_liste.php`, `pendenzen_settings.php`, `listen_settings.php` | Hauptliste, Listenprofile, Spalten, Filter, Bearbeitungs-/Exportmodals. |
| Partnerverwaltung | `pages/benutzer.php`, `profil.php`, `firmen.php`, `admin_matrix_hub.php` | Personen, Profile, Firmen, Zuordnungen. |
| Stammdaten | `pages/bkp_codes.php`, `vorgangsart_settings.php`, `personen_taxonomie.php`, `user_taxonomy.php`, `pendenz_kategorien*.php` | BKP, Vorgangsarten, Personentypen und Kategorien. |
| Bestand/Mietwesen | `pages/wohnungen_liste.php`, `wohnung_edit.php`, `mieterspiegel.php`, `mieter_zuweisen.php`, `interessenten.php` | Einheiten, Räume, Mieter, Interessenten, Vertragsbezug. |
| Dokumente/Protokolle | `pages/abnahmen.php`, `abnahme.php`, `wohnungsabnahme_protokoll.php`, `pdf_designer.php`, `files.php`, `storage_manager.php` | Protokolle, PDFs, Ablage. |
| System | `pages/system_settings.php`, `simulate.php`, `api/quick_insert.php`, `quick_update.php`, `batch_update.php` | Systemparameter, Simulation, administrative Schnellaktionen. |

Die Superadminnavigation verlinkt auch normale Fachmodule. Ein Link im Superadminmenü macht die Zielseite **nicht automatisch exklusiv**. `pages/protokoll_manager.php` und `pages/audit.php` werden verlinkt, fehlen aber im aktuellen Hauptverzeichnis. Historische Kopien ersetzen diese URLs nicht.

Explizit privilegierte Beispiele: `index_superadmin.php`, `pages/system_settings.php`, `simulate.php`, `tools/konto_verwaltung/import.php`, APIs `quick_insert`, `quick_update`, `batch_update`, `pendenzen_preview`. Im Speichermanager ist endgültiges Löschen zusätzlich auf Superadmin begrenzt. `pages/teams.php` verlangt dagegen exakt `admin` und schließt dadurch einen echten Superadmin aus – `require_role()` implementiert keine automatische Rollenvererbung.

## 5. Benutzer, Firmen, Mandanten und Rechte

### 5.1 Rollen und fachliche Typen

Aktuelles `benutzer.rolle`: `superadmin`, `admin`, `benutzer`, `gast`. Aktuell gezählt: 2 Superadmins, 1 Admin, 8 Benutzer, keine Gastkonten. Nicht angemeldete Besucher werden in Helfern ebenfalls als `gast` bezeichnet; ein eingeloggtes Gastkonto wäre trotzdem eine authentifizierte Session.

`projekt_mitglieder.rolle`: `owner`, `manager`, `mitarbeiter`, `gast`. Diese Rollen sind projektbezogen und nicht mit der globalen Rolle gleichzusetzen. Altes `database/schema.sql` bzw. einzelne Seiten verwenden zusätzlich `kunde`, `mitarbeiter`, `projektleiter`; diese stehen nicht im aktuellen globalen Rollen-ENUM.

`benutzer.business_type`: `standard`, `mieter`, `vermieter`, `mietinteressent`, `vormieter`, `handwerker`, `kunde`, `lieferant`. Weitere Klassifikation über `person_types`, `person_statuses`, `benutzer_personentypen`, `person_type_id`, `person_status_id`, `mieter_phase`, `labels_json`. Diese Merkmale steuern Formulare/Empfängervorschläge und teilweise Rechte, sind aber keine konsistente zusätzliche RBAC-Schicht.

`permissions_json` existiert in `benutzer`; in den geprüften zentralen Auth-/Adminwegen wurde keine durchgehende Auswertung dieses Feldes gefunden. Eine vorhandene Spalte ist kein Nachweis wirksamer Einzelberechtigungen.

### 5.2 Benutzerverwaltung

`pages/benutzer.php` bietet Profilpflege, Benutzeranlage, Einladung, Löschen, Sperren/Entsperren, Bild-/Logo-/Titelbildupload, Firmenübernahme, Personentypen, Sichtbarkeitsfelder und Ordnersynchronisation. SQL und Aktionsbehandlung liegen in derselben Datei. Tabellen: `benutzer`, `firmen`, `benutzer_personentypen`, `person_types`, `person_statuses`, `user_events`; zusätzlich Zuordnungs-/Taxonomietabellen gemäß Register.

Normale Benutzer werden beim Zugriff auf ein anderes `view`/`id` auf ihr eigenes Profil umgeleitet. Admin/Superadmin dürfen andere Profile sehen. POST-Aktionen nutzen `benutzer_csrf_validate_or_throw()`.

Wichtige tatsächliche Unterschiede:

- Die Oberfläche erlaubt Admins die Anlage von `admin`, `benutzer`, `gast`; Superadmin zusätzlich `superadmin`.
- `api/benutzer_create.php` erlaubt Admins dagegen nur `benutzer`/`gast`, Benutzern nur `gast`; es setzt ein festes gemeinsames Initialpasswort. Dessen Wert wird hier bewusst nicht wiedergegeben.
- Die Seite schützt Superadmins vor Löschung durch Admins und schützt Admin-/Superadminkonten vor unzulässigem Sperren. Profilrollen werden gesondert begrenzt; andere Profilfelder eines Superadmins sind dadurch nicht automatisch geschützt.
- Der `sync_folder`-Zweig prüft nicht dieselbe Zielbenutzerberechtigung wie die Profiländerung.
- `api/benutzer_update.php` und `api/benutzer_delete.php` umgehen die Schutzlogik der Oberfläche vollständig; Details im Sicherheitskapitel.

`pages/profil.php`, `profile_fill.php`, `public_profile.php`, `benutzereinstellungen.php`, `includes/user_ui.php`, `user_taxonomy.php` ergänzen Profildarstellung, öffentliche Sichtbarkeit und Einstellungen. Die verschiedenen Sichtbarkeitsfelder (`profile_vis`, einzelne `vis_*`, Bilderflags) sind getrennt von Pendenz-ACL und Firmen-/Projektmitgliedschaft.

### 5.3 Firmen und Mandanten

`pages/firmen.php` ist Admin/Superadmin vorbehalten und prüft CSRF bei POST. Firmen sind Stammdaten/Vorlagen mit Name, Adresse, Kontakt, Logo, Website und BKP-Bezug. `firmen_vorlagen_map` ordnet Firmen den Welten `bkp`, `mieter`, `vermieter` und den jeweiligen Referenz-IDs zu; die Tabelle wird beim Seitenaufruf sichergestellt.

Daneben existieren `firma`, `firma_user`, `firmen_overrides`, direkte `benutzer.firma_id`-Bezüge und kopierte `benutzer.firma_*`-Felder. `pages/admin_matrix_hub.php` verwaltet direkte Projekt-/Teamzuordnungen und Firmen-Include/Exclude-Overrides. Die Seite nutzt `benutzer_projekte`, `benutzer_teams`, `team_projekte`, `firmen_overrides`; die zentrale Projektprüfung benutzt dagegen `projekt_mitglieder`.

**Mandantentrennung ist nicht als durchgängige Systemgrenze implementiert.** `pendenzen.mandant_id` und `pendenz_field_defs.mandant_id` existieren, aber es gibt keine Tabelle `mandanten`. Teilweise wird die aktuelle Benutzer-ID als Scope eingesetzt. Die globale Hauptliste filtert nicht nach `mandant_id`. Firmenzugehörigkeit oder ein Projektname mit Eigentümerpräfix begrenzen daher nicht zuverlässig den Datenzugriff. `kv_mandate` ist ein Konto-Zuordnungsmodell und keine Mandantenverwaltung.

### 5.4 Vorgesehene Helferrechte versus tatsächliche Anwendung

| Helfer / Funktion | Im Code implementierte Regel |
|---|---|
| `is_admin()` | `admin` und `superadmin`, unter Berücksichtigung der Simulation. |
| `require_role()` | Exakter Vergleich gegen rohe Sessionrolle; keine automatische Vererbung. |
| `require_project_access()` | Admin/Superadmin werden zugelassen; sonst Eintrag in `projekt_mitglieder` erforderlich. |
| `can_view_pendenz()` | Superadmin immer; Mieter nur passende `wohnung_id`; Handwerker nur `unternehmer_projekte` und ggf. passendes BKP; sonst Ersteller, Zuständiger, Projektmitglied bei `sichtbarkeit=projekt` oder `pendenz_acl.can_view`. |
| `can_edit_pendenz()` | Superadmin immer; Mieter nie; Handwerker nur als Zuständiger bei `assignee_can_edit`; sonst Projektrolle owner/manager, Ersteller, berechtigter Zuständiger oder `pendenz_acl.can_edit`. |

Ein globaler Admin erhält in `can_view_pendenz()`/`can_edit_pendenz()` **nicht** denselben pauschalen Bypass wie in `require_project_access()`. Auch deshalb können Listen, Medien und PDF unterschiedliche Ergebnisse liefern. Handwerker-Leserecht und -Bearbeitungsrecht prüfen unterschiedliche Voraussetzungen. Der Service behandelt BKP zusätzlich teilweise als `vorgangsart_id`, der Authhelper nicht.

### 5.5 Rechteprüfung pro wesentlicher Seite und Aktion

Diese Matrix beschreibt den aktuellen Code, nicht eine gewünschte Freigabe. „Login“ allein bedeutet keine Prüfung des betroffenen Projekts/Datensatzes. „Ohne Gate“ bezieht sich auf den geprüften PHP-Includegraph; externe Webserverregeln können die Erreichbarkeit zusätzlich begrenzen.

| Datei / Aktion | Tatsächliches Gate | Aktions-/Datensatzprüfung und Befund |
|---|---|---|
| `index_admin.php` | Login + Admin/Superadmin | Expliziter Startseitenschutz. |
| `index_superadmin.php` | Login + Superadmin | Globale Datenübersicht. |
| `index_private.php` | Login | Für Nichtadmins Projektmitgliedschaftsfilter; nicht vollständige ACL-Abbildung. |
| `pages/benutzer.php` | Login; eigene/fremde Profile getrennt | CSRF und viele Aktionsregeln; Ordnersync abweichend. |
| `api/benutzer_create.php` | Rollenvergleich | Keine zentrale Sperr-/CSRF-Prüfung; festes Initialpasswort. |
| `api/benutzer_update.php` | Ohne Gate | Änderbare Felder enthalten `rolle`; Ziel-ID und Feldliste reichen. Kritisch. |
| `api/benutzer_delete.php` | Ohne Gate | DELETE über GET-ID; keine Hierarchie-/CSRF-Prüfung. Kritisch. |
| `pages/firmen.php` | Login + Admin/Superadmin | POST-CSRF vorhanden; globale Firmenverwaltung. |
| `pages/admin_matrix_hub.php` | Login + Admin/Superadmin | Zuordnungssystem mit CSRF-Helfer, aber nicht dieselbe Mitgliedschaftsquelle wie Auth. |
| `pages/projekte.php` | Login | Anlegen/Ändern/Kontoverknüpfungen ohne zusätzliche Projekt-/Adminprüfung; Installationsaktion vorhanden. |
| `pages/objekt_neu.php`, `objekt_edit.php` | Login | Existenzprüfung ersetzt keine Projektmitgliedschaft. |
| `pages/wohnung_edit.php` | Login + Admin/Superadmin | Umfangreiche Schreib-/Raum-/Mietfunktionen; automatische DDL beim Laden. |
| `pages/wohnungen_liste.php` | Login | Filter nach Projekt/Objekt/Typ, kein Benutzer-Scope im SQL. |
| `pages/mieterspiegel.php` | Login | Keine zusätzliche Rollen-/Projektprüfung und kein POST-CSRF-Gate; Anlage, Mietänderung, Zuweisung, Synchronisation und umfangreiche Löschkette. |
| `pages/mieter_zuweisen.php` | Login + POST-CSRF | Zuweisung, Historisierung und Löschen ohne Benutzer-/Projektberechtigung; anderes Mietmodell als `mieterspiegel.php`. |
| `pages/ajax_unit_rooms.php` | `is_logged_in()` | Räume und globale Raumvorlagen veränderbar; keine Objekt-/Rollen-/CSRF-Prüfung; DDL und ggf. Vorlagenanlage auch bei GET. |
| `pages/vertrag_gen.php`, `vertrag_save.php` | Ohne Authgate | Ungecastete IDs in SQL; Speichern ändert Mietdaten vor PDF-Erzeugung, ohne POST-Zwang oder CSRF. |
| `pages/interessent_invite_save.php` | Ohne Authgate | Benutzer-/Interessentenanlage und Projektordner; erzeugt einen Link, versendet keine E-Mail. |
| `pages/ajax_unit_applicants.php` | Ohne Authgate | Kontaktabfrage nach Wohnungs-ID; im aktuellen Schema scheitert die Sortierung an fehlendem `interessenten.created_at`. |
| `api/projekt_member_list.php` | Ohne Gate | Trotz Name **Anlage** einer Mitgliedschaft per E-Mail/Projekt, kein Listenendpunkt. |
| `api/projekt_member_update.php` | Ohne Gate | Ändert Projektrolle anhand Mitgliedschafts-ID. |
| `api/projekt_member_remove.php` | Ohne Gate | Löscht Mitgliedschaft per GET. |
| `pages/pendenzen.php` | Login | Hauptabfrage ohne Benutzer-/Mandanten-/ACL-Scope; Inline-/Anhang-/Profilaktionen nicht mit zentraler ACL abgesichert. |
| `pages/pendenz_neu.php` | Login bzw. bereits eingeloggt | Anlage, Duplikat, Bearbeitung nach ID; keine zentrale Projekt-/Pendenz-ACL. |
| `api/pendenzen_inline_save.php` | Kein ausgeführtes Authgate | `_bootstrap.php` definiert Helfer, erzwingt aber keine Anmeldung; ACL nur angekündigter Kommentar. |
| `api/gantt_task_create.php` | Session-User-ID | Keine Projektmitgliedschaftsprüfung; Terminberechnungen greifen auf Vorgänger-IDs zu. |
| `api/pendenzen_list.php` | Login | Filter/Paging, aber kein Benutzer-/Objektberechtigungsscope. |
| `api/pendenzen_get.php`, `pendenzen_delete.php` | Kein wirksames eigenes Gate | Zusätzlich PDO/MySQLi-/Bootstrapfehler und fehlendes `deleted_by`; nicht als funktionierend freigegeben. |
| `pages/pendenz_show.php` | Nur bei nicht öffentlichen Datensätzen Login + View-ACL | Bei `public_enabled=1` reicht ID ohne Token; zusätzliche `_vis_admin_only`-Sperre möglich. |
| `pages/pendenz_public.php` | Öffentlicher Token + `public_enabled` | Token ist Zugangsberechtigung; Rückmeldung ändert Status/Medien, kein normales Benutzerrecht. |
| `pages/pendenz_response.php` | Login + Zuständiger oder Admin | Keine Transition-Allowlist; Kommentar-/Uploadpfad mit Schemaabweichungen. |
| `api/pendenz_media.php` | Login + View-ACL | Für Cover/Löschen zusätzlich Edit-ACL und Dateizuordnung; kein CSRF-Aufruf. |
| `api/pendenz_set_cover.php` | Login | Fehlende Pendenz-ACL trotz Kommentar; schwächerer Parallelweg. |
| `pages/pendenz_pdf.php` | Gültiger Public-Token oder Login + View-ACL | Schutz besser als bei `pendenz_show.php`; Token wird aber ins Log geschrieben. |
| `pages/pendenzen_list_pdf.php` | Login | Eigene Filterabfrage, keine entsprechende zentrale Pendenz-ACL. |
| `pages/ajax_send_pendenz_mail.php` | `is_logged_in()` | Versand für übergebene ID ohne Edit-/View-ACL oder CSRF. |
| `api/listen_save.php` | Login | Update vorhandener Liste ohne Eigentümerbedingung; keine Transaktion für Ersatz aller Spalten. |
| `api/listen_preview.php` | Login | Jüngste Pendenzen ohne Benutzer-Scope. |
| `api/manage_pdf_templates.php`, `save_pdf_mask.php` | Eingeloggt | Globale Vorlagen/Standardeinstellung von normalen Benutzern änderbar. |
| `pages/abnahme_action.php` | Ohne Authgate | Protokoll löschen/umbenennen; Löschen kann PDF-Datei entfernen. |
| `pages/abnahme.php` | Kein Authgate vor dem Datenzugriff | Projekt-/Wohnungs-ID wird direkt aus GET in SQL eingesetzt; zusätzlich Schema-Selbstheilung. |
| `pages/abnahme_save.php`, `wohnungsabnahme_save.php` | Kein zentraler Auth-/Rollen-/CSRF-Aufruf im geprüften Code | Umfangreiche Speicher-/Uploadaktionen; öffentlich erreichbarer Pfad wäre kritisch. |
| `pages/file.php` | Login + Projektzugriff | `fs_safe_join()` und Datei-Existenzprüfung; Adminbypass gemäß Authhelper. |
| `pages/storage_manager.php` | Eigene Gates | Schreibaktionen überwiegend Admin + eigenes CSRF, endgültiges Löschen Superadmin; übrige Ablagewege getrennt prüfen. |
| `api/dirlist.php` | Session-User-ID | Kann Betriebssystemverzeichnisse auflisten; kein auf Projektroot begrenzter Lesescope. |
| `pages/finanzen.php` | Login + Admin/Superadmin | Manuelle Buchungen, kein zentraler POST-CSRF-Aufruf. |
| `tools/konto_verwaltung/index.php`: `kv_save_konto_mapping`, `kv_new_booking` | Login + CSRF, **vor** Superadminprüfung | Diese beiden Schreibzweige laufen vor dem Rollenabbruch; keine Projekt-/Kontoberechtigung. |
| `tools/konto_verwaltung/index.php`: Liste, Batchzuordnung, Kategorie, Löschen | Zusätzlich Superadmin ab Zeile 142 | Spätere Zweige durch Rollengate geschützt; POST-CSRF bereits im ersten POST-Block erzwungen. |
| `tools/konto_verwaltung/import.php` | Login + Superadmin | CSV-Vorschau/Import; keine CSRF-Prüfung, keine Importtransaktion oder Dublettensperre. |
| `tools/mieterspiegel/index.php`, `tools/mietkontrolle/index.php` | Superadmin bzw. Admin/Superadmin | Beide zusätzlich Login; unterschiedliche Sollmodelle, Schemaabweichungen und fehlendes POST-CSRF. |
| `api/notifications_*.php` | Login | Abfragen/Markieren auf eigenen `user_id` eingeschränkt; Markieren ohne CSRF. |
| `pages/pendenz_inbox.php` | Login | Liest `notifications` global, nicht pro Empfänger. |
| `api/ai_query.php`, `ai_chat_messages.php` | Login + Chatbesitz | Bei KI-Pendenzanlage keine passende Projektprüfung. |
| `pages/ai_assistant.php` | Login | Chatnachrichten werden im Löschzweig vor Besitzprüfung gelöscht; globale Trainingsdaten im POST löschbar. |
| `pages/system_settings.php` | Rohe Sessionrolle Superadmin | Header/Navi vorher; DDL/Default-INSERT beim Laden; POST ohne CSRF. |

Das vollständige statische Register im Anhang ergänzt diese manuell eingeordnete Matrix. Eine absolute Aussage „Rolle X darf nur Y“ wäre angesichts der Umgehungswege fachlich falsch.

## 6. Projekte, Objekte, Einheiten und Mitarbeiter

### 6.1 Hierarchie und Projektkontext

```text
projekte
  ├─ objekte (projekt_id)
  │    └─ wohnungen (objekt_id)
  │         ├─ raeume (wohnung_id)
  │         ├─ wohnung_mieter / Mietverhältnisse
  │         └─ wohnung_bilder / wohnung_dokumente
  ├─ projekt_plaene / plan_zonen
  ├─ projekt_mitglieder (zentrale Authquelle)
  ├─ benutzer_projekte / team_projekte (parallele Zuordnungen)
  └─ pendenzen (zusätzlich direkte Objekt-, Wohnungs-, Raumbezüge)
```

Projekte besitzen Nummer, Name, Beschreibung, Adresse, Start/Ende, Status, Kategorie, Sortierung, Bilder, Ersteller, Soft-Delete/Zeitstempel, `root_path`, `storage_type`, `fs_rel_path`, `wohnungen_rel_path`, `pendenzen_profile_id`. Die Statusliste umfasst u. a. Grundstück, Planung, Bewilligung, Bau, Vermietung, verkauft, aktiv/geplant/laufend/abgeschlossen/archiviert. Ein Projekt kann damit auch eine Liegenschaft oder ein Portfolio repräsentieren; es gibt keine getrennte kanonische Eigentümerhierarchie.

Objekte gehören über `objekte.projekt_id` zu genau einem referenzierten Projekt. `wohnungen.objekt_id` verweist auf das Objekt; Wohnungen besitzen keine eigene direkte `projekt_id` im aktuellen Schema. Eine Pendenz kann dagegen gleichzeitig `projekt_id`, `objekt_id`, `wohnung_id`, `raum_id` tragen. Einzelne Fremdschlüssel garantieren nicht, dass alle gewählten Eltern zusammenpassen; `pendenzen.objekt_id` besitzt derzeit keinen deklarierten FK.

`includes/project_ctx.php`, `pages/projekt_waehlen.php`, `api/projekt_context.php`, Navigation und einzelne Seiten verwenden `current_project_id`. Ein aus GET übernommener Projektkontext ist keine Berechtigungsprüfung. Manche Seiten verwenden `id`, andere `projekt_id`, Wohnungen zusätzlich `unit_id`/`wohnung_id`; Rücksprung- und Auswahlmodi erzeugen weitere URL-Varianten.

### 6.2 Projektfunktionen

- `pages/projekte.php`: Liste, Projektformular, Bild, Status, Listenprofil und Konto-Verknüpfung; `kv_konten`/`kv_buchungen`-Installation als Seitenaktion.
- `pages/projekt_neu.php`: eigener Anlageweg; `projekt_bearbeiten.php`: älterer Weg mit globalem `projektleiter`-Gate und fehlenden Helper-/Schemaannahmen.
- `pages/projekt_detail.php`, `projekt_dashboard.php`, `projekt_baum.php`: Detailstruktur, Baum, Ordnernavigation, Karten, Vorschau und Ablagekontext. `project_dash_cards`, `fs_nodes`, `fs_folder_meta` speichern Anzeige- und Ordnerinformationen.
- `pages/projekt_verknuepfungen.php`, `includes/links.php`: generische Verknüpfungen über `projekt_verknuepfungen`; nicht gleichbedeutend mit Projektmitgliedschaften.
- `pages/projekt_plaene.php`, `plan_zonen_edit.php`, `pages/ajax_get_plans.php`, `api/projekt_context.php`: Planablage, Ebenen/Zonen und Auswahl. Pendenzen tragen `plan_id`, `pin_x`, `pin_y`, weitere Pin-/Snapshotinformationen in JSON bzw. Dateien.

### 6.3 Einheiten, Räume, Mieter und Personal

`pages/objekt_neu.php`, `objekt_edit.php` verwalten Objektstammdaten. `wohnung_neu.php`, `wohnung_edit.php`, `wohnung_detail.php`, `wohnungen_liste.php`, `vermietungseinheiten.php` arbeiten mit teilweise überlappenden Einheitenmodellen (`wohnungen`, `vermietungseinheiten`, `einheit_typen`). `wohnungen` enthält Wohnungs-/Parkplatz-/Lagerarten, Etage, Fläche, Zimmer, Ausstattung, Veröffentlichung, Miet-Sollwerte und Bilderpfade.

`wohnung_edit.php` enthält umfangreiche Raumverwaltung und automatische Ergänzungen des Schemas, u. a. für eine lokal derzeit fehlende `wohnung_mietzins_historie`. Räume liegen im neueren Modell in `raeume`, im älteren Modell zusätzlich `zimmer` und `gegenstaende`. Diese Modelle sind nicht automatisch synchron.

Mitarbeiter sind keine eigene zentrale Tabelle: Es sind Benutzer mit globaler Rolle, Projektrolle und/oder Personentyp. Mitgliedschaften und Zuständigkeiten werden über `projekt_mitglieder`, `benutzer_projekte`, `benutzer_teams`, `team_projekte`, `unternehmer_projekte` abgebildet. Lokaler Stand: `projekt_mitglieder=0`, `projekt_memberships=0`, `benutzer_projekte=7`. Der Service bevorzugt die **Existenz** von `projekt_mitglieder`, nicht ihre Befüllung; die leere Tabelle verhindert dort den Fallback auf vorhandene alternative Zuordnungen.

Mieterzuweisung und Vermietung: `pages/mieter_zuweisen.php`, `mieterspiegel.php`, `mieter_dashboard.php`, `interessenten*.php`, `bewerbung.php`, `anfrage.php`, `vertrag_gen.php`, `vertrag_save.php`, `includes/rent.php`. Tabellen umfassen `wohnung_mieter`, `projekt_verknuepfungen`, `mietverhaeltnisse`, `mietvertraege`, `mieten_tarife`, `miete_adjustments`, `interessenten`, `benutzer`, `wohnung_dokumente`.

Diese Tabellen bilden **keinen einheitlichen Mietvertrag**: `pages/mieterspiegel.php` weist in `wohnung_mieter` zu; `pages/mieter_zuweisen.php` schreibt dagegen in `projekt_verknuepfungen`. Dessen lokale FKs zeigen auf `liegenschaften`, `vermietungseinheiten` und `mieter`, während die Personenauswahl im Code `benutzer` bevorzugt. Die konkreten Beziehungen und Folgen sind in Abschnitt 21 dokumentiert. Öffentliche Bewerbungs-/Interessentenlinks bilden zusätzliche Eingänge in interne Datenabläufe.

## 7. Pendenzen: Aufbau, Anlage, Bearbeitung und Zuordnung

### 7.1 Datenmodell

Die Tabelle `pendenzen` enthält deutlich mehr als Titel und Termin:

| Feldgruppe | Wichtige tatsächliche Felder |
|---|---|
| Kontext | `mandant_id`, `projekt_id`, `objekt_id`, `wohnung_id`, `raum_id`, `ordner_id`, `fs_rel_path`, `fs_branch` |
| Inhalt | `titel`, `kurzbeschreibung`, `langbeschreibung`, `beschreibung`, `notiz`, `extra_json` |
| Taxonomie | `vorgangsart_id`, `art_id`, `vorlagen_welt`, `bkp_id`, `kategorie_id`, `subkategorie_id`, Mieter-/Vermieterkategorien |
| Bearbeitung | `status`, `wichtigkeit`, `erstellt_von`, `zustaendig_id`, `assignee_can_edit`, `sichtbarkeit`, `sicht_ref_id`, `zustaendig_typ` |
| Alternative Empfänger | `empfaenger_typ`, `empfaenger_profil_id`, `empfaenger_benutzer_id`, `ersteller_benutzer_id` |
| Termine | `startdatum`, `enddatum`, `uhrzeit`, `tageszeit`, `dauer`, `vorgaenger_id` |
| Freigabe/extern | `confirmation_required`, `confirmation_by`, `confirmation_at`, `public_enabled`, `public_token`, `external_can_view`, `external_can_upload` |
| Rückmeldung | `unt_bemerkung`, `unt_new_input`, `submitted_by`, `submitted_at`, `reviewed_by`, `reviewed_at` |
| Plan/Protokoll | `plan_id`, `pin_x`, `pin_y`, `is_protocol`, `protocol_type` |
| Technisch | `sort_index`, `send_now`, `deleted_at`, mehrere deutsche/englische Zeitstempel |

`extra_json` speichert u. a. Vorlage/manuell getrennte Textbestandteile, Kategorien, dynamische Felder, Planinformationen und `_view`-Darstellung/Sichtbarkeit. Einige Informationen liegen zusätzlich in regulären Spalten. Nicht jeder Schreibweg hält beide Darstellungen konsistent.

### 7.2 Schreibwege und Auswahlablauf

1. **Hauptcockpit:** `pages/pendenzen.php` verarbeitet Anlage/Bearbeitung, `inline_update`, Cover, Anhangslöschung, Layout-/Profilaktionen und Rückmeldungsflag selbst.
2. **Ausführliches Formular:** `pages/pendenz_neu.php`, auch eingebettet mit `embed=1`; unterstützt `edit_id`, `duplicate_id`, Vorgangsarten, Empfängervorgaben, Bilder/Dokumente und Planmarkierungen.
3. **Schnellerfassung:** `includes/quick_capture.php` → `pages/ajax_quick_pendenz.php`, unterstützt auch browserseitige Offline-Warteschlange.
4. **Tabellen-/Ganttänderung:** `api/pendenzen_inline_save.php`, `gantt_task_create.php`; zusätzliche ältere APIs `pendenzen_save.php`, `pendenzen_get.php`, `pendenzen_delete.php`.
5. **KI-Anlage:** `api/ai_query.php` schreibt direkt in `pendenzen`.

Typischer Formularablauf: Vorgangsart wählen → Vorlagenwelt/BKP bzw. Mieter-/Vermieterkategorie → Projekt/Objekt/Wohnung/Raum → Empfänger → Texte aus Vorlage plus manuellen Ergänzungen → Termine/Plan/Anhänge → SQL und Dateisystemschreiben. `pendenzen_arten` und `pendenzen_art_empfaenger_defaults` liefern Standardprojekt/-objekt/-wohnung/-benutzer und Empfängertyp/-status. Browserfunktionen wie `filterObjekte()`, `filterWohnungen()`, `filterRaeume()` und `filterEmpfaenger()` reduzieren Auswahlmöglichkeiten. Diese UI-Filter ersetzen keine serverseitige Prüfung einer manipulierten Kombination.

### 7.3 Zuständigkeit und Mitarbeiterzuweisung

Operativ ist `pendenzen.zustaendig_id → benutzer.id` die zentrale Zuständigkeit: Hauptliste, Detailseite, Mailversand und Authhelper lesen sie. Das Feld wird in Formularen und Inline-APIs direkt gesetzt. `assignee_can_edit` erlaubt oder begrenzt die Bearbeitung durch den Zuständigen.

`zustaendig_typ` kennt zwar `user`, `team`, `firma`, aber der tatsächliche FK von `zustaendig_id` zeigt auf `benutzer`. Eine allgemeine polymorphe Team-/Firmenzuständigkeit ist damit nicht konsistent realisiert. Empfängerprofil und alternative Benutzerfelder sind weitere historische/neue Ansätze, keine Garantie einer Mehrfachzuweisung. Unternehmerprojekt-/BKP-Zuordnung steuert vor allem Zugriff/Auswahl und ist nicht dasselbe wie der konkrete Verantwortliche einer Aufgabe.

Priorität heißt im aktuellen Kern **`wichtigkeit`**, üblicherweise 1–5; Haupt-Inlinebearbeitung begrenzt auf diesen Bereich. Ältere APIs verwenden `prioritaet`, `prio` oder `zugewiesen_an`; diese Namen sind teilweise nicht im aktuellen Schema vorhanden. `pendenzen_inline_save.php` übersetzt `prio` zu `wichtigkeit`, andere Endpunkte tun dies nicht einheitlich.

### 7.4 Kategorien und Vorlagen

| Funktion | Dateien | Tabellen |
|---|---|---|
| Vorgangsarten/Empfängervorgaben | `pages/vorgangsart_settings.php`, `includes/vorgang_taxonomy.php` | `pendenzen_arten`, `pendenzen_arten_projekte`, `pendenzen_art_empfaenger_defaults` |
| BKP-Hierarchie und Textvorlagen | `pages/bkp_codes.php` | `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte` |
| Allgemeine Pendenzkategorien | `pages/pendenz_kategorien.php` | `pendenz_kategorien`, `pendenz_subkategorien` |
| Mieter-/Vermieterwelten | `pages/pendenz_kategorien_mieter.php`, `pendenz_kategorien_vermieter.php` | Gleichnamige Kategorien-/Subkategorientabellen |
| Unternehmerkategorien | `pages/pendenz_kategorien_unternehmer.php` | Wrapper, der `bkp_codes.php` lädt; kein eigenständiges Kategorienmodul. |
| Strukturvorlagen | `pages/vorlagen.php`, `vorlage_bearbeiten.php`, `unterkategorie_*.php`, `api/unterkategorie_*.php` | `struktur_vorlagen`, `unterkategorien`, `projekt_vorlagen` |

Die Bezeichnungen Kategorie, Unterkategorie, Vorgangsart und BKP sind nicht austauschbar. Die Hauptliste filtert teilweise reguläre `kategorie_id`, der Service teilweise JSON-Pfade. Dadurch können identische Filterbezeichnungen unterschiedliche Datensätze treffen.

## 8. Status, Rückmeldungen, Kommentare und Verlauf

Aktuelles DB-ENUM: `offen`, `in Bearbeitung`, `erledigt`, `archiviert`. Aktueller Bestand: 98 offen, 6 in Bearbeitung, 3 erledigt, 1 archiviert, **3 mit leerem Status**.

Die Hauptoberfläche akzeptiert zusätzlich `Unt. Erledigt.`. `pages/pendenz_public.php` schreibt diesen Wert nach einer Unternehmerrückmeldung. `pages/pendenzen.php` erweitert das Statusfeld auf VARCHAR jedoch nur innerhalb eines Fehlerzweigs für fehlende Unternehmerfelder. Sind diese Felder vorhanden, bleibt das ENUM unverändert. Der aktuelle Schemazustand belegt genau diese Inkompatibilität. Die drei Leerwerte passen zum nicht strengen SQL-Modus; ihre historische Entstehung wurde nicht rekonstruiert.

Es existiert **keine zentral von allen Wegen verwendete Statusmaschine**:

- `pages/pendenzen.php`: direkte UPDATEs mit eigener Werteliste.
- `api/pendenzen_inline_save.php`: generische Feldänderung, keine zentrale Transitionprüfung.
- `pages/pendenz_response.php`: Zuständiger/Admin kann `new_status` schreiben; keine eigene vollständige Transition-Allowlist.
- `pages/pendenz_public.php`: Token-Rückmeldung setzt `confirmation_at`, `status`, `unt_bemerkung`, `unt_new_input=1`; zwei JPEG- und ein PDF-Slot, bestehende Slotdatei wird ersetzt.
- `pages/pendenz_show.php` und `pages/pendenzen.php?action=reset_unt_bell` löschen das Rückmeldungsflag. Die Detailseite tut dies bereits beim Lesen durch eine Session mit User-ID, nicht nur nach einer fachlichen Abnahme.
- `includes/pendenz_workflow.php` bietet `wf_change_status()` für `offen`, `zugewiesen`, `eingereicht`, `in_pruefung`, `freigegeben`, `nacharbeit`, `abgelehnt`, schreibt aber in die lokal fehlende Spalte `status_workflow`. `pages/pendenz_work.php` ist eine identische Helperkopie. Kein aktiver durchgängiger Aufruferfluss wurde gefunden.

`pages/pendenz_review.php` ist entgegen dem Namen derzeit eine **Projektstrukturansicht**, die `id` als Projekt-ID interpretiert, und kein implementierter Freigabedialog. `pendenz_inbox.php` verlinkt sie mit einer Pendenz-ID. Zusätzlich erwartet sie u. a. das lokal fehlende `wohnungen.bild`.

Kommentare/Verlauf bestehen aus mehreren Ansätzen:

- `pendenzen.notiz`, `unt_bemerkung` und Beschreibungstexte werden praktisch verwendet.
- `pendenz_kommentare`, `comments`, `pendenz_events` sind Tabellen; ein konsistenter aktiver Kommentar-CRUD über die Hauptseiten wurde nicht gefunden.
- `pendenz_response.php` schreibt Kommentare/Historie nur, falls `pendenz_history` existiert; lokal fehlt diese Tabelle. Ohne sie wird der eingegebene Nachrichtentext in diesem Zweig nicht als History gespeichert.
- Derselbe Rückmeldungsupload erwartet `pendenz_dateien.erstellt_von`; das tatsächliche Feld heißt `hochgeladen_von`.
- `includes/audit.php::log_action()` schreibt `audit_log(actor_id,entity,entity_id,action,changes,ip)`. Einzelne Projekt-/Mitgliedschaftswege nutzen es, viele direkte Pendenz-/Benutzeränderungen nicht. Kein lückenloses Audit aller Aktionen.

## 9. Termine, Erinnerungen und Benachrichtigungen

### 9.1 Terminprogramm

`pages/terminprogramm.php` lädt Pendenzen des gewählten Projekts und rendert Tabelle plus Frappe-Gantt-Diagramm. `assets/js/frappe-gantt.min.js`, `api/gantt_task_create.php`, `api/pendenzen_inline_save.php` sind beteiligt. Fertigstellungsanzeige: erledigt 100 %, in Bearbeitung 50 %, sonst 0 %. Fehlende Start-/Enddaten werden für die Anzeige teilweise durch heute bzw. +2 Tage ersetzt.

`vorgaenger_id` ist ein **VARCHAR**, keine reine FK-ID: unterstützt Zeichenfolgen mit EA/EE/AA/AE und Tagesoffset. APIs berechnen Anfang/Ende und Dauer; im Inlineweg wird die Vorgängerliste bei der expliziten Berechnung nach dem ersten verarbeitbaren Eintrag abgebrochen. Die Gantt-Anzeige extrahiert Zahlen per Regex, wodurch auch Offsetzahlen als Kandidaten auftreten können. Kein vollständiger Nachweis einer zyklusfreien, projektisolierten Terminplanung mit Arbeitskalender und transitiver Neuberechnung aller Nachfolger.

`api/export_outlook.php` erzeugt ICS als VEVENT oder VTODO aus einer Pendenz. Das ist ein Dateiexport, keine bidirektionale Outlook-/Kalendersynchronisation. Nur Loginprüfung, keine entsprechende Pendenz-ACL im Export.

### 9.2 Benachrichtigungsmodelle

| Modell | Verwendung |
|---|---|
| `user_notifications` | `api/notifications_list.php`, `notifications_mark_seen.php`, Navigations-/Seiten-JS: eigene Meldungen, Ungelesenzähler, `seen_at`, Links. |
| `notifications` | `includes/pendenz_workflow.php::wf_notify()`, `pages/pendenz_inbox.php`: Workflow-Inbox mit Referenztyp/-ID; Empfänger kann NULL sein. |
| `benachrichtigungen` | Weiteres historisches Schema; FK zeigt auf `users`, nicht auf die operativen `benutzer`. |
| `pendenzen.unt_new_input` | Separate Glocke/Rückmeldungsmarkierung in Pendenzliste und Detailansicht. |
| `chat_reads`, `chat_message_recipients` | Chatbezogene Lese-/Zustellstände, getrennt von allgemeiner Benachrichtigung. |

Aktuell sind alle drei allgemeinen Benachrichtigungstabellen leer. Das beweist nicht, dass keine Nachrichtenfunktion existiert, sondern zeigt den aktuellen Bestand. Writer und Reader sind nicht allgemein verbunden. Es gibt keinen nachgewiesenen automatischen täglichen Lauf für fällige Pendenzen, überfällige Mieten oder Rechnungen.

### 9.3 Geplante Prozesse

`maintenance/expire_invites.php` ist ausdrücklich CLI-only: markiert abgelaufene Einladungen in `benutzer`, sucht seit drei Tagen offene Einladungen, rotiert Token/Frist, versendet Erinnerungen und schreibt `user_events`. Der Name beschreibt nicht nur Ablaufmarkierung, sondern auch Versand. Installation und Ausführungsintervall im Betrieb sind unbekannt. Bei Versandfehlern wurden Token und Einladungszeit bereits aktualisiert; eine zuverlässige Versandqueue ist das nicht.

Aktuell meldet MariaDB `event_scheduler=OFF`; im Schema wurden **keine SQL-Events und keine Trigger** gefunden. Dateien `migration_*`, `migrate_*`, `fix_*`, `scan_drive.php`, `pages/sync_*.php`, `cleanup_temp.php`, `scripts/audit.ps1` sind Wartungs-/Hilfsskripte, keine Belege für konfigurierte Cronjobs.

Browserpolling (z. B. Chat alle 3 Sekunden, Benachrichtigungen etwa alle 60 Sekunden) und Offline-Synchronisation sind nur bei laufendem Browser aktiv und ersetzen keinen Serverscheduler.

## 10. Anhänge, Bilder, Dokumente und Speicher

### 10.1 Pendenzmedien

Primärtabelle `pendenz_dateien`: Pendenz-ID, Typ `image`/`file`, Pfad, MIME, Größe, Titel, Cover, Sortierung und `hochgeladen_von`. Daneben `pendenz_anhaenge` und `anhaenge`. Die Hauptliste besitzt Kompatibilitätsleser/-löschwege; andere Endpunkte verwenden nur eine der Tabellen. Die Bildtypbezeichnungen `image` und `bild` werden in verschiedenen Ebenen umgewandelt/verwendet.

Typische aktuelle Ablage:

```text
uploads/pendenzen/<pendenz-id>/bilder/
uploads/pendenzen/<pendenz-id>/dokumente/
uploads/pendenzen/<pendenz-id>/unternehmer/
uploads/pendenzen/<snapshot-dateiname>
```

`includes/media.php::pendenz_fs_base()` bildet zusätzlich `uploads/pendenzen/<projekt-oder-ordnerpfad>/<id-slug>` ab. Verschiedene Uploadwege verwenden somit unterschiedliche Pfadkonventionen.

`pages/pendenz_neu.php`, `pages/ajax_quick_pendenz.php`: Mehrfachuploads; Dateiendungs-/Größenlisten, Bild-MIME-Präfixprüfung teilweise anhand des **vom Client gelieferten** MIME-Wertes. Im Quickweg maximal 15 MiB pro Datei. Der allgemeine `includes/functions.php::handle_upload()` hat eigene MIME-/Bildverarbeitungslogik und Systemparameter; diese schützt nicht automatisch die separaten Uploadimplementierungen. `includes/media.php::image_to_max_1mb()` komprimiert Bilder über GD, soweit verwendbar.

`api/pendenz_media.php` prüft Datei↔Pendenz und Editberechtigung beim Löschen/Coverwechsel. `api/pendenz_set_cover.php` und Seitenaktionen sind schwächere Parallelwege. Dateischreiben, DB-INSERT und Löschung alter Dateien sind nicht durchgehend atomar; bei Teilfehlern können Dateien ohne DB-Zeile oder DB-Zeilen ohne Datei entstehen.

### 10.2 Projektablage und Drive

Zentrale Funktionen: `includes/fs.php` (`project_root_path`, `fs_safe_join`, Scan, Objekt-/Wohnungsordner, `sync_project_folders`), `includes/user_folder_automation.php`, `pages/files.php`, `file.php`, `files_master.php`, `storage_manager.php`, `quick_folder_editor.php`, `project_storage.php`, `ordnerstruktur.php`, `ordner_verknuepfen.php`.

Tabellen: `projekte.root_path`, `fs_nodes`, `fs_folder_meta`, `project_dash_cards`, `ordner_links`, `ordner`, `folders`, `files`, `documents`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `ordner_vorlage_applied*`, `projekt_verknuepfungen`. Einträge repräsentieren teils Dateisystemindizes, teils logische Ordner/Metadaten; sie sind nicht ein einziges kanonisches Dokumentenregister.

`pages/file.php` liefert eine Datei nach Login, Projektprüfung und sicherem Pfadjoin aus. Direkte URLs unter `uploads/` umgehen diesen PHP-Gate. `uploads/.htaccess` versucht PHP-Ausführung zu verhindern; das ist kein Zugriffsschutz für private Bilder/PDFs. Seine Wirksamkeit hängt vom Serverhandler ab.

`storage_manager.php` besitzt eigene Pfadnormalisierung, Rollen-/CSRF-Helfer, Suche, Upload, Verschieben/Umbenennen, Cover/Zuweisungsmetadaten, Papierkorb `._trash`, Wiederherstellung/Undo und Superadmin-Harddelete. Teile der Metadaten liegen als JSON-Dateien im Dateisystem. Das ist ein eigenständiges Ablagesystem mit anderer Logik als `files.php`/`fs_nodes`.

`includes/fs.php::sync_project_folders(..., $pruneDb)` kann Ordner anlegen, umbenennen, Wohnungen importieren und bei aktiviertem Prune DB-Wohnungen löschen, wenn Ordner fehlen. `pages/mieterspiegel.php` bietet diesen Ablauf an. Fehlender Cloud-Mount und echte Löschung werden dabei nicht zuverlässig als verschiedene Ursachen modelliert.

Keine direkte Google-Drive-API-Verbindung im geprüften aktiven Kern: lokale Windows-/Drive-Desktop-Pfade, kein Composer-Google-Client, keine durchgängigen Drive-IDs, Changes-API, Webhooks oder serverseitige Syncqueue. Die frühere Analyse meldet alle zwölf Projektroots als lokal unerreichbare Windows-Pfade; diese Dateisystem-Erreichbarkeitsmessung wurde hier nicht als neue Laufzeitmessung wiederholt.

## 11. E-Mail, Einladungen und Passwortreset

| Funktion | Dateien | Daten / Besonderheit |
|---|---|---|
| Zentraler HTML-Versand | `includes/mailer.php`, `mail.php`, `email_templates.php` | Optional PHPMailer/SMTP, sonst PHP-mail-Fallback; Log-/Dry-Run-Unterstützung. |
| Älterer Versandhelfer | `includes/mail.php` | `send_mail()` delegiert an `send_mail_html()`; weitere Kontaktkanalpfade getrennt. |
| Benutzereinladung | `pages/benutzer.php`, `invite_accept.php`, `invite_open.php` | Hash in `benutzer.invite_token_hash`, Ablauf, Öffnungspixel, Status und `user_events`. |
| Alternative Einladungen | `api/user_invite.php`, `pages/accept_invite.php`, `pendenz_invite.php` | Unterschiedliche Tokenmodelle: `user_tokens` erwartet/fehlend, `user_invites`, Pendenz-/Profilbezug. |
| Interessentenlink | `pages/interessent_invite_save.php`, `interessenten_form_public.php` | Erstellt `interessenten.token`, gibt einen kopierbaren Link zurück; **kein Versandaufruf** in diesem Erzeugungsweg. |
| Passwortreset | `pages/password_reset_request.php`, `password_reset.php` | Token mit zwei Stunden Ablauf; erwartet fehlende `user_tokens` und `must_change_password`. |
| Pendenzmail | `pages/ajax_send_pendenz_mail.php` | Zuständiger aus `zustaendig_id`; HTML mit Standort, Terminen, Bildern und öffentlichem oder internem Link. |
| Einladungserinnerung | `maintenance/expire_invites.php` | CLI-Lauf, drei Tage, direkte Zustellung ohne Queue. |

`MAIL_DRY_RUN` kann `.eml` unter `logs/emails` ablegen; ein erfolgreicher Rückgabewert im Dry-Run bedeutet keine externe Zustellung. `mailer.php` berücksichtigt PHPMailer nur, wenn die Klasse tatsächlich verfügbar ist. SMTP-Konstanten allein belegen daher keinen funktionierenden SMTP-Versand. Zugangsdaten und API-Schlüssel werden hier nicht reproduziert; effektive Versandkonfiguration/Zustellung wurde nicht getestet.

Die Option `send_now` wird in den drei zentralen Erfassungswegen gespeichert. Dort wurde kein zugehöriger serverseitiger Versandaufruf gefunden. Ein Häkchen „sofort senden“ ist somit kein Nachweis, dass eine Mail verschickt wird; der separate AJAX-Mailweg existiert tatsächlich.

`includes/mail.php::send_sms()` schreibt SMS in Logs und hat optionale sms77-/Twilio-Zweige; ohne Anbieterintegration meldet auch die Simulation Erfolg. Ein sichtbarer SMS-Erfolg bestätigt daher keine Zustellung.

Passwortreset ist nicht nur durch fehlende Tabellen unvollständig: Er aktualisiert `passwort`, während Login ein vorhandenes `passwort_hash` bevorzugt. Sein Auto-Login übernimmt außerdem die Rolle aus der bestehenden Session statt aus dem abgefragten Zielkonto. Diese Logik wäre auch nach bloßer Schemaergänzung nicht sicher/konsistent.

## 12. PDF, Abnahmen, Reports und Statistiken

### 12.1 PDF-/Protokollsysteme

- `pages/pendenz_pdf.php`: Einzelreport mit Pendenz, Standort, Verantwortlichen/Erstellerbranding, Bildern/Anhängen und `extra_json._view`; `settings.pdf_mask_default` beeinflusst Layout. Dompdf mit zusätzlicher Ressourcen-/Pfadverarbeitung; Zugriff über ACL oder Public-Token.
- `pages/pendenzen_list_pdf.php`: Listen-/Sammelreport; eigene Filter, Sortierung, optionale `protocol_data` aus GET/POST, Layout-/Protokollparameter.
- `pages/pdf_designer.php`, `api/pdf_templates.php`, `manage_pdf_templates.php`, `save_pdf_mask.php`: Masken, gespeicherte Konfiguration und globale Defaultauswahl über `pdf_templates`/`settings`.
- `pages/abnahmen.php`, `abnahme.php`, `abnahme_neu.php`, `abnahme_edit.php`, `abnahme_save.php`, `abnahme_action.php`: Abnahme-/Protokollanlage, Bearbeitung, Bilder und PDF-Ablage, insbesondere `abnahmen`, `abnahme_protokolle`.
- `pages/wohnungsabnahme_protokoll.php`, `wohnungsabnahme_save.php`: umfangreicher paralleler Wohnungsabnahmeweg mit Raum-/Zustandsdaten und Unterschrifts-/Bildverarbeitung.
- `api/manage_protocol_templates.php`, Tabellen `protokoll_typen`, `protokoll_formulare`, `protokoll_vorlagen`, Verknüpfung `abnahme_mangel_link`: zusätzliche Vorlagen-/Mängelbezüge.
- `pages/vertrag_gen.php`, `vertrag_save.php`, `templates/mietvertrag_template.html`: Mietvertrag per Dompdf; verändert zuvor `wohnung_mieter`. Unabhängige Pfadbildung, fest codierte Vermieterdaten und kein Eintrag in einem Dokumentregister; Details in Abschnitt 21.3.

PDFs und Protokollbilder werden u. a. unter `uploads/protocols` gespeichert. Nicht jeder Report ist ein reiner Read-only-Aufruf: Debuglogs, Exporte, Zwischen-/Bilddateien oder Persistierung können entstehen. Deshalb wurden keine PDFs über die Anwendung generiert.

### 12.2 Reports/Statistik und Finanzen

`index_superadmin.php` zählt Benutzer, Projekte, Pendenzen, Listen und Kontodatensätze und zeigt jüngste Datensätze/Importinformationen. Zählungen sind nicht durchgehend identisch mit Soft-Delete-/Sichtbarkeitsfiltern der Fachlisten; alternative Tabellen werden bei Existenz verwendet.

`pages/finanzen.php` zeigt und schreibt `finanzen_konto` mit Soll/Haben je Wohnung/Benutzer. `tools/konto_verwaltung/index.php` und `import.php` bilden einen weiteren Kontoweg mit `liegenschafts_konto`, manuellen Buchungen, CSV-Vorschau/Import, Filtern und Batchbearbeitung. **`tools/konto_verwaltung/export.php` und `konto_verwaltung.php` sind jeweils 0 Byte groß; darüber ist kein Export implementiert.** `kv_konten`, `kv_buchungen`, `kv_import_batches`, `kv_buchung_items`, `kv_rules`, `kv_alias`, `kv_mandate`, `kv_mietvertraege` sind weitere Tabellen/Ansätze; der aktive Bankimport ist damit nicht automatisch vollständig integriert.

`tools/mietkontrolle/index.php` berechnet monatliche Soll-/Ist-Werte und Overrides, erwartet aber lokal fehlende `wohnung_mietverhaeltnis` und `wohnung_miet_override`. Seine lokale `h()`-Definition ist ein weiterer Helperzweig; allein daraus folgt keine Kollision beim Direktaufruf, da die zentralen Definitionen in `config.php` und `includes/functions.php` mit `function_exists()` geschützt sind. `pages/mieterspiegel.php` und `tools/mieterspiegel/index.php` sind andere Ansichten. Letzteres Tool erwartet die lokal fehlenden Spalten `wohnungen.label` und `wohnungen.liegenschaft_id`. Eine einheitliche vollständige Buchhaltung mit Rechnungslauf, automatischem Zahlungsausgleich, Mahnwesen und periodischer Berichterstellung ist nicht belegt.

Es gibt keine zentrale Reportengine mit automatisch einheitlicher Mandanten-/Projekt-ACL. PDF-, CSV-, ICS-, Dashboard- und Tabellenabfragen müssen als separate Datenzugriffe verstanden werden.

## 13. Suche, Filter, Tabellen, Modals und AJAX

### 13.1 Hauptliste und Profile

Die Hauptabfrage in `pages/pendenzen.php` lädt höchstens **100** nicht softgelöschte Pendenzen, absteigend nach ID, mit joins zu Projekten, Objekten, Wohnungen, Räumen, Benutzern und Vorgangsarten sowie Unterabfragen für Medien. Listenprofilfilter können Status, Projekt, Kategorie und „offen“ begrenzen. Ein Benutzer-/Mandanten-/ACL-Filter fehlt. Browserseitige Suche/Sortierung auf den geladenen Zeilen ist deshalb nicht automatisch eine vollständige Suche im Gesamtbestand.

`listen` speichert Name, Tabelle, `filters_json`, Sortierung, Seitengröße, `owner_id`, `shared`, `is_default`. `listen_spalten` enthält Spaltenreihenfolge, Breiten und Sichtbarkeit getrennt für Desktop/iPad/Mobil. Die Hauptseite lädt Profile ohne durchgängigen `owner_id`/`shared`-Scope. `api/listen_index.php`, `listen_one.php`, `listen_save.php`, `listen_delete.php`, `listen_preview.php`, `listen_field_save.php`, `listen_field_delete.php`, `inspect_table.php` unterstützen Verwaltung, Vorschau und virtuelle Felder (`pendenz_field_defs`). Rechte sind je Endpunkt unterschiedlich.

`api/pendenzen_list.php` bietet tatsächliches serverseitiges Paging (5–200 Zeilen), Textsuche, Projekt/Status/Datum/only_open, erlaubte Sortierspalten und Gesamtzahl. Sein `only_open` verwendet jedoch `in_bearbeitung`/`wartend` zusätzlich zu `offen`, was nicht dem aktuellen ENUM entspricht. Bei Enddatum-Sortierung wird ASC erzwungen, obwohl eine Richtung eingelesen wird.

`Service.php::searchResult()` hat eigene Suche in Titel/Kurz-/Langbeschreibung/Pfad, Projekt-/Objekt-/Wohnungsfilter, JSON-Kategorien und Rollenisolierung. `list()`/`listResult()` sind dagegen nicht entsprechend eingeschränkt; Count-/Searchlogik unterscheidet sich ebenfalls. `ServiceFallback.php` ist ein weiterer kompatibler Suchweg. Keine dieser Implementierungen ist automatisch die alleinige Datenquelle aller Ansichten.

### 13.2 Andere Tabellen und Suchfunktionen

- `pages/pendenzen_liste.php`, `pendenzen_settings.php`, `listen_settings.php`: zusätzliche Listen-/Spaltenoberflächen.
- `assets/js/smarttable.js`, `pages/api_smarttable.php`, `tables.php`, `table_rows.php`: generischer SmartTable-Ansatz; weitere Varianten in Sicherungsordnern. Tabellen-/Spaltenschema und erlaubte Datenquellen sind nicht überall konsistent.
- `api/inspect_table.php`: Admin/Superadmin-Introspektion von Spalten/PK und virtuellen Feldern.
- `pages/storage_manager.php`: rekursive Dateinamensuche im gewählten Root/Zweig; kein universeller Dokumentvolltextindex.
- `api/options_users.php`, `api/chat/search_users.php`, `search_projects.php`, `search_companies.php`: Auswahl-/Suchdaten für Benutzer, Projekte, Firmen; eigene Session-/Scope-Prüfungen.
- Konto-/Miet-/Bestandsseiten besitzen separate Filter nach Zeitraum, Projekt, Wohnung, Text, Betrag oder Typ.

### 13.3 Modals und Frontendverträge

`pages/pendenzen.php` enthält Bearbeitungs-, Vorschau-, Listenlayout-, PDF-/Protokoll- und Medienmodals; `pendenz_neu.php?embed=1` kann als eingebettetes Formular erscheinen. Planpins/-zeichnungen verwenden Canvas-/Pointerlogik und Snapshots. Weitere Modals für Benutzer, Mieterzuweisung, Listen-/PDF-Designer und Gantt-Spalten sind lokal implementiert.

Beteiligte JS-Dateien: `assets/js/pendenzen_list.js`, `pendenzen_inline_create.js`, `pendenzen_cols_order.js`, `listen_settings.js`, `projekte.js`, `projekt_baum.js`, `benutzer.js`, `app.js`; erhebliche Logik liegt zusätzlich inline. Endpunkte liefern uneinheitlich `{ok}`, `{success}`, Plaintext, HTML-Fragmente oder Weiterleitungen. Fehlerunterdrückung und bereits ausgegebener Header/Navigation können JSON-Antworten verfälschen. Ein Modal oder ausgeblendeter Button ist keine serverseitige Zugriffskontrolle.

## 14. Chat sowie gimi / Co-Pilot

### 14.1 Interner Chat

`pages/chat.php`, `includes/footer.php`, `assets/js/chat*.js` und zwei API-Familien (`api/chat_*.php` und `api/chat/*.php`) bilden Chatseite und Widget. Tabellen: `chat_rooms`, `chat_members`, `chat_messages`, `chat_reads`, `chat_message_recipients`, `chat_typing`, `chat_attachments`, `chat_messages_pendenzen`.

Raummitgliedschaftsprüfungen existieren, beispielsweise `api/chat_messages.php::ensure_member()` und in `api/chat/rooms.php` per JOIN. Die Nachrichtenschemata unterscheiden sich: ältere Wege erwarten `sender_id`/`message_text`, andere passen `user_id`/`body` dynamisch an. `api/chat_send.php` ruft `csrf_validate_or_throw()` auf; der eingebundene zentrale CSRF-Helfer stellt diesen Namen nicht bereit. Polling und Lesestände sind vorhanden; `api/chat/rooms.php` berechnet Ungelesen teils als Differenz globaler Nachrichten-IDs, nicht als genaue Anzahl pro Raum.

### 14.2 gimi ist eine aktive Schreibfunktion

`pages/ai_assistant.php` ist die KI-Kommandozentrale; `includes/footer.php` enthält ein zusätzliches gimi-Widget einschließlich Spracheingabe, `assets/js/ai_assistant.js` einen weiteren Client. `api/ai_query.php`, `api/ai_chat_messages.php` und `app/modules/ai/AiService.php` sind der Backendkern.

Tabellen: `ai_chats` (Benutzer, Kontext-URL, Projekt), `ai_messages` (Rolle/Inhalt), `ai_training` (globale/eigene, aktive und URL-bezogene Wissensbausteine), zusätzlich `ai_suggestions` als vorhandener Schemaansatz.

Ablauf:

1. Client sendet Prompt, Kontext-URL und optionale Chat-ID.
2. Kontext-URL wird auf ausgewählte Parameter normalisiert; bei `GIMI_LOAD_CONTEXT` wird der benutzereigene Verlauf geladen.
3. Bestehender Chat wird auf Besitz geprüft, sonst Chat angelegt; Nutzernachricht wird gespeichert.
4. Service lädt globale/eigene Trainingsregeln, baut einen Prompt und ruft Gemini über cURL auf. API lädt zehn Historieneinträge, Service verwendet davon zuletzt drei. Ohne Schlüssel existiert eine Mockantwort; Modell-/Retry-Fallbacks sind implementiert, ihre externe Verfügbarkeit wurde nicht getestet.
5. Antwort wird gespeichert. Enthält sie `[ACTION:CREATE_PENDENZ|...]`, interpretiert der Server diesen Text und erstellt **unmittelbar eine Pendenz** mit Titel, Projekt, Ersteller, offenem Status und Enddatum.

Projekt-ID stammt aus Kontext/Session, letzter Fallback ist Projekt 1. Keine serverseitige Projektmitgliedschaftsprüfung vor diesem INSERT, keine gesonderte Bestätigung, keine strukturierte Toolberechtigung. Modell-/Trainingstext kann deshalb Schreibaktionen beeinflussen. Die ursprüngliche Assistentenantwort wird vor der Umwandlung des Aktionsmarkers gespeichert; gespeicherter und angezeigter Text können auseinanderliegen.

Weitere konkrete Risiken: TLS-Zertifikats-/Hostprüfung im cURL-Aufruf ist abgeschaltet; `getMockResponse()` liest aktive Trainingsdaten ohne denselben Benutzerfilter; `pages/ai_assistant.php` löscht bei `delete_chat` Nachrichten vor einer Besitzbedingung auf der Chatzeile. Der Trainingslöschzweig erlaubt serverseitig auch globale Einträge (`user_id IS NULL`), selbst wenn die UI den Button eingeschränkt zeigt. CSRF ist dort nicht durchgehend vorhanden.

## 15. Mobile Darstellung und Offlinebetrieb des Adminbereichs

Vorhandene mobile Anpassungen sind konkret implementiert, aber nicht visuell/laufzeitgetestet:

- `includes/nav_admin.php`, `nav_benutzer.php`, `nav_superadmin.php`: Top-/Seitennavigation, Burger-/Mobiletrigger, aufklappbare Untermenüs, persistierte Layoutwahl in Cookie/Session/localStorage. Superadmin hat umfangreichere Menüs und eine mobile Aktionsleiste.
- `pages/pendenzen.php`: eigene Breakpoints für Desktop ab 1025 px, Tablet 769–1024 px, Mobil bis 768 px sowie zusätzliche 900-/1024-px-Regeln; `listen_spalten` steuert gerätespezifische Spaltensichtbarkeit/-breiten. Mobile Tabellen-, Formular-/Modal- und Unterschriftsregeln sind vorhanden.
- `includes/quick_capture.php`, `pages/pendenz_neu.php`: Schnellerfassung, Datei-/Bildauswahl, Planzeichnung und eingebettete Ansichten. `includes/footer.php` ergänzt gimi, Chat und Offlineanzeige.
- `pages/terminprogramm.php`: nebeneinander liegende Tabelle/Gantt mit initial 450 px linker Spalte und Splitter; sehr breite Tabellen, horizontales Scrollen und zahlreiche kleine Bedienelemente. Mobile Benutzbarkeit ist damit nicht allein durch generelle CSS-Mediaqueries bewiesen.

Besonders zu prüfen bei einer späteren isolierten Browserabnahme: kleine Viewports, Bildschirmtastatur, Modalscrollen, Fokus/Tab-Reihenfolge, Touch statt Hover, Drag/Resize, lange Tabellenüberschriften, Querformat, Pinzeichnen und gleichzeitig sichtbare Navigations-/Chat-/gimi-Ebenen. Es gibt unterschiedliche z-index-Systeme und viele Inline-Styles; Überlagerungsfehler sind ein konkretes Prüfgebiet, kein hier beobachteter visueller Defekt.

Offline/PWA:

- `manifest.json`, `sw.js`, Registrierung in `includes/footer.php`.
- `sw.js` speichert erfolgreiche dynamische GET-Antworten einschließlich PHP-Seiten. Offline-Fallback nutzt `ignoreSearch: true`; unterschiedliche IDs/Filter können dadurch dieselbe gecachte Seite liefern.
- Cache ist nicht nach angemeldetem Benutzer getrennt. Logout entfernt diesen Cache nicht. Private Inhalte können bei Benutzerwechsel auf demselben Gerät offline weiter sichtbar sein.
- `assets/js/offline_sync.js` nutzt IndexedDB `PendenzOfflineDB_v3`, Store `syncQueue`, speichert Formulardaten/Dateien und sendet sie später erneut. Die Queue enthält keinen durchgängigen Benutzer-/Mandantenvertrag oder serverseitigen Idempotenznachweis. Wiederholung nach unklarer Antwort kann Duplikate erzeugen; eine Login-HTML-Antwort ist kein sicherer Speichernachweis.
- Browserqueue und Service Worker sind keine zuverlässige serverseitige Erinnerungs-/Drive-Synchronisation.

## 16. Sicherheitsbefunde mit Priorität

Keine Exploits wurden ausgeführt. Die folgenden Aussagen sind Codebefunde mit erläutertem Wirkpfad; produktive HTTP-Erreichbarkeit bleibt von der Serverkonfiguration abhängig.

| Priorität | Befund / konkrete Fundstellen | Auswirkung |
|---|---|---|
| Kritisch | `api/benutzer_update.php`, `benutzer_delete.php`: nur Konfigurationsinclude, kein Authgate; Rollenänderung bzw. GET-Löschung | Kontoübernahme/Rechteausweitung und Datenverlust bei Erreichbarkeit. |
| Kritisch | `api/pendenzen_inline_save.php`: angekündigte ACL ist nur Kommentar; `_bootstrap.php` erzwingt nichts | Direkte Aufgaben-/Status-/Zuständigkeits-/Projektänderung ohne Anmeldung. |
| Kritisch | `api/projekt_member_list.php`, `projekt_member_update.php`, `projekt_member_remove.php` | Unberechtigte Anlage/Änderung/Löschung von Projektmitgliedschaften. |
| Kritisch | `pages/pendenzen.php` ab ca. Zeile 40; `pendenz_show.php` ab ca. Zeile 57 | Automatische öffentliche Freigabe plus tokenloser ID-Zugriff auf Detaildaten. Aktuell alle 111 Pendenzen mit den drei Public-/Externflags aktiv. |
| Kritisch | `pages/abnahme_action.php`, `abnahme_save.php`, `wohnungsabnahme_save.php`; Root-/Seiten-Reset-/Migrationsdateien | Schreib-, Upload-, PDF- und Löschfunktionen ohne zentralen Authschutz bzw. gefährliche Wartung im Webbaum. |
| Kritisch | `pages/abnahme.php:8–12`: ungecastete GET-Parameter `projekt_id`/`unit_id` werden in SQL eingesetzt | Konkreter SQL-Injection-Kandidat bereits beim Laden der Abnahmeseite; kein Exploittest durchgeführt. |
| Kritisch | `pages/vertrag_gen.php:8`, `vertrag_save.php:20`: ungeprüfte IDs bzw. Betrags-/Datumswerte in SQL, kein Authgate | Mietdaten können über einen direkten Request geändert werden; SQL-Injection-Kandidaten. Die spätere PDF-Erzeugung schützt das vorangehende UPDATE nicht. |
| Hoch | `pages/mieterspiegel.php:116`, `:203`, `:296`; `pages/ajax_unit_rooms.php` | Angemeldete Benutzer können ohne Projekt-/Aktionsberechtigung und CSRF weitreichend ändern/löschen; unparametrisiertes Mietbeginn-Datum und fehlende Transaktion in der Löschkette. |
| Hoch | `tools/konto_verwaltung/index.php:77`, `:103`, Rollenabbruch erst `:142` | Zwei Konto-/Buchungsaktionen werden bereits vor der Superadminprüfung ausgeführt. Spätere Batch-/Löschzweige sind dagegen durch diese Prüfung geschützt. |
| Hoch | `pages/interessent_invite_save.php`; `interessenten_form_public.php:47` | Unauthentifizierte Benutzer-/Interessentenanlage; tokenbasierter Dokumentupload ohne serverseitige Endungs-/MIME-/Größenliste. Ausführbarkeit hochgeladener Dateien hängt vom Speicherort und Webserver ab. |
| Hoch | `pages/pendenzen.php`, `projekte.php`, `terminprogramm.php`, `api/pendenzen_list.php`, `pendenzen_list_pdf.php` | Login schützt Einstieg, aber nicht Daten anderer Projekte/Firmen; UI-Filter sind keine Autorisierung. |
| Hoch | `sw.js` dynamischer Cache + `ignoreSearch`; `logout.php` ohne Cachebereinigung | Offenlegung/verwechselte Anzeige interner Daten im Offlinebetrieb und bei Benutzerwechsel. |
| Hoch | `api/ai_query.php` Antwortmarker → INSERT; `AiService.php` TLS-Prüfung aus | Unkontrollierte KI-Schreibaktion, fehlender Projekt-Scope, unsichere Transportverifikation. |
| Hoch | `pages/ai_assistant.php` Chat-/Trainingslöschung | Fremde Nachrichten/globales Wissen sind serverseitig nicht so geschützt wie die UI suggeriert. |
| Hoch | `includes/fs.php::sync_project_folders` mit Prune, `pages/mieterspiegel.php` | Ein nicht verfügbarer Ordner kann als Löschanlass für DB-Einheiten behandelt werden. |
| Hoch | `api/dirlist.php`, alternative Upload-/Pfadwege, direkte `uploads/`-URLs | Zu breiter Dateisystemeinblick; private Dokumente nicht allgemein über ACL geschützt; MIME-/Pfadprüfungen uneinheitlich. |
| Hoch | Fehlende CSRF in zahlreichen Schreibwegen und GET-Mutationen | Aktionen können außerhalb der vorgesehenen Oberfläche ausgelöst werden; SameSite=Lax ersetzt keine Aktionsprüfung. |
| Hoch | `login.php` Klartextkompatibilität, API-Initialpasswort, Resetrollen-/Hashlogik | Uneinheitliche Passwortsicherheit und problematischer Konto-/Resetlebenszyklus. |
| Mittel–hoch | `config.php` auch im Onlinezweig mit Fehleranzeige; Debug-/Info-/SQL-/Kopiedateien im Webbaum | Schema-, Pfad-, Diagnose- und ggf. Konfigurationsinformationen können offengelegt werden. |
| Mittel–hoch | `includes/csp.php` mit `*`, `unsafe-inline`, `unsafe-eval` | CSP ist sehr locker; sie kompensiert HTML-/JS-Injection nicht. Konkrete XSS-Ausnutzung wurde nicht getestet. |
| Mittel | `api/listen_save.php`, globale PDF-Settings | Normale Benutzer können fremde Profile bzw. gemeinsame Ausgabe beeinflussen. |
| Mittel | `pages/pendenz_pdf.php` protokolliert Public-Token; `login_debug.log` im Root | Zusätzliche vertrauliche Metadaten/Token in Logs; `logs/.htaccess` schützt nicht automatisch Rootlogs. |
| Mittel | Mehrere Sessionstarter und rohe/simulierte Rollen | Unterschiedliche Cookie-/Sperr-/Rollenwirkung je Einstiegsroute. |
| Mittel | Fehlende Transaktionen/Auditabdeckung und divergierende Soft-Deletefilter | Teilzustände, nicht nachvollziehbare Änderungen, Export-/UI-Differenzen. |

Vorhandene Schutzmaßnahmen werden ausdrücklich anerkannt: Prepared Statements an vielen Stellen, Passwort-Hashprüfung, Session-ID-Regeneration, CSRF-Implementierungen, zentrale ACL-Helfer, besitzgeschützte KI-Verläufe, raumbezogene Chatprüfungen, Dateipfadguard in `pages/file.php`, Admin-/Superadmin-Gates in mehreren Verwaltungsmodulen und Schutzdateien unter `logs`/`uploads`/`api`. Ihr Hauptproblem ist die ungleichmäßige Anwendung.

## 17. Teilfertige, versteckte und derzeit nicht belegte Funktionen

| Funktion / Datei | Tatsächlicher Fertigstellungsbefund |
|---|---|
| `pages/protokoll_manager.php`, `pages/audit.php` | Menüziele fehlen im aktuellen Hauptbaum. |
| `pages/project_storage.php` | Ruft `require_login()` auf, ohne Authhelper im eigenen Includegraph bereitzustellen. |
| `pages/ordner_vorlagen.php` | Erwartet `ordner_vorlagen_nodes.name`, aktuelle Tabelle hat stattdessen u. a. `rel_path`, `is_dir`, `sort`. |
| `pages/storage_manager.php` | Query auf `root_path,storage_root`; `storage_root` fehlt, Fehler wird gefangen und lokaler Fallback gewählt. Damit kann ein anderer Ablageort entstehen. |
| `pages/pendenz_review.php` | Projektstruktur statt Pendenzreview; ID-Semantik und Wohnungsbildfeld passen nicht. |
| Workflowhelper | `status_workflow` fehlt; keine vollständige Integration in aktuelle Statusupdates. |
| `pages/pendenz_response.php` | Fehlende Historytabelle; Uploaderfeld widerspricht Schema. |
| `api/pendenzen_get.php`, `pendenzen_delete.php` | PDO-Vertrag passt nicht zu aktuellem Bootstrap; `deleted_by` fehlt. |
| `api/pendenzen_save.php` | Erwartet u. a. `prioritaet`/`zugewiesen_an`, die im aktuellen Pendenzschema fehlen. |
| Passwortreset / alternative Einladung | `user_tokens` und `benutzer.must_change_password` fehlen. |
| `tools/mietkontrolle/index.php` | Zwei erwartete Miettabellen fehlen; die lokale Helperdefinition allein belegt keinen Laufzeitabbruch. |
| `tools/mieterspiegel/index.php` | `wohnungen.label` und `wohnungen.liegenschaft_id` fehlen; Abfrage bei gewähltem Projekt steht vor den Schreibaktionen. |
| `tools/konto_verwaltung/export.php`, `konto_verwaltung.php` | Leere Dateien, jeweils 0 Byte. Kein implementierter Export bzw. zweiter Controller. |
| `pages/ajax_unit_applicants.php`, `ajax_applicants.php` | Erster Weg erwartet `interessenten.created_at` statt `erstellt_am`; zweiter Weg erwartet die fehlende Tabelle `miet_interessenten`. |
| `pages/interessenten_form_public.php` | Schreibt Status `eingereicht`, der im aktuellen ENUM nicht enthalten ist; Linkablauf wird textlich erwähnt, aber nicht geprüft. |
| `pages/vertrag_save.php` | Fehlender Fallbackordner `uploads/contracts`, ungeeigneter `realpath()`-Neuanlagepfad, fehlendes `obj_name` im SELECT, JSON-Header trotz HTML-Ausgabe. |
| `pages/wohnung_edit.php` | Beabsichtigt fehlende Mietzinshistorie und weitere Spalten automatisch beim Laden anzulegen. Funktionalität ist schemaabhängig. |
| `pages/dashboard.php`, `pages/login.php`, `public/index.php`, `pages/projekt_bearbeiten.php`, `pages/teams.php` | Alte Architektur-/Helper-/Rollenerwartungen; nicht gleichwertig mit den aktuellen Hauptwegen. |
| `api/chat_send.php` | Erwartet nicht bereitgestellten CSRF-Funktionsnamen und anderes Nachrichtenschema. |
| `send_now` | Erfassungswege speichern den Schalter, automatischer Versand dort nicht belegt. |
| `permissions_json`, `ai_suggestions`, generische SmartTables | Vorhandene Spalten/Tabellen/Fragmente ohne nachgewiesenen durchgängigen aktiven Systemvertrag. |
| `admin_matrix_hub.php`, Ordner-Vorlagen, `files_master.php`, `portfolio_master.php` | Zusätzliche/direkt aufrufbare Verwaltungswege; nicht überall prominent im aktuellen Menü. |
| gimi-Mock, Spracheingabe, Public-Rückmeldung, ICS, Offlinequeue, Rollensimulation | Tatsächlich implementierte Nebenfunktionen, die eine reine Menüinventur übersehen würde. |

„Nicht belegt“ bedeutet weder sicher ungenutzt noch gefahrlos löschbar. Externe Links, gespeicherte Browser-URLs, dynamische Navigation und Betriebsjobs können Dateien verwenden. Historische Kopien sind technisch eigene PHP-Routenkandidaten.

## 18. Doppelter Code und kritische Änderungsstellen

### 18.1 Duplikation

Viele Sicherungen liegen innerhalb des Anwendungsbaums: `pages/*Kopie*.php`, `pages/pages per 07.05.2026/`, `pages/aus homepage/`, `includes/Aus gesicherte dateien/`, `Absicherung vor löschen/`, `api/aus web/`, `assets/js/sicherung/`. Nicht alle Kopien sind bytegleich; ähnliche Dateinamen können abweichende Rechte/Schreibwege enthalten.

Konkrete Parallelimplementierungen:

- Pendenzformulare/-uploads/-Textkombination in `pages/pendenzen.php`, `pendenz_neu.php`, `ajax_quick_pendenz.php` und zahlreichen Varianten.
- Status-/Terminkalkulation in Formularen, Gantt-APIs, Service und Workflowhelpern.
- Cover/Löschen in Hauptseite, Detailseite, `api/pendenz_media.php`, `pendenz_set_cover.php`.
- Zwei Login-/Dashboardfamilien und mehrere Einladungs-/Passworttokenmodelle.
- Mehrere Chat-JS-/API-Familien und gimi-Clients.
- `h/e/url/site_prefix`, Tabellen-/Spaltenprüfungen, Sessionstarter und CSRF-Varianten in vielen Dateien.
- `includes/pendenz_workflow.php` und `pages/pendenz_work.php`; `api/migrate_lists.php`/`migrate_lists2.php`; `includes/authz.php`/Kopie sind konkrete identische Kandidaten, siehe aktueller Hashanhang.
- Datenmodellduplikate: `benutzer`/`users`, `firmen`/`firma`, `raeume`/`zimmer`, mehrere Miet-/Ablage-/Benachrichtigungstabellen.

### 18.2 Besonders kritische Dateien bei späteren Änderungen

| Datei / Gruppe | Warum kritisch |
|---|---|
| `config.php` | DB, Session, URL, Fehleranzeige, Umgebung; nahezu jeder Request betroffen. |
| `includes/auth.php` | Loginrouting, Sperrung, Simulation, globale Rollen, Projekt- und Pendenzrechte. |
| `includes/functions.php`, `db.php`, `url_helpers.php` | Gemeinsame Helfer, Upload-/URL-/DB-Verträge; gleichnamige Fallbacks können Verhalten ändern. |
| `api/bootstrap.php`, `_bootstrap.php` | Uneinheitliche Fehler-/Session-/DB-/Authverträge; ein Include allein schützt keinen Endpoint. |
| `pages/pendenzen.php` | Hauptliste plus SQL, DDL, Publicfreigabe, Schreiben, UI und Exportanbindung in einer Datei. |
| `pages/pendenz_neu.php`, `ajax_quick_pendenz.php` | Anlage/Bearbeitung, Vorlagen-/Empfängerlogik und Medien; Änderungen müssen mehrere Schreibwege berücksichtigen. |
| `api/pendenzen_inline_save.php`, `gantt_task_create.php` | Status, Verantwortliche, Kontext und abhängige Termine. |
| `includes/fs.php`, `user_folder_automation.php`, `pages/mieterspiegel.php` | Dateisystem und DB werden gekoppelt verändert; Umbenennen/Prune kann Bestand betreffen. |
| `pages/benutzer.php`, `profil.php`, `api/benutzer_*.php` | Rollen, Kontakt-/Firmen-/Personendaten, Token und Benutzerlebenszyklus. |
| `pages/wohnung_edit.php`, `mieter_zuweisen.php` | Räume, Wohnungen, Mieter, Dokumente, Verträge und Schemaänderungen. |
| `pages/vertrag_save.php`, `interessent_invite_save.php`, `interessenten_form_public.php` | Verknüpfen Mietdaten, Benutzeranlage, öffentliche Tokens und private Dokumentablage ohne einheitliche Guards. |
| `tools/konto_verwaltung/index.php`, `import.php`, `tools/mieterspiegel/index.php` | Rollenprüfung an unterschiedlicher Stelle; `liegenschaft_id` wird widersprüchlich als Projekt- oder Objekt-ID verwendet. |
| `includes/pendenz_workflow.php`, `audit.php`, `mailer.php` | Ereignisse, Verlauf, Benachrichtigung und Versand; derzeit nicht universell eingebunden. |
| `pages/pendenz_show.php`, `pendenz_public.php`, `pendenz_pdf.php` | Interne/öffentliche Sichtbarkeit und Medien-/Reportausgabe greifen ineinander. |
| `includes/header.php`, `footer.php`, `nav_*.php`, `sw.js` | Gesamtlayout, CSP, mobile Navigation, Chat/gimi, Cache-/Offlineverhalten. |
| `api/ai_query.php`, `app/modules/ai/AiService.php` | Modellkommunikation plus echte Geschäftsdatenschreibaktion. |
| `database/schema.sql`, `sql/`, Rootmigrationen | Kein verlässlich kanonischer Neubaupfad; unkoordinierte Ausführung kann dem aktuellen Schema widersprechen. |

## 19. Datenbankbefunde und Beziehungen

Die aktuellen Metadaten umfassen 138 Tabellen, 122 FK-Spaltenbeziehungen und 502 Indexspalteneinträge (letzteres ist **nicht** die Anzahl eigenständiger Indizes). Vollständige Tabellen-/Spaltenübersicht und FKs folgen im Anhang.

Aktuell gezählte Kerndaten: 11 Benutzer, 12 Projekte, 12 Objekte, 31 Wohnungen, 201 Räume und 111 Pendenzen. Diese lokalen Zahlen sind kein Beleg für denselben Livebestand. Frühere Unterlagen mit 18 Benutzern und 46 Pendenzen dürfen nicht ungeprüft übernommen werden.

Wichtige echte Fremdschlüssel:

- `objekte.projekt_id → projekte.id`; `wohnungen.objekt_id → objekte.id`.
- `pendenzen.projekt_id → projekte.id`, `wohnung_id → wohnungen.id`, `raum_id → raeume.id`, `bkp_id → bkp_codes.id`, `art_id → pendenzen_arten.id`.
- `pendenzen.erstellt_von`, `zustaendig_id`, alternative Ersteller-/Empfängerfelder → `benutzer`; `empfaenger_profil_id → benutzer_profile`.
- `pendenzen.ordner_id → pendenz_ordner.id`.
- `pendenz_dateien.pendenz_id`, `pendenz_anhaenge.pendenz_id`, `anhaenge.pendenz_id`, `pendenz_acl.pendenz_id → pendenzen.id`.
- `projekt_mitglieder` verweist auf Projekt, Benutzer und Hinzufügenden.

Nicht durch entsprechende FKs abgesichert sind u. a. `pendenzen.mandant_id`, `objekt_id`, `vorgangsart_id`, `plan_id` und die Zeichenkette `vorgaenger_id`. Gerade `art_id` besitzt einen FK, während der häufig aktive Schreib-/Joinweg `vorgangsart_id` verwendet. Sichtbare Dropdownbeziehungen sind daher nicht stets DB-Constraints.

Relevante aktuelle Abweichungen: `status_workflow`, `prioritaet`, `zugewiesen_an`, `deleted_by` fehlen in `pendenzen`; `storage_root` fehlt in `projekte`; `name` fehlt in `ordner_vorlagen_nodes`; `bild`/`bezeichnung` fehlen in `wohnungen`; `user_tokens`, `pendenz_history`, `wohnung_mietverhaeltnis`, `wohnung_miet_override`, `wohnung_mietzins_historie` fehlen als Tabellen. Alt-SQL enthält zusätzliche/andere Modelle und darf nicht als exakte Beschreibung dieses Bestands gelesen werden.

## 20. Verifikation und offene Laufzeitgrenzen

Frisch durchgeführte Syntaxprüfung: 835 PHP-Dateien außerhalb Vendor/Uploads/Storage/Logs mit `php -n -l`; 834 ohne Syntaxfehler, ein Parserfehler in `migration_online_columns.php:158` (unerwartetes `]`). Dieser Befund wurde nicht repariert. Syntaxfreiheit bestätigt weder auflösbare Funktionen/Includes noch passende Datenbankspalten oder funktionsfähige Rechte.

Die Analyse hat bewusst keine Benutzeraktionen, SQL-Migrationen, Reparaturen, Logins, Uploads, E-Mails oder PDF-Erzeugung ausgelöst. Die Datenbankprüfung lief nur mit separater Read-only-Transaktion; Schema-/Event-/Triggerbefunde wurden frisch erhoben. Vorhandene Quell- und Dokumentdateien wurden für einen abschließenden Hashvergleich erfasst. Die folgenden Anhänge wurden aus aktuellen Quellmetadaten und der Read-only-Schemaerhebung erstellt; Suchmarker werden ausdrücklich nicht als bewiesene Berechtigung bezeichnet.

Abschließender Inhaltsvergleich: Die 1.238 bereits vorhandenen Dateien außerhalb `vendor/`, `uploads/`, `storage/`, `logs/` und `.git/` haben denselben aggregierten SHA-256-Wert wie vor der Dokumenterstellung (`4485B3F6ABD639D27364C1408B618D8BA4BE3B2181FA594D7A0186910C5DD65A`). Diese Messung umfasst auch die bestehenden Dokumente. Die ausgenommenen Verzeichnisse wurden nicht schreibend bearbeitet; sie sind nicht Bestandteil dieser Hashbestätigung. Neu erstellt wurde ausschließlich `PROJECT_ANALYSIS.md`.

Offen bleiben eine isolierte funktionale Rollen-/Aktionsprüfung, visuelle mobile Abnahme, Test der tatsächlichen SMTP-/KI-/Driveverbindung, Prüfung externer Aufgabenplanung und Abgleich mit dem produktiven Deployment. Wegen nachgewiesener GET-Seiteneffekte wären diese Prüfungen am unveränderten produktiven Bestand nicht rein lesend. Dieser Bericht behauptet deshalb keine vollständige Funktionsfähigkeit oder bestandene Sicherheitsabnahme.

## 21. Vertiefte Prüfung: Mietwesen, Konten und angrenzende Adminaktionen

Dieser Abschnitt ergänzt die systemweite Analyse um die vollständiger nachverfolgten Miet-/Kontoabläufe. Grundlage sind der aktuelle Quelltext, die zuvor rein lesend erhobenen Schemadaten und die tatsächlichen Frontendaufrufe. Keine dieser Aktionen wurde ausgelöst. Ein vorhandener Schreibzweig ist nicht gleichbedeutend mit einem fehlerfrei durchlaufenden Gesamtprozess.

### 21.1 Aktionskarte und Einbindung in die Oberfläche

| Einstieg / Aktion | Daten und Dateien | Tatsächliche Absicherung / Einordnung |
|---|---|---|
| `pages/mieterspiegel.php`: Projektwahl, Tabelle, Einheitsdashboard | `projekte`, `objekte`, `wohnungen`, `wohnung_mieter`, `benutzer`, `kontakte`; Projektroot und Wohnungsordner | `require_login()` in Zeile 9; Projektwahl aus allen Projekten. Benutzerliste ebenfalls global. Kein Benutzer-/Projektberechtigungsfilter. |
| `add_unit`, `fast_update`, `save_unit_dash`, `save_unit_specs` in derselben Seite | Anlage/Änderung von `wohnungen`, teilweise `wohnung_mieter`; `includes/fs.php::ensure_unit_folder()` | POST ohne CSRF-/Rollen-/Projektprüfung; übergebene Objekt-/Wohnungs-IDs werden nicht an einen berechtigten Projektkontext gebunden. |
| `assign_tenant`, `move_to_history` | `wohnung_mieter`, `benutzer.mieter_phase`; Pool-/Mieter-/Vormieterordner | Nur Seitengate. Status- und Dateisystemänderungen sind nicht atomar. |
| `sync_fs`, `sync`, `sync_single`, `create_missing_folders` | `projekte`, `objekte`, `wohnungen`, Mietdaten über Scanhelfer; reale Ordner | Nur Seitengate; Synchronisation kann anlegen, umbenennen und mit Prune löschen. |
| `delete_unit`, `delete_unit_force`, `delete_applicant` | Wohnungsabhängigkeiten bzw. `interessenten`/optionale `miet_interessenten` | Nur Seitengate; beide Wohnungs-Löschnamen führen in denselben Handler. Einzel-IDs werden ohne Scope verwendet. |
| `pages/mieter_zuweisen.php`: `guess_from_path`, `save_zuordnung`, `move_to_vormieter`, `delete_zuordnung` | `projekt_verknuepfungen`, `fs_nodes`, `projekte`; per FK gewählte Objekt-/Einheitstabellen; Personen aus `benutzer` bevorzugt | Login; POST mit `csrf_validate()` in Zeile 401. Keine entsprechende Rechteprüfung am Projekt oder der Zuweisungs-ID. |
| `pages/ajax_unit_rooms.php`: Laden, `add_room`, `delete_room`, `add_template`, `delete_template` | `raeume`, `raum_vorlagen`, `includes/room_taxonomy.php` | Prüft `is_logged_in()`, nicht zentrale Kontosperre/Projekt-/Adminrecht; kein CSRF. |
| `pages/vertrag_gen.php` → `pages/vertrag_save.php` | `wohnungen` → `objekte` → `projekte`, `wohnung_mieter`; HTML-Vorlage und PDF | Kein Auth-/CSRF-/Projektgate im Includegraph; Speichern verlangt serverseitig nicht einmal POST. |
| `pages/interessent_invite_save.php` → `pages/interessenten_form_public.php` | `benutzer`, `interessenten`, `projekte.root_path`; Dokumente im Interessentenpool | Linkerzeugung ohne Authgate. Öffentlicher Formularzugriff über Token, ohne geprüfte Ablaufzeit. |
| `tools/konto_verwaltung/index.php`: `kv_save_konto_mapping`, `kv_new_booking` | `kv_konten`, `liegenschafts_konto`; Kontext aus Request | Login + CSRF. **Ausführung vor** späterem Superadminabbruch. |
| Derselbe Controller: `set_cat`, `assign_proj_whg`, `delete_rows`, `apply_assignment` | `liegenschafts_konto` | Hier greift bereits die Superadminprüfung; CSRF gilt auch für diesen zweiten POST-Block. |
| `tools/konto_verwaltung/import.php`: Vorschau, Bestätigung, Verwerfen | Session-Vorschau; bestätigte Zeilen in `liegenschafts_konto` | Login + Superadmin; kein CSRF-Gate und keine atomare Importtransaktion. |
| `tools/mieterspiegel/index.php` | `miete_schedules`, `miete_adjustments`, `liegenschafts_konto`, `projekte`, erwartete Wohnungsfelder | Login + Superadmin; Schemafehler bei Projektwahl. |
| `tools/mietkontrolle/index.php` | Erwartet `wohnung_mietverhaeltnis`, `wohnung_miet_override`; Istwerte aus `liegenschafts_konto` | Login + Admin/Superadmin; die beiden Miettabellen fehlen lokal. |

Die Vertrags-, Einladungs- und Interessentenendpunkte sind nicht bloß nach Dateinamen vermutete Funktionen: `pages/mieterspiegel.php:1163` verlinkt `vertrag_gen.php`; der Einladungsdialog ruft ab Zeile 1549 `interessent_invite_save.php` auf; der Einheitsdialog lädt ab Zeile 1747 `ajax_unit_applicants.php`. Raumaktionen rufen `ajax_unit_rooms.php` auf. Damit gehören diese zusätzlichen Routen zum tatsächlich verdrahteten Adminablauf.

### 21.2 Zwei unterschiedliche Mieterzuweisungen und ihre Folgen

**Wohnungsbezogener Weg:** `pages/mieterspiegel.php:288` beendet bisherige aktive Zeilen in `wohnung_mieter`, ermittelt die Person aus `benutzer`, verschiebt gegebenenfalls einen Interessentenpool-Ordner, setzt `benutzer.mieter_phase='mieter'` und legt eine neue aktive Zeile in `wohnung_mieter` an. Der Datensatz enthält Wohnungs-/Benutzer-ID, Namen, Netto, Nebenkosten, Rolle `mieter`, Startdatum und Status. Der Endtermin der vorherigen Zeilen wird auf den eingereichten Beginn gesetzt, ohne Abzug eines Tages. Es wird nicht geprüft, ob die übergebene Wohnung zum angezeigten Projekt gehört.

Das Datum `beginn` wird in Zeile 296 direkt in das erste UPDATE eingesetzt. Der spätere INSERT nutzt zwar ein Prepared Statement, beseitigt aber die SQL-Injection-Stelle im vorherigen UPDATE nicht. Vorheriges Mietverhältnis, Benutzerphase, neue Zeile und Ordnerbewegung werden nicht in einer gemeinsamen Transaktion oder einem kompensierenden Ablauf gesichert.

`fast_update` kann Netto/Nebenkosten für **alle** aktiven Mietzeilen einer Wohnung ändern. Fehlt eine aktive Zeile, legt der Code eine solche ohne konkrete Benutzerzuweisung an (`pages/mieterspiegel.php:179`). Damit kann eine aktive Mietzeile auch allein zur Speicherung von Beträgen entstehen.

Die Historisierung ab Zeile 354 übernimmt eine `wohnung_mieter.id` unter dem alten Parameternamen `pv_id`. Sie sucht den **ersten** Ordner mit Präfix `Mieter_`, ohne ihn anhand der konkreten Person zu identifizieren. Bei mehreren solchen Ordnern kann somit ein anderer Ordner als die ausgewählte Mietzeile verschoben werden. Fehlt ein Ordner, wird nur die DB-Zeile historisiert. Der Pfadaufbau nutzt außerdem den aktuellen Seitenprojektkontext, während die Mietzeile nur anhand ihrer ID geladen wird.

**Ordnerbezogener Weg:** `pages/mieter_zuweisen.php:79` ermittelt die Tabellen für Objekt/Liegenschaft und Einheit über die realen FKs von `projekt_verknuepfungen`. Im aktuellen Schema ist dies:

| Feld | Tatsächliches FK-Ziel | Im untersuchten Ablauf |
|---|---|---|
| `projekt_verknuepfungen.projekt_id` | `projekte.id` | Gewähltes Projekt und Dateisystemroot. |
| `projekt_verknuepfungen.liegenschaft_id` | `liegenschaften.id` | Pfad-/Namensheuristik, nicht die Haupttabelle `objekte`. |
| `projekt_verknuepfungen.einheit_id` | `vermietungseinheiten.id` | Pfad-/Namensheuristik, nicht die Haupttabelle `wohnungen`. |
| `projekt_verknuepfungen.mieter_id` | `mieter.id` | Personenauswahl bevorzugt dennoch `benutzer.id` (`:327–340`). |
| `projekt_verknuepfungen.ordner_id` | `ordner.id` | Beim Speichern ausdrücklich NULL; der Pfad wird separat gespeichert. |

Die Tabelle `liegenschaften` besitzt lokal nur `id` und `name`, keinen Projekt-FK. Deshalb lädt die Heuristik hier die Liegenschaften global. Bei fehlendem Namensmatch kann sie die erste Liegenschaft verwenden; bei einer einzigen Einheitsoption existiert ebenfalls ein automatischer Fallback. Diese Existenz-/Namensprüfungen ersetzen weder eine fachlich eindeutige Zuordnung noch eine Benutzerberechtigung.

Bei Personen ist die Abweichung besonders konkret: Eine `benutzer.id` wird als `mieter_id` gespeichert, deren FK aber auf `mieter.id` zeigt. Fehlt dort dieselbe Zahl, kann der INSERT am FK scheitern; existiert sie für eine andere Person, kann eine fachlich falsche Verknüpfung entstehen. Ein Mapping zwischen den beiden Personentabellen wird in diesem Speicherzweig nicht vorgenommen.

`save_zuordnung` erstellt zunächst `<Einheit>/Mieter/<Name>` und scannt gegebenenfalls `fs_nodes`, bevor der INSERT in `projekt_verknuepfungen` erfolgt (`:436–472`). Ein späterer DB-Fehler macht die Ordneranlage nicht rückgängig. `move_to_vormieter` verschiebt den Ordner und aktualisiert danach die Zuordnung; `delete_zuordnung` löscht nur die Zuordnungszeile. **Dieser Weg schreibt keine korrespondierende `wohnung_mieter`-Zeile.** Einträge aus den beiden Oberflächen erscheinen deshalb nicht automatisch in denselben Mietansichten oder Vertragsabfragen.

### 21.3 Mietvertrag: Änderung, PDF und Speicherpfad

Der vollständige vorhandene Ablauf lautet:

1. `pages/vertrag_gen.php:8–9` lädt die Wohnung über Objekt und Projekt sowie eine aktive Mietzeile. Die Request-ID `unit_id` wird ungecastet in SQL eingesetzt. `projekt_id` wird nicht zur Absicherung der abgefragten Wohnung verwendet.
2. Das Formular übernimmt Name, Netto und Nebenkosten. Beim Beginn liest es `wohnung_mieter.move_in`; diese Spalte existiert lokal nicht. Das aktuelle Modell verwendet `startdatum`, weshalb der Formularfallback das heutige Datum anzeigt (`:51`).
3. `pages/vertrag_save.php:20` aktualisiert **zuerst** aktive `wohnung_mieter`-Zeilen. Beträge, Datum und Wohnungs-ID werden direkt in SQL eingesetzt; nur der Mietername wird dort escaped. Ohne POST-Werte greifen Defaults wie Netto/NK 0 und Name `N/A`. Mangels Methodengate kann daher bereits ein GET mit Wohnungs-ID diesen Updatezweig erreichen.
4. Erst danach werden Wohnung und `templates/mietvertrag_template.html` geladen. Die Vorlage existiert lokal. Fehlende Vorlage, fehlerhafte PDF-Erzeugung oder ein Speicherfehler rollen das vorherige Mietdaten-UPDATE nicht zurück.
5. Platzhalter werden per `str_replace()` gefüllt; Vermieteradresse und Ort sind fest codierte Musterdaten. Benutzer-/DB-Texte werden vor der HTML-Ersetzung nicht allgemein HTML-escaped. Dompdf erhält `isRemoteEnabled=true` (`:58`). Daraus ergibt sich ein Risiko für fremde HTML-/Ressourceneinbindung; konkrete Netzwerkzugriffe wurden nicht getestet.
6. Der PDF-Zielpfad wird aus Projektroot des PHP-Verzeichnisses, **Projektname**, einem erwarteten `obj_name`, `Wohnungen` und `folder_name` zusammengesetzt (`:66`). Die Abfrage in Zeile 23 selektiert `obj_name` jedoch nicht. Dieser Weg verwendet nicht den konfigurierten `projekte.root_path` und nicht den zentralen sicheren Datei-Download.
7. Fallback ist `realpath('../uploads/contracts')`. Dieser Ordner fehlt lokal. `realpath()` liefert für einen nicht vorhandenen Ordner keinen gültigen Neuanlagepfad; das anschließende `mkdir()` arbeitet somit nicht mit dem beabsichtigten Pfad. Eine erfolgreiche Ablage im Fallback ist aktuell nicht belegt.
8. Der Dateiname ersetzt im Mieternamen nur Leerzeichen, nicht allgemein Pfadseparatoren; er enthält lediglich das Tagesdatum. Der Code prüft den Rückgabewert von `file_put_contents()` nicht. Mehrere Verträge für denselben Namen am selben Tag nutzen denselben Dateinamen, statt eine Version anzulegen.
9. Es folgt eine HTML-Erfolgsseite mit direktem Link, obwohl Zeile 9 `Content-Type: application/json` gesetzt hat. Kein INSERT in `wohnung_dokumente`, `documents`, `mietvertraege` oder ein anderes PDF-Register gehört zu diesem Speicherzweig.

Dieser Prozess ist somit zugleich Mietdateneditor und Dateierzeuger, mit mehreren nachgelagerten Fehlerstellen. Die Erfolgsmeldung ist kein verlässlicher Nachweis einer geschriebenen und registrierten Vertragsdatei.

### 21.4 Interessenten: Linkerzeugung, Status, Upload und AJAX

`pages/interessent_invite_save.php` benötigt keine Anmeldung. Es sucht einen Benutzer anhand der E-Mail, legt andernfalls einen Benutzer mit Rolle `gast` an, erzeugt einen zufälligen Token, schreibt `interessenten` und versucht, unter dem gewählten Projektroot `00_Pool/Interessenten/<Name>_<Benutzer-ID>` anzulegen. Projekt und Wohnung werden nicht als zusammengehörige und berechtigte Einheit validiert. Datenbank- und Ordneraktionen bilden keine Transaktion.

Die Meldung bezeichnet das neue Konto als „deaktiviert“. Der INSERT in Zeile 25 setzt aber keinen expliziten Sperr-/Deaktivierungswert; er setzt auch kein Passwort. Aus der Meldung folgt deshalb weder ein bestätigter Loginzugang noch eine technisch erzwungene Kontosperre. Der Rückgabewert enthält einen kopierbaren Formularlink. Eine E-Mail wird in dieser Datei nicht versendet.

`pages/interessenten_form_public.php` lädt den Datensatz über den gespeicherten Token und die Wohnungs-/Objekt-/Projektjoins. Es prüft weder eine Ablaufzeit noch einen verbrauchten Zustand und rotiert den Token nach Absenden nicht. Die Fehlermeldung „abgelaufener Link“ beschreibt daher keine tatsächlich vorhandene Ablaufprüfung. Ein gültiger Link ist ein wiederverwendbarer Zugriffsschlüssel auf die Daten dieses Formulars.

Der Upload `betreibungsauszug` wird ab Zeile 47 mit der Dateiendung des ursprünglichen Namens in den Interessentenordner verschoben. Im Servercode fehlen eine Endungs-/MIME-Allowlist und eine eigene Größenprüfung; PHP-/Serverlimits können zusätzlich gelten. Der Upload geschieht vor dem UPDATE und wird nicht in `wohnung_dokumente` registriert. Ein Ordner außerhalb des Webroots und ein ausführbarer Webordner hätten unterschiedliche Auswirkungen; die produktive Erreichbarkeit bzw. Ausführbarkeit wurde nicht getestet.

Der Formularspeicherzweig setzt `interessenten.status='eingereicht'`. Das aktuelle ENUM erlaubt ausschließlich `neu`, `eingeladen`, `abgelehnt`, `angenommen`. Im erhobenen nicht strengen SQL-Modus kann daraus ein leerer ENUM-Wert entstehen; in einer strengeren Umgebung kann die Abfrage scheitern. Eine Erfolgsanzeige belegt somit nicht den beabsichtigten fachlichen Status.

Zwei Interessentenlisten sind getrennt zu bewerten:

- `pages/ajax_unit_applicants.php:17` hat kein Authgate und selektiert Namen, E-Mail und Status anhand der Wohnungs-ID. Es fällt von der fehlenden `miet_interessenten` auf die vorhandene `interessenten` zurück, sortiert dann aber nach `created_at`. Lokal heißt die Datumsspalte `erstellt_am`. **Ein aktuell funktionierender unauthentifizierter Datenabruf wird daher nicht behauptet:** Der fehlende Zugriffsschutz und der Schemafehler bestehen gleichzeitig.
- `pages/ajax_applicants.php:15` verlangt Login, fragt aber ausschließlich die lokal fehlende `miet_interessenten` ab, mit Status `offen`. Dieser alternative Reader besitzt keinen solchen Tabellenfallback und bildet ebenfalls nicht das vorhandene Interessentenmodell ab.

Der Client `pages/mieterspiegel.php::loadApplicants()` erwartet direkt ein Array und setzt Namen/E-Mail per Template-String in `innerHTML` ein. Für diese Werte gibt es im betreffenden Renderzweig kein HTML-Escaping. Das ist eine weitere HTML-Injection-Stelle im vorgesehenen Datenfluss; ihre praktische Ausnutzung wurde nicht geprüft und hängt hier zusätzlich vom funktionierenden Reader ab. Die serverseitige Aktion `delete_applicant` in derselben Seite verwendet dagegen die vorhandene Fallbacktabelle und löscht anhand einer ungescopten ID.

### 21.5 Kontoverwaltung: verspätetes Gate und widersprüchliche IDs

Die Reihenfolge in `tools/konto_verwaltung/index.php` ist sicherheitsrelevant:

1. Login in Zeile 13, danach Header/Navigation, Kontext-/Auswahldaten und CSRF-Include.
2. Bei jedem POST `csrf_require()` in Zeile 72.
3. `kv_save_konto_mapping` ab Zeile 77 und `kv_new_booking` ab Zeile 103 ändern bereits Daten.
4. **Erst in Zeile 142** folgt der Abbruch, wenn die rohe Sessionrolle nicht `superadmin` ist.
5. Danach folgen Schema-Ensure, weitere Filter, Auswertungen und der zweite POST-Block für Batchaktionen ab Zeile 249.

Ein Nicht-Superadmin kann deshalb bei gültigem eigenem CSRF-Token die ersten zwei Schreibzweige erreichen und anschließend trotzdem „Zugriff verweigert“ erhalten. Die sichtbare Fehlermeldung macht einen vorherigen DB-Write nicht rückgängig. Umgekehrt wäre die Aussage falsch, dass die gesamte Kontoliste und alle Löschaktionen für jeden angemeldeten Benutzer freigegeben seien: Die späteren Zweige befinden sich hinter dem Superadmingate.

`kv_new_booking` schreibt abhängig von vorhandenen Spalten Projekt, Objekt, Wohnung und Mieter. Dabei wird `ctx_objekt_id` als `liegenschaft_id` gespeichert (`:123`). Der **tatsächliche FK** lautet aber `liegenschafts_konto.liegenschaft_id → projekte.id`. Der Import und die späteren Projekt-Batchzuweisungen verwenden an dieser Stelle dagegen eine Projekt-ID. Ein Objektwert kann somit am Projekt-FK scheitern oder bei zufällig gleicher ID auf ein anderes Projekt zeigen. Das separat vorhandene `projekt_id` beseitigt diese widersprüchliche Bedeutung nicht.

`kv_save_konto_mapping` verwendet `kv_konten.liegenschaft_id` ebenfalls als Objekt-ID. Ein einheitlicher fachlicher Vertrag über die Bedeutung von „Liegenschaft“ fehlt zwischen den Tabellen und Controllern. Zusätzlich lädt die Kontext-Wohnungsauswahl `wohnungen.bezeichnung` (`:54`); diese Spalte fehlt lokal. Bei gesetztem Objektkontext kann der Ablauf deshalb schon vor dem POST-Handler scheitern. `objekte.bezeichnung` existiert dagegen tatsächlich.

Die späteren, auf Superadmin beschränkten Funktionen sind implementiert:

- `set_cat`: Kategorie für ausgewählte Buchungs-IDs ändern.
- `assign_proj_whg`: ausgewählten Zeilen eine Projekt-ID und/oder ein freies `wohnung_label` zuordnen.
- `delete_rows`: ausgewählte Zeilen aus `liegenschafts_konto` physisch löschen.
- `apply_assignment`: Beschreibungen per LIKE oder REGEXP suchen und gefundene Zeilen zuordnen. Ohne `assign_scope` wird der aktuelle UI-Filter nicht in die Suche übernommen; die Suche kann alle passenden Buchungen betreffen.

Eine gemeinsame Transaktion für mehrzeilige Änderungen ist in diesen Zweigen nicht vorhanden. Freie Wohnungslabels sind nicht mit einem konsistenten Wohnungs-FK gleichzusetzen.

**CSV-Import:** `tools/konto_verwaltung/import.php` prüft Login und Superadmin vor dem Import. Der Upload wird als Zeilenarray plus Dateiname und Projekt-ID in der Session zwischengespeichert. Erwartet werden feste Positionen: Datum in Index 1, Beschreibung in Index 2, Betrag in Index 3. Die Parserlogik verwendet Tabulatoren mit einem Semikolon-Fallback pro einspaltiger Datenzeile. Es gibt kein allgemeines Bankformat-/Spaltenmapping.

Beim Bestätigen werden Zeilen einzeln in `liegenschafts_konto` geschrieben (`:64–91`). Projekt-ID landet in `liegenschaft_id`; Mieter/Eigentümer bleiben NULL. Kategorien werden anhand `md5(beschreibung)` übernommen, sodass gleiche Beschreibungstexte dieselbe Eingabekategorie teilen. Die Betragskonvertierung ersetzt Komma durch Punkt und entfernt Leerzeichen, behandelt aber beispielsweise Schweizer Tausenderapostrophe nicht ausdrücklich. Importqualität für andere Zahlen-/Datumsformate ist nicht nachgewiesen.

Es gibt in diesem Bestätigungsweg keinen CSRF-Aufruf, keine Transaktion und keinen Dateihash-/Zeilen-Deduplizierungsschutz. Die Sessionvorschau wird erst nach der Schleife gelöscht. Bei einem Teilabbruch können bereits gespeicherte Zeilen bestehen bleiben und bei erneuter Bestätigung doppelt geschrieben werden. `kv_import_batches` und `kv_buchungen` werden durch diesen Import nicht befüllt.

Die Dateien `tools/konto_verwaltung/export.php` und `tools/konto_verwaltung/konto_verwaltung.php` sind leer. Ihr Vorhandensein ist kein Nachweis einer zusätzlichen Kontofunktion oder eines CSV-Exports.

### 21.6 Mietsoll, Löschketten und Seiteneffekte beim Lesen

`tools/mieterspiegel/index.php` verwaltet Sollintervalle in `miete_schedules` und Monatskorrekturen in `miete_adjustments`. Beide verweisen über das historisch benannte `liegenschaft_id` auf `projekte.id`; die Wohnung ist ein Textlabel. Vor dem POST-Handler fragt das Tool bei gewähltem Projekt `SELECT label FROM wohnungen WHERE liegenschaft_id=?` ab (`:75`). Beide Wohnungsfelder fehlen im aktuellen Schema. Die Intervall-/Korrekturzweige sind damit vorhandener Code, aber im lokalen Stand nicht als funktionierender Gesamtweg belegt. Beim automatischen Schließen eines vorherigen Intervalls erfolgt UPDATE vor INSERT des neuen Intervalls, ohne gemeinsame Transaktion. POST-CSRF wird nicht erzwungen.

`tools/mietkontrolle/index.php` benutzt nochmals ein anderes Sollmodell: `wohnung_mietverhaeltnis` und `wohnung_miet_override`. Die Istseite summiert `liegenschafts_konto` nach Projekt-ID, Wohnungslabel und Zeitraum. Die zwei Solltabellen fehlen. Weder dieser Weg noch `miete_schedules` werden automatisch durch die oben beschriebenen `wohnung_mieter`-/`projekt_verknuepfungen`-Zuweisungen gepflegt.

Die Löschkette `pages/mieterspiegel.php:203–250` entfernt nacheinander Gegenstände, Abnahmemängel-Verknüpfungen, Zimmer, Abnahmen, Mietverträge/-verhältnisse, Wohnungsbild-/Dokumentzeilen, Interessenten und Wohnungs-Mieterzeilen. Danach entkoppelt sie Pendenzen über `wohnung_id=NULL` und löscht die Wohnung. Nicht alle Schritte sind lediglich FK-Cascades: Es handelt sich um eine explizite Folge unabhängiger SQL-Anweisungen ohne `BEGIN`/`ROLLBACK`. Scheitert ein späterer Schritt, können bereits gelöschte abhängige Daten fehlen, obwohl die Wohnung noch existiert. Dateiinhalte zu gelöschten Dokument-/Bildzeilen werden in diesem Zweig nicht entsprechend entfernt. Die Namen `delete_unit` und `delete_unit_force` stehen nicht für unterschiedlich sichere Löschverfahren.

Weitere konkrete GET-Seiteneffekte:

- `pages/mieterspiegel.php:12` ruft `raum_taxonomy_ensure_tables()` auf; ab Zeile 39 werden fehlende Wohnungsfelder ergänzt.
- `pages/ajax_unit_rooms.php:25` ruft denselben Ensure-Helper auf und legt bei einem GET gegebenenfalls die Vorlage „Keller“ an.
- `includes/room_taxonomy.php:29–31` führt in diesem Helper **bei jedem Aufruf** Zeichensatz-/Spalten-ALTERs auf `raum_vorlagen` und `raeume` aus, nicht nur nach festgestelltem Migrationsbedarf. Bei leerer Vorlagentabelle werden Standardwerte eingefügt.
- `tools/konto_verwaltung/index.php::ensure_liegenschafts_konto()` wird hinter dem Superadmingate auch beim Laden ausgeführt und kann Tabelle bzw. Wohnungslabel-Spalte anlegen.

Die Raum-AJAX-Aktionen lassen normale angemeldete Benutzer außerdem globale Raumvorlagen löschen oder unter demselben Namen per DELETE + INSERT ersetzen. `add_room`/`delete_room` verwenden direkt die Wohnungs-/Raum-ID, ohne Projektberechtigungsprüfung. Ein Login allein begrenzt diese Eingriffe nicht auf eigene Projekte.

### 21.7 Mobile Risiken dieser zusätzlichen Verwaltungsseiten

Die ergänzend geprüften Seiten haben eigene Layouts, die von der mobilen Pendenzliste unabhängig sind:

- `pages/mieter_zuweisen.php:528` definiert `320px 1fr`, zusätzlich feste zwei-/dreispaltige Formulargruppen. Im lokalen Styleblock ist kein eigener kleiner Bildschirm-Breakpoint vorhanden.
- `pages/mieterspiegel.php:762` verwendet im Einheitsmodal ebenfalls `320px 1fr`; der Anlage-Dialog ist ab Zeile 903 mit 400 px Breite definiert. Mehrere Feldergruppen bleiben dreispaltig, Tabellen-/Kartencontainer verwenden `overflow:hidden`. Der eigene Mediaquery betrifft den Druck.
- `tools/konto_verwaltung/index.php:497` nutzt sechs Spalten mit jeweils mindestens 140 px, andere Blöcke vier Spalten mit mindestens 200 px. `style.css` erlaubt horizontales Scrollen und setzt für die Kontotabelle mindestens 760 px. Das hilft der Tabelle, ist aber noch kein vollständiger Umbau der Formularraster für Mobilgeräte.
- `tools/mieterspiegel/index.php:211` hat eine Filterzeile mit fünf Spalten einschließlich fester Breiten und weitere mehrspaltige Eingabemasken.

Daraus folgen konkrete Risiken für Überbreite, abgeschnittene Inhalte und umständliche Formularbedienung auf Smartphones. Globale Styles können mitwirken; ohne gerenderte Browserabnahme wird kein bestimmter visueller Defekt als beobachtet ausgegeben. Diese Prüfung wurde wegen der beschriebenen Seiteneffekte weiterhin statisch durchgeführt.

## Anhang A – vollständige aktuelle Tabellen- und Spaltenübersicht

Quelle: direkte Read-only-Metadatenabfrage vom 11.09.2026. Namen/Typen sind Ist-Schema, keine vorgeschlagenen Migrationen. `?` bedeutet nullable, `PK` Primärschlüssel, `AI` auto_increment. Keine personenbezogenen Inhalte, Passwörter oder Tokens enthalten.

### A. abnahmen

Engine: InnoDB.

`id` int(11) PK AI; `projekt_id` int(11) ?; `wohneinheit_id` int(11) ?; `unternehmer_id` int(11) ?; `abnahme_typ` enum('Vorabnahme','Bauabnahme','Mietabnahme','Garantie') ?; `status` enum('Entwurf','Abgeschlossen','Unterschrieben') ?; `datum` date ?; `protokoll_pfad` varchar(255) ?; `bemerkungen` text ?; `erstellt_am` timestamp.

Indizes: `PRIMARY` (id) UNIQUE.

### A. abnahme_mangel_link

Engine: InnoDB.

`id` int(11) PK AI; `abnahme_id` int(11) ?; `pendenz_id` int(11) ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. abnahme_protokolle

Engine: InnoDB.

`id` int(11) PK AI; `projekt_id` int(11) ?; `wohnung_id` int(11) ?; `mieter_id` int(11) ?; `mieter_name_custom` varchar(255) ?; `daten_json` longtext ?; `erstellt_am` datetime ?; `erstellt_von` int(11) ?; `pdf_pfad` varchar(255) ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. ai_chats

Engine: InnoDB.

`id` int(11) PK AI; `title` varchar(255); `user_id` int(11) ?; `created_at` timestamp; `context_url` varchar(255) ?; `projekt_id` int(11) ?.

Indizes: `idx_chat_context` (user_id, context_url); `PRIMARY` (id) UNIQUE.

### A. ai_messages

Engine: InnoDB.

`id` int(11) PK AI; `chat_id` int(11); `role` enum('user','assistant'); `content` text; `created_at` timestamp.

Indizes: `chat_id` (chat_id); `PRIMARY` (id) UNIQUE.

### A. ai_suggestions

Engine: InnoDB.

`id` int(11) PK AI; `context` varchar(100) ?; `suggestion_key` varchar(100) ?; `content` text ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. ai_training

Engine: InnoDB.

`id` int(11) PK AI; `user_id` int(11) ?; `category` varchar(50) ?; `scope_url` varchar(255) ?; `title` varchar(255) ?; `content` text ?; `is_active` tinyint(1) ?; `created_at` timestamp; `updated_at` timestamp.

Indizes: `PRIMARY` (id) UNIQUE.

### A. anhaenge

Engine: InnoDB.

`id` int(11) PK AI; `bereich` varchar(50); `referenz_id` int(11); `pendenz_id` int(11); `dateiname` varchar(255); `pfad` varchar(500) ?; `typ` varchar(255) ?; `groesse` int(11) ?; `hochgeladen_von` int(11) ?; `erstellt_am` timestamp.

Indizes: `idx_anhaenge_pendenz` (pendenz_id); `idx_anh_erstellt` (erstellt_am); `idx_anh_ref` (bereich, referenz_id); `PRIMARY` (id) UNIQUE.

### A. audit_log

Engine: InnoDB.

`id` int(11) PK AI; `actor_id` int(11) ?; `entity` enum('benutzer','projekt','pendenz','anhaenge','mitgliedschaft'); `entity_id` int(11); `action` enum('create','update','delete','add_member','remove_member','upload','permission_change','login'); `changes` longtext ?; `ip` varchar(45) ?; `created_at` timestamp.

Indizes: `idx_audit_actor` (actor_id); `PRIMARY` (id) UNIQUE.

### A. benachrichtigungen

Engine: InnoDB; aktuell gezählte Zeilen: 0.

`id` int(11) PK AI; `user_id` int(11); `typ` varchar(50); `titel` varchar(255); `text` text ?; `link_url` varchar(512) ?; `is_read` tinyint(1); `created_at` datetime; `read_at` datetime ?.

Indizes: `idx_user_isread` (user_id, is_read, created_at); `PRIMARY` (id) UNIQUE.

### A. benutzer

Engine: InnoDB; aktuell gezählte Zeilen: 11.

`id` int(11) PK AI; `name` varchar(255); `adresse` varchar(255) ?; `anrede` varchar(20) ?; `telefonnummer` varchar(50) ?; `email` varchar(255) ?; `passwort_hash` varchar(255) ?; `eingeladen_am` datetime ?; `aktiviert_am` datetime ?; `passwort` varchar(255) ?; `geburtsdatum` date ?; `rolle` enum('superadmin','admin','benutzer','gast') ?; `permissions_json` text ?; `business_type` enum('standard','mieter','vermieter','mietinteressent','vormieter','handwerker','kunde','lieferant'); `mieter_phase` enum('interessent','mieter','vormieter','gekündigt') ?; `kontaktweg` enum('email','telefon','whatsapp','sms','post') ?; `is_blocked` tinyint(1) ?; `extra_json` text ?; `startdatum` date ?; `enddatum` date ?; `bild` varchar(500) ?; `erstellt_am` timestamp; `letzter_login` timestamp ?; `profilbild` varchar(500) ?; `firmenlogo` varchar(500) ?; `firma_name` varchar(255) ?; `firma_adresse` varchar(255) ?; `firma_telefon` varchar(50) ?; `firma_email` varchar(255) ?; `firma_website` varchar(255) ?; `titelbild` varchar(500) ?; `public_image_source` enum('profil','logo') ?; `show_profilbild_public` tinyint(1) ?; `show_firmenlogo_public` tinyint(1) ?; `show_titelbild_public` tinyint(1) ?; `deleted_at` datetime ?; `created_at` datetime ?; `updated_at` datetime ?; `heimatland` varchar(100) ?; `position` varchar(100) ?; `wohnung_id` int(11) ?; `vorgangsart_id` int(11) ?; `beruf` varchar(100) ?; `profile_vis` longtext ?; `invite_status` enum('none','invited','opened','accepted','profile_updated','expired'); `invite_token_hash` char(64) ?; `invite_expires` datetime ?; `invite_last_opened_at` datetime ?; `invited_by` int(11) ?; `invited_at` datetime ?; `first_login_at` datetime ?; `last_login_at` datetime ?; `profile_updated_at` datetime ?; `vis_geburtsdatum` enum('privat','intern','oeffentlich') ?; `vis_heimatland` enum('privat','intern','oeffentlich') ?; `vis_position` enum('privat','intern','oeffentlich') ?; `vis_aufenthaltstitel` enum('privat','intern','oeffentlich') ?; `vis_beruf` enum('privat','intern','oeffentlich') ?; `vorname` varchar(100) ?; `nachname` varchar(100) ?; `strasse` varchar(150) ?; `hausnummer` varchar(20) ?; `plz` varchar(20) ?; `ort` varchar(120) ?; `aufenthaltstitel` varchar(100) ?; `firma_strasse` varchar(150) ?; `firma_hausnummer` varchar(20) ?; `firma_plz` varchar(20) ?; `firma_ort` varchar(120) ?; `person_type_id` int(11) ?; `person_status_id` int(11) ?; `labels_json` longtext ?; `folder_path` varchar(2048) ?; `projekt_id` int(11) ?; `objekt_id` int(11) ?; `firma_id` int(11) ?.

Indizes: `fk_benutzer_person_status` (person_status_id); `fk_benutzer_person_type` (person_type_id); `idx_benutzer_invited_at` (invited_at); `idx_benutzer_invite_last_opened_at` (invite_last_opened_at); `idx_benutzer_objekt_id` (objekt_id); `idx_benutzer_projekt_id` (projekt_id); `idx_benutzer_wohnung` (wohnung_id); `idx_benutzer_wohnung_id` (wohnung_id); `PRIMARY` (id) UNIQUE; `uq_benutzer_email` (email) UNIQUE.

### A. benutzer_personentypen

Engine: InnoDB.

`id` int(11) PK AI; `benutzer_id` int(11); `person_type_id` int(11); `person_status_id` int(11) ?; `is_primary` tinyint(1); `sort_order` int(11); `created_at` datetime; `updated_at` datetime.

Indizes: `idx_bpt_benutzer` (benutzer_id); `idx_bpt_status` (person_status_id); `idx_bpt_type` (person_type_id); `PRIMARY` (id) UNIQUE.

### A. benutzer_profile

Engine: InnoDB.

`id` int(11) PK AI; `benutzer_id` int(11); `profil_typ` enum('eigentuemer','architekt','vermieter','mieter','unternehmer'); `bezeichnung` varchar(255) ?; `firma_id` int(11) ?; `projekt_id` int(11) ?; `objekt_id` int(11) ?; `wohnung_id` int(11) ?; `vorgangsart_id` int(11) ?; `ist_standard` tinyint(1); `aktiv` tinyint(1); `sort_order` int(11); `extra_json` longtext ?; `created_at` datetime; `updated_at` datetime.

Indizes: `idx_bp_aktiv_standard` (aktiv, ist_standard); `idx_bp_benutzer` (benutzer_id); `idx_bp_firma` (firma_id); `idx_bp_objekt` (objekt_id); `idx_bp_projekt` (projekt_id); `idx_bp_typ` (profil_typ); `idx_bp_vorgangsart` (vorgangsart_id); `idx_bp_wohnung` (wohnung_id); `PRIMARY` (id) UNIQUE.

### A. benutzer_projekte

Engine: InnoDB; aktuell gezählte Zeilen: 7.

`benutzer_id` int(11) PK; `projekt_id` int(11) PK.

Indizes: `PRIMARY` (benutzer_id, projekt_id) UNIQUE.

### A. benutzer_teams

Engine: InnoDB.

`benutzer_id` int(11) PK; `team_id` int(11) PK.

Indizes: `PRIMARY` (benutzer_id, team_id) UNIQUE.

### A. bkp_codes

Engine: InnoDB.

`id` int(11) PK AI; `code` varchar(20); `bezeichnung` varchar(255); `titel` varchar(255) ?; `beschreibung` text ?; `parent_id` int(11) ?.

Indizes: `code` (code) UNIQUE; `parent_id` (parent_id); `PRIMARY` (id) UNIQUE.

### A. bkp_kategorien

Engine: InnoDB.

`id` int(11) PK AI; `bkp_id` int(11); `name` varchar(255).

Indizes: `bkp_id` (bkp_id); `PRIMARY` (id) UNIQUE.

### A. bkp_vorlagen_texte

Engine: InnoDB.

`id` int(11) PK AI; `kategorie_id` int(11); `titel` varchar(255) ?; `beschreibung` text ?; `text` text.

Indizes: `kategorie_id` (kategorie_id); `PRIMARY` (id) UNIQUE.

### A. chat_attachments

Engine: InnoDB.

`id` int(11) PK AI; `message_id` int(11); `original_name` varchar(255); `mime_type` varchar(255) ?; `size_bytes` int(11) ?; `path` varchar(500); `created_at` datetime.

Indizes: `idx_ca_message` (message_id); `PRIMARY` (id) UNIQUE.

### A. chat_members

Engine: InnoDB.

`room_id` int(11) PK; `user_id` int(11) PK; `role` enum('member','admin'); `joined_at` datetime; `last_read_message_id` int(11) ?.

Indizes: `idx_chat_members_lastread` (last_read_message_id); `idx_chat_members_user_room` (user_id, room_id); `PRIMARY` (room_id, user_id) UNIQUE.

### A. chat_messages

Engine: InnoDB.

`id` int(11) PK AI; `projekt_id` int(11) ?; `room_id` int(11); `sender_id` int(11) ?; `message_type` enum('text','system'); `message_text` mediumtext ?; `created_at` datetime; `edited_at` datetime ?; `deleted_at` datetime ?; `message` text; `read_at` datetime ?.

Indizes: `ft_chat_message` (message); `idx_chat_messages_created` (created_at); `idx_chat_messages_project` (projekt_id); `idx_chat_messages_room_time` (room_id, created_at); `idx_chat_messages_sender` (sender_id); `idx_chat_messages_sender_time` (sender_id, created_at); `PRIMARY` (id) UNIQUE.

### A. chat_messages_pendenzen

Engine: InnoDB.

`message_id` int(11) PK; `pendenz_id` int(11) PK.

Indizes: `idx_cmp_pendenz` (pendenz_id); `PRIMARY` (message_id, pendenz_id) UNIQUE.

### A. chat_message_recipients

Engine: InnoDB.

`message_id` int(11) PK; `recipient_user_id` int(11) PK; `delivery_status` enum('sent','read'); `read_at` datetime ?.

Indizes: `idx_cmr_recipient_status` (recipient_user_id, delivery_status); `PRIMARY` (message_id, recipient_user_id) UNIQUE.

### A. chat_reads

Engine: InnoDB.

`room_id` int(11) PK; `user_id` int(11) PK; `last_read_id` bigint(20); `updated_at` timestamp.

Indizes: `idx_user` (user_id); `PRIMARY` (room_id, user_id) UNIQUE.

### A. chat_rooms

Engine: InnoDB.

`id` int(11) PK AI; `room_type` enum('team','project','dm','group'); `name` varchar(255) ?; `project_id` int(11) ?; `dm_key` varchar(64) ?; `created_by` int(11); `created_at` datetime; `updated_at` datetime; `company_id` int(11) ?.

Indizes: `idx_chat_rooms_created_by` (created_by); `idx_chat_rooms_project` (project_id); `idx_chat_rooms_room_type` (room_type); `idx_company` (company_id); `PRIMARY` (id) UNIQUE; `uq_chat_rooms_dm_key` (dm_key) UNIQUE.

### A. chat_typing

Engine: InnoDB.

`room_id` int(11) PK; `user_id` int(11) PK; `until` datetime.

Indizes: `PRIMARY` (room_id, user_id) UNIQUE.

### A. comments

Engine: InnoDB.

`id` int(11) PK AI; `post_id` int(11); `author_id` int(11); `content` text; `created_at` datetime.

Indizes: `author_id` (author_id); `post_id` (post_id); `PRIMARY` (id) UNIQUE.

### A. documents

Engine: InnoDB.

`id` int(11) PK AI; `folder_id` int(11); `filename` varchar(255); `storage_relpath` varchar(2048); `size` bigint(20) ?; `checksum` char(64) ?; `created_at` datetime ?; `updated_at` datetime ?; `deleted_at` datetime ?.

Indizes: `fk_docs_folder` (folder_id); `idx_storage` (storage_relpath); `PRIMARY` (id) UNIQUE.

### A. einheit_typen

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(100) ?; `icon` varchar(50) ?; `gruppe` varchar(100) ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. favoriten

Engine: InnoDB.

`id` int(11) PK AI; `entity_type` enum('user','team','gemeinschaft'); `entity_id` int(11); `unterkategorie_id` int(11); `aktiv` tinyint(1) ?.

Indizes: `PRIMARY` (id) UNIQUE; `unterkategorie_id` (unterkategorie_id).

### A. files

Engine: InnoDB.

`id` int(11) PK AI; `post_id` int(11) ?; `comment_id` int(11) ?; `uploader_id` int(11); `path` varchar(500); `mime` varchar(150); `size` int(11); `created_at` datetime.

Indizes: `comment_id` (comment_id); `post_id` (post_id); `PRIMARY` (id) UNIQUE.

### A. finanzen_konto

Engine: InnoDB.

`id` int(11) PK AI; `wohnung_id` int(11) ?; `benutzer_id` int(11) ?; `datum` date; `beleg_nr` varchar(50) ?; `text` varchar(255); `soll` decimal(12,2) ?; `haben` decimal(12,2) ?; `status` enum('offen','ausgeglichen','storniert') ?; `created_at` timestamp.

Indizes: `benutzer_id` (benutzer_id); `PRIMARY` (id) UNIQUE; `wohnung_id` (wohnung_id).

### A. firma

Engine: InnoDB.

`id` int(11) PK AI; `benutzer_id` int(11); `firmenname` varchar(255) ?; `adresse` varchar(255) ?; `telefon` varchar(50) ?; `email` varchar(255) ?; `website` varchar(255) ?; `logo` varchar(500) ?; `erstellt_am` timestamp.

Indizes: `benutzer_id` (benutzer_id); `PRIMARY` (id) UNIQUE.

### A. firma_user

Engine: InnoDB.

`id` bigint(20) PK AI; `user_id` int(11); `firma_id` int(11); `rolle` varchar(100) ?; `is_primary` tinyint(1); `created_at` datetime; `updated_at` datetime.

Indizes: `idx_firma` (firma_id); `idx_firma_2` (firma_id); `idx_user` (user_id); `PRIMARY` (id) UNIQUE; `uq_user_firma` (user_id, firma_id) UNIQUE.

### A. firmen

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(255); `strasse` varchar(255) ?; `plz_ort` varchar(255) ?; `email` varchar(255) ?; `telefon` varchar(100) ?; `adresse` varchar(255) ?; `ort` varchar(255) ?; `logo_url` varchar(500) ?; `created_at` datetime; `updated_at` datetime; `website` varchar(255) ?; `logo` varchar(512) ?; `bkp_id` int(11) ?.

Indizes: `PRIMARY` (id) UNIQUE; `uq_firmen_name` (name) UNIQUE.

### A. firmen_overrides

Engine: InnoDB.

`id` int(11) PK AI; `firma_id` int(11); `benutzer_id` int(11); `mode` enum('include','exclude'); `created_at` timestamp.

Indizes: `fk_fo_user` (benutzer_id); `PRIMARY` (id) UNIQUE; `uq_firma_user` (firma_id, benutzer_id) UNIQUE.

### A. firmen_vorlagen_map

Engine: InnoDB.

`id` int(11) PK AI; `firma_id` int(11); `welt` varchar(30); `ref_id` int(11); `created_at` timestamp ?.

Indizes: `idx_firma_welt` (firma_id, welt); `PRIMARY` (id) UNIQUE; `uniq_firma_welt_ref` (firma_id, welt, ref_id) UNIQUE.

### A. folders

Engine: InnoDB.

`id` int(11) PK AI; `parent_id` int(11) ?; `name` varchar(120); `fs_name` varchar(120); `path` varchar(1024); `depth` tinyint(3) unsigned; `created_at` datetime ?; `updated_at` datetime ?; `deleted_at` datetime ?.

Indizes: `idx_depth` (depth); `idx_path` (path); `PRIMARY` (id) UNIQUE; `uq_parent_name` (parent_id, name, deleted_at) UNIQUE.

### A. folder_audit_log

Engine: InnoDB.

`id` int(11) PK AI; `happened_at` datetime ?; `user_id` int(11) ?; `action` enum('create','rename','move','soft_delete','restore'); `folder_id` int(11); `before_path` varchar(1024) ?; `after_path` varchar(1024) ?; `note` text ?.

Indizes: `idx_folder` (folder_id); `PRIMARY` (id) UNIQUE.

### A. fs_folder_meta

Engine: InnoDB.

`id` int(11) PK AI; `project_id` int(11); `rel_path` varchar(500); `icon` varchar(50) ?.

Indizes: `PRIMARY` (id) UNIQUE; `project_id` (project_id, rel_path) UNIQUE.

### A. fs_nodes

Engine: InnoDB.

`id` bigint(20) unsigned PK AI; `project_id` int(10) unsigned; `rel_path` varchar(1024); `name` varchar(255); `parent_rel_path` varchar(1024) ?; `is_dir` tinyint(1); `is_shared` tinyint(1) ?; `size` bigint(20) unsigned; `mtime` datetime; `content_sha` char(64) ?; `rel_path_sha` binary(32) ?; `parent_rel_path_sha` binary(32) ?.

Indizes: `idx_fs_project` (project_id); `idx_proj_parent` (project_id, parent_rel_path); `idx_proj_parent_sha` (project_id, parent_rel_path_sha); `PRIMARY` (id) UNIQUE; `uq_proj_relsha` (project_id, rel_path_sha) UNIQUE.

### A. gegenstaende

Engine: InnoDB.

`id` int(11) PK AI; `zimmer_id` int(11); `name` varchar(255); `bild` varchar(255) ?; `erstellt_am` timestamp.

Indizes: `fk_gegenstaende_zimmer` (zimmer_id); `PRIMARY` (id) UNIQUE.

### A. interessenten

Engine: InnoDB.

`id` int(11) PK AI; `kontakt_id` int(11) ?; `wohnung_id` int(11) ?; `vorname` varchar(100); `nachname` varchar(100); `email` varchar(255); `token` varchar(64) ?; `benutzer_id` int(11) ?; `telefon` varchar(50) ?; `geburtsdatum` date ?; `gehalt_monat` decimal(12,2) ?; `beruf` varchar(255) ?; `arbeitgeber` varchar(255) ?; `anzahl_personen` int(11) ?; `haustiere` text ?; `instrumente` text ?; `bemerkungen` text ?; `status` enum('neu','eingeladen','abgelehnt','angenommen') ?; `erstellt_am` timestamp.

Indizes: `PRIMARY` (id) UNIQUE; `token` (token); `wohnung_id` (wohnung_id).

### A. kontakte

Engine: InnoDB.

`id` int(11) PK AI; `vorname` varchar(100) ?; `nachname` varchar(100) ?; `email` varchar(255) ?; `telefon` varchar(50) ?; `typ` enum('mieter','interessent','handwerker','partner','sonstiges') ?; `created_at` timestamp.

Indizes: `PRIMARY` (id) UNIQUE.

### A. konten

Engine: InnoDB.

`id` int(11) PK AI; `bezeichnung` varchar(255); `iban` varchar(64) ?; `bic` varchar(32) ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. konto_buchungen

Engine: InnoDB.

`id` int(11) PK AI; `project_id` int(11); `ordner_link_id` int(11); `datum` date; `typ` enum('miete','nk','nachzahlung','reduktion','gutschrift','zahlung','kaution','kaution_rueck'); `betrag` decimal(12,2); `referenz` varchar(255) ?; `bemerkung` text ?; `created_at` timestamp.

Indizes: `ordner_link_id` (ordner_link_id, datum); `PRIMARY` (id) UNIQUE.

### A. konto_rules

Engine: InnoDB.

`id` int(11) PK AI; `aktiv` tinyint(1); `priority` int(11); `pattern` varchar(200); `is_regex` tinyint(1); `liegenschaft_id` int(11) ?; `wohnung_label` varchar(100) ?; `set_kategorie` varchar(100) ?; `set_zahlungsart` varchar(50) ?; `note` varchar(255) ?; `created_at` datetime ?.

Indizes: `idx_rules` (aktiv, priority); `idx_rules_proj` (liegenschaft_id); `PRIMARY` (id) UNIQUE.

### A. kv_alias

Engine: InnoDB.

`id` int(11) PK AI; `benutzer_id` int(11); `alias_name` varchar(255); `alias_norm` varchar(255); `counterparty_iban` varchar(34) ?; `created_at` datetime.

Indizes: `counterparty_iban` (counterparty_iban); `PRIMARY` (id) UNIQUE; `uniq_alias` (alias_norm) UNIQUE.

### A. kv_buchungen

Engine: InnoDB.

`id` bigint(20) PK AI; `konto_id` int(11) ?; `buchungstag` date ?; `valutadatum` date ?; `betrag` decimal(12,2); `waehrung` char(3) ?; `text_raw` text ?; `gegenkonto_name` varchar(255) ?; `gegenkonto_iban` varchar(34) ?; `referenz` varchar(140) ?; `zweck` text ?; `import_batch_id` int(11) ?; `matched_benutzer_id` int(11) ?; `matched_ve_id` int(11) ?; `matched_liegenschaft_id` int(11) ?; `match_score` int(11) ?; `kategorie` enum('Miete','Nebenkosten','Depot','Gutschrift','Ausgabe','Sonstiges') ?; `status` enum('offen','zugeordnet','gesplittet','ignoriert') ?; `created_at` datetime; `updated_at` datetime.

Indizes: `gegenkonto_iban` (gegenkonto_iban); `import_batch_id` (import_batch_id); `matched_benutzer_id` (matched_benutzer_id); `PRIMARY` (id) UNIQUE; `referenz` (referenz); `uniq_guard` (konto_id, buchungstag, betrag, referenz, gegenkonto_iban) UNIQUE.

### A. kv_buchung_items

Engine: InnoDB.

`id` bigint(20) PK AI; `buchung_id` bigint(20); `type` enum('Miete','NK','Depot','Aufwand','Korrektur'); `periode` date ?; `amount` decimal(12,2); `benutzer_id` int(11) ?; `ve_id` int(11) ?; `liegenschaft_id` int(11) ?; `konto_code` varchar(40) ?; `note` varchar(255) ?; `created_at` datetime.

Indizes: `buchung_id` (buchung_id); `PRIMARY` (id) UNIQUE.

### A. kv_import_batches

Engine: InnoDB.

`id` int(11) PK AI; `konto_id` int(11) ?; `filename` varchar(255); `imported_at` datetime; `row_count` int(11); `file_hash` char(64).

Indizes: `konto_id` (konto_id); `PRIMARY` (id) UNIQUE.

### A. kv_konten

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(120); `iban` varchar(34) ?; `bank` varchar(120) ?; `waehrung` char(3) ?; `liegenschaft_id` int(11) ?; `projekt_id` int(11) ?; `fs_rel_path` varchar(255) ?; `created_at` datetime; `updated_at` datetime.

Indizes: `PRIMARY` (id) UNIQUE; `uniq_iban` (iban) UNIQUE.

### A. kv_mandate

Engine: InnoDB.

`id` int(11) PK AI; `benutzer_id` int(11); `type` enum('QR','ESR','AUFTRAG','IBAN'); `value` varchar(140); `created_at` datetime.

Indizes: `benutzer_id` (benutzer_id); `PRIMARY` (id) UNIQUE; `uniq_type_value` (type, value) UNIQUE.

### A. kv_mietvertraege

Engine: InnoDB.

`id` int(11) PK AI; `benutzer_id` int(11); `ve_id` int(11) ?; `liegenschaft_id` int(11) ?; `start_date` date; `end_date` date ?; `miete_monat` decimal(12,2); `nk_monat` decimal(12,2); `faellig_tag` tinyint(4); `created_at` datetime.

Indizes: `benutzer_id` (benutzer_id); `liegenschaft_id` (liegenschaft_id); `PRIMARY` (id) UNIQUE; `ve_id` (ve_id).

### A. kv_rules

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(120); `field` enum('text','gegenkonto_name','referenz','iban'); `pattern` varchar(255); `assign_kategorie` varchar(40) ?; `assign_liegenschaft_id` int(11) ?; `assign_ve_id` int(11) ?; `assign_benutzer_id` int(11) ?; `priority` int(11); `active` tinyint(1); `created_at` datetime.

Indizes: `PRIMARY` (id) UNIQUE.

### A. liegenschaften

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(255).

Indizes: `PRIMARY` (id) UNIQUE.

### A. liegenschafts_konto

Engine: InnoDB.

`id` int(11) PK AI; `konto_id` int(11) ?; `projekt_id` int(11) ?; `liegenschaft_id` int(11) ?; `buchungsdatum` date ?; `betrag` decimal(12,2); `beschreibung` varchar(255) ?; `kategorie` varchar(100) ?; `wohnung_label` varchar(100) ?; `zahlungsart` varchar(50) ?; `import_dateiname` varchar(255) ?; `import_benutzer_id` int(11) ?; `mieter_id` int(11) ?; `eigentuemer_id` int(11) ?; `konto_nr` varchar(255); `wohnung_id` int(11) ?.

Indizes: `idx_konto_liegenschaft` (liegenschaft_id); `idx_lk_beschr` (beschreibung); `idx_lk_betrag` (betrag); `idx_lk_buchdatum` (buchungsdatum); `idx_lk_eigentuemer` (eigentuemer_id); `idx_lk_import_user` (import_benutzer_id); `idx_lk_kategorie` (kategorie); `idx_lk_mieter` (mieter_id); `idx_lk_proj` (liegenschaft_id); `idx_lk_zahlungsart` (zahlungsart); `PRIMARY` (id) UNIQUE.

### A. listen

Engine: InnoDB.

`id` int(10) unsigned PK AI; `name` varchar(200); `table_name` varchar(100); `filters_json` longtext ?; `sort_col` varchar(100) ?; `sort_dir` enum('asc','desc'); `per_page` int(11); `owner_id` int(10) unsigned ?; `shared` tinyint(1); `created_at` datetime; `updated_at` datetime; `is_default` tinyint(1) ?.

Indizes: `idx_owner` (owner_id); `idx_table` (table_name); `PRIMARY` (id) UNIQUE.

### A. listen_spalten

Engine: InnoDB.

`id` int(10) unsigned PK AI; `listen_id` int(10) unsigned; `col_name` varchar(100); `sort_order` int(11); `width_desktop` varchar(10) ?; `width_ipad` varchar(10) ?; `width_mobile` varchar(10) ?; `visible_desktop` tinyint(1) ?; `visible_ipad` tinyint(1) ?; `visible_mobile` tinyint(1) ?.

Indizes: `idx_list` (listen_id); `idx_sort` (sort_order); `PRIMARY` (id) UNIQUE; `unq_listen_col` (listen_id, col_name) UNIQUE.

### A. maengelliste

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(255); `email` varchar(255) ?; `rolle` varchar(50) ?; `firma_id` int(11) ?; `is_active` tinyint(1) ?; `created_at` datetime ?; `updated_at` datetime ?; `deleted_at` datetime ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. mentions

Engine: InnoDB.

`id` int(11) PK AI; `post_id` int(11) ?; `comment_id` int(11) ?; `mentioned_user_id` int(11); `created_at` datetime.

Indizes: `PRIMARY` (id) UNIQUE.

### A. mieten_tarife

Engine: InnoDB.

`id` int(11) PK AI; `ordner_link_id` int(11); `valid_from` date; `valid_to` date ?; `mietzins_netto` decimal(12,2); `nk_akonto` decimal(12,2) ?; `bemerkung` text ?; `created_at` timestamp.

Indizes: `ordner_link_id` (ordner_link_id, valid_from); `PRIMARY` (id) UNIQUE.

### A. mieter

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(255); `email` varchar(255) ?; `telefon` varchar(50) ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. miete_adjustments

Engine: InnoDB.

`id` int(11) PK AI; `liegenschaft_id` int(11); `wohnung_label` varchar(100); `monat` date; `delta` decimal(12,2); `grund` varchar(255) ?.

Indizes: `idx_adj` (liegenschaft_id, wohnung_label, monat); `PRIMARY` (id) UNIQUE; `uniq_adj` (liegenschaft_id, wohnung_label, monat, grund) UNIQUE.

### A. miete_schedules

Engine: InnoDB.

`id` int(11) PK AI; `liegenschaft_id` int(11); `wohnung_label` varchar(100); `valid_from` date; `valid_to` date ?; `soll_miete` decimal(12,2); `typ` enum('vertrag_start','index','wiedervermietung','reduktion','korrektur') ?; `bemerkung` varchar(255) ?.

Indizes: `idx_sched` (liegenschaft_id, wohnung_label, valid_from); `PRIMARY` (id) UNIQUE.

### A. mietverhaeltnisse

Engine: InnoDB.

`id` int(11) PK AI; `wohnung_id` int(11); `benutzer_id` int(11); `startdatum` date ?; `enddatum` date ?; `status` enum('geplant','aktiv','beendet') ?; `created_at` timestamp.

Indizes: `benutzer_id` (benutzer_id); `PRIMARY` (id) UNIQUE; `wohnung_id` (wohnung_id).

### A. mietvertraege

Engine: InnoDB.

`id` int(11) PK AI; `wohnung_id` int(11); `benutzer_id` int(11); `beginn` date; `ende` date ?; `miete_netto` decimal(10,2); `nk_aconto` decimal(10,2); `zahlungstag` tinyint(4); `bemerkung` text ?.

Indizes: `idx_active` (wohnung_id, beginn, ende); `idx_user` (benutzer_id); `idx_whg` (wohnung_id); `PRIMARY` (id) UNIQUE.

### A. notifications

Engine: InnoDB; aktuell gezählte Zeilen: 0.

`id` int(11) PK AI; `user_id` int(11) ?; `ref_type` enum('pendenz'); `ref_id` int(11); `type` varchar(64); `message` varchar(500); `is_read` tinyint(1); `created_at` timestamp.

Indizes: `idx_n_read` (is_read); `idx_n_ref` (ref_type, ref_id); `idx_n_user` (user_id); `PRIMARY` (id) UNIQUE.

### A. objekte

Engine: InnoDB; aktuell gezählte Zeilen: 12.

`id` int(11) PK AI; `projekt_id` int(11); `name` varchar(255); `folder_name` varchar(255) ?; `bezeichnung` varchar(255) ?; `bild` varchar(255) ?; `erstellt_am` timestamp.

Indizes: `fk_objekte_projekt` (projekt_id); `idx_objekte_projekt_name` (projekt_id, name); `PRIMARY` (id) UNIQUE.

### A. ordner

Engine: InnoDB.

`id` int(11) PK AI; `project_id` int(11) ?; `parent_id` int(11) ?; `name` varchar(255); `fs_rel_path` varchar(1024) ?.

Indizes: `idx_ordner_path` (fs_rel_path); `idx_ordner_project` (project_id); `idx_parent` (parent_id); `PRIMARY` (id) UNIQUE.

### A. ordner_links

Engine: InnoDB.

`id` int(11) PK AI; `project_id` int(11); `fs_rel_path` varchar(1024); `typ` enum('benutzer','unternehmen','konto','sonstiges'); `benutzer_id` int(11) ?; `ziel_id` int(11) ?; `label` varchar(255) ?; `bemerkung` text ?; `created_at` timestamp; `folder_rel_path` varchar(1024) ?; `beginn` date ?; `ende` date ?; `status` varchar(32) ?.

Indizes: `PRIMARY` (id) UNIQUE; `uniq_link` (project_id, fs_rel_path, typ, benutzer_id, ziel_id) UNIQUE.

### A. ordner_vorlagen

Engine: InnoDB.

`id` int(10) unsigned PK AI; `name` varchar(191); `beschreibung` text ?; `version` int(11); `is_active` tinyint(1); `created_at` timestamp.

Indizes: `PRIMARY` (id) UNIQUE; `uq_name_version` (name, version) UNIQUE.

### A. ordner_vorlagen_nodes

Engine: InnoDB.

`id` bigint(20) unsigned PK AI; `vorlage_id` int(10) unsigned; `rel_path` varchar(1024); `is_dir` tinyint(1); `sort` int(11).

Indizes: `idx_vorlage_id` (vorlage_id); `PRIMARY` (id) UNIQUE; `uq_vorlage_rel` (vorlage_id, rel_path) UNIQUE.

### A. ordner_vorlage_applied

Engine: InnoDB.

`id` bigint(20) unsigned PK AI; `projekt_id` int(11); `vorlage_id` int(10) unsigned; `version` int(11); `applied_at` timestamp.

Indizes: `idx_ova_proj` (projekt_id); `idx_ova_vorlage` (vorlage_id); `PRIMARY` (id) UNIQUE; `uq_proj_vorlage_version` (projekt_id, vorlage_id, version) UNIQUE.

### A. ordner_vorlage_applied_nodes

Engine: InnoDB.

`id` bigint(20) unsigned PK AI; `applied_id` bigint(20) unsigned; `rel_path` varchar(1024); `is_dir` tinyint(1).

Indizes: `idx_ovan_applied` (applied_id); `PRIMARY` (id) UNIQUE.

### A. pdf_templates

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(255); `config_json` longtext; `is_default` tinyint(1) ?; `created_at` timestamp; `type` varchar(20) ?; `parent_id` int(11) ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. pendenzen

Engine: InnoDB; aktuell gezählte Zeilen: 111.

`id` int(11) PK AI; `mandant_id` int(11) ?; `projekt_id` int(11) ?; `vorgangsart_id` int(11) ?; `vorlagen_welt` varchar(30) ?; `empfaenger_typ` varchar(30) ?; `objekt_id` int(11) ?; `wohnung_id` int(11) ?; `ordner_id` int(11) unsigned ?; `fs_rel_path` varchar(1024) ?; `fs_branch` varchar(50) ?; `sort_index` bigint(20) ?; `titel` varchar(255); `kurzbeschreibung` text ?; `langbeschreibung` text ?; `notiz` text ?; `beschreibung` text ?; `status` enum('offen','in Bearbeitung','erledigt','archiviert'); `art_id` int(11) ?; `raum_id` int(11) ?; `bkp_id` int(11) ?; `wichtigkeit` tinyint(4) ?; `startdatum` date ?; `enddatum` date ?; `uhrzeit` time ?; `tageszeit` varchar(50) ?; `dauer` varchar(50) ?; `send_now` tinyint(1); `vorgaenger_id` varchar(255) ?; `erstellt_am` timestamp; `geaendert_am` timestamp; `erstellt_von` int(11) ?; `sichtbarkeit` enum('privat','projekt','custom'); `assignee_can_edit` tinyint(1); `zustaendig_id` int(11) ?; `empfaenger_profil_id` int(11) ?; `empfaenger_benutzer_id` int(11) ?; `ersteller_benutzer_id` int(11) ?; `deleted_at` datetime ?; `created_at` datetime ?; `updated_at` datetime ?; `confirmation_required` tinyint(1) ?; `confirmation_by` enum('assignee','owner','any') ?; `confirmation_at` datetime ?; `external_can_view` tinyint(1) ?; `external_can_upload` tinyint(1) ?; `public_enabled` tinyint(1); `public_token` char(64) ?; `submitted_by` varchar(64) ?; `submitted_at` datetime ?; `reviewed_by` int(11) ?; `reviewed_at` datetime ?; `extra_json` longtext ?; `zustaendig_typ` enum('user','team','firma'); `sicht_ref_id` int(11) ?; `is_protocol` tinyint(1) ?; `protocol_type` enum('none','abnahme','uebergabe','besichtigung') ?; `kategorie_id` int(11) ?; `subkategorie_id` int(11) ?; `mieter_kategorie_id` int(11) ?; `mieter_subkategorie_id` int(11) ?; `vermieter_kategorie_id` int(11) ?; `vermieter_subkategorie_id` int(11) ?; `unt_bemerkung` text ?; `unt_new_input` tinyint(1) ?; `plan_id` int(11) ?; `pin_x` float ?; `pin_y` float ?.

Indizes: `fk_pendenz_art` (art_id); `fk_pendenz_bkp` (bkp_id); `fk_pendenz_raum` (raum_id); `fk_pendenz_wohnung` (wohnung_id); `idx_pendenzen_erstellt_am` (erstellt_am); `idx_pendenzen_erstellt_von` (erstellt_von); `idx_pendenzen_projekt` (projekt_id); `idx_pendenzen_sort_index` (sort_index); `idx_pendenzen_status` (status); `idx_pendenzen_zustaendig` (zustaendig_id); `idx_pend_created` (erstellt_am); `idx_pend_deleted` (deleted_at); `idx_pend_empfaenger_benutzer` (empfaenger_benutzer_id); `idx_pend_empfaenger_profil` (empfaenger_profil_id); `idx_pend_ersteller` (erstellt_von); `idx_pend_ersteller_benutzer` (ersteller_benutzer_id); `idx_pend_ordner` (ordner_id); `idx_pend_proj` (projekt_id); `idx_pend_proj_folder` (projekt_id, fs_rel_path); `idx_pend_status` (status); `idx_pend_zustaendig` (zustaendig_id); `idx_sicht` (sichtbarkeit, sicht_ref_id); `idx_vorgangsart` (vorgangsart_id); `idx_zust` (zustaendig_typ, zustaendig_id); `PRIMARY` (id) UNIQUE; `uq_pend_public_token` (public_token) UNIQUE.

### A. pendenzen_arten

Engine: InnoDB.

`id` int(11) PK AI; `slug` varchar(100); `name` varchar(100); `icon` varchar(50) ?; `default_projekt_id` int(11) ?; `color` varchar(20) ?; `farbe` varchar(20) ?; `sort_order` int(11) ?; `allowed_roles` text ?; `allowed_business_types` text ?; `default_objekt_id` int(11) ?; `default_wohnung_id` int(11) ?; `default_benutzer_id` int(11) ?; `allow_override_projekt` tinyint(1); `allow_override_objekt` tinyint(1); `allow_override_wohnung` tinyint(1); `allow_override_benutzer` tinyint(1); `is_active` tinyint(1); `vorlagen_welt` varchar(30); `empfaenger_typ` varchar(30) ?; `empfaenger_person_type_id` int(11) ?; `empfaenger_person_status_id` int(11) ?; `bkp_erforderlich` tinyint(1); `nur_firmen_bkp` tinyint(1); `firma_bkp_filter` tinyint(1).

Indizes: `idx_pa_default_benutzer` (default_benutzer_id); `idx_pa_default_objekt` (default_objekt_id); `idx_pa_default_projekt` (default_projekt_id); `idx_pa_default_wohnung` (default_wohnung_id); `PRIMARY` (id) UNIQUE.

### A. pendenzen_arten_projekte

Engine: InnoDB.

`vorgangsart_id` int(11) PK; `projekt_id` int(11) PK; `objekt_id` int(11) ?.

Indizes: `idx_vap_obj` (objekt_id); `PRIMARY` (vorgangsart_id, projekt_id) UNIQUE.

### A. pendenzen_art_empfaenger_defaults

Engine: InnoDB.

`id` int(11) PK AI; `pendenz_art_id` int(11); `benutzer_id` int(11) ?; `projekt_id` int(11) ?; `objekt_id` int(11) ?; `wohnung_id` int(11) ?; `bkp_id` int(11) ?; `bkp_mode` varchar(20); `sort_order` int(11); `is_active` tinyint(1); `created_at` datetime; `updated_at` datetime.

Indizes: `idx_paed_art` (pendenz_art_id); `idx_paed_benutzer` (benutzer_id); `idx_paed_bkp` (bkp_id); `idx_paed_objekt` (objekt_id); `idx_paed_projekt` (projekt_id); `idx_paed_wohnung` (wohnung_id); `PRIMARY` (id) UNIQUE.

### A. pendenzen_meta

Engine: InnoDB.

`id` int(11) PK AI; `pendenz_id` int(11); `meta_key` varchar(255); `meta_value` text ?.

Indizes: `idx_pm_pendenz` (pendenz_id); `PRIMARY` (id) UNIQUE; `uq_pendenzen_meta` (pendenz_id, meta_key) UNIQUE.

### A. pendenz_acl

Engine: InnoDB.

`id` int(11) PK AI; `pendenz_id` int(11); `benutzer_id` int(11); `can_view` tinyint(1) ?; `can_edit` tinyint(1) ?; `erstellt_am` timestamp.

Indizes: `idx_acl_benutzer` (benutzer_id); `idx_acl_pendenz` (pendenz_id); `PRIMARY` (id) UNIQUE; `uq_acl` (pendenz_id, benutzer_id) UNIQUE.

### A. pendenz_anhaenge

Engine: InnoDB.

`id` int(11) PK AI; `pendenz_id` int(11); `pfad` varchar(500); `erstellt_am` timestamp; `quelle` varchar(50) ?; `stored_name` varchar(255); `file_path` varchar(500); `file_ext` varchar(20) ?; `mime_type` varchar(120) ?; `file_size` bigint(20) ?; `typ` varchar(20); `uploaded_by` int(11) ?; `created_at` timestamp ?; `original_name` varchar(255).

Indizes: `idx_typ` (typ); `pendenz_id` (pendenz_id); `PRIMARY` (id) UNIQUE.

### A. pendenz_dateien

Engine: InnoDB.

`id` bigint(20) unsigned PK AI; `pendenz_id` int(11); `typ` enum('image','file','audio','video'); `pfad` varchar(1024); `mimetype` varchar(190) ?; `groesse` int(10) unsigned ?; `titel` varchar(255) ?; `is_cover` tinyint(1); `sort_index` int(11) ?; `hochgeladen_von` int(11) ?; `created_at` datetime; `updated_at` datetime.

Indizes: `fk_pendfile_user` (hochgeladen_von); `idx_cover` (pendenz_id, is_cover); `idx_pendenz` (pendenz_id); `idx_sort` (pendenz_id, sort_index); `idx_typ` (typ); `PRIMARY` (id) UNIQUE.

### A. pendenz_events

Engine: InnoDB.

`id` int(11) PK AI; `pendenz_id` int(11); `event` varchar(64); `actor` varchar(64) ?; `details` longtext ?; `created_at` timestamp.

Indizes: `idx_pe_event` (event); `idx_pe_pend` (pendenz_id); `PRIMARY` (id) UNIQUE.

### A. pendenz_export_profiles

Engine: InnoDB.

`id` int(11) PK AI; `mandant_id` int(11) ?; `name` varchar(100); `columns_json` text; `is_default` tinyint(1); `created_by` int(11); `created_at` datetime.

Indizes: `PRIMARY` (id) UNIQUE.

### A. pendenz_field_defs

Engine: InnoDB.

`id` int(11) PK AI; `mandant_id` int(11) ?; `field_key` varchar(64); `label` varchar(120); `type` enum('text','number','date','select','checkbox'); `options_json` longtext ?; `required` tinyint(1); `sort_order` int(11); `enabled` tinyint(1); `created_at` datetime ?.

Indizes: `field_key` (field_key) UNIQUE; `PRIMARY` (id) UNIQUE.

### A. pendenz_kategorien

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(190); `vorlagen_welt` varchar(30); `projekt_id` int(11) ?; `objekt_id` int(11) ?; `sort_order` int(11) ?; `created_at` timestamp.

Indizes: `name` (name) UNIQUE; `PRIMARY` (id) UNIQUE; `projekt_id` (projekt_id).

### A. pendenz_kategorien_backup_20260418

Engine: InnoDB.

`id` int(11); `name` varchar(190); `vorlagen_welt` varchar(30); `projekt_id` int(11) ?; `objekt_id` int(11) ?; `sort_order` int(11) ?; `created_at` timestamp.

Indizes: .

### A. pendenz_kategorien_mieter

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(190); `projekt_id` int(11) ?; `objekt_id` int(11) ?; `sort_order` int(11) ?; `aktiv` tinyint(1); `created_at` timestamp; `updated_at` timestamp; `sortierung` int(11).

Indizes: `idx_pkm_objekt` (objekt_id); `idx_pkm_projekt` (projekt_id); `idx_pkm_sort` (sort_order, name); `PRIMARY` (id) UNIQUE.

### A. pendenz_kategorien_vermieter

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(190); `projekt_id` int(11) ?; `objekt_id` int(11) ?; `sort_order` int(11) ?; `aktiv` tinyint(1); `created_at` timestamp; `updated_at` timestamp; `sortierung` int(11).

Indizes: `idx_pkv_objekt` (objekt_id); `idx_pkv_projekt` (projekt_id); `idx_pkv_sort` (sort_order, name); `PRIMARY` (id) UNIQUE.

### A. pendenz_kommentare

Engine: InnoDB.

`id` int(11) PK AI; `pendenz_id` int(11); `text` text; `erstellt_am` timestamp; `erstellt_von` varchar(50) ?.

Indizes: `pendenz_id` (pendenz_id); `PRIMARY` (id) UNIQUE.

### A. pendenz_ordner

Engine: InnoDB.

`id` int(11) unsigned PK AI; `projekt_id` int(11) ?; `parent_id` int(11) unsigned ?; `name` varchar(190); `slug` varchar(190); `projekt_id_key` int(11) ?; `parent_id_key` int(11) ?; `created_at` datetime; `updated_at` datetime.

Indizes: `idx_parent` (parent_id); `idx_proj` (projekt_id); `PRIMARY` (id) UNIQUE; `uq_ordner_slug` (projekt_id_key, parent_id_key, slug) UNIQUE.

### A. pendenz_subkategorien

Engine: InnoDB.

`id` int(11) PK AI; `kategorie_id` int(11); `name` varchar(190); `vorlagen_welt` varchar(30); `projekt_id` int(11) ?; `objekt_id` int(11) ?; `sort_order` int(11) ?; `created_at` timestamp.

Indizes: `kategorie_id` (kategorie_id); `PRIMARY` (id) UNIQUE; `projekt_id` (projekt_id).

### A. pendenz_subkategorien_backup_20260418

Engine: InnoDB.

`id` int(11); `kategorie_id` int(11); `name` varchar(190); `vorlagen_welt` varchar(30); `projekt_id` int(11) ?; `objekt_id` int(11) ?; `sort_order` int(11) ?; `created_at` timestamp.

Indizes: .

### A. pendenz_subkategorien_mieter

Engine: InnoDB.

`id` int(11) PK AI; `kategorie_id` int(11); `name` varchar(190); `projekt_id` int(11) ?; `objekt_id` int(11) ?; `sort_order` int(11) ?; `aktiv` tinyint(1); `created_at` timestamp; `updated_at` timestamp; `beschreibung` text ?; `sortierung` int(11).

Indizes: `idx_pskm_kategorie` (kategorie_id); `idx_pskm_objekt` (objekt_id); `idx_pskm_projekt` (projekt_id); `idx_pskm_sort` (sort_order, name); `PRIMARY` (id) UNIQUE.

### A. pendenz_subkategorien_vermieter

Engine: InnoDB.

`id` int(11) PK AI; `kategorie_id` int(11); `name` varchar(190); `projekt_id` int(11) ?; `objekt_id` int(11) ?; `sort_order` int(11) ?; `aktiv` tinyint(1); `created_at` timestamp; `updated_at` timestamp; `beschreibung` text ?; `sortierung` int(11).

Indizes: `idx_pskv_kategorie` (kategorie_id); `idx_pskv_objekt` (objekt_id); `idx_pskv_projekt` (projekt_id); `idx_pskv_sort` (sort_order, name); `PRIMARY` (id) UNIQUE.

### A. pendenz_tokens

Engine: InnoDB.

`id` int(11) PK AI; `pendenz_id` int(11); `email` varchar(255) ?; `token` varchar(64); `permissions` longtext ?; `expires_at` datetime ?; `used_at` datetime ?; `created_at` timestamp.

Indizes: `fk_pend_tok_pend` (pendenz_id); `PRIMARY` (id) UNIQUE; `token` (token) UNIQUE.

### A. pendenz_views

Engine: InnoDB.

`id` int(11) PK AI; `pendenz_id` int(11); `user_id` int(11); `viewed_at` timestamp.

Indizes: `idx_views_pendenz` (pendenz_id); `idx_views_user` (user_id); `PRIMARY` (id) UNIQUE; `uq_views` (pendenz_id, user_id) UNIQUE.

### A. person_statuses

Engine: InnoDB.

`id` int(11) PK AI; `type_id` int(11); `slug` varchar(64); `name` varchar(128); `sort_index` int(11); `active` tinyint(1); `created_at` timestamp; `sort` int(11); `is_default` tinyint(1).

Indizes: `idx_type_sort` (type_id, sort); `PRIMARY` (id) UNIQUE; `uniq_type_slug` (type_id, slug) UNIQUE; `uq_type_name` (type_id, name) UNIQUE; `uq_type_slug` (type_id, slug) UNIQUE.

### A. person_types

Engine: InnoDB.

`id` int(11) PK AI; `slug` varchar(64); `name` varchar(128); `sort` int(11); `active` tinyint(1); `created_at` timestamp.

Indizes: `idx_sort` (sort); `PRIMARY` (id) UNIQUE; `slug` (slug) UNIQUE; `uq_name` (name) UNIQUE; `uq_slug` (slug) UNIQUE.

### A. plan_zonen

Engine: InnoDB.

`id` int(11) PK AI; `plan_id` int(11); `wohnung_id` int(11); `x_pct` float; `y_pct` float; `width_pct` float; `height_pct` float.

Indizes: `PRIMARY` (id) UNIQUE.

### A. posts

Engine: InnoDB.

`id` int(11) PK AI; `projekt_id` int(11); `author_id` int(11); `type` enum('post','announcement','task') ?; `visibility` enum('private','internal','project','public') ?; `audience_json` longtext ?; `content` text; `pinned` tinyint(1) ?; `created_at` datetime; `updated_at` datetime ?.

Indizes: `author_id` (author_id); `PRIMARY` (id) UNIQUE; `projekt_id` (projekt_id).

### A. profile_tokens

Engine: InnoDB.

`id` int(11) PK AI; `user_id` int(11); `token` varchar(64); `required_fields` longtext ?; `optional_fields` longtext ?; `expires_at` datetime ?; `used_at` datetime ?; `created_at` timestamp.

Indizes: `fk_profile_tokens_user` (user_id); `PRIMARY` (id) UNIQUE; `token` (token) UNIQUE.

### A. project_dash_cards

Engine: InnoDB.

`id` bigint(20) unsigned PK AI; `project_id` int(11); `rel_path` varchar(1024); `title` varchar(255) ?; `cover_path` varchar(1024) ?; `sort` int(11); `show_in_dashboard` tinyint(1).

Indizes: `idx_proj_sort` (project_id, sort); `PRIMARY` (id) UNIQUE; `uq_proj_rel` (project_id, rel_path) UNIQUE.

### A. projekte

Engine: InnoDB; aktuell gezählte Zeilen: 12.

`id` int(11) PK AI; `nummer` varchar(50) ?; `sort_index` int(11) ?; `name` varchar(255); `beschreibung` text ?; `root_path` varchar(1024) ?; `storage_type` enum('local','cloud') ?; `adresse` varchar(255) ?; `startdatum` date ?; `enddatum` date ?; `status` enum('grundstück','planung','bewilligung','bau','vermietung','verkauft','aktiv','geplant','laufend','abgeschlossen','archiviert') ?; `kategorie` varchar(100) ?; `pendenzen_profile_id` int(11) ?; `bild` varchar(255) ?; `profilbild` varchar(500) ?; `erstellt_am` timestamp; `aktualisiert_am` timestamp; `erstellt_von` int(11) ?; `geaendert_am` timestamp; `deleted_at` datetime ?; `created_at` datetime ?; `updated_at` datetime ?; `fs_rel_path` varchar(255) ?; `wohnungen_rel_path` varchar(500) ?.

Indizes: `idx_projekte_erstellt_am` (erstellt_am); `idx_projekte_sort_index` (sort_index); `idx_projekte_status` (status); `PRIMARY` (id) UNIQUE.

### A. projekt_memberships

Engine: InnoDB; aktuell gezählte Zeilen: 0.

`id` int(11) PK AI; `projekt_id` int(11); `user_id` int(11); `role` enum('owner','manager','member','viewer') ?.

Indizes: `PRIMARY` (id) UNIQUE; `projekt_id` (projekt_id, user_id) UNIQUE; `user_id` (user_id).

### A. projekt_mitglieder

Engine: InnoDB; aktuell gezählte Zeilen: 0.

`id` int(11) PK AI; `projekt_id` int(11); `benutzer_id` int(11); `rolle` enum('owner','manager','mitarbeiter','gast') ?; `hinzugefuegt_von` int(11) ?; `erstellt_am` timestamp.

Indizes: `idx_pm_benutzer` (benutzer_id); `idx_pm_hinzu` (hinzugefuegt_von); `idx_pm_projekt` (projekt_id); `PRIMARY` (id) UNIQUE; `uq_pm` (projekt_id, benutzer_id) UNIQUE.

### A. projekt_plaene

Engine: InnoDB.

`id` int(11) PK AI; `projekt_id` int(11); `name` varchar(255); `datei_pfad` varchar(255); `created_at` datetime ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. projekt_verknuepfungen

Engine: InnoDB.

`id` bigint(20) unsigned PK AI; `projekt_id` int(11); `liegenschaft_id` int(11); `einheit_id` int(11); `mieter_id` int(11) ?; `konto_id` int(11) ?; `fs_rel_path` varchar(1024) ?; `ordner_id` int(11) ?; `status` enum('aktiv','historisch','geplant'); `mietzins_netto` decimal(10,2) ?; `nk_akonto` decimal(10,2) ?; `beginn` date ?; `ende` date ?; `bemerkung` text ?.

Indizes: `idx_beginn` (beginn); `idx_einheit` (einheit_id); `idx_ende` (ende); `idx_konto` (konto_id); `idx_lieg` (liegenschaft_id); `idx_mieter` (mieter_id); `idx_ordner` (ordner_id); `idx_proj` (projekt_id); `idx_status` (status); `PRIMARY` (id) UNIQUE; `uniq_einheit_aktiv` (einheit_id, status) UNIQUE.

### A. projekt_vorlagen

Engine: InnoDB.

`id` int(11) PK AI; `projekt_id` int(11); `vorlage_id` int(11).

Indizes: `PRIMARY` (id) UNIQUE; `vorlage_id` (vorlage_id).

### A. protokoll_formulare

Engine: InnoDB.

`id` int(11) PK AI; `vorlage_id` int(11); `name` varchar(255); `json_data` text ?; `created_at` timestamp.

Indizes: `PRIMARY` (id) UNIQUE.

### A. protokoll_typen

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(255); `icon` varchar(50) ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. protokoll_vorlagen

Engine: InnoDB.

`id` int(11) PK AI; `typ_id` int(11); `name` varchar(255); `json_data` text ?; `is_default` tinyint(1) ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. raeume

Engine: InnoDB; aktuell gezählte Zeilen: 201.

`id` int(11) PK AI; `wohnung_id` int(11); `name` varchar(255) ?; `sort_order` int(11) ?.

Indizes: `PRIMARY` (id) UNIQUE; `wohnung_id` (wohnung_id).

### A. raum_vorlagen

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(100); `icon` varchar(50) ?; `default_sort` int(11) ?.

Indizes: `idx_unique_name` (name) UNIQUE; `PRIMARY` (id) UNIQUE.

### A. settings

Engine: InnoDB.

`k` varchar(191) PK; `v` mediumtext; `updated_at` timestamp.

Indizes: `PRIMARY` (k) UNIQUE.

### A. smarttable_columns

Engine: InnoDB.

`id` bigint(20) unsigned PK AI; `user_id` int(11); `table_key` varchar(64); `column_key` varchar(64); `label` varchar(255); `enabled` tinyint(1); `sort_index` int(11); `width` int(11) ?.

Indizes: `idx_user_table` (user_id, table_key); `PRIMARY` (id) UNIQUE; `uq_user_table_col` (user_id, table_key, column_key) UNIQUE.

### A. struktur_vorlagen

Engine: InnoDB.

`id` int(11) PK AI; `user_id` int(11); `name` varchar(255); `description` text; `max_depth` int(11); `created_at` timestamp.

Indizes: `PRIMARY` (id) UNIQUE.

### A. system_settings

Engine: InnoDB.

`s_key` varchar(50) PK; `s_value` text ?.

Indizes: `PRIMARY` (s_key) UNIQUE.

### A. teams

Engine: InnoDB.

`id` bigint(20) unsigned PK AI; `projekt_id` int(11) ?; `name` varchar(100).

Indizes: `PRIMARY` (id) UNIQUE.

### A. team_projekte

Engine: InnoDB.

`team_id` bigint(20) unsigned PK; `projekt_id` bigint(20) unsigned PK.

Indizes: `idx_tp_projekt` (projekt_id); `idx_tp_team` (team_id); `PRIMARY` (team_id, projekt_id) UNIQUE.

### A. teilnehmer

Engine: InnoDB.

`id` int(11) PK AI; `unterkategorie_id` int(11); `user_id` int(11) ?; `rolle` varchar(50) ?.

Indizes: `PRIMARY` (id) UNIQUE; `unterkategorie_id` (unterkategorie_id).

### A. testtabellesetings

Engine: InnoDB.

`id` int(11) PK AI; `feld1` text ?; `created_at` datetime ?; `updated_at` datetime ?; `deleted_at` datetime ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. unterkategorien

Engine: InnoDB.

`id` int(11) PK AI; `vorlage_id` int(11); `projekt_id` int(11) ?; `parent_id` int(11) ?; `name_variable` varchar(50); `label_default` varchar(255); `position` int(11) ?; `code` varchar(50) ?.

Indizes: `idx_projekt_id` (projekt_id); `parent_id` (parent_id); `PRIMARY` (id) UNIQUE; `unique_code_per_vorlage` (vorlage_id, code) UNIQUE; `uniq_projekt_code` (projekt_id, code) UNIQUE; `uq_vorlage_code` (vorlage_id, code) UNIQUE.

### A. unternehmer_projekte

Engine: InnoDB.

`id` int(11) PK AI; `benutzer_id` int(11); `projekt_id` int(11); `bkp_id` int(11) ?.

Indizes: `benutzer_id` (benutzer_id); `bkp_id` (bkp_id); `PRIMARY` (id) UNIQUE; `projekt_id` (projekt_id).

### A. users

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(255).

Indizes: `PRIMARY` (id) UNIQUE.

### A. user_events

Engine: InnoDB.

`id` int(11) PK AI; `user_id` int(11); `type` varchar(40); `meta` longtext ?; `created_at` datetime.

Indizes: `PRIMARY` (id) UNIQUE; `user_id` (user_id).

### A. user_invites

Engine: InnoDB.

`id` int(11) PK AI; `user_id` int(11); `token` char(64); `expires_at` datetime; `used_at` datetime ?; `created_at` datetime.

Indizes: `idx_user` (user_id); `PRIMARY` (id) UNIQUE; `token_unique` (token) UNIQUE.

### A. user_notifications

Engine: InnoDB; aktuell gezählte Zeilen: 0.

`id` int(11) PK AI; `user_id` int(11); `message` varchar(500); `link_url` varchar(500) ?; `created_at` datetime; `seen_at` datetime ?.

Indizes: `idx_un_user_seen` (user_id, seen_at); `PRIMARY` (id) UNIQUE.

### A. vermietungseinheiten

Engine: InnoDB.

`id` int(11) PK AI; `name` varchar(160); `projekt_id` int(11) ?; `liegenschaft_id` int(11) ?; `adresse` varchar(255) ?; `ve_code` varchar(60) ?; `mieter_benutzer_id` int(11) ?; `flaeche_m2` decimal(10,2) ?; `miete_monat` decimal(12,2) ?; `nk_monat` decimal(12,2) ?; `fs_rel_path` varchar(255) ?; `created_at` datetime; `updated_at` datetime.

Indizes: `idx_lieg` (liegenschaft_id); `idx_mieter` (mieter_benutzer_id); `idx_proj` (projekt_id); `PRIMARY` (id) UNIQUE.

### A. vorgangsart

Engine: InnoDB.

`id` int(11) PK AI; `bezeichnung` varchar(100); `status` enum('aktiv','inaktiv') ?.

Indizes: `PRIMARY` (id) UNIQUE.

### A. warteliste

Engine: InnoDB.

`id` int(11) PK AI; `benutzer_id` int(11); `liegenschaft_id` int(11) ?; `objekt_id` int(11) ?; `wunsch_zimmer_min` decimal(3,1) ?; `budget_max` decimal(10,2) ?; `status` enum('aktiv','kontaktiert','erledigt') ?; `created_at` timestamp.

Indizes: `PRIMARY` (id) UNIQUE.

### A. wohnungen

Engine: InnoDB; aktuell gezählte Zeilen: 31.

`id` int(11) PK AI; `objekt_id` int(11); `name` varchar(255); `status` varchar(50) ?; `folder_name` varchar(255) ?; `grundriss_pfad` varchar(500) ?; `typ` enum('wohnung','parkplatz','tiefgarage','allgemein','lager') ?; `typ_id` int(11) ?; `etage` varchar(50) ?; `zimmer` decimal(3,1) ?; `baeder` decimal(3,1) ?; `ausrichtung` varchar(50) ?; `badezimmer` decimal(3,1) ?; `balkon` tinyint(1) ?; `wintergarten` tinyint(1) ?; `ausstattung_details` longtext ?; `terrasse` tinyint(1) ?; `apply_token` varchar(64) ?; `is_published` tinyint(1) ?; `inserat_id` varchar(100) ?; `available_from` date ?; `letzter_renovation` date ?; `gesamtzustand` varchar(100) ?; `flaeche` decimal(10,2) ?; `mietzins_netto_soll` decimal(10,2) ?; `mietzins_nk_soll` decimal(10,2) ?; `profilbild` varchar(500) ?; `extra` longtext ?; `erstellt_am` timestamp; `updated_at` timestamp; `baujahr` int(11) ?; `heizungsart` varchar(100) ?; `bodenbelag` varchar(255) ?; `kueche_details` text ?; `bad_details` text ?; `lift` tinyint(1) ?; `barrierefrei` tinyint(1) ?; `haustiere_erlaubt` tinyint(1) ?; `waschmaschine` varchar(100) ?; `keller_vorhanden` tinyint(1) ?; `parkplatz` varchar(100) ?; `minergie` tinyint(1) ?; `glasfaser` tinyint(1) ?; `besonnerung` varchar(100) ?; `aussicht` varchar(100) ?; `laermpegel` varchar(100) ?; `titelbild_pfad` varchar(255) ?.

Indizes: `apply_token` (apply_token) UNIQUE; `idx_wohnungen_objekt_name` (objekt_id, name); `PRIMARY` (id) UNIQUE.

### A. wohnung_bilder

Engine: InnoDB.

`id` int(11) PK AI; `wohnung_id` int(11); `pfad` varchar(500); `is_cover` tinyint(1) ?.

Indizes: `PRIMARY` (id) UNIQUE; `wohnung_id` (wohnung_id).

### A. wohnung_dokumente

Engine: InnoDB.

`id` int(11) PK AI; `wohnung_id` int(11); `name` varchar(255) ?; `pfad` varchar(500); `typ` varchar(50) ?.

Indizes: `PRIMARY` (id) UNIQUE; `wohnung_id` (wohnung_id).

### A. wohnung_mieter

Engine: InnoDB.

`id` int(11) PK AI; `wohnung_id` int(11); `benutzer_id` int(11); `kontakt_id` int(11) ?; `mieter_name` varchar(255) ?; `mietzins_netto` decimal(10,2) ?; `nk_akonto` decimal(10,2) ?; `rolle` enum('vormieter','mieter','nachmieter'); `startdatum` date ?; `enddatum` date ?; `status` varchar(50) ?; `extra` longtext ?; `erstellt_am` timestamp.

Indizes: `benutzer_id` (benutzer_id); `PRIMARY` (id) UNIQUE; `wohnung_id` (wohnung_id).

### A. zimmer

Engine: InnoDB.

`id` int(11) PK AI; `wohnung_id` int(11); `bezeichnung` varchar(255); `nutzung` varchar(100) ?; `profilbild` varchar(500) ?; `extra` longtext ?; `erstellt_am` timestamp.

Indizes: `PRIMARY` (id) UNIQUE; `wohnung_id` (wohnung_id).

## Anhang B – vollständige deklarierte Fremdschlüssel

Das Register enthält die tatsächlich deklarierten FK-Spaltenbeziehungen. Zusammengehörige Zeilen mit gleichem Constraintnamen bilden gegebenenfalls einen mehrspaltigen FK. Nicht deklarierte fachliche Beziehungen erscheinen hier nicht.

| Quelle | Ziel | Constraint | ON UPDATE | ON DELETE |
|---|---|---|---|---|
| `ai_messages.chat_id` | `ai_chats.id` | ai_messages_ibfk_1 | RESTRICT | CASCADE |
| `anhaenge.pendenz_id` | `pendenzen.id` | fk_anhaenge_pendenz | RESTRICT | CASCADE |
| `audit_log.actor_id` | `benutzer.id` | fk_audit_actor | RESTRICT | SET NULL |
| `benachrichtigungen.user_id` | `users.id` | fk_benach_user | RESTRICT | CASCADE |
| `benutzer.person_status_id` | `person_statuses.id` | fk_benutzer_person_status | RESTRICT | SET NULL |
| `benutzer.person_type_id` | `person_types.id` | fk_benutzer_person_type | RESTRICT | SET NULL |
| `benutzer_profile.benutzer_id` | `benutzer.id` | fk_bp_benutzer | RESTRICT | CASCADE |
| `benutzer_profile.firma_id` | `firmen.id` | fk_bp_firma | RESTRICT | SET NULL |
| `benutzer_profile.objekt_id` | `objekte.id` | fk_bp_objekt | RESTRICT | SET NULL |
| `benutzer_profile.projekt_id` | `projekte.id` | fk_bp_projekt | RESTRICT | SET NULL |
| `benutzer_profile.vorgangsart_id` | `pendenzen_arten.id` | fk_bp_vorgangsart | RESTRICT | SET NULL |
| `benutzer_profile.wohnung_id` | `wohnungen.id` | fk_bp_wohnung | RESTRICT | SET NULL |
| `bkp_codes.parent_id` | `bkp_codes.id` | bkp_codes_ibfk_1 | RESTRICT | CASCADE |
| `bkp_kategorien.bkp_id` | `bkp_codes.id` | bkp_kategorien_ibfk_1 | RESTRICT | CASCADE |
| `bkp_vorlagen_texte.kategorie_id` | `bkp_kategorien.id` | bkp_vorlagen_texte_ibfk_1 | RESTRICT | CASCADE |
| `chat_attachments.message_id` | `chat_messages.id` | fk_chat_attachments_message | CASCADE | CASCADE |
| `chat_members.room_id` | `chat_rooms.id` | fk_chat_members_room | CASCADE | CASCADE |
| `chat_members.user_id` | `benutzer.id` | fk_chat_members_user | CASCADE | CASCADE |
| `chat_messages.room_id` | `chat_rooms.id` | fk_chat_messages_room | CASCADE | CASCADE |
| `chat_messages.sender_id` | `benutzer.id` | fk_chat_messages_sender | CASCADE | SET NULL |
| `chat_messages_pendenzen.message_id` | `chat_messages.id` | fk_cmp_message | CASCADE | CASCADE |
| `chat_message_recipients.message_id` | `chat_messages.id` | fk_cmr_message | CASCADE | CASCADE |
| `chat_message_recipients.recipient_user_id` | `benutzer.id` | fk_cmr_user | CASCADE | CASCADE |
| `chat_rooms.created_by` | `benutzer.id` | fk_chat_rooms_creator | CASCADE | RESTRICT |
| `comments.post_id` | `posts.id` | comments_ibfk_1 | RESTRICT | CASCADE |
| `comments.author_id` | `benutzer.id` | comments_ibfk_2 | RESTRICT | CASCADE |
| `documents.folder_id` | `folders.id` | fk_docs_folder | CASCADE | RESTRICT |
| `favoriten.unterkategorie_id` | `unterkategorien.id` | favoriten_ibfk_1 | RESTRICT | CASCADE |
| `files.post_id` | `posts.id` | files_ibfk_1 | RESTRICT | CASCADE |
| `files.comment_id` | `comments.id` | files_ibfk_2 | RESTRICT | CASCADE |
| `firma.benutzer_id` | `benutzer.id` | firma_ibfk_1 | RESTRICT | CASCADE |
| `firma_user.firma_id` | `firmen.id` | fk_fu_firma | RESTRICT | CASCADE |
| `firma_user.user_id` | `benutzer.id` | fk_fu_user | RESTRICT | CASCADE |
| `firmen_overrides.firma_id` | `firmen.id` | fk_fo_firma | RESTRICT | CASCADE |
| `firmen_overrides.benutzer_id` | `benutzer.id` | fk_fo_user | RESTRICT | CASCADE |
| `folders.parent_id` | `folders.id` | fk_folders_parent | CASCADE | RESTRICT |
| `gegenstaende.zimmer_id` | `zimmer.id` | fk_gegenstaende_zimmer | RESTRICT | CASCADE |
| `interessenten.wohnung_id` | `wohnungen.id` | interessenten_ibfk_1 | RESTRICT | SET NULL |
| `konto_buchungen.ordner_link_id` | `ordner_links.id` | fk_kb_link | RESTRICT | CASCADE |
| `konto_rules.liegenschaft_id` | `projekte.id` | fk_rules_proj | RESTRICT | SET NULL |
| `kv_buchungen.konto_id` | `kv_konten.id` | kv_buchungen_ibfk_1 | RESTRICT | SET NULL |
| `kv_buchungen.import_batch_id` | `kv_import_batches.id` | kv_buchungen_ibfk_2 | RESTRICT | SET NULL |
| `kv_buchung_items.buchung_id` | `kv_buchungen.id` | kv_buchung_items_ibfk_1 | RESTRICT | CASCADE |
| `kv_import_batches.konto_id` | `kv_konten.id` | kv_import_batches_ibfk_1 | RESTRICT | SET NULL |
| `liegenschafts_konto.liegenschaft_id` | `projekte.id` | fk_konto_projekt | RESTRICT | SET NULL |
| `liegenschafts_konto.eigentuemer_id` | `benutzer.id` | fk_lk_eigentuemer | RESTRICT | SET NULL |
| `liegenschafts_konto.import_benutzer_id` | `benutzer.id` | fk_lk_import_user | RESTRICT | SET NULL |
| `liegenschafts_konto.mieter_id` | `benutzer.id` | fk_lk_mieter | RESTRICT | SET NULL |
| `listen_spalten.listen_id` | `listen.id` | fk_listen_columns_list | RESTRICT | CASCADE |
| `mieten_tarife.ordner_link_id` | `ordner_links.id` | fk_mt_link | RESTRICT | CASCADE |
| `miete_adjustments.liegenschaft_id` | `projekte.id` | fk_adj_proj | RESTRICT | CASCADE |
| `miete_schedules.liegenschaft_id` | `projekte.id` | fk_sched_proj | RESTRICT | CASCADE |
| `objekte.projekt_id` | `projekte.id` | fk_objekte_projekt | RESTRICT | CASCADE |
| `ordner.parent_id` | `ordner.id` | fk_ordner_parent | CASCADE | SET NULL |
| `ordner_vorlagen_nodes.vorlage_id` | `ordner_vorlagen.id` | fk_ovn_v | RESTRICT | CASCADE |
| `ordner_vorlage_applied.projekt_id` | `projekte.id` | fk_ova_proj | RESTRICT | CASCADE |
| `ordner_vorlage_applied.vorlage_id` | `ordner_vorlagen.id` | fk_ova_v | RESTRICT | RESTRICT |
| `ordner_vorlage_applied_nodes.applied_id` | `ordner_vorlage_applied.id` | fk_ovan_a | RESTRICT | CASCADE |
| `pendenzen.art_id` | `pendenzen_arten.id` | fk_pendenz_art | RESTRICT | SET NULL |
| `pendenzen.bkp_id` | `bkp_codes.id` | fk_pendenz_bkp | RESTRICT | SET NULL |
| `pendenzen.raum_id` | `raeume.id` | fk_pendenz_raum | RESTRICT | SET NULL |
| `pendenzen.wohnung_id` | `wohnungen.id` | fk_pendenz_wohnung | RESTRICT | SET NULL |
| `pendenzen.empfaenger_benutzer_id` | `benutzer.id` | fk_pend_empfaenger_benutzer | RESTRICT | SET NULL |
| `pendenzen.empfaenger_profil_id` | `benutzer_profile.id` | fk_pend_empfaenger_profil | RESTRICT | SET NULL |
| `pendenzen.erstellt_von` | `benutzer.id` | fk_pend_ersteller | RESTRICT | SET NULL |
| `pendenzen.ersteller_benutzer_id` | `benutzer.id` | fk_pend_ersteller_benutzer | RESTRICT | SET NULL |
| `pendenzen.ordner_id` | `pendenz_ordner.id` | fk_pend_ordner | RESTRICT | SET NULL |
| `pendenzen.projekt_id` | `projekte.id` | fk_pend_proj | RESTRICT | CASCADE |
| `pendenzen.zustaendig_id` | `benutzer.id` | fk_pend_zust | RESTRICT | SET NULL |
| `pendenzen_arten.default_benutzer_id` | `benutzer.id` | fk_pa_default_benutzer | CASCADE | SET NULL |
| `pendenzen_arten.default_objekt_id` | `objekte.id` | fk_pa_default_objekt | CASCADE | SET NULL |
| `pendenzen_arten.default_projekt_id` | `projekte.id` | fk_pa_default_projekt | CASCADE | SET NULL |
| `pendenzen_arten.default_wohnung_id` | `wohnungen.id` | fk_pa_default_wohnung | CASCADE | SET NULL |
| `pendenzen_art_empfaenger_defaults.pendenz_art_id` | `pendenzen_arten.id` | fk_paed_art | RESTRICT | CASCADE |
| `pendenzen_meta.pendenz_id` | `pendenzen.id` | fk_pm_pendenz | RESTRICT | CASCADE |
| `pendenz_acl.benutzer_id` | `benutzer.id` | fk_acl_benutzer | RESTRICT | CASCADE |
| `pendenz_acl.pendenz_id` | `pendenzen.id` | fk_acl_pendenz | RESTRICT | CASCADE |
| `pendenz_anhaenge.pendenz_id` | `pendenzen.id` | pendenz_anhaenge_ibfk_1 | RESTRICT | CASCADE |
| `pendenz_dateien.pendenz_id` | `pendenzen.id` | fk_pendfile_pend | RESTRICT | CASCADE |
| `pendenz_dateien.hochgeladen_von` | `benutzer.id` | fk_pendfile_user | RESTRICT | SET NULL |
| `pendenz_events.pendenz_id` | `pendenzen.id` | fk_pe_pend | RESTRICT | CASCADE |
| `pendenz_kommentare.pendenz_id` | `pendenzen.id` | pendenz_kommentare_ibfk_1 | RESTRICT | CASCADE |
| `pendenz_ordner.parent_id` | `pendenz_ordner.id` | fk_pord_parent | RESTRICT | SET NULL |
| `pendenz_ordner.projekt_id` | `projekte.id` | fk_pord_proj | RESTRICT | CASCADE |
| `pendenz_subkategorien.kategorie_id` | `pendenz_kategorien.id` | fk_pend_subcat_cat | RESTRICT | CASCADE |
| `pendenz_subkategorien_mieter.kategorie_id` | `pendenz_kategorien_mieter.id` | fk_pskm_kategorie | RESTRICT | CASCADE |
| `pendenz_subkategorien_vermieter.kategorie_id` | `pendenz_kategorien_vermieter.id` | fk_pskv_kategorie | RESTRICT | CASCADE |
| `pendenz_tokens.pendenz_id` | `pendenzen.id` | fk_pend_tok_pend | RESTRICT | CASCADE |
| `pendenz_views.pendenz_id` | `pendenzen.id` | fk_views_pendenz | RESTRICT | CASCADE |
| `pendenz_views.user_id` | `benutzer.id` | fk_views_user | RESTRICT | CASCADE |
| `person_statuses.type_id` | `person_types.id` | fk_ps_type | RESTRICT | CASCADE |
| `person_statuses.type_id` | `person_types.id` | person_statuses_ibfk_1 | RESTRICT | CASCADE |
| `posts.projekt_id` | `projekte.id` | posts_ibfk_1 | RESTRICT | CASCADE |
| `posts.author_id` | `benutzer.id` | posts_ibfk_2 | RESTRICT | CASCADE |
| `profile_tokens.user_id` | `benutzer.id` | fk_profile_tokens_user | RESTRICT | CASCADE |
| `project_dash_cards.project_id` | `projekte.id` | fk_pdc_proj | RESTRICT | CASCADE |
| `projekt_memberships.projekt_id` | `projekte.id` | projekt_memberships_ibfk_1 | RESTRICT | CASCADE |
| `projekt_memberships.user_id` | `benutzer.id` | projekt_memberships_ibfk_2 | RESTRICT | CASCADE |
| `projekt_mitglieder.benutzer_id` | `benutzer.id` | fk_pm_benutzer | RESTRICT | CASCADE |
| `projekt_mitglieder.hinzugefuegt_von` | `benutzer.id` | fk_pm_hinzu | RESTRICT | SET NULL |
| `projekt_mitglieder.projekt_id` | `projekte.id` | fk_pm_projekt | RESTRICT | CASCADE |
| `projekt_verknuepfungen.einheit_id` | `vermietungseinheiten.id` | fk_pv_einheit | CASCADE | RESTRICT |
| `projekt_verknuepfungen.konto_id` | `konten.id` | fk_pv_konto | CASCADE | SET NULL |
| `projekt_verknuepfungen.liegenschaft_id` | `liegenschaften.id` | fk_pv_liegenschaft | CASCADE | RESTRICT |
| `projekt_verknuepfungen.mieter_id` | `mieter.id` | fk_pv_mieter | CASCADE | SET NULL |
| `projekt_verknuepfungen.ordner_id` | `ordner.id` | fk_pv_ordner | CASCADE | SET NULL |
| `projekt_verknuepfungen.projekt_id` | `projekte.id` | fk_pv_projekt | CASCADE | RESTRICT |
| `raeume.wohnung_id` | `wohnungen.id` | raeume_ibfk_1 | RESTRICT | CASCADE |
| `teilnehmer.unterkategorie_id` | `unterkategorien.id` | teilnehmer_ibfk_1 | RESTRICT | CASCADE |
| `unterkategorien.vorlage_id` | `struktur_vorlagen.id` | unterkategorien_ibfk_1 | RESTRICT | CASCADE |
| `unterkategorien.parent_id` | `unterkategorien.id` | unterkategorien_ibfk_2 | RESTRICT | CASCADE |
| `unternehmer_projekte.benutzer_id` | `benutzer.id` | unternehmer_projekte_ibfk_1 | RESTRICT | CASCADE |
| `unternehmer_projekte.projekt_id` | `projekte.id` | unternehmer_projekte_ibfk_2 | RESTRICT | CASCADE |
| `unternehmer_projekte.bkp_id` | `bkp_codes.id` | unternehmer_projekte_ibfk_3 | RESTRICT | SET NULL |
| `user_events.user_id` | `benutzer.id` | fk_user_events_user | RESTRICT | CASCADE |
| `user_invites.user_id` | `benutzer.id` | fk_invites_user | RESTRICT | CASCADE |
| `wohnungen.objekt_id` | `objekte.id` | wohnungen_ibfk_1 | RESTRICT | CASCADE |
| `wohnung_bilder.wohnung_id` | `wohnungen.id` | wohnung_bilder_ibfk_1 | RESTRICT | CASCADE |
| `wohnung_dokumente.wohnung_id` | `wohnungen.id` | wohnung_dokumente_ibfk_1 | RESTRICT | CASCADE |
| `wohnung_mieter.wohnung_id` | `wohnungen.id` | wohnung_mieter_ibfk_1 | RESTRICT | CASCADE |
| `wohnung_mieter.benutzer_id` | `benutzer.id` | wohnung_mieter_ibfk_2 | RESTRICT | CASCADE |
| `zimmer.wohnung_id` | `wohnungen.id` | zimmer_ibfk_1 | RESTRICT | CASCADE |

## Anhang C – aktuelles PHP-Datei-/Tabellenregister

Alle 835 erfassten PHP-Dateien außerhalb Vendor/Uploads/Storage/Logs. Das Register unterscheidet Hauptbaum und erkennbare Kopien. Auch ein Root-Debugskript ist enthalten, weil es direkt erreichbar sein könnte. Statische SQL-Referenzen wurden gegen das tatsächlich vorhandene Schema abgeglichen; dynamische Tabellenbezüge und indirekte Includes können zusätzliche Tabellen verwenden. `–` bedeutet keine direkt erkannte Referenz, nicht garantiert keine DB-Nutzung.

Die letzte Spalte nennt lediglich im Text gefundene Funktionsnamen. Sie kann Definitionen, Kommentare oder bedingte Aufrufe enthalten und ist **kein Nachweis wirksamer Autorisierung**. Insbesondere `_bootstrap.php` und der Kommentar in `pendenzen_inline_save.php` schützen nicht automatisch. Direkte Session-/Rollenvergleiche sind in dieser Suchspalte nicht vollständig repräsentiert; die manuell geprüfte Matrix in Abschnitt 5.5 ist maßgeblich.

### C.1 Hauptbaum einschließlich Hilfs-/Wartungsskripten

| Datei | Direkt erkannte aktuelle Tabellen | Prüfhelfer-Suchmarker |
|---|---|---|
| `api/_bootstrap.php` | – | require_superadmin_json |
| `api/ai_chat_messages.php` | `ai_chats`, `ai_messages` | require_login |
| `api/ai_query.php` | `ai_chats`, `ai_messages`, `pendenzen` | require_login |
| `api/batch_update.php` | – | require_superadmin_json |
| `api/benutzer_create.php` | `benutzer` | – |
| `api/benutzer_delete.php` | `benutzer` | – |
| `api/benutzer_update.php` | `benutzer` | – |
| `api/bootstrap.php` | – | – |
| `api/chat_list.php` | `benutzer`, `chat_members`, `chat_messages` | require_login |
| `api/chat_members.php` | `benutzer`, `chat_members` | require_login |
| `api/chat_messages.php` | `benutzer`, `chat_members`, `chat_message_recipients`, `chat_messages`, `chat_rooms` | require_login |
| `api/chat_rooms.php` | `benutzer`, `chat_members`, `chat_rooms` | require_login |
| `api/chat_send.php` | `chat_members`, `chat_message_recipients`, `chat_messages`, `chat_rooms` | require_login |
| `api/chat/create_room.php` | `benutzer`, `chat_members`, `chat_rooms`, `projekte` | – |
| `api/chat/delete_room.php` | `chat_members`, `chat_messages`, `chat_reads`, `chat_rooms`, `chat_typing` | – |
| `api/chat/list_rooms.php` | `chat_members`, `chat_messages`, `chat_reads`, `chat_rooms` | – |
| `api/chat/list_team_rooms.php` | `chat_members`, `chat_rooms` | – |
| `api/chat/messages.php` | `benutzer`, `chat_members`, `chat_rooms` | – |
| `api/chat/read.php` | `chat_members`, `chat_reads` | – |
| `api/chat/rooms.php` | `chat_members`, `chat_messages`, `chat_reads`, `chat_rooms` | – |
| `api/chat/search_companies.php` | – | – |
| `api/chat/search_projects.php` | – | – |
| `api/chat/search_users.php` | – | – |
| `api/chat/typing.php` | `chat_typing` | – |
| `api/dashboard_prefs.php` | – | – |
| `api/dashboard_upload.php` | `pendenz_dateien` | require_login |
| `api/dirlist.php` | – | – |
| `api/export_outlook.php` | `pendenzen`, `projekte` | require_login |
| `api/favoriten_toggle.php` | `favoriten` | require_login |
| `api/gantt_task_create.php` | `pendenzen` | – |
| `api/health.php` | – | – |
| `api/inspect_table.php` | `pendenz_field_defs` | require_login |
| `api/listen_delete.php` | `listen` | require_login |
| `api/listen_field_delete.php` | `pendenz_field_defs` | – |
| `api/listen_field_save.php` | `pendenz_field_defs` | – |
| `api/listen_index.php` | `listen` | require_login |
| `api/listen_one.php` | `listen`, `listen_spalten` | require_login |
| `api/listen_preview.php` | `benutzer`, `objekte`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `wohnungen` | require_login |
| `api/listen_save.php` | `listen`, `listen_spalten` | require_login |
| `api/manage_pdf_templates.php` | `pdf_templates`, `settings` | – |
| `api/manage_protocol_templates.php` | `protokoll_formulare`, `protokoll_typen`, `protokoll_vorlagen` | – |
| `api/migrate_lists.php` | `listen`, `pendenz_export_profiles` | – |
| `api/migrate_lists2.php` | `listen`, `pendenz_export_profiles` | – |
| `api/notifications_list.php` | `user_notifications` | require_login |
| `api/notifications_mark_seen.php` | `user_notifications` | require_login |
| `api/options_users.php` | `benutzer` | require_login |
| `api/pdf_templates.php` | `pdf_templates` | require_login |
| `api/pendenz_media.php` | `pendenz_dateien`, `pendenzen` | require_login, can_view_pendenz, can_edit_pendenz |
| `api/pendenz_set_cover.php` | `pendenz_dateien` | require_login |
| `api/pendenzen_delete.php` | `pendenzen` | – |
| `api/pendenzen_get.php` | `benutzer`, `pendenzen`, `projekte` | – |
| `api/pendenzen_inline_save.php` | `pendenzen` | – |
| `api/pendenzen_list.php` | `benutzer`, `objekte`, `pendenzen`, `projekte`, `raeume`, `wohnungen` | require_login |
| `api/pendenzen_preview.php` | `benutzer`, `objekte`, `pendenz_dateien`, `pendenzen`, `projekte`, `wohnungen` | require_superadmin_json |
| `api/pendenzen_save_widths.php` | – | – |
| `api/pendenzen_save.php` | `pendenzen` | – |
| `api/projekt_context.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `pendenz_ordner`, `team_projekte`, `teams` | require_login |
| `api/projekt_member_list.php` | `benutzer`, `projekt_mitglieder` | – |
| `api/projekt_member_remove.php` | `projekt_mitglieder` | – |
| `api/projekt_member_update.php` | `projekt_mitglieder` | – |
| `api/quick_insert.php` | – | require_superadmin_json |
| `api/quick_update.php` | – | require_superadmin_json |
| `api/save_pdf_mask.php` | `settings` | – |
| `api/test_db.php` | `pendenz_anhaenge`, `pendenz_dateien` | – |
| `api/test_insert.php` | `pendenz_dateien` | – |
| `api/unterkategorie_add.php` | `unterkategorien` | require_login |
| `api/unterkategorie_indent.php` | `unterkategorien` | require_login |
| `api/unterkategorie_move.php` | `unterkategorien` | require_login |
| `api/user_invite.php` | `benutzer` | – |
| `app/core/autoload.php` | – | – |
| `app/modules/ai/AiService.php` | `ai_training` | – |
| `app/modules/pendenzen/Service.php` | `benutzer`, `finanzen_konto`, `objekte`, `pendenz_dateien`, `pendenz_kategorien`, `pendenzen`, `pendenzen_arten`, `projekt_mitglieder`, `projekte`, `raeume`, `unternehmer_projekte`, `wohnungen` | – |
| `app/modules/pendenzen/ServiceFallback.php` | `benutzer`, `finanzen_konto`, `pendenz_dateien`, `pendenz_kategorien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `wohnungen` | – |
| `assets/ajax/assign_membership.php` | `benutzer_projekte`, `benutzer_teams`, `team_projekte` | require_login |
| `assets/ajax/create_team.php` | `team_projekte`, `teams` | require_login |
| `assets/ajax/delete_team.php` | `benutzer_teams`, `team_projekte`, `teams` | require_login |
| `assets/ajax/firmen_overrides.php` | `firmen_overrides` | require_login |
| `brain_migration_columns.php` | `pendenzen` | – |
| `brain_migration_protokoll.php` | `protokoll_typen`, `protokoll_vorlagen` | – |
| `check_benutzer.php` | – | – |
| `check_bkp_texts.php` | – | – |
| `check_cols.php` | `projekte` | – |
| `check_db_v2.php` | – | – |
| `check_db.php` | – | – |
| `check_defaults.php` | `pendenzen_art_empfaenger_defaults` | – |
| `check_objekte_deep.php` | `objekte` | – |
| `check_pendenzen_cols.php` | – | – |
| `check_pendenzen.php` | `pendenzen` | – |
| `check_protokolle_cols.php` | – | – |
| `check_schema.php` | – | – |
| `check_schemas_v2.php` | – | – |
| `check_users.php` | `benutzer` | – |
| `check_wohnungen_media.php` | – | – |
| `check.php` | – | – |
| `cleanup_temp.php` | – | – |
| `config.php` | – | – |
| `create_superadmin.php` | `benutzer` | – |
| `db_fix_listen.php` | `listen` | – |
| `db_fix_simple.php` | `listen` | – |
| `db_fix_standalone.php` | `listen` | – |
| `db_fix_zero_dates.php` | `pendenzen`, `projekte` | – |
| `debug_benutzer_schema.php` | – | – |
| `debug_chat_owner.php` | `ai_chats` | – |
| `debug_cols.php` | – | – |
| `debug_columns.php` | `pendenz_dateien` | – |
| `debug_counts.php` | `pendenzen` | – |
| `debug_data.php` | `objekte`, `wohnungen` | – |
| `debug_db_sort.php` | – | – |
| `debug_db.php` | – | – |
| `debug_final.php` | `objekte`, `wohnungen` | – |
| `debug_folders.php` | `folders` | – |
| `debug_hierarchy.php` | – | – |
| `debug_images.php` | `pendenz_dateien` | – |
| `debug_objects.php` | `objekte`, `wohnungen` | – |
| `debug_paths.php` | `fs_folder_meta`, `fs_nodes` | – |
| `debug_probe.php` | – | – |
| `debug_schema_direct.php` | – | – |
| `debug_schema_media.php` | – | – |
| `debug_schema_status.php` | – | – |
| `debug_schema.php` | – | – |
| `debug_sql_mode.php` | – | – |
| `debug_sqlite.php` | `ai_chats`, `ai_training` | – |
| `debug_stats.php` | `fs_nodes` | – |
| `debug_task.php` | `pendenzen` | – |
| `debug_user_id.php` | `benutzer` | – |
| `debug_v_test.php` | `pendenz_kategorien_vermieter` | – |
| `demonstration_init.php` | `benutzer` | – |
| `desc_pendenzen.php` | – | – |
| `dev_list.php` | – | – |
| `dump_pendenzen.php` | – | – |
| `find_sub.php` | `pendenz_subkategorien_vermieter` | – |
| `fix_bkp_schema.php` | `bkp_codes`, `bkp_vorlagen_texte` | – |
| `fix_db_v2.php` | `fs_folder_meta` | – |
| `fix_schema.php` | `pendenzen` | – |
| `fix_web.php` | `einheit_typen`, `pendenz_subkategorien_vermieter` | – |
| `force_fix.php` | – | – |
| `force_login.php` | – | – |
| `force_refresh_rooms.php` | `raum_vorlagen` | – |
| `force_scan_final.php` | – | – |
| `force_scan.php` | – | – |
| `get_cols.php` | – | – |
| `get_hierarchy.php` | `objekte`, `projekte`, `wohnungen` | – |
| `import_marbach.php` | `wohnung_mieter`, `wohnungen` | – |
| `includes/audit.php` | `audit_log`, `benutzer` | – |
| `includes/auth.php` | `benutzer`, `pendenz_acl`, `projekt_mitglieder`, `unternehmer_projekte`, `user_events` | require_login, require_role, require_project_access, can_view_pendenz, can_edit_pendenz |
| `includes/authz.php` | – | – |
| `includes/bootstrap.php` | – | – |
| `includes/csp.php` | – | – |
| `includes/csrf_benutzer.php` | – | benutzer_csrf_validate_or_throw |
| `includes/csrf_core.php` | – | – |
| `includes/csrf.php` | – | csrf_require, csrf_validate |
| `includes/db.php` | – | – |
| `includes/email_templates.php` | – | – |
| `includes/footer.php` | – | – |
| `includes/fs.php` | `fs_nodes`, `objekte`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `includes/functions.php` | `benutzer`, `firma_user`, `firmen`, `pendenzen`, `plan_zonen`, `projekt_plaene`, `system_settings` | – |
| `includes/header.php` | – | – |
| `includes/layout.php` | – | – |
| `includes/links.php` | – | – |
| `includes/mail.php` | – | – |
| `includes/mailer.php` | – | – |
| `includes/media.php` | `pendenz_ordner` | – |
| `includes/nav_admin.php` | `projekte` | – |
| `includes/nav_auto.php` | – | – |
| `includes/nav_benutzer.php` | – | – |
| `includes/nav_dispatch.php` | – | – |
| `includes/nav_notifications.php` | `user_notifications` | – |
| `includes/nav_public.php` | – | – |
| `includes/nav_superadmin.php` | `projekte` | – |
| `includes/nav.php` | – | – |
| `includes/pendenz_workflow.php` | `notifications`, `pendenz_events`, `pendenzen` | – |
| `includes/project_ctx.php` | – | – |
| `includes/quick_capture.php` | – | – |
| `includes/rent.php` | `konto_buchungen`, `mieten_tarife` | – |
| `includes/room_taxonomy.php` | `raeume`, `raum_vorlagen` | – |
| `includes/security_headers.php` | – | – |
| `includes/tree_renderer.php` | `unterkategorien` | – |
| `includes/units_adapter.php` | `liegenschafts_konto` | – |
| `includes/url_helpers.php` | – | – |
| `includes/user_folder_automation.php` | `benutzer`, `objekte`, `wohnungen` | – |
| `includes/user_taxonomy.php` | `person_statuses`, `person_types` | – |
| `includes/user_ui.php` | – | – |
| `includes/vorgang_taxonomy.php` | `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte` | – |
| `index_admin.php` | – | require_login |
| `index_private.php` | `pendenzen`, `projekt_mitglieder`, `projekte` | require_login |
| `index_public.php` | – | – |
| `index_superadmin.php` | `benutzer`, `liegenschafts_konto`, `objekte`, `pendenz_dateien`, `pendenzen`, `projekte`, `wohnungen` | require_login, require_role |
| `index.php` | – | – |
| `info.php` | – | – |
| `Konzept/Projekt Struktur/20250923_Projektstruktur_1.php` | – | – |
| `list_all_tables.php` | – | – |
| `list_tables_web.php` | – | – |
| `list_tables.php` | – | – |
| `list_users.php` | `benutzer` | – |
| `login_do.php` | – | – |
| `login.php` | `benutzer`, `user_events` | – |
| `logout.php` | – | – |
| `maintenance/expire_invites.php` | `benutzer`, `user_events` | – |
| `make_hash.php` | – | – |
| `migrate_live.php` | `bkp_kategorien`, `bkp_vorlagen_texte` | – |
| `migrate_mieterspiegel.php` | `wohnungen` | – |
| `migrate_protokoll_name.php` | `abnahme_protokolle` | – |
| `migrate_time.php` | `pendenzen` | – |
| `migrate_user_folders.php` | `benutzer` | – |
| `migrate_vorg.php` | `pendenzen` | – |
| `migration_bkp_codes_fields.php` | `bkp_codes` | – |
| `migration_bkp_hierarchy.php` | `bkp_kategorien`, `bkp_vorlagen_texte` | – |
| `migration_bkp_template_fields.php` | `bkp_vorlagen_texte` | – |
| `migration_bkp2_data.php` | `bkp_codes` | – |
| `migration_bkp2_full.php` | `bkp_codes` | – |
| `migration_finanzen.php` | `finanzen_konto` | – |
| `migration_mietverhaeltnisse.php` | `mietverhaeltnisse` | – |
| `migration_object_folders.php` | `objekte` | – |
| `migration_online_columns.php` | `firmen_vorlagen_map` | – |
| `migration_pendenz_fields.php` | `pendenz_field_defs` | – |
| `migration_project_customization.php` | `pendenz_kategorien`, `pendenz_subkategorien`, `projekte` | – |
| `migration_realestate.php` | `interessenten`, `pendenzen`, `wohnung_bilder`, `wohnung_dokumente`, `wohnungen` | – |
| `migration_structure_update.php` | `benutzer`, `bkp_codes`, `pendenzen`, `pendenzen_arten`, `raeume`, `unternehmer_projekte` | – |
| `migration_user_wohnung.php` | `benutzer` | – |
| `mini_check.php` | `fs_nodes` | – |
| `mini_debug.php` | `fs_nodes` | – |
| `model_probe.php` | – | – |
| `mysql/migrate_live.php` | `bkp_kategorien`, `bkp_vorlagen_texte` | – |
| `pages/abnahme_action.php` | `abnahme_protokolle` | – |
| `pages/abnahme_edit.php` | `abnahme_mangel_link`, `abnahmen`, `benutzer`, `pendenzen`, `projekte`, `wohnungen` | require_login |
| `pages/abnahme_neu.php` | `abnahmen`, `benutzer`, `objekte`, `projekte`, `wohnungen` | require_login |
| `pages/abnahme_save.php` | `abnahme_protokolle`, `benutzer`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/abnahme.php` | `abnahme_protokolle`, `benutzer`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen_arten`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/abnahmen.php` | `abnahmen`, `benutzer`, `projekte` | require_login |
| `pages/accept_invite.php` | `benutzer`, `user_invites` | – |
| `pages/admin_matrix_hub.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `firmen`, `firmen_overrides`, `projekte`, `team_projekte`, `teams` | require_login |
| `pages/ai_assistant.php` | `ai_chats`, `ai_messages`, `ai_training` | require_login |
| `pages/ajax_applicants.php` | – | require_login |
| `pages/ajax_get_plans.php` | `plan_zonen`, `projekt_plaene`, `wohnungen` | require_login |
| `pages/ajax_quick_pendenz.php` | `benutzer`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `projekte`, `raeume`, `wohnungen` | – |
| `pages/ajax_send_pendenz_mail.php` | `benutzer`, `objekte`, `pendenz_dateien`, `pendenzen`, `projekte`, `raeume`, `wohnungen` | – |
| `pages/ajax_template_preview.php` | `ordner_vorlagen_nodes` | – |
| `pages/ajax_unit_applicants.php` | – | – |
| `pages/ajax_unit_rooms.php` | `raeume`, `raum_vorlagen` | – |
| `pages/anfrage.php` | `objekte`, `wohnungen` | – |
| `pages/api_smarttable.php` | – | – |
| `pages/benachrichtigungen.php` | – | require_login |
| `pages/benutzer.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/benutzereinstellungen.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `projekte`, `team_projekte`, `teams` | require_login |
| `pages/bewerbung.php` | `benutzer`, `objekte`, `projekte`, `wohnungen` | – |
| `pages/bkp_codes.php` | `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte` | require_login |
| `pages/brain_migration.php` | `pendenzen` | – |
| `pages/chat.php` | `chat_members`, `chat_rooms`, `projekte` | require_login |
| `pages/check_cols.php` | `pendenzen` | – |
| `pages/check_headers.php` | – | – |
| `pages/check_schema_bkp.php` | – | – |
| `pages/check_task.php` | `benutzer`, `pendenzen` | – |
| `pages/check_u4.php` | `benutzer` | – |
| `pages/cleanup_temp.php` | `objekte`, `projekte`, `wohnungen` | – |
| `pages/dashboard.php` | `pendenzen`, `projekte` | require_login |
| `pages/debug_export.php` | `pdf_templates` | – |
| `pages/debug_nav.php` | – | – |
| `pages/debug_schema_status.php` | – | – |
| `pages/debug_schema_web.php` | – | – |
| `pages/debug_task.php` | `pendenzen` | – |
| `pages/desc_benutzer.php` | – | – |
| `pages/dev_csp_check.php` | – | – |
| `pages/docs.php` | – | require_login |
| `pages/file.php` | – | require_login, require_project_access |
| `pages/files_master.php` | `projekte` | require_login |
| `pages/files.php` | `fs_nodes`, `objekte`, `projekte`, `wohnungen` | require_login, require_project_access |
| `pages/finanzen.php` | `benutzer`, `finanzen_konto`, `wohnungen` | require_login |
| `pages/firmen.php` | `bkp_codes`, `firmen`, `firmen_vorlagen_map`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter` | require_login, csrf_require |
| `pages/fix_rooms_express.php` | `raum_vorlagen` | – |
| `pages/hard_reset.php` | – | – |
| `pages/idee.php` | – | – |
| `pages/interessent_invite_save.php` | `benutzer`, `interessenten` | – |
| `pages/interessenten_form_public.php` | `interessenten`, `objekte`, `projekte`, `wohnungen` | – |
| `pages/interessenten_form.php` | `interessenten`, `objekte`, `projekte`, `wohnungen` | – |
| `pages/interessenten.php` | `interessenten`, `objekte`, `wohnungen` | require_login |
| `pages/invite_accept.php` | `benutzer`, `user_events` | – |
| `pages/invite_open.php` | `benutzer`, `user_events` | – |
| `pages/kontakt.php` | – | – |
| `pages/lint_all.php` | – | – |
| `pages/list_users.php` | `benutzer` | – |
| `pages/listen_settings.php` | `pendenz_kategorien`, `projekte` | require_login |
| `pages/login.php` | `benutzer` | – |
| `pages/logout.php` | – | – |
| `pages/mieter_dashboard.php` | `benutzer`, `finanzen_konto`, `objekte`, `pendenzen`, `wohnung_bilder`, `wohnung_dokumente`, `wohnungen` | require_login |
| `pages/mieter_zuweisen.php` | `benutzer`, `fs_nodes`, `mieter`, `projekt_verknuepfungen` | require_login, csrf_validate |
| `pages/mieterspiegel.php` | `abnahme_mangel_link`, `abnahmen`, `benutzer`, `gegenstaende`, `interessenten`, `kontakte`, `mietverhaeltnisse`, `mietvertraege`, `objekte`, `pendenzen`, `projekt_verknuepfungen`, `projekte`, `wohnung_bilder`, `wohnung_dokumente`, `wohnung_mieter`, `wohnungen`, `zimmer` | require_login |
| `pages/neu 1invite_accept.php` | `benutzer` | – |
| `pages/objekt_edit.php` | `objekte`, `projekte` | require_login |
| `pages/objekt_neu.php` | `objekte`, `projekte` | require_login |
| `pages/ordner_verknuepfen.php` | `benutzer`, `fs_nodes`, `ordner_links` | require_login, csrf_validate |
| `pages/ordner_vorlage_apply.php` | `ordner_vorlage_applied`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `projekte`, `unterkategorien` | require_login |
| `pages/ordner_vorlagen_edit.php` | `ordner_vorlagen`, `ordner_vorlagen_nodes` | csrf_validate |
| `pages/ordner_vorlagen_new.php` | `ordner_vorlagen`, `ordner_vorlagen_nodes` | csrf_validate |
| `pages/ordner_vorlagen.php` | `ordner_vorlagen`, `ordner_vorlagen_nodes` | require_login |
| `pages/ordnerstruktur.php` | `documents`, `folder_audit_log`, `folders` | require_login, require_role |
| `pages/password_reset_request.php` | `benutzer` | – |
| `pages/password_reset.php` | `benutzer` | require_login |
| `pages/pdf_designer.php` | `pendenzen`, `settings` | require_login |
| `pages/pendenz_inbox.php` | `notifications`, `pendenzen` | require_login |
| `pages/pendenz_invite.php` | `pendenz_tokens`, `pendenzen` | require_login |
| `pages/pendenz_kategorien_mieter.php` | `objekte`, `pendenz_kategorien_mieter`, `pendenz_subkategorien_mieter`, `projekte` | require_login |
| `pages/pendenz_kategorien_unternehmer.php` | – | – |
| `pages/pendenz_kategorien_vermieter.php` | `objekte`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_vermieter`, `projekte` | require_login |
| `pages/pendenz_kategorien.php` | – | require_login |
| `pages/pendenz_neu modern.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu__.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu_modern.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pendenz_ordner.php` | `pendenz_ordner`, `pendenzen`, `projekte` | require_login |
| `pages/pendenz_pdf.php` | `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `settings`, `wohnungen` | require_login, can_view_pendenz |
| `pages/pendenz_public.php` | `pendenz_dateien`, `pendenzen`, `projekte`, `wohnungen` | – |
| `pages/pendenz_response.php` | `pendenz_dateien`, `pendenzen` | require_login |
| `pages/pendenz_review.php` | `benutzer`, `gegenstaende`, `objekte`, `projekte`, `wohnung_mieter`, `wohnungen`, `zimmer` | require_login |
| `pages/pendenz_show.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `pendenz_acl`, `pendenz_dateien`, `pendenz_ordner`, `pendenzen`, `plan_zonen`, `projekt_plaene`, `projekte`, `team_projekte`, `teams`, `wohnungen` | require_login, can_view_pendenz, can_edit_pendenz |
| `pages/pendenz_vorlagen.php` | – | require_login, require_role |
| `pages/pendenz_work.php` | `notifications`, `pendenz_events`, `pendenzen` | – |
| `pages/pendenzen_list_pdf.php` | `benutzer`, `firmen`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `settings`, `wohnungen` | require_login |
| `pages/pendenzen_liste.php` | `benutzer`, `finanzen_konto`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pendenzen_settings.php` | `pendenz_export_profiles`, `pendenz_field_defs`, `pendenzen`, `projekte` | require_login |
| `pages/pendenzen.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `projekte`, `raeume`, `settings`, `wohnungen` | require_login |
| `pages/personen_taxonomie.php` | – | require_login, csrf_require |
| `pages/plan_zonen_edit.php` | `objekte`, `plan_zonen`, `projekt_plaene`, `wohnungen` | require_login |
| `pages/profil.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/profile_fill.php` | `benutzer`, `profile_tokens` | – |
| `pages/project_storage.php` | `objekte`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `projekte`, `wohnungen` | require_login, csrf_validate |
| `pages/projekt_baum.php` | `fs_nodes`, `ordner_vorlagen`, `projekt_vorlagen`, `projekte`, `unterkategorien` | require_login |
| `pages/projekt_bearbeiten.php` | `projekte` | require_login, require_role |
| `pages/projekt_dashboard.php` | `benutzer`, `fs_folder_meta`, `fs_nodes`, `kontakte`, `objekte`, `ordner_links`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `pendenzen`, `project_dash_cards`, `projekte`, `wohnung_mieter`, `wohnungen` | require_login, csrf_validate |
| `pages/projekt_detail.php` | `benutzer`, `gegenstaende`, `objekte`, `pendenzen`, `projekte`, `wohnung_mieter`, `wohnungen`, `zimmer` | require_login |
| `pages/projekt_neu.php` | `objekte`, `projekte` | require_login |
| `pages/projekt_plaene.php` | `plan_zonen`, `projekt_plaene`, `projekte` | require_login |
| `pages/projekt_verknuepfungen.php` | `fs_nodes`, `projekte` | require_login, csrf_validate |
| `pages/projekt_waehlen.php` | `projekte` | – |
| `pages/projekte.php` | `benutzer`, `chat_members`, `chat_messages`, `chat_rooms`, `kv_buchungen`, `kv_import_batches`, `kv_konten`, `pendenz_export_profiles`, `projekt_mitglieder`, `projekte` | require_login |
| `pages/public_profile.php` | `benutzer` | – |
| `pages/quick_folder_editor.php` | `fs_folder_meta`, `objekte`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `projekte`, `wohnungen` | require_login |
| `pages/schema_check.php` | `benutzer`, `firmen` | – |
| `pages/schema_to_file.php` | `benutzer`, `firmen` | – |
| `pages/secret_reset_temp.php` | – | – |
| `pages/set_password.php` | `benutzer` | – |
| `pages/settings.php` | – | require_login, require_role |
| `pages/setup.php` | `benutzer` | – |
| `pages/storage_manager.php` | `projekte` | require_login, sm_require_admin, sm_require_superadmin, csrf_validate |
| `pages/struktur_einbauen.php` | `projekte`, `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/sync_drive_real.php` | `objekte`, `projekte` | – |
| `pages/sync_v2.php` | `objekte`, `projekte` | – |
| `pages/system_settings.php` | `system_settings` | – |
| `pages/table_rows.php` | – | require_login, require_role |
| `pages/tables.php` | – | require_login, require_role |
| `pages/teams.php` | `teams` | require_login, require_role |
| `pages/terminprogramm.php` | `benutzer`, `objekte`, `pendenz_dateien`, `pendenz_kategorien`, `pendenzen`, `projekte`, `wohnungen` | require_login |
| `pages/test_context.php` | – | – |
| `pages/test_delete_diagnostic.php` | `bkp_vorlagen_texte` | – |
| `pages/test_path.php` | – | – |
| `pages/test_schema.php` | `benutzer`, `firmen` | – |
| `pages/test_schema2.php` | `benutzer`, `firmen` | – |
| `pages/ueber_uns.php` | – | – |
| `pages/unterkategorie_bearbeiten.php` | `unterkategorien` | require_login |
| `pages/unterkategorie_loeschen.php` | `unterkategorien` | require_login |
| `pages/unterkategorie_neu.php` | `unterkategorien` | require_login |
| `pages/user_events.php` | `benutzer`, `user_events` | require_role |
| `pages/user_taxonomy.php` | `person_statuses`, `person_types` | – |
| `pages/vermietungseinheiten.php` | `benutzer`, `projekte`, `vermietungseinheiten` | require_login |
| `pages/vertrag_gen.php` | `objekte`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/vertrag_save.php` | `objekte`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/vorgangsart_settings.php` | `benutzer`, `bkp_codes`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/vorlage_bearbeiten.php` | `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/vorlage_loeschen.php` | `projekt_vorlagen`, `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/vorlage_reset_neu.php` | `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/vorlage_sync_fs.php` | `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/vorlage_tree.php` | `projekte`, `unterkategorien` | require_login |
| `pages/vorlagen.php` | `benutzer`, `projekt_vorlagen`, `projekte`, `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/wohnung_detail.php` | `benutzer`, `fs_nodes`, `mietvertraege`, `objekte`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `pendenzen`, `projekte`, `wohnungen` | require_login |
| `pages/wohnung_edit.php` | `benutzer`, `einheit_typen`, `objekte`, `wohnung_bilder`, `wohnung_dokumente`, `wohnung_mieter`, `wohnungen` | require_login, require_role |
| `pages/wohnung_neu.php` | `einheit_typen`, `objekte`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `wohnung_bilder`, `wohnung_dokumente`, `wohnungen` | require_login |
| `pages/wohnungen_import_fs.php` | `fs_nodes`, `objekte`, `wohnungen` | require_login |
| `pages/wohnungen_liste.php` | `benutzer`, `einheit_typen`, `fs_nodes`, `objekte`, `projekte`, `wohnungen` | require_login |
| `pages/wohnungsabnahme_protokoll.php` | `abnahme_protokolle`, `benutzer`, `objekte`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/wohnungsabnahme_save.php` | `abnahme_protokolle`, `benutzer`, `objekte`, `pendenz_dateien`, `pendenzen`, `projekte`, `raeume`, `wohnung_mieter`, `wohnungen` | – |
| `patch_pendenz.php` | `pendenzen` | – |
| `phpinfo.php` | – | – |
| `portfolio_master.php` | `benutzer`, `objekte`, `pendenzen`, `projekte`, `wohnungen` | require_login |
| `public/impressum.php` | – | – |
| `public/index.php` | – | – |
| `register.php` | `benutzer` | – |
| `rename_col.php` | `listen_spalten` | – |
| `rename_table.php` | – | – |
| `repair_paths.php` | `objekte`, `pendenzen`, `wohnungen` | – |
| `run_migration.php` | – | – |
| `run_reset.php` | – | – |
| `scan_drive.php` | – | – |
| `scratch_audit_p1.php` | `objekte`, `wohnungen` | – |
| `scratch_catch_all.php` | `objekte` | – |
| `scratch_check_all_types.php` | `einheit_typen` | – |
| `scratch_check_deps.php` | `pendenzen`, `wohnungen` | – |
| `scratch_check_icons.php` | `einheit_typen` | – |
| `scratch_check_listen.php` | `listen` | – |
| `scratch_check_p2.php` | `pendenzen` | – |
| `scratch_check_projekte.php` | `projekte` | – |
| `scratch_check_types.php` | `einheit_typen` | – |
| `scratch_check_unit.php` | `wohnungen` | – |
| `scratch_check_units_p1.php` | `objekte`, `wohnungen` | – |
| `scratch_cleanup_unit.php` | `interessenten`, `wohnung_mieter`, `wohnungen` | – |
| `scratch_count_all.php` | `objekte` | – |
| `scratch_db_check.php` | `objekte`, `projekte`, `wohnungen` | – |
| `scratch_db_inspect.php` | – | – |
| `scratch_db_pendenzen.php` | – | – |
| `scratch_db_repair.php` | `abnahme_mangel_link`, `abnahmen`, `gegenstaende`, `interessenten`, `mietverhaeltnisse`, `mietvertraege`, `pendenzen`, `wohnung_bilder`, `wohnung_dokumente`, `wohnung_mieter`, `wohnungen`, `zimmer` | – |
| `scratch_debug_objects.php` | `objekte` | – |
| `scratch_desc_wohnungen.php` | – | – |
| `scratch_detail_audit.php` | `objekte` | – |
| `scratch_final_audit.php` | `objekte` | – |
| `scratch_find_all_ghosts.php` | `objekte` | – |
| `scratch_find_deps.php` | – | – |
| `scratch_fix_and_check.php` | `einheit_typen` | – |
| `scratch_global_audit.php` | `objekte`, `projekte` | – |
| `scratch_global_cleanup_v2.php` | `objekte`, `wohnungen` | – |
| `scratch_global_cleanup.php` | `objekte`, `pendenzen`, `wohnungen` | – |
| `scratch_pdo_check.php` | – | – |
| `scratch_restore_listen.php` | `listen` | – |
| `scratch_schema_check.php` | – | – |
| `scratch_test_delete.php` | `wohnungen` | – |
| `scratch_test_query.php` | – | – |
| `scratch/add_columns.php` | `wohnungen` | – |
| `scratch/check_benutzer_schema.php` | – | – |
| `scratch/check_benutzer_tables.php` | – | – |
| `scratch/check_bkp_schema.php` | – | – |
| `scratch/check_cols.php` | `pendenzen` | – |
| `scratch/check_data.php` | `objekte`, `projekte` | – |
| `scratch/check_indexes.php` | `listen_spalten` | – |
| `scratch/check_latest.php` | `abnahme_protokolle` | – |
| `scratch/check_listen.php` | `listen` | – |
| `scratch/check_lists_v2.php` | `listen` | – |
| `scratch/check_lists.php` | `listen` | – |
| `scratch/check_logos.php` | `benutzer` | – |
| `scratch/check_pendenzen_schema.php` | – | – |
| `scratch/check_pendenzen.php` | – | – |
| `scratch/check_schema_bkp.php` | – | – |
| `scratch/check_schema.php` | – | – |
| `scratch/check_tables.php` | – | – |
| `scratch/check_task_87.php` | `benutzer`, `pendenzen` | – |
| `scratch/check_task_9.php` | `pendenzen` | – |
| `scratch/check_types.php` | `einheit_typen` | – |
| `scratch/check_vermieter_data.php` | `pendenz_kategorien_vermieter` | – |
| `scratch/check_vermieter.php` | `pendenz_kategorien_vermieter` | – |
| `scratch/compare_sql.php` | – | – |
| `scratch/create_pdf_templates_table.php` | `pdf_templates` | – |
| `scratch/create_settings.php` | `settings` | – |
| `scratch/db_cleanup_templates.php` | `raum_vorlagen` | – |
| `scratch/db_debug.php` | – | – |
| `scratch/db_diag.php` | – | – |
| `scratch/db_inspect_v2.php` | – | – |
| `scratch/db_inspect.php` | – | – |
| `scratch/db_reinforce.php` | `raeume`, `raum_vorlagen` | – |
| `scratch/diagnose_all.php` | `benutzer`, `bkp_codes`, `pendenz_kategorien`, `unternehmer_projekte` | – |
| `scratch/dump_local_schema.php` | – | – |
| `scratch/dump_schema.php` | – | – |
| `scratch/export_templates.php` | `pdf_templates` | – |
| `scratch/fix_keller_forever.php` | `raum_vorlagen` | – |
| `scratch/fix_passwort_null.php` | `benutzer` | – |
| `scratch/fix_pendenzen_rooms.php` | `pendenzen` | – |
| `scratch/get_settings_sql.php` | `settings` | – |
| `scratch/init_vorgang.php` | – | – |
| `scratch/inspect_cache_body.php` | – | – |
| `scratch/inspect_cache.php` | – | – |
| `scratch/inspect_db.php` | `pendenzen_arten` | – |
| `scratch/inspect_foreign_keys.php` | – | – |
| `scratch/inspect_pendenzen.php` | – | – |
| `scratch/inspect_schema.php` | – | – |
| `scratch/list_dbs_pdo.php` | – | – |
| `scratch/list_dbs.php` | – | – |
| `scratch/list_tables.php` | – | – |
| `scratch/migrate_listen_spalten.php` | `listen_spalten` | – |
| `scratch/parse_cache.php` | – | – |
| `scratch/reset_template_72.php` | `protokoll_vorlagen` | – |
| `scratch/schema.php` | – | – |
| `scratch/search_tables.php` | – | – |
| `scratch/seed_templates.php` | `pdf_templates` | – |
| `scratch/setup_abnahme_table.php` | `abnahme_protokolle` | – |
| `scratch/setup_settings.php` | `system_settings` | – |
| `scratch/sync_drive_to_db.php` | `objekte`, `projekte`, `wohnungen` | – |
| `scratch/test_api.php` | – | – |
| `scratch/test_cache.php` | – | – |
| `scratch/test_delete_diagnostic.php` | `bkp_vorlagen_texte` | – |
| `scratch/test_pdf_generation.php` | – | – |
| `scratch/test_proxy.php` | – | – |
| `scratch/test_resize.php` | – | – |
| `scratch/update_template_72.php` | `protokoll_vorlagen` | – |
| `scratch/update_types.php` | `einheit_typen` | – |
| `seed_bkp_hierarchy_full.php` | `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte` | – |
| `seed_bkp_hierarchy.php` | `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte` | – |
| `seed_protocol_templates.php` | `protokoll_typen`, `protokoll_vorlagen` | – |
| `setup_pins_db.php` | – | – |
| `setup_plaene_db.php` | `pendenzen`, `plan_zonen`, `projekt_plaene` | – |
| `show_cols.php` | `benutzer` | – |
| `show_tables.php` | – | – |
| `simulate.php` | `benutzer` | – |
| `test_ajax.php` | – | – |
| `test_cols.php` | – | – |
| `test_db.php` | `objekte`, `wohnungen` | – |
| `test_db2.php` | `projekte` | – |
| `test_dompdf.php` | – | – |
| `test_err.php` | – | – |
| `test_err2.php` | – | – |
| `test_fs.php` | `fs_nodes` | – |
| `test_hash.php` | – | – |
| `test_json.php` | `firma_user`, `firmen_vorlagen_map` | – |
| `test_pendenzen.php` | `pendenzen` | – |
| `test_save.php` | `pendenzen` | – |
| `test_srv.php` | – | – |
| `test_srv2.php` | – | – |
| `test_srv3.php` | – | – |
| `test_srv4.php` | – | – |
| `test_sync.php` | `benutzer` | – |
| `test_tables.php` | – | – |
| `test.php` | – | – |
| `tmp.php` | `listen` | – |
| `tmp/check_col.php` | – | – |
| `tmp/check_db_diag.php` | – | – |
| `tmp/db_check.php` | – | – |
| `tmp/dump_tax.php` | `person_statuses`, `person_types` | – |
| `tmp/fix_db_migration_simple.php` | `mieter`, `projekte`, `wohnungen` | – |
| `tmp/fix_db_saas.php` | `listen`, `pendenz_field_defs` | – |
| `tmp/fix_status_column.php` | `wohnungen` | – |
| `tmp/kill_locks.php` | – | – |
| `tmp/migration_web.php` | `mieter`, `projekte`, `wohnungen` | – |
| `tmp2.php` | `listen` | – |
| `tools/check_assets.php` | – | – |
| `tools/konto_verwaltung/export.php` | – | – |
| `tools/konto_verwaltung/import.php` | `liegenschafts_konto`, `projekte` | require_login |
| `tools/konto_verwaltung/index.php` | `benutzer`, `kv_konten`, `liegenschafts_konto`, `objekte`, `projekte`, `wohnung_mieter`, `wohnungen` | require_login, csrf_require |
| `tools/konto_verwaltung/konto_verwaltung.php` | – | – |
| `tools/mieterspiegel/index.php` | `liegenschafts_konto`, `miete_adjustments`, `miete_schedules`, `projekte`, `wohnungen` | require_login |
| `tools/mietkontrolle/index.php` | `liegenschafts_konto`, `projekte` | require_login |
| `tools/seed_demo_users.php` | `benutzer`, `user_invites` | – |
| `tools/test_deepseek.php` | – | – |
| `tools/test_openai.php` | – | – |
| `widgets/projekt_einheiten_panel.php` | – | – |

### C.2 Kopien und historische PHP-Pfade

Keine Löschfreigabe. Diese Dateien besitzen teilweise abweichende Funktionalität und können bei Webroot-Erreichbarkeit eigene Endpunkte bilden.

| Datei | Direkt erkannte aktuelle Tabellen | Prüfhelfer-Suchmarker |
|---|---|---|
| `Absicherung vor löschen/alles was mit smart ist/demo_smarttable.php` | – | – |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_api.php` | – | – |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_delete.php` | – | – |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_fetch.php` | `smarttable_columns` | – |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_restore.php` | – | – |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_save.php` | `smarttable_columns` | – |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_settings_get.php` | `smarttable_columns` | – |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_settings_save.php` | `smarttable_columns` | – |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_settings.php` | – | require_login |
| `Absicherung vor löschen/Benutzer/benutzer_edit.php` | `benutzer` | require_login |
| `Absicherung vor löschen/Benutzer/benutzer_invite.php` | `benutzer`, `projekt_mitglieder`, `user_events` | require_login |
| `Absicherung vor löschen/Benutzer/benutzer_request.php` | `benutzer`, `profile_tokens` | require_login |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_private.php` | – | – |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_public.php` | – | – |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_role.php` | – | – |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_superadmin_start.php` | – | require_login |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_tools.php` | – | – |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_user.php` | – | – |
| `Absicherung vor löschen/Nav die gelöscht werden können/sim_bar.php` | – | – |
| `api/ai_chat_messages - Kopie (2).php` | `ai_chats`, `ai_messages` | require_login |
| `api/ai_chat_messages - Kopie.php` | `ai_chats`, `ai_messages` | require_login |
| `api/ai_query - Kopie.php` | `ai_chats`, `ai_messages`, `pendenzen` | require_login |
| `api/aus web/listen_preview.php` | `benutzer`, `objekte`, `pendenzen`, `pendenzen_arten`, `projekte`, `wohnungen` | require_login |
| `app/modules/pendenzen/Service - Kopie.php` | `benutzer`, `finanzen_konto`, `objekte`, `pendenz_dateien`, `pendenz_kategorien`, `pendenzen`, `pendenzen_arten`, `projekt_mitglieder`, `projekte`, `raeume`, `unternehmer_projekte`, `wohnungen` | – |
| `config - Kopie.php` | – | – |
| `includes/Aus gesicherte dateien/nav_admin.php` | – | – |
| `includes/auth - Kopie.php` | `benutzer`, `pendenz_acl`, `projekt_mitglieder`, `unternehmer_projekte`, `user_events` | require_login, require_role, can_view_pendenz, can_edit_pendenz |
| `includes/authz - Kopie.php` | – | – |
| `includes/footer - Kopie.php` | – | – |
| `includes/header.bak.php` | – | – |
| `includes/media - Kopie.php` | `pendenz_ordner` | – |
| `includes/vorgang_taxonomy - Kopie.php` | `pendenzen`, `pendenzen_arten` | – |
| `pages/ai_assistant - Kopie.php` | `ai_chats`, `ai_messages`, `ai_training` | require_login |
| `pages/aus homepage/check_cols.php` | `pendenzen` | – |
| `pages/aus homepage/debug_task.php` | `pendenzen` | – |
| `pages/aus homepage/pdf_designer.php` | `pendenzen`, `settings` | require_login |
| `pages/aus homepage/pendenz_neu.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/aus homepage/pendenz_pdf.php` | `benutzer`, `firma_user`, `firmen`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `settings`, `wohnungen` | require_login, can_view_pendenz |
| `pages/aus homepage/pendenz_public.php` | `pendenz_dateien`, `pendenzen`, `projekte` | – |
| `pages/aus homepage/pendenz_show.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `pendenz_acl`, `pendenz_dateien`, `pendenz_ordner`, `pendenzen`, `projekte`, `team_projekte`, `teams`, `wohnungen` | require_login, can_view_pendenz, can_edit_pendenz |
| `pages/aus homepage/pendenzen_.php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/aus homepage/pendenzen_2.php` | `benutzer`, `benutzer_personentypen`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `projekte`, `settings`, `wohnungen` | require_login |
| `pages/aus homepage/pendenzen_3.php` | `benutzer`, `benutzer_personentypen`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `projekte`, `settings`, `wohnungen` | require_login |
| `pages/aus homepage/wohnungsabnahme_protokoll.php` | `abnahme_protokolle`, `benutzer`, `objekte`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/aus homepage/wohnungsabnahme_save.php` | `abnahme_protokolle`, `benutzer`, `objekte`, `pendenz_dateien`, `pendenzen`, `projekte`, `raeume`, `wohnung_mieter`, `wohnungen` | – |
| `pages/benutzer - Kopie (2).php` | `benutzer`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/benutzer - Kopie (3).php` | `benutzer`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/benutzer - Kopie.php` | `benutzer`, `bkp_codes`, `firmen`, `objekte`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/firmen - Kopie (2).php` | `bkp_codes`, `firmen` | require_login, csrf_require |
| `pages/firmen - Kopie.php` | `bkp_codes`, `firmen` | require_login, csrf_require |
| `pages/listen_settings - Kopie.php` | `pendenz_kategorien`, `projekte` | require_login |
| `pages/pages per 07.05.2026/abnahme_action.php` | `abnahme_protokolle` | – |
| `pages/pages per 07.05.2026/abnahme_edit.php` | `abnahme_mangel_link`, `abnahmen`, `benutzer`, `pendenzen`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/abnahme_neu.php` | `abnahmen`, `benutzer`, `objekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/abnahme_save.php` | `abnahme_protokolle`, `benutzer`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/pages per 07.05.2026/abnahme.php` | `abnahme_protokolle`, `benutzer`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen_arten`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/pages per 07.05.2026/abnahmen.php` | `abnahmen`, `benutzer`, `projekte` | require_login |
| `pages/pages per 07.05.2026/accept_invite.php` | `benutzer`, `user_invites` | – |
| `pages/pages per 07.05.2026/admin_matrix_hub.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `firmen`, `firmen_overrides`, `projekte`, `team_projekte`, `teams` | require_login |
| `pages/pages per 07.05.2026/ai_assistant - Kopie.php` | `ai_chats`, `ai_messages`, `ai_training` | require_login |
| `pages/pages per 07.05.2026/ai_assistant.php` | `ai_chats`, `ai_messages`, `ai_training` | require_login |
| `pages/pages per 07.05.2026/ajax_applicants.php` | – | require_login |
| `pages/pages per 07.05.2026/ajax_quick_pendenz.php` | `benutzer`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `projekte`, `raeume`, `wohnungen` | – |
| `pages/pages per 07.05.2026/ajax_template_preview.php` | `ordner_vorlagen_nodes` | – |
| `pages/pages per 07.05.2026/ajax_unit_applicants.php` | – | – |
| `pages/pages per 07.05.2026/ajax_unit_rooms.php` | `raeume`, `raum_vorlagen` | – |
| `pages/pages per 07.05.2026/anfrage.php` | `objekte`, `wohnungen` | – |
| `pages/pages per 07.05.2026/api_smarttable.php` | – | – |
| `pages/pages per 07.05.2026/baujournal_mockup_final.php` | – | require_login |
| `pages/pages per 07.05.2026/baujournal2.php` | – | require_login |
| `pages/pages per 07.05.2026/benachrichtigungen.php` | – | require_login |
| `pages/pages per 07.05.2026/benutzer - Kopie (2).php` | `benutzer`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/pages per 07.05.2026/benutzer - Kopie (3).php` | `benutzer`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/pages per 07.05.2026/benutzer - Kopie.php` | `benutzer`, `bkp_codes`, `firmen`, `objekte`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/pages per 07.05.2026/benutzer.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/pages per 07.05.2026/benutzereinstellungen.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `projekte`, `team_projekte`, `teams` | require_login |
| `pages/pages per 07.05.2026/bewerbung.php` | `benutzer`, `objekte`, `projekte`, `wohnungen` | – |
| `pages/pages per 07.05.2026/bkp_codes.php` | `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte` | require_login |
| `pages/pages per 07.05.2026/brain_migration.php` | `pendenzen` | – |
| `pages/pages per 07.05.2026/chat.php` | `chat_members`, `chat_rooms`, `projekte` | require_login |
| `pages/pages per 07.05.2026/check_cols.php` | `benutzer` | – |
| `pages/pages per 07.05.2026/check_headers.php` | – | – |
| `pages/pages per 07.05.2026/check_task.php` | `benutzer`, `pendenzen` | – |
| `pages/pages per 07.05.2026/check_u4.php` | `benutzer` | – |
| `pages/pages per 07.05.2026/cleanup_temp.php` | `objekte`, `projekte`, `wohnungen` | – |
| `pages/pages per 07.05.2026/dashboard.php` | `pendenzen`, `projekte` | require_login |
| `pages/pages per 07.05.2026/debug_export.php` | `pdf_templates` | – |
| `pages/pages per 07.05.2026/debug_nav.php` | – | – |
| `pages/pages per 07.05.2026/debug_schema_web.php` | – | – |
| `pages/pages per 07.05.2026/desc_benutzer.php` | – | – |
| `pages/pages per 07.05.2026/dev_csp_check.php` | – | – |
| `pages/pages per 07.05.2026/docs.php` | – | require_login |
| `pages/pages per 07.05.2026/file.php` | – | require_login, require_project_access |
| `pages/pages per 07.05.2026/files_master.php` | `projekte` | require_login |
| `pages/pages per 07.05.2026/files.php` | `fs_nodes`, `objekte`, `projekte`, `wohnungen` | require_login, require_project_access |
| `pages/pages per 07.05.2026/finanzen.php` | `benutzer`, `finanzen_konto`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/firmen - Kopie (2).php` | `bkp_codes`, `firmen` | require_login, csrf_require |
| `pages/pages per 07.05.2026/firmen - Kopie.php` | `bkp_codes`, `firmen` | require_login, csrf_require |
| `pages/pages per 07.05.2026/firmen.php` | `bkp_codes`, `firmen`, `firmen_vorlagen_map`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter` | require_login, csrf_require |
| `pages/pages per 07.05.2026/fix_rooms_express.php` | `raum_vorlagen` | – |
| `pages/pages per 07.05.2026/hard_reset.php` | – | – |
| `pages/pages per 07.05.2026/idee.php` | – | – |
| `pages/pages per 07.05.2026/interessent_invite_save.php` | `benutzer`, `interessenten` | – |
| `pages/pages per 07.05.2026/interessenten_form_public.php` | `interessenten`, `objekte`, `projekte`, `wohnungen` | – |
| `pages/pages per 07.05.2026/interessenten_form.php` | `interessenten`, `objekte`, `projekte`, `wohnungen` | – |
| `pages/pages per 07.05.2026/interessenten.php` | `interessenten`, `objekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/invite_accept.php` | `benutzer`, `user_events` | – |
| `pages/pages per 07.05.2026/invite_open.php` | `benutzer`, `user_events` | – |
| `pages/pages per 07.05.2026/kontakt.php` | – | – |
| `pages/pages per 07.05.2026/lint_all.php` | – | – |
| `pages/pages per 07.05.2026/list_users.php` | `benutzer` | – |
| `pages/pages per 07.05.2026/listen_settings - Kopie.php` | `pendenz_kategorien`, `projekte` | require_login |
| `pages/pages per 07.05.2026/listen_settings.php` | `pendenz_kategorien`, `projekte` | require_login |
| `pages/pages per 07.05.2026/login.php` | `benutzer` | – |
| `pages/pages per 07.05.2026/logout.php` | – | – |
| `pages/pages per 07.05.2026/mieter_dashboard.php` | `benutzer`, `finanzen_konto`, `objekte`, `pendenzen`, `wohnung_bilder`, `wohnung_dokumente`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/mieter_zuweisen.php` | `benutzer`, `fs_nodes`, `mieter`, `projekt_verknuepfungen` | require_login, csrf_validate |
| `pages/pages per 07.05.2026/mieterspiegel.php` | `abnahme_mangel_link`, `abnahmen`, `benutzer`, `gegenstaende`, `interessenten`, `kontakte`, `mietverhaeltnisse`, `mietvertraege`, `objekte`, `pendenzen`, `projekt_verknuepfungen`, `projekte`, `wohnung_bilder`, `wohnung_dokumente`, `wohnung_mieter`, `wohnungen`, `zimmer` | require_login |
| `pages/pages per 07.05.2026/neu 1invite_accept.php` | `benutzer` | – |
| `pages/pages per 07.05.2026/objekt_edit.php` | `objekte`, `projekte` | require_login |
| `pages/pages per 07.05.2026/objekt_neu.php` | `objekte`, `projekte` | require_login |
| `pages/pages per 07.05.2026/ordner_verknuepfen.php` | `benutzer`, `fs_nodes`, `ordner_links` | require_login, csrf_validate |
| `pages/pages per 07.05.2026/ordner_vorlage_apply.php` | `ordner_vorlage_applied`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `projekte`, `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/ordner_vorlagen_edit.php` | `ordner_vorlagen`, `ordner_vorlagen_nodes` | csrf_validate |
| `pages/pages per 07.05.2026/ordner_vorlagen_new.php` | `ordner_vorlagen`, `ordner_vorlagen_nodes` | csrf_validate |
| `pages/pages per 07.05.2026/ordner_vorlagen.php` | `ordner_vorlagen`, `ordner_vorlagen_nodes` | require_login |
| `pages/pages per 07.05.2026/ordnerstruktur.php` | `documents`, `folder_audit_log`, `folders` | require_login, require_role |
| `pages/pages per 07.05.2026/password_reset_request.php` | `benutzer` | – |
| `pages/pages per 07.05.2026/password_reset.php` | `benutzer` | require_login |
| `pages/pages per 07.05.2026/pdf_designer.php` | `pendenzen`, `settings` | require_login |
| `pages/pages per 07.05.2026/pendenz_inbox.php` | `notifications`, `pendenzen` | require_login |
| `pages/pages per 07.05.2026/pendenz_invite.php` | `pendenz_tokens`, `pendenzen` | require_login |
| `pages/pages per 07.05.2026/pendenz_kategorien - Kopie (2).php` | `pendenz_kategorien`, `pendenz_subkategorien`, `projekte` | require_login |
| `pages/pages per 07.05.2026/pendenz_kategorien - Kopie.php` | – | require_login |
| `pages/pages per 07.05.2026/pendenz_kategorien_mieter - Kopie.php` | `objekte`, `pendenz_kategorien_mieter`, `pendenz_subkategorien_mieter`, `projekte` | require_login |
| `pages/pages per 07.05.2026/pendenz_kategorien_mieter.php` | `objekte`, `pendenz_kategorien_mieter`, `pendenz_subkategorien_mieter`, `projekte` | require_login |
| `pages/pages per 07.05.2026/pendenz_kategorien_unternehmer.php` | – | – |
| `pages/pages per 07.05.2026/pendenz_kategorien_vermieter - Kopie.php` | `objekte`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_vermieter`, `projekte` | require_login |
| `pages/pages per 07.05.2026/pendenz_kategorien_vermieter.php` | `objekte`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_vermieter`, `projekte` | require_login |
| `pages/pages per 07.05.2026/pendenz_kategorien-Kopie.php` | – | require_login |
| `pages/pages per 07.05.2026/pendenz_kategorien-Kopie(2).php` | `pendenz_kategorien`, `pendenz_subkategorien`, `projekte` | require_login |
| `pages/pages per 07.05.2026/pendenz_kategorien.php` | – | require_login |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (2).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (3).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (4).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (5).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (6).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (7).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (8).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (9).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu modern.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu_modern.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_neu.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenz_ordner.php` | `pendenz_ordner`, `pendenzen`, `projekte` | require_login |
| `pages/pages per 07.05.2026/pendenz_pdf.php` | `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `settings`, `wohnungen` | require_login, can_view_pendenz |
| `pages/pages per 07.05.2026/pendenz_public.php` | `pendenz_dateien`, `pendenzen`, `projekte`, `wohnungen` | – |
| `pages/pages per 07.05.2026/pendenz_response.php` | `pendenz_dateien`, `pendenzen` | require_login |
| `pages/pages per 07.05.2026/pendenz_review.php` | `benutzer`, `gegenstaende`, `objekte`, `projekte`, `wohnung_mieter`, `wohnungen`, `zimmer` | require_login |
| `pages/pages per 07.05.2026/pendenz_show.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `pendenz_acl`, `pendenz_dateien`, `pendenz_ordner`, `pendenzen`, `projekte`, `team_projekte`, `teams`, `wohnungen` | require_login, can_view_pendenz, can_edit_pendenz |
| `pages/pages per 07.05.2026/pendenz_vorlagen.php` | – | require_login, require_role |
| `pages/pages per 07.05.2026/pendenz_work.php` | `notifications`, `pendenz_events`, `pendenzen` | – |
| `pages/pages per 07.05.2026/pendenzen - Kopie (10).php` | `benutzer`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (11).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (12).php` | `benutzer`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (13).php` | `benutzer`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (14).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (15).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (16).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (17).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (18).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (19).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (2).php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firmen`, `listen`, `listen_spalten`, `objekte`, `pendenz_acl`, `pendenz_dateien`, `pendenz_field_defs`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `team_projekte`, `teams`, `unternehmer_projekte`, `wohnungen` | require_login, can_view_pendenz, can_edit_pendenz |
| `pages/pages per 07.05.2026/pendenzen - Kopie (20).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (21).php` | `benutzer`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_arten`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (22).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (23).php` | `benutzer`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_arten`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (3).php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firmen`, `listen`, `listen_spalten`, `objekte`, `pendenz_acl`, `pendenz_dateien`, `pendenz_field_defs`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `raeume`, `team_projekte`, `teams`, `unternehmer_projekte`, `wohnungen` | require_login, can_view_pendenz, can_edit_pendenz |
| `pages/pages per 07.05.2026/pendenzen - Kopie (4).php` | `benutzer`, `objekte`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (5).php` | `benutzer`, `objekte`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (6).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (7).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (8).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie (9).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen - Kopie.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `firmen`, `listen`, `listen_spalten`, `objekte`, `pendenz_acl`, `pendenz_dateien`, `pendenz_field_defs`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `projekte`, `team_projekte`, `teams`, `wohnungen` | require_login, can_view_pendenz, can_edit_pendenz |
| `pages/pages per 07.05.2026/pendenzen_list_pdf.php` | `benutzer`, `firmen`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `settings`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen_liste.php` | `benutzer`, `finanzen_konto`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/pendenzen_settings.php` | `pendenz_export_profiles`, `pendenz_field_defs`, `pendenzen`, `projekte` | require_login |
| `pages/pages per 07.05.2026/pendenzen.php` | `benutzer`, `benutzer_personentypen`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `projekte`, `raeume`, `settings`, `wohnungen` | – |
| `pages/pages per 07.05.2026/personen_taxonomie.php` | – | require_login, csrf_require |
| `pages/pages per 07.05.2026/profil - Kopie (2).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/pages per 07.05.2026/profil - Kopie.php` | `benutzer`, `bkp_codes`, `firma_user`, `firmen`, `objekte`, `pendenzen_arten`, `projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/pages per 07.05.2026/profil.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/pages per 07.05.2026/profile_fill.php` | `benutzer`, `profile_tokens` | – |
| `pages/pages per 07.05.2026/project_storage.php` | `objekte`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `projekte`, `wohnungen` | require_login, csrf_validate |
| `pages/pages per 07.05.2026/projekt_baum.php` | `fs_nodes`, `ordner_vorlagen`, `projekt_vorlagen`, `projekte`, `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/projekt_bearbeiten.php` | `projekte` | require_login, require_role |
| `pages/pages per 07.05.2026/projekt_dashboard.php` | `benutzer`, `fs_folder_meta`, `fs_nodes`, `kontakte`, `objekte`, `ordner_links`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `pendenzen`, `project_dash_cards`, `projekte`, `wohnung_mieter`, `wohnungen` | require_login, csrf_validate |
| `pages/pages per 07.05.2026/projekt_detail.php` | `benutzer`, `gegenstaende`, `objekte`, `pendenzen`, `projekte`, `wohnung_mieter`, `wohnungen`, `zimmer` | require_login |
| `pages/pages per 07.05.2026/projekt_neu.php` | `objekte`, `projekte` | require_login |
| `pages/pages per 07.05.2026/projekt_verknuepfungen.php` | `fs_nodes`, `projekte` | require_login, csrf_validate |
| `pages/pages per 07.05.2026/projekt_waehlen.php` | `projekte` | – |
| `pages/pages per 07.05.2026/projekte.php` | `benutzer`, `chat_members`, `chat_messages`, `chat_rooms`, `kv_buchungen`, `kv_import_batches`, `kv_konten`, `pendenz_export_profiles`, `projekt_mitglieder`, `projekte` | require_login |
| `pages/pages per 07.05.2026/protokoll_designer.php` | `benutzer`, `firmen`, `protokoll_typen`, `protokoll_vorlagen` | – |
| `pages/pages per 07.05.2026/protokoll_fill_pdf.php` | `benutzer`, `protokoll_vorlagen` | – |
| `pages/pages per 07.05.2026/protokoll_fill.php` | `benutzer`, `protokoll_typen`, `protokoll_vorlagen` | – |
| `pages/pages per 07.05.2026/protokoll_manager.php` | `benutzer`, `firma_user`, `firmen`, `objekte`, `projekte`, `protokoll_typen`, `protokoll_vorlagen`, `raeume`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/protokoll_pdf.php` | `benutzer`, `firmen`, `protokoll_typen`, `protokoll_vorlagen` | – |
| `pages/pages per 07.05.2026/protokoll_test.php` | `benutzer`, `bkp_codes`, `mieter`, `objekte`, `projekte`, `raeume`, `wohnungen` | – |
| `pages/pages per 07.05.2026/protokoll_view.php` | `benutzer`, `firmen`, `protokoll_typen`, `protokoll_vorlagen` | – |
| `pages/pages per 07.05.2026/protokoll_vorlagen.php` | `protokoll_typen` | require_login |
| `pages/pages per 07.05.2026/public_profile.php` | `benutzer` | – |
| `pages/pages per 07.05.2026/quick_folder_editor.php` | `fs_folder_meta`, `objekte`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/run_seed.php` | `protokoll_typen`, `protokoll_vorlagen` | – |
| `pages/pages per 07.05.2026/schema_check.php` | `benutzer`, `firmen` | – |
| `pages/pages per 07.05.2026/schema_to_file.php` | `benutzer`, `firmen` | – |
| `pages/pages per 07.05.2026/secret_reset_temp.php` | – | – |
| `pages/pages per 07.05.2026/set_password.php` | `benutzer` | – |
| `pages/pages per 07.05.2026/settings.php` | – | require_login, require_role |
| `pages/pages per 07.05.2026/setup.php` | `benutzer` | – |
| `pages/pages per 07.05.2026/storage_manager.php` | `projekte` | require_login, sm_require_admin, sm_require_superadmin, csrf_validate |
| `pages/pages per 07.05.2026/struktur_einbauen.php` | `projekte`, `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/sync_drive_real.php` | `objekte`, `projekte` | – |
| `pages/pages per 07.05.2026/sync_v2.php` | `objekte`, `projekte` | – |
| `pages/pages per 07.05.2026/system_settings.php` | `system_settings` | – |
| `pages/pages per 07.05.2026/table_rows.php` | – | require_login, require_role |
| `pages/pages per 07.05.2026/tables.php` | – | require_login, require_role |
| `pages/pages per 07.05.2026/teams.php` | `teams` | require_login, require_role |
| `pages/pages per 07.05.2026/terminprogramm.php` | `benutzer`, `objekte`, `pendenz_dateien`, `pendenz_kategorien`, `pendenzen`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/test_context.php` | – | – |
| `pages/pages per 07.05.2026/test_schema.php` | `benutzer`, `firmen` | – |
| `pages/pages per 07.05.2026/test_schema2.php` | `benutzer`, `firmen` | – |
| `pages/pages per 07.05.2026/ueber_uns.php` | – | – |
| `pages/pages per 07.05.2026/unterkategorie_bearbeiten.php` | `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/unterkategorie_loeschen.php` | `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/unterkategorie_neu.php` | `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/user_events.php` | `benutzer`, `user_events` | require_role |
| `pages/pages per 07.05.2026/user_taxonomy.php` | `person_statuses`, `person_types` | – |
| `pages/pages per 07.05.2026/vermietungseinheiten.php` | `benutzer`, `projekte`, `vermietungseinheiten` | require_login |
| `pages/pages per 07.05.2026/vertrag_gen.php` | `objekte`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/pages per 07.05.2026/vertrag_save.php` | `objekte`, `projekte`, `wohnung_mieter`, `wohnungen` | – |
| `pages/pages per 07.05.2026/vorgangsart_settings - Kopie (2).php` | `benutzer`, `firma_user`, `firmen`, `objekte`, `pendenzen_arten`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/vorgangsart_settings - Kopie.php` | `objekte`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte` | require_login |
| `pages/pages per 07.05.2026/vorgangsart_settings.php` | `benutzer`, `bkp_codes`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/vorlage_bearbeiten.php` | `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/vorlage_loeschen.php` | `projekt_vorlagen`, `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/vorlage_reset_neu.php` | `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/vorlage_sync_fs.php` | `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/vorlage_tree.php` | `projekte`, `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/vorlagen.php` | `benutzer`, `projekt_vorlagen`, `projekte`, `struktur_vorlagen`, `unterkategorien` | require_login |
| `pages/pages per 07.05.2026/wohnung_detail.php` | `benutzer`, `fs_nodes`, `mietvertraege`, `objekte`, `ordner_vorlagen`, `ordner_vorlagen_nodes`, `pendenzen`, `projekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/wohnung_edit.php` | `objekte`, `wohnungen` | require_login, require_role |
| `pages/pages per 07.05.2026/wohnung_neu.php` | `ordner_vorlagen`, `ordner_vorlagen_nodes`, `wohnung_bilder`, `wohnung_dokumente`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/wohnungen_import_fs.php` | `fs_nodes`, `objekte`, `wohnungen` | require_login |
| `pages/pages per 07.05.2026/wohnungen_liste.php` | `benutzer`, `einheit_typen`, `fs_nodes`, `objekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_kategorien - Kopie (2).php` | `pendenz_kategorien`, `pendenz_subkategorien`, `projekte` | require_login |
| `pages/pendenz_kategorien - Kopie.php` | – | require_login |
| `pages/pendenz_kategorien_mieter - Kopie.php` | `objekte`, `pendenz_kategorien_mieter`, `pendenz_subkategorien_mieter`, `projekte` | require_login |
| `pages/pendenz_kategorien_vermieter - Kopie.php` | `objekte`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_vermieter`, `projekte` | require_login |
| `pages/pendenz_kategorien-Kopie.php` | – | require_login |
| `pages/pendenz_kategorien-Kopie(2).php` | `pendenz_kategorien`, `pendenz_subkategorien`, `projekte` | require_login |
| `pages/pendenz_neu - Kopie (2).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu - Kopie (3).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu - Kopie (4).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu - Kopie (5).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu - Kopie (6).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu - Kopie (7).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu - Kopie (8).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu - Kopie (9).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_dateien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenz_neu - Kopie.php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `bkp_vorlagen_texte`, `firma_user`, `firmen`, `firmen_vorlagen_map`, `objekte`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_art_empfaenger_defaults`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (10).php` | `benutzer`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (11).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (12).php` | `benutzer`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (13).php` | `benutzer`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `raeume`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (14).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (15).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (16).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (17).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (18).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (19).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (2).php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firmen`, `listen`, `listen_spalten`, `objekte`, `pendenz_acl`, `pendenz_dateien`, `pendenz_field_defs`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `projekte`, `raeume`, `team_projekte`, `teams`, `unternehmer_projekte`, `wohnungen` | require_login, can_view_pendenz, can_edit_pendenz |
| `pages/pendenzen - Kopie (20).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (21).php` | `benutzer`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_arten`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (22).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (23).php` | `benutzer`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_kategorien_mieter`, `pendenz_kategorien_vermieter`, `pendenz_subkategorien`, `pendenz_subkategorien_mieter`, `pendenz_subkategorien_vermieter`, `pendenzen`, `pendenzen_arten`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (3).php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `bkp_codes`, `bkp_kategorien`, `bkp_vorlagen_texte`, `firmen`, `listen`, `listen_spalten`, `objekte`, `pendenz_acl`, `pendenz_dateien`, `pendenz_field_defs`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `raeume`, `team_projekte`, `teams`, `unternehmer_projekte`, `wohnungen` | require_login, can_view_pendenz, can_edit_pendenz |
| `pages/pendenzen - Kopie (4).php` | `benutzer`, `objekte`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (5).php` | `benutzer`, `objekte`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (6).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (7).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (8).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie (9).php` | `benutzer`, `listen`, `listen_spalten`, `objekte`, `pendenz_anhaenge`, `pendenz_dateien`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte`, `wohnungen` | require_login |
| `pages/pendenzen - Kopie.php` | `benutzer`, `benutzer_projekte`, `benutzer_teams`, `firmen`, `listen`, `listen_spalten`, `objekte`, `pendenz_acl`, `pendenz_dateien`, `pendenz_field_defs`, `pendenz_kategorien`, `pendenz_subkategorien`, `pendenzen`, `projekte`, `team_projekte`, `teams`, `wohnungen` | require_login, can_view_pendenz, can_edit_pendenz |
| `pages/profil - Kopie (2).php` | `benutzer`, `benutzer_personentypen`, `bkp_codes`, `firmen`, `objekte`, `pendenzen_arten`, `person_statuses`, `person_types`, `projekte`, `unternehmer_projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/profil - Kopie.php` | `benutzer`, `bkp_codes`, `firma_user`, `firmen`, `objekte`, `pendenzen_arten`, `projekte`, `user_invites`, `wohnungen` | require_login, benutzer_csrf_validate_or_throw |
| `pages/vorgangsart_settings - Kopie (2).php` | `benutzer`, `firma_user`, `firmen`, `objekte`, `pendenzen_arten`, `projekte`, `wohnungen` | require_login |
| `pages/vorgangsart_settings - Kopie.php` | `objekte`, `pendenzen_arten`, `pendenzen_arten_projekte`, `projekte` | require_login |

## Anhang D – aktuelle bytegleiche Quelltextgruppen

Ermittlung über SHA-256 des aktuellen Inhalts der inventarisierten PHP/JS/CSS/SQL-Dateien. 207 Gruppen mit mindestens zwei identischen Dateien. Das ist enger als funktional doppelter Code; unterschiedliche Versionen werden hier nicht zusammengefasst. Leere Dateien können ebenfalls Gruppen bilden.

- `api/ai_chat_messages - Kopie (2).php` = `api/ai_chat_messages - Kopie.php`
- `api/migrate_lists.php` = `api/migrate_lists2.php`
- `debug_schema_status.php` = `pages/debug_schema_status.php`
- `debug_task.php` = `pages/aus homepage/debug_task.php` = `pages/debug_task.php`
- `includes/authz - Kopie.php` = `includes/authz.php`
- `includes/pendenz_workflow.php` = `pages/pages per 07.05.2026/pendenz_work.php` = `pages/pendenz_work.php`
- `migrate_live.php` = `mysql/migrate_live.php`
- `pages/abnahme.php` = `pages/pages per 07.05.2026/abnahme.php`
- `pages/abnahmen.php` = `pages/pages per 07.05.2026/abnahmen.php`
- `pages/abnahme_action.php` = `pages/pages per 07.05.2026/abnahme_action.php`
- `pages/abnahme_edit.php` = `pages/pages per 07.05.2026/abnahme_edit.php`
- `pages/abnahme_neu.php` = `pages/pages per 07.05.2026/abnahme_neu.php`
- `pages/abnahme_save.php` = `pages/pages per 07.05.2026/abnahme_save.php`
- `pages/accept_invite.php` = `pages/pages per 07.05.2026/accept_invite.php`
- `pages/admin_matrix_hub.php` = `pages/pages per 07.05.2026/admin_matrix_hub.php`
- `pages/ai_assistant - Kopie.php` = `pages/pages per 07.05.2026/ai_assistant - Kopie.php`
- `pages/ai_assistant.php` = `pages/pages per 07.05.2026/ai_assistant.php`
- `pages/ajax_applicants.php` = `pages/pages per 07.05.2026/ajax_applicants.php`
- `pages/ajax_template_preview.php` = `pages/pages per 07.05.2026/ajax_template_preview.php`
- `pages/ajax_unit_applicants.php` = `pages/pages per 07.05.2026/ajax_unit_applicants.php`
- `pages/ajax_unit_rooms.php` = `pages/pages per 07.05.2026/ajax_unit_rooms.php`
- `pages/anfrage.php` = `pages/pages per 07.05.2026/anfrage.php`
- `pages/api_smarttable.php` = `pages/pages per 07.05.2026/api_smarttable.php`
- `pages/aus homepage/check_cols.php` = `pages/check_cols.php` = `scratch/check_cols.php`
- `pages/aus homepage/pendenzen_.php` = `pages/pages per 07.05.2026/pendenzen - Kopie (8).php` = `pages/pages per 07.05.2026/pendenzen - Kopie (9).php` = `pages/pendenzen - Kopie (8).php` = `pages/pendenzen - Kopie (9).php`
- `pages/benachrichtigungen.php` = `pages/pages per 07.05.2026/benachrichtigungen.php`
- `pages/benutzer - Kopie (2).php` = `pages/pages per 07.05.2026/benutzer - Kopie (2).php`
- `pages/benutzer - Kopie (3).php` = `pages/pages per 07.05.2026/benutzer - Kopie (3).php`
- `pages/benutzer - Kopie.php` = `pages/pages per 07.05.2026/benutzer - Kopie.php`
- `pages/benutzereinstellungen.php` = `pages/pages per 07.05.2026/benutzereinstellungen.php`
- `pages/bewerbung.php` = `pages/pages per 07.05.2026/bewerbung.php`
- `pages/bkp_codes.php` = `pages/pages per 07.05.2026/bkp_codes.php`
- `pages/brain_migration.php` = `pages/pages per 07.05.2026/brain_migration.php`
- `pages/chat.php` = `pages/pages per 07.05.2026/chat.php`
- `pages/check_headers.php` = `pages/pages per 07.05.2026/check_headers.php`
- `pages/check_schema_bkp.php` = `scratch/check_schema_bkp.php`
- `pages/check_task.php` = `pages/pages per 07.05.2026/check_task.php`
- `pages/check_u4.php` = `pages/pages per 07.05.2026/check_u4.php`
- `pages/cleanup_temp.php` = `pages/pages per 07.05.2026/cleanup_temp.php`
- `pages/dashboard.php` = `pages/pages per 07.05.2026/dashboard.php`
- `pages/debug_export.php` = `pages/pages per 07.05.2026/debug_export.php`
- `pages/debug_nav.php` = `pages/pages per 07.05.2026/debug_nav.php`
- `pages/debug_schema_web.php` = `pages/pages per 07.05.2026/debug_schema_web.php`
- `pages/desc_benutzer.php` = `pages/pages per 07.05.2026/desc_benutzer.php`
- `pages/dev_csp_check.php` = `pages/pages per 07.05.2026/dev_csp_check.php`
- `pages/docs.php` = `pages/pages per 07.05.2026/docs.php`
- `pages/file.php` = `pages/pages per 07.05.2026/file.php`
- `pages/files.php` = `pages/pages per 07.05.2026/files.php`
- `pages/files_master.php` = `pages/pages per 07.05.2026/files_master.php`
- `pages/finanzen.php` = `pages/pages per 07.05.2026/finanzen.php`
- `pages/firmen - Kopie (2).php` = `pages/pages per 07.05.2026/firmen - Kopie (2).php`
- `pages/firmen - Kopie.php` = `pages/pages per 07.05.2026/firmen - Kopie.php`
- `pages/firmen.php` = `pages/pages per 07.05.2026/firmen.php`
- `pages/fix_rooms_express.php` = `pages/pages per 07.05.2026/fix_rooms_express.php`
- `pages/hard_reset.php` = `pages/pages per 07.05.2026/hard_reset.php`
- `pages/idee.php` = `pages/pages per 07.05.2026/idee.php`
- `pages/interessenten.php` = `pages/pages per 07.05.2026/interessenten.php`
- `pages/interessenten_form.php` = `pages/pages per 07.05.2026/interessenten_form.php`
- `pages/interessenten_form_public.php` = `pages/pages per 07.05.2026/interessenten_form_public.php`
- `pages/interessent_invite_save.php` = `pages/pages per 07.05.2026/interessent_invite_save.php`
- `pages/invite_accept.php` = `pages/pages per 07.05.2026/invite_accept.php`
- `pages/invite_open.php` = `pages/pages per 07.05.2026/invite_open.php`
- `pages/kontakt.php` = `pages/pages per 07.05.2026/kontakt.php`
- `pages/kontodaten tabelle.sql` = `pages/pages per 07.05.2026/kontodaten tabelle.sql` = `tools/konto_verwaltung/export.php` = `tools/konto_verwaltung/konto_verwaltung.php`
- `pages/lint_all.php` = `pages/pages per 07.05.2026/lint_all.php`
- `pages/listen_settings - Kopie.php` = `pages/listen_settings.php` = `pages/pages per 07.05.2026/listen_settings - Kopie.php` = `pages/pages per 07.05.2026/listen_settings.php`
- `pages/list_users.php` = `pages/pages per 07.05.2026/list_users.php`
- `pages/login.php` = `pages/pages per 07.05.2026/login.php`
- `pages/logout.php` = `pages/pages per 07.05.2026/logout.php`
- `pages/mieter_dashboard.php` = `pages/pages per 07.05.2026/mieter_dashboard.php`
- `pages/mieter_zuweisen.php` = `pages/pages per 07.05.2026/mieter_zuweisen.php`
- `pages/neu 1invite_accept.php` = `pages/pages per 07.05.2026/neu 1invite_accept.php`
- `pages/objekt_edit.php` = `pages/pages per 07.05.2026/objekt_edit.php`
- `pages/objekt_neu.php` = `pages/pages per 07.05.2026/objekt_neu.php`
- `pages/ordnerstruktur.php` = `pages/pages per 07.05.2026/ordnerstruktur.php`
- `pages/ordner_verknuepfen.php` = `pages/pages per 07.05.2026/ordner_verknuepfen.php`
- `pages/ordner_vorlagen.php` = `pages/pages per 07.05.2026/ordner_vorlagen.php`
- `pages/ordner_vorlagen_edit.php` = `pages/pages per 07.05.2026/ordner_vorlagen_edit.php`
- `pages/ordner_vorlagen_new.php` = `pages/pages per 07.05.2026/ordner_vorlagen_new.php`
- `pages/ordner_vorlage_apply.php` = `pages/pages per 07.05.2026/ordner_vorlage_apply.php`
- `pages/pages per 07.05.2026/password_reset.php` = `pages/password_reset.php`
- `pages/pages per 07.05.2026/pdf_designer.php` = `pages/pdf_designer.php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (10).php` = `pages/pendenzen - Kopie (10).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (11).php` = `pages/pendenzen - Kopie (11).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (12).php` = `pages/pendenzen - Kopie (12).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (13).php` = `pages/pendenzen - Kopie (13).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (14).php` = `pages/pages per 07.05.2026/pendenzen - Kopie (15).php` = `pages/pages per 07.05.2026/pendenzen - Kopie (16).php` = `pages/pages per 07.05.2026/pendenzen - Kopie (22).php` = `pages/pendenzen - Kopie (14).php` = `pages/pendenzen - Kopie (15).php` = `pages/pendenzen - Kopie (16).php` = `pages/pendenzen - Kopie (22).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (17).php` = `pages/pendenzen - Kopie (17).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (18).php` = `pages/pages per 07.05.2026/pendenzen - Kopie (19).php` = `pages/pages per 07.05.2026/pendenzen - Kopie (20).php` = `pages/pendenzen - Kopie (18).php` = `pages/pendenzen - Kopie (19).php` = `pages/pendenzen - Kopie (20).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (2).php` = `pages/pendenzen - Kopie (2).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (21).php` = `pages/pendenzen - Kopie (21).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (23).php` = `pages/pendenzen - Kopie (23).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (3).php` = `pages/pendenzen - Kopie (3).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (4).php` = `pages/pendenzen - Kopie (4).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (5).php` = `pages/pendenzen - Kopie (5).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (6).php` = `pages/pendenzen - Kopie (6).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie (7).php` = `pages/pendenzen - Kopie (7).php`
- `pages/pages per 07.05.2026/pendenzen - Kopie.php` = `pages/pendenzen - Kopie.php`
- `pages/pages per 07.05.2026/pendenzen_settings.php` = `pages/pendenzen_settings.php`
- `pages/pages per 07.05.2026/pendenz_inbox.php` = `pages/pendenz_inbox.php`
- `pages/pages per 07.05.2026/pendenz_invite.php` = `pages/pendenz_invite.php`
- `pages/pages per 07.05.2026/pendenz_kategorien - Kopie (2).php` = `pages/pages per 07.05.2026/pendenz_kategorien-Kopie(2).php` = `pages/pendenz_kategorien - Kopie (2).php` = `pages/pendenz_kategorien-Kopie(2).php`
- `pages/pages per 07.05.2026/pendenz_kategorien - Kopie.php` = `pages/pages per 07.05.2026/pendenz_kategorien-Kopie.php` = `pages/pendenz_kategorien - Kopie.php` = `pages/pendenz_kategorien-Kopie.php`
- `pages/pages per 07.05.2026/pendenz_kategorien.php` = `pages/pendenz_kategorien.php`
- `pages/pages per 07.05.2026/pendenz_kategorien_mieter - Kopie.php` = `pages/pendenz_kategorien_mieter - Kopie.php`
- `pages/pages per 07.05.2026/pendenz_kategorien_mieter.php` = `pages/pendenz_kategorien_mieter.php`
- `pages/pages per 07.05.2026/pendenz_kategorien_unternehmer.php` = `pages/pendenz_kategorien_unternehmer.php`
- `pages/pages per 07.05.2026/pendenz_kategorien_vermieter - Kopie.php` = `pages/pendenz_kategorien_vermieter - Kopie.php`
- `pages/pages per 07.05.2026/pendenz_kategorien_vermieter.php` = `pages/pendenz_kategorien_vermieter.php`
- `pages/pages per 07.05.2026/pendenz_neu - Kopie (2).php` = `pages/pages per 07.05.2026/pendenz_neu modern.php` = `pages/pendenz_neu - Kopie (2).php` = `pages/pendenz_neu modern.php`
- `pages/pages per 07.05.2026/pendenz_neu - Kopie (3).php` = `pages/pendenz_neu - Kopie (3).php`
- `pages/pages per 07.05.2026/pendenz_neu - Kopie (4).php` = `pages/pendenz_neu - Kopie (4).php`
- `pages/pages per 07.05.2026/pendenz_neu - Kopie (5).php` = `pages/pendenz_neu - Kopie (5).php`
- `pages/pages per 07.05.2026/pendenz_neu - Kopie (6).php` = `pages/pages per 07.05.2026/pendenz_neu - Kopie (7).php` = `pages/pages per 07.05.2026/pendenz_neu - Kopie (8).php` = `pages/pages per 07.05.2026/pendenz_neu - Kopie (9).php` = `pages/pendenz_neu - Kopie (6).php` = `pages/pendenz_neu - Kopie (7).php` = `pages/pendenz_neu - Kopie (8).php` = `pages/pendenz_neu - Kopie (9).php`
- `pages/pages per 07.05.2026/pendenz_neu - Kopie.php` = `pages/pendenz_neu - Kopie.php`
- `pages/pages per 07.05.2026/pendenz_neu_modern.php` = `pages/pendenz_neu_modern.php`
- `pages/pages per 07.05.2026/pendenz_ordner.php` = `pages/pendenz_ordner.php`
- `pages/pages per 07.05.2026/pendenz_response.php` = `pages/pendenz_response.php`
- `pages/pages per 07.05.2026/pendenz_review.php` = `pages/pendenz_review.php`
- `pages/pages per 07.05.2026/pendenz_vorlagen.php` = `pages/pendenz_vorlagen.php`
- `pages/pages per 07.05.2026/personen_taxonomie.php` = `pages/personen_taxonomie.php`
- `pages/pages per 07.05.2026/profil - Kopie (2).php` = `pages/profil - Kopie (2).php`
- `pages/pages per 07.05.2026/profil - Kopie.php` = `pages/profil - Kopie.php`
- `pages/pages per 07.05.2026/profile_fill.php` = `pages/profile_fill.php`
- `pages/pages per 07.05.2026/project_storage.php` = `pages/project_storage.php`
- `pages/pages per 07.05.2026/projekte.php` = `pages/projekte.php`
- `pages/pages per 07.05.2026/projekt_baum.php` = `pages/projekt_baum.php`
- `pages/pages per 07.05.2026/projekt_bearbeiten.php` = `pages/projekt_bearbeiten.php`
- `pages/pages per 07.05.2026/projekt_detail.php` = `pages/projekt_detail.php`
- `pages/pages per 07.05.2026/projekt_verknuepfungen.php` = `pages/projekt_verknuepfungen.php`
- `pages/pages per 07.05.2026/projekt_waehlen.php` = `pages/projekt_waehlen.php`
- `pages/pages per 07.05.2026/public_profile.php` = `pages/public_profile.php`
- `pages/pages per 07.05.2026/quick_folder_editor.php` = `pages/quick_folder_editor.php`
- `pages/pages per 07.05.2026/schema_check.php` = `pages/schema_check.php`
- `pages/pages per 07.05.2026/schema_to_file.php` = `pages/schema_to_file.php`
- `pages/pages per 07.05.2026/secret_reset_temp.php` = `pages/secret_reset_temp.php`
- `pages/pages per 07.05.2026/settings.php` = `pages/settings.php`
- `pages/pages per 07.05.2026/setup.php` = `pages/setup.php`
- `pages/pages per 07.05.2026/set_password.php` = `pages/set_password.php`
- `pages/pages per 07.05.2026/storage_manager.php` = `pages/storage_manager.php`
- `pages/pages per 07.05.2026/struktur_einbauen.php` = `pages/struktur_einbauen.php`
- `pages/pages per 07.05.2026/sync_drive_real.php` = `pages/sync_drive_real.php`
- `pages/pages per 07.05.2026/sync_v2.php` = `pages/sync_v2.php`
- `pages/pages per 07.05.2026/system_settings.php` = `pages/system_settings.php`
- `pages/pages per 07.05.2026/tables.php` = `pages/tables.php`
- `pages/pages per 07.05.2026/table_rows.php` = `pages/table_rows.php`
- `pages/pages per 07.05.2026/teams.php` = `pages/teams.php`
- `pages/pages per 07.05.2026/temp_script1.js` = `pages/temp_script1.js`
- `pages/pages per 07.05.2026/temp_script2.js` = `pages/temp_script2.js`
- `pages/pages per 07.05.2026/test_context.php` = `pages/test_context.php`
- `pages/pages per 07.05.2026/test_schema.php` = `pages/test_schema.php`
- `pages/pages per 07.05.2026/test_schema2.php` = `pages/test_schema2.php`
- `pages/pages per 07.05.2026/ueber_uns.php` = `pages/ueber_uns.php`
- `pages/pages per 07.05.2026/unterkategorie_bearbeiten.php` = `pages/unterkategorie_bearbeiten.php`
- `pages/pages per 07.05.2026/unterkategorie_loeschen.php` = `pages/unterkategorie_loeschen.php`
- `pages/pages per 07.05.2026/unterkategorie_neu.php` = `pages/unterkategorie_neu.php`
- `pages/pages per 07.05.2026/user_events.php` = `pages/user_events.php`
- `pages/pages per 07.05.2026/user_taxonomy.php` = `pages/user_taxonomy.php`
- `pages/pages per 07.05.2026/vermietungseinheiten.php` = `pages/vermietungseinheiten.php`
- `pages/pages per 07.05.2026/vertrag_gen.php` = `pages/vertrag_gen.php`
- `pages/pages per 07.05.2026/vertrag_save.php` = `pages/vertrag_save.php`
- `pages/pages per 07.05.2026/vorgangsart_settings - Kopie (2).php` = `pages/vorgangsart_settings - Kopie (2).php`
- `pages/pages per 07.05.2026/vorgangsart_settings - Kopie.php` = `pages/vorgangsart_settings - Kopie.php`
- `pages/pages per 07.05.2026/vorgangsart_settings.php` = `pages/vorgangsart_settings.php`
- `pages/pages per 07.05.2026/vorlagen.php` = `pages/vorlagen.php`
- `pages/pages per 07.05.2026/vorlage_bearbeiten.php` = `pages/vorlage_bearbeiten.php`
- `pages/pages per 07.05.2026/vorlage_loeschen.php` = `pages/vorlage_loeschen.php`
- `pages/pages per 07.05.2026/vorlage_reset_neu.php` = `pages/vorlage_reset_neu.php`
- `pages/pages per 07.05.2026/vorlage_sync_fs.php` = `pages/vorlage_sync_fs.php`
- `pages/pages per 07.05.2026/vorlage_tree.php` = `pages/vorlage_tree.php`
- `pages/pages per 07.05.2026/wohnungen_import_fs.php` = `pages/wohnungen_import_fs.php`
- `pages/pages per 07.05.2026/wohnung_detail.php` = `pages/wohnung_detail.php`
- `pages/test_delete_diagnostic.php` = `scratch/test_delete_diagnostic.php`
- `scratch/check_pendenzen.php` = `scratch_db_pendenzen.php`
- `sql/2025-09-14_add_sort_index.sql` = `sql/alle tabellen/2025-09-14_add_sort_index.sql`
- `sql/2025-09-18_smarttable_config.sql` = `sql/alle tabellen/2025-09-18_smarttable_config.sql`
- `sql/alle tabellen/2025-09-13_rbac_and_audit.sql` = `sql/migrations/2025-09-13_rbac_and_audit.sql`
- `sql/alle tabellen/2025-09-15_insert_demo_daten_mit_bilder.sql` = `sql/zusätzliche geladen/2025-09-15_insert_demo_daten_mit_bilder.sql`
- `sql/alle tabellen/alle tabellen ergänzen.sql` = `sql/zusätzliche geladen/alle tabellen ergänzen.sql`
- `sql/alle tabellen/create_anhaenge_table.sql` = `sql/create_anhaenge_table.sql`
- `sql/alle tabellen/create_benutzer_table.sql` = `sql/create_benutzer_table.sql`
- `sql/alle tabellen/create_benutzer_table_passwort.sql` = `sql/create_benutzer_table_passwort.sql`
- `sql/alle tabellen/create_pendenzen_table.sql` = `sql/create_pendenzen_table.sql`
- `sql/alle tabellen/create_projekte_table.sql` = `sql/create_projekte_table.sql`
- `sql/alle tabellen/erweiterung projekte.sql` = `sql/zusätzliche geladen/erweiterung projekte.sql`
- `sql/alle tabellen/Indexe für Performance.sql` = `sql/zusätzliche geladen/Indexe für Performance.sql`
- `sql/alle tabellen/JSON Gerenerated Columns.sql` = `sql/zusätzliche geladen/JSON Gerenerated Columns.sql`
- `sql/alle tabellen/JSON-Keys indizierbar machen.sql` = `sql/zusätzliche geladen/JSON-Keys indizierbar machen.sql`
- `sql/alle tabellen/kontodaten tabelle.sql` = `sql/kontodaten tabelle.sql`
- `sql/alle tabellen/liegenschafts_konto (2).sql` = `sql/liegenschafts_konto.sql`
- `sql/alle tabellen/liegenschafts_konto.sql` = `tools/konto_verwaltung/sql/liegenschafts_konto.sql`
- `sql/alle tabellen/liegenschafts_konto_ergänzungen.sql` = `sql/liegenschafts_konto_ergänzungen.sql` = `tools/konto_verwaltung/liegenschafts_konto_ergänzungen.sql`
- `sql/alle tabellen/passwort und benutzername.sql` = `sql/passwort und benutzername.sql`
- `sql/alle tabellen/pendenz2.sql` = `sql/zusätzliche geladen/pendenz2.sql`
- `sql/alle tabellen/pendenzen zusatztabellen abhängigkeiten.sql` = `sql/pendenzen zusatztabellen abhängigkeiten.sql`
- `sql/alle tabellen/projekte befüllen.sql` = `tools/konto_verwaltung/sql/projekte befüllen.sql`
- `sql/SQL-Erweiterungen pendenzen.sql` = `sql/alle tabellen/SQL-Erweiterungen pendenzen.sql`
- `sql/Superadmin Nedim.sql` = `sql/alle tabellen/Superadmin Nedim.sql`
- `sql/alle tabellen/vendor.sql` = `sql/zusätzliche geladen/vendor.sql`
- `sql/alle tabellen/wohnung_label zusat kategorie.sql` = `tools/konto_verwaltung/sql/wohnung_label zusat kategorie.sql`
- `sql/Benutzer sql/11_chat einstellungen.sql` = `sql/Benutzer sql/12_User pma anlegen + Rechte.sql`
- `sql/sql wiederherstellung/17_smarttable erweitern binds nav scopes.sql` = `sql/sql wiederherstellung/21_SmartTable um „Binds, Nav & Scopes“ erweitern.sql`
- `sql/sql wiederherstellung/18_als SmartTable registrieren.sql` = `sql/sql wiederherstellung/22_pendenzen als SmartTable registrieren.sql`
- `sql/sql wiederherstellung/23_benutzerbilder.sql` = `sql/sql wiederherstellung/25_move_uploaded_file.sql`
- `sql/sql wiederherstellung/36_6. Mieter-Zuordnung (Wohnung ↔ Benutzer).sql` = `sql/sql wiederherstellung/44_wohnung_mieter.sql`
- `sql/sql wiederherstellung/37_Bestehende Tabellen löschen.sql` = `sql/sql wiederherstellung/41_Alle betroffenen Tabellen explizit löschen.sql`
- `tmp.php` = `tmp2.php`

## Anhang E – übrige Quelltext- und Schemaartefakte

Vollständige Pfadliste der zusätzlich erfassten JS-/CSS-/SQL-Dateien. SQL-Dateien wurden ausschließlich als Text untersucht, nicht importiert oder ausgeführt. Namen wie „Migration“ oder „Fix“ sind keine Aussage über sichere Ausführbarkeit oder aktuellen Installationsstand.

### E. JS

- `Absicherung vor löschen/alles was mit smart ist/smarttable_settings_wizard.js`
- `Absicherung vor löschen/alles was mit smart ist/smarttable_settings.js`
- `Absicherung vor löschen/Benutzer/benutzer_edit.js`
- `assets/dashboard.js`
- `assets/js/ai_assistant.js`
- `assets/js/app.js`
- `assets/js/benutzer.js`
- `assets/js/chat_boot.js`
- `assets/js/chat_core.js`
- `assets/js/chat_create.js`
- `assets/js/chat_page.js`
- `assets/js/chat_widget.js`
- `assets/js/chat.js`
- `assets/js/frappe-gantt.min.js`
- `assets/js/html2pdf.bundle.min.js`
- `assets/js/listen_settings.js`
- `assets/js/nav_notifications.js`
- `assets/js/notifications_dropdown.js`
- `assets/js/notifications_page.js`
- `assets/js/offline_sync.js`
- `assets/js/pendenzen_cols_order.js`
- `assets/js/pendenzen_inline_create.js`
- `assets/js/pendenzen_list.js`
- `assets/js/projekt_baum.js`
- `assets/js/projekte.js`
- `assets/js/sicherung/offline_sync.js`
- `assets/js/smarttable.js`
- `assets/smarttable_settings.js`
- `assets/smarttable.js`
- `assets/tablelite.js`
- `pages/pages per 07.05.2026/temp_script1.js`
- `pages/pages per 07.05.2026/temp_script2.js`
- `pages/temp_script1.js`
- `pages/temp_script2.js`
- `sw.js`

### E. CSS

- `assets/chat_widget.css`
- `assets/css/ai_assistant.css`
- `assets/css/frappe-gantt.css`
- `assets/css/notifications.css`
- `assets/css/projekt_baum.css`
- `assets/css/style.css`
- `assets/css/vorlagen_dashboard.css`
- `assets/dashboard.css`
- `assets/nav.css`
- `assets/smarttable_settings.css`
- `assets/smarttable.css`
- `assets/style.css`
- `tools/konto_verwaltung/style.css`

### E. SQL

- `database/schema.sql`
- `migration_einmalig.sql`
- `migration_online_columns.sql`
- `mysql/2026_04_19_benutzer_personentypen.sql`
- `mysql/2026_04_19_benutzer_profile_seed.sql`
- `mysql/2026_04_19_benutzer_profile.sql`
- `mysql/2026_04_19_pendenzen_art_empfaenger_defaults.sql`
- `mysql/pendenz_com (6).sql`
- `pages/kontodaten tabelle.sql`
- `pages/pages per 07.05.2026/kontodaten tabelle.sql`
- `scratch/local_schema.sql`
- `scratch/pdf_templates_data.sql`
- `scratch/reset_72.sql`
- `scratch/update_template_72.sql`
- `sql/2025-09-14_add_sort_index.sql`
- `sql/2025-09-18_smarttable_config.sql`
- `sql/alle tabellen/2025-09-13_rbac_and_audit.sql`
- `sql/alle tabellen/2025-09-14_add_sort_index.sql`
- `sql/alle tabellen/2025-09-15_insert_demo_daten_mit_bilder.sql`
- `sql/alle tabellen/2025-09-18_smarttable_config.sql`
- `sql/alle tabellen/alle tabellen ergänzen.sql`
- `sql/alle tabellen/create_anhaenge_table.sql`
- `sql/alle tabellen/create_benutzer_table_passwort.sql`
- `sql/alle tabellen/create_benutzer_table.sql`
- `sql/alle tabellen/create_pendenzen_table.sql`
- `sql/alle tabellen/create_projekte_table.sql`
- `sql/alle tabellen/erweiterung projekte.sql`
- `sql/alle tabellen/Indexe für Performance.sql`
- `sql/alle tabellen/JSON Gerenerated Columns.sql`
- `sql/alle tabellen/JSON-Keys indizierbar machen.sql`
- `sql/alle tabellen/kontodaten tabelle.sql`
- `sql/alle tabellen/liegenschafts_konto (2).sql`
- `sql/alle tabellen/liegenschafts_konto_ergänzungen.sql`
- `sql/alle tabellen/liegenschafts_konto.sql`
- `sql/alle tabellen/passwort und benutzername.sql`
- `sql/alle tabellen/pendenz2.sql`
- `sql/alle tabellen/pendenzen zusatztabellen abhängigkeiten.sql`
- `sql/alle tabellen/projekte befüllen.sql`
- `sql/alle tabellen/SQL-Erweiterungen pendenzen.sql`
- `sql/alle tabellen/Superadmin Nedim.sql`
- `sql/alle tabellen/vendor.sql`
- `sql/alle tabellen/wohnung_label zusat kategorie.sql`
- `sql/alles ums pendenzen/01_Smart-Tabellen & Settings restlos entfernen.sql`
- `sql/alles ums pendenzen/02_Pendenzen-Tabelle minimal passend zur pendenzen.sql`
- `sql/alles ums pendenzen/03_ACL-Tabelle für “Sichtbarkeit = custom.sql`
- `sql/alles ums pendenzen/04_Optionale, aber nützliche Tabellen fürs Modul.sql`
- `sql/alles ums pendenzen/05_START TRANSACTION.sql`
- `sql/alles ums pendenzen/06_listen_manager.sql`
- `sql/alles ums pendenzen/07_Tabellenstruktur (SQL-Logik) benutzer.sql`
- `sql/alles ums pendenzen/08_ALTER TABLE teams MODIFY.sql`
- `sql/alles ums pendenzen/09_Benutzer - Projekte, Benutzer - Teams, Teams - Projekte.sql`
- `sql/alles ums pendenzen/1) team_projekte.sql`
- `sql/alles ums pendenzen/10_datnebank neu st johann löschen.sql`
- `sql/alles ums pendenzen/11_2025-09-22_m2m_teams_projekte.sql`
- `sql/alles ums pendenzen/12_pendenz.com 11_2025-09-22_m2m_teams_projekte_fix.sql`
- `sql/alles ums pendenzen/12_prüfen 3 punkte.sql`
- `sql/alles ums pendenzen/13_team_projekte_now_no_fk.sql`
- `sql/alles ums pendenzen/14_team_projekte_add_fk.sql`
- `sql/alles ums pendenzen/15_team_projekte_add_fk_smart.sql`
- `sql/alles ums pendenzen/2) (Optional) alte 1 n-Daten migrieren, nur wenn.sql`
- `sql/alles ums pendenzen/3) Indizes (optional, aber gut für Performance).sql`
- `sql/alles ums pendenzen/4) Sanity-Check (bitte so – ohne G).sql`
- `sql/alles ums pendenzen/Falls noch ein FK-Fehler auftaucht (#1005 150).sql`
- `sql/Benutzer sql/01_Benutzer mit firm.sql`
- `sql/Benutzer sql/02_Schritt 2 firma_user.sql`
- `sql/Benutzer sql/03_Optional Seed + Verknüpfung (damit du sofort Testdaten hast).sql`
- `sql/Benutzer sql/04_prüfen.sql`
- `sql/Benutzer sql/05_ALTER TABLE pendenzen.sql`
- `sql/Benutzer sql/06_1) SPALTEN entrümpeln (nur echte Duplikate Altlasten).sql`
- `sql/Benutzer sql/07_Migration Ordner & Dateien (einmalig ausführen).sql`
- `sql/Benutzer sql/08_(optional) SQL – firmen um Felder ergänzen.sql`
- `sql/Benutzer sql/09_Unread-Status pro Raum Nutzer.sql`
- `sql/Benutzer sql/10_SQL – Public-Link Felder.sql`
- `sql/Benutzer sql/11_chat einstellungen.sql`
- `sql/Benutzer sql/12_CREATE TABLE IF NOT EXISTS firmen_overrides.sql`
- `sql/Benutzer sql/12_User pma anlegen + Rechte.sql`
- `sql/Benutzer sql/13_chat nachrichten.sql`
- `sql/Benutzer sql/14 cahtrooms.sql`
- `sql/Benutzer sql/15_Token-Tabelle für Einladungen.sql`
- `sql/Benutzer sql/16_Drag-&-Drop.sql`
- `sql/Benutzer sql/17_2025_10_02_user_taxonomy.sql`
- `sql/Benutzer sql/18_DB einmal erweitern.sql`
- `sql/Benutzer sql/19_DB-Spalten (falls noch nicht vorhanden).sql`
- `sql/Benutzer sql/neu 318_Optional) Filter auf der Benutzerliste.sql`
- `sql/create_anhaenge_table.sql`
- `sql/create_benutzer_table_passwort.sql`
- `sql/create_benutzer_table.sql`
- `sql/create_pendenzen_table.sql`
- `sql/create_projekte_table.sql`
- `sql/kontodaten tabelle.sql`
- `sql/Kontoverwaltung/00_setup_projekt_verknuepfungen.sql`
- `sql/Kontoverwaltung/01_liegenschafts_konto.wohnung_label.sql`
- `sql/Kontoverwaltung/018_fks_projekt_verknuepfungen.sql`
- `sql/Kontoverwaltung/02_SQL – Tabellen für Tarifverlauf & Anpassungen.sql`
- `sql/Kontoverwaltung/03_Vermietungseinheiten mit Projekt- & Liegenschafts-Bezug, Ordnerpfad.sql`
- `sql/Kontoverwaltung/04_Wohnungen → Ordnerpfad.sql`
- `sql/Kontoverwaltung/05_Benutzer ⇄ Wohnung (mit optionalem Mieterordner).sql`
- `sql/Kontoverwaltung/06_optional, sehr nützlich) Wohnungsfläche für NK-Verteilung.sql`
- `sql/Kontoverwaltung/07_Konten Bewegungen sauber verknüpfbar machen.sql`
- `sql/Kontoverwaltung/08_Mietverträge (Mieterspiegel-Grundlage).sql`
- `sql/Kontoverwaltung/09_Auswertungen & Nebenkostenabrechnung (kurz).sql`
- `sql/Kontoverwaltung/10_NK-Kosten pro Liegenschaft & Zeitraum.sql`
- `sql/Kontoverwaltung/11_Migrationen (bereinigt, direkt lauffähig).sql`
- `sql/Kontoverwaltung/12_Mieterspiegel – Abfrage ohne  (für phpMyAdmin).sql`
- `sql/Kontoverwaltung/13_NK-Kosten pro Liegenschaft & Zeitraum – Abfrage ohne.sql`
- `sql/Kontoverwaltung/14_Fallback ohne CTE (falls deine DB keine CTEs kann).sql`
- `sql/Kontoverwaltung/15_Schnelle Checks.sql`
- `sql/Kontoverwaltung/16_SQL zentrale Link-Tabelle.sql`
- `sql/Kontoverwaltung/17_Beispielhafte FKs – nur ausführen, wenn die referenzierten Tabellen-Spalten existieren.sql`
- `sql/Kontoverwaltung/19_Aktive DB prüfen.sql`
- `sql/Kontoverwaltung/20_In die richtige DB wechseln & evtl. Reste aufräumen.sql`
- `sql/Kontoverwaltung/21_projekt_verknuepfungen.sql`
- `sql/Kontoverwaltung/22_ALTER TABLE projekt_verknuepfungen.sql`
- `sql/Kontoverwaltung/23_Existieren die referenzierten Tabellen.sql`
- `sql/Kontoverwaltung/24_CREATE TABLE projekt_verknuepfungen.sql`
- `sql/Kontoverwaltung/25_USE pendenz_com.sql`
- `sql/Kontoverwaltung/26_Sobald du mieter, konten, ordner.sql`
- `sql/Kontoverwaltung/27_Alternative falls du lieber überall UNSIGNED willst.sql`
- `sql/Kontoverwaltung/28_Lösung fehlende Tabellen anlegen.sql`
- `sql/Kontoverwaltung/29_für Ordner-Filter pro Projekt.sql`
- `sql/Kontoverwaltung/30_Öffnen-Link setzen + beim Ordner-Wechsel aktualisieren.sql`
- `sql/Kontoverwaltung/31_Eine einfache, generische Link-Tabelle.sql`
- `sql/Kontoverwaltung/32_Tariftabelle (Historie).sql`
- `sql/Kontoverwaltung/33_Buchungen  Konto (für Abrechnung).sql`
- `sql/liegenschafts_konto_ergänzungen.sql`
- `sql/liegenschafts_konto.sql`
- `sql/migrations/2025-09-13_rbac_and_audit.sql`
- `sql/passwort und benutzername.sql`
- `sql/pendenzen zusatztabellen abhängigkeiten.sql`
- `sql/Smarttabellen/01_smarttable_columns an smarttable_tables koppeln.sql`
- `sql/Smarttabellen/02_has_project_scope + project_fk_field.sql`
- `sql/Smarttabellen/03_ALTER TABLE pendenzen.sql`
- `sql/Smarttabellen/04_Action-Definitionen auslagerbar.sql`
- `sql/Smarttabellen/05_Audit Protokoll.sql`
- `sql/Smarttabellen/06_View-Sharing erweitern.sql`
- `sql/Smarttabellen/07_i18n für Labels.sql`
- `sql/Smarttabellen/08_DB-Mini-Erweiterung.sql`
- `sql/Smarttabellen/09_projekte.sql`
- `sql/Smarttabellen/10_Soft-Delete standardisieren.sql`
- `sql/Smarttabellen/11_always_where pflegen.sql`
- `sql/Smarttabellen/12_smarttable_columns.sql`
- `sql/Smarttabellen/13_Indizes für Performance.sql`
- `sql/Smarttabellen/14_Soft-Delete-Migration.sql`
- `sql/Smarttabellen/15_smarttable_tables upsert für projekte.sql`
- `sql/Smarttabellen/16_DB Soft-Delete standardisieren + Settings bereinigen.sql`
- `sql/Smarttabellen/17_DB standardisieren (empfohlen).sql`
- `sql/Smarttabellen/18_user erweitern.sql`
- `sql/Smarttabellen/19_SQL-Migration (einmal ausführen).sql`
- `sql/Smarttabellen/20_SQL-Migration (einmal ausführen).sql`
- `sql/Smarttabellen/21_DB-Erweiterung (Relationen optional pflegen).sql`
- `sql/Smarttabellen/22_SQL (Migration, einmal ausführen).sql`
- `sql/Smarttabellen/23_SQL Tabelle + Trigger.sql`
- `sql/Smarttabellen/24_DB-Migration (einmal in phpMyAdmin ausführen).sql`
- `sql/Smarttabellen/25_SQL – neue Felder & Sichtbarkeit (einmal ausführen).sql`
- `sql/Smarttabellen/26_SQL-Migration in MariaDB.sql`
- `sql/Smarttabellen/27_zusätzliche felder neue benutzer.sql`
- `sql/Smarttabellen/28_Tabelle wohnungen.sql`
- `sql/Smarttabellen/29_Tabelle wohnung_mieten.sql`
- `sql/Smarttabellen/30_Tabelle wohnung_mieter.sql`
- `sql/Smarttabellen/31_Tabelle wohnung_dokumente.sql`
- `sql/Smarttabellen/32_ALTER TABLE benutzer ergänzen.sql`
- `sql/Smarttabellen/33_Kleine SQL-Indices (einmalig ausführen) benutzer.sql`
- `sql/Smarttabellen/34_Möglichkeit A – Spalte etage in der DB hinzufügen.sql`
- `sql/Smarttabellen/35_Erweiterungen für wohnungen-Tabelle.sql`
- `sql/Smarttabellen/36_Neue Tabellen für Dateien für Wohnungen.sql`
- `sql/Smarttabellen/37_SQL-Erweiterung wohnungen mit halbe zimmer auch.sql`
- `sql/Smarttabellen/38_Logging, SMTP, Invite-Settings.sql`
- `sql/Smarttabellen/39_benutzer erweiterungen.sql`
- `sql/Smarttabellen/40_Lösung Tabelle erweitern wohnungen.sql`
- `sql/Smarttabellen/41_login mit name und nachname.sql`
- `sql/Smarttabellen/42_detailierte Benutzerinformationen.sql`
- `sql/Smarttabellen/43_Firmadaresse detailierter.sql`
- `sql/Smarttabellen/44_SQL – Tabelle benachrichtigungen.sql`
- `sql/Smarttabellen/45_MVP-Plan Datenmodell.sql`
- `sql/Smarttabellen/46_SQL — 50_chat_core.sql`
- `sql/Smarttabellen/47_CREATE TABLE chat_messages.sql`
- `sql/Smarttabellen/48_Tabelle für Nachrichten.sql`
- `sql/Smarttabellen/49_Temporär FOREIGN KEY weglassen.sql`
- `sql/sql wiederherstellung/0_alles löschen ausser benutzer.sql`
- `sql/sql wiederherstellung/01_basistabell.sql`
- `sql/sql wiederherstellung/02_smocketest.sql`
- `sql/sql wiederherstellung/03_voreinstellung benutzer und ein projekt.sql`
- `sql/sql wiederherstellung/04_on duplikate key.sql`
- `sql/sql wiederherstellung/06_schnell checks.sql`
- `sql/sql wiederherstellung/07_demoprojekt.sql`
- `sql/sql wiederherstellung/08_jüngere duplikate löschen.sql`
- `sql/sql wiederherstellung/10_keine doppelt anlegen.sql`
- `sql/sql wiederherstellung/11_schnelle Übersicht-View.sql`
- `sql/sql wiederherstellung/12_projekte fixen.sql`
- `sql/sql wiederherstellung/13_pendenzen.sql`
- `sql/sql wiederherstellung/14_kontoverwaltung.sql`
- `sql/sql wiederherstellung/15_Liegenschaftskonto.sql`
- `sql/sql wiederherstellung/16_smart setings.sql`
- `sql/sql wiederherstellung/17_smarttable erweitern binds nav scopes.sql`
- `sql/sql wiederherstellung/18_als SmartTable registrieren.sql`
- `sql/sql wiederherstellung/19_smarttable_settings.sql`
- `sql/sql wiederherstellung/20_Beispieleinträge.sql`
- `sql/sql wiederherstellung/21_SmartTable um „Binds, Nav & Scopes“ erweitern.sql`
- `sql/sql wiederherstellung/22_pendenzen als SmartTable registrieren.sql`
- `sql/sql wiederherstellung/23_benutzerbilder.sql`
- `sql/sql wiederherstellung/24_Firmendaten speichern.sql`
- `sql/sql wiederherstellung/25_move_uploaded_file.sql`
- `sql/sql wiederherstellung/27_SHOW COLUMNS FROM benutzer.sql`
- `sql/sql wiederherstellung/28_profilbild AS objekt_bild.sql`
- `sql/sql wiederherstellung/28_projekt mit relation.sql`
- `sql/sql wiederherstellung/28_tabellen anpassen.sql`
- `sql/sql wiederherstellung/29_projekte.sql`
- `sql/sql wiederherstellung/30_2. Objekte (gehören zu einem Projekt).sql`
- `sql/sql wiederherstellung/31_3. Wohnungen (gehören zu einem Objekt).sql`
- `sql/sql wiederherstellung/32_4. Zimmer (gehören zu einer Wohnung).sql`
- `sql/sql wiederherstellung/35_5. Gegenstände (gehören zu einem Zimmer).sql`
- `sql/sql wiederherstellung/36_6. Mieter-Zuordnung (Wohnung ↔ Benutzer).sql`
- `sql/sql wiederherstellung/36_Fremdschlüssel ergänzen.sql`
- `sql/sql wiederherstellung/36_spalten ergänzen falls fehlen.sql`
- `sql/sql wiederherstellung/37_Bestehende Tabellen löschen.sql`
- `sql/sql wiederherstellung/38_2. Neu importieren (meine saubere Version).sql`
- `sql/sql wiederherstellung/39_Variante A – Temporär Foreign Keys deaktivieren.sql`
- `sql/sql wiederherstellung/40_Foreign Keys wirklich deaktivieren.sql`
- `sql/sql wiederherstellung/41_Alle betroffenen Tabellen explizit löschen.sql`
- `sql/sql wiederherstellung/42_FK-Checks wieder einschalten.sql`
- `sql/sql wiederherstellung/43_SHOW TABLES.sql`
- `sql/sql wiederherstellung/44_wohnung_mieter.sql`
- `sql/sql wiederherstellung/45_profilbilder projekt.sql`
- `sql/sql wiederherstellung/46_queri_testen.sql`
- `sql/sql wiederherstellung/47_objekte (projekt_id, name, bezeichnung, bild).sql`
- `sql/SQL-Erweiterungen pendenzen.sql`
- `sql/struktur_vorlagen.sql/01_struktur_vorlagen.sql`
- `sql/struktur_vorlagen.sql/02_SQL-Beispiel zum Einfügen der Standard-Vorlage.sql`
- `sql/struktur_vorlagen.sql/03_Spalte projekt_id hinzufügen, um projektbezogene Unterkategorien zu speichern.sql`
- `sql/struktur_vorlagen.sql/04_UPDATE struktur_vorlagen.sql`
- `sql/struktur_vorlagen.sql/05_TABLE unterkategorien.sql`
- `sql/struktur_vorlagen.sql/06_Projekte um Rootpfad ergänzen.sql`
- `sql/struktur_vorlagen.sql/07_CREATE TABLE IF NOT EXISTS ordner_vorlagen.sql`
- `sql/struktur_vorlagen.sql/08_projekte.id auf UNSIGNED vereinheitlichen.sql`
- `sql/struktur_vorlagen.sql/09_ordnervorlage.sql`
- `sql/struktur_vorlagen.sql/10_INSERT INTO ordner_vorlagen.sql`
- `sql/struktur_vorlagen.sql/11_dashboard_cards.sql`
- `sql/Superadmin Nedim.sql`
- `sql/zusätzliche geladen/2025-09-15_insert_demo_daten_mit_bilder.sql`
- `sql/zusätzliche geladen/alle tabellen ergänzen.sql`
- `sql/zusätzliche geladen/erweiterung projekte.sql`
- `sql/zusätzliche geladen/Indexe für Performance.sql`
- `sql/zusätzliche geladen/JSON Gerenerated Columns.sql`
- `sql/zusätzliche geladen/JSON-Keys indizierbar machen.sql`
- `sql/zusätzliche geladen/pendenz2.sql`
- `sql/zusätzliche geladen/vendor.sql`
- `tools/konto_verwaltung/liegenschafts_konto_ergänzungen.sql`
- `tools/konto_verwaltung/sql/liegenschafts_konto.sql`
- `tools/konto_verwaltung/sql/projekte befüllen.sql`
- `tools/konto_verwaltung/sql/wohnung_label zusat kategorie.sql`
