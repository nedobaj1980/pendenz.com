# Vollständiges statisches Routenregister

Alle PHP-Dateien außerhalb Vendor, Uploads, Storage und Logverzeichnissen. Direkte Dateiaufrufe sind Routenkandidaten; Erreichbarkeit auf dem Live-Server ist nicht geprüft. Marker sind Suchhinweise, keine bewiesenen Rechte und keine Freigabe. Includes, dynamische SQL-Abfragen und indirekte Guards erfordern Kontextprüfung. Die manuell geprüfte Rollen-/Aktionsmatrix steht in 03-security-audit.md. Kopien bleiben als potenzielle eigene URLs enthalten.

| Datei | Klasse | Loginmarker | Rollenmarker | Objekt-ACL-Marker | CSRF-Marker | Schreibmarker | DDL |
|---|---|---|---|---|---|---|---|
| `Absicherung vor löschen/alles was mit smart ist/demo_smarttable.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_api.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_delete.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_fetch.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_restore.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_save.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_settings.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_settings_get.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/alles was mit smart ist/smarttable_settings_save.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `Absicherung vor löschen/Benutzer/benutzer_edit.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `Absicherung vor löschen/Benutzer/benutzer_invite.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `Absicherung vor löschen/Benutzer/benutzer_request.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_private.php` | Kopie/Archivkandidat | kein Marker | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_public.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_role.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_superadmin_start.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_tools.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/Nav die gelöscht werden können/nav_user.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `Absicherung vor löschen/Nav die gelöscht werden können/sim_bar.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/ai_chat_messages - Kopie (2).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/ai_chat_messages - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/ai_chat_messages.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/ai_query - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/ai_query.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/aus web/listen_preview.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/batch_update.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/benutzer_create.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/benutzer_delete.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/benutzer_update.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/bootstrap.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/chat/create_room.php` | Routenkandidat | kein Marker | ja | kein Marker | ja | ja | kein Marker |
| `api/chat/delete_room.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `api/chat/list_rooms.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/chat/list_team_rooms.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/chat/messages.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `api/chat/read.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `api/chat/rooms.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/chat/search_companies.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/chat/search_projects.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/chat/search_users.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/chat/typing.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `api/chat_list.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/chat_members.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `api/chat_messages.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/chat_rooms.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `api/chat_send.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `api/dashboard_prefs.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `api/dashboard_upload.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/dirlist.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | kein Marker | kein Marker |
| `api/export_outlook.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/favoriten_toggle.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/gantt_task_create.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/health.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/inspect_table.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/listen_delete.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/listen_field_delete.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/listen_field_save.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/listen_index.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/listen_one.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/listen_preview.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/listen_save.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/manage_pdf_templates.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/manage_protocol_templates.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `api/migrate_lists.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/migrate_lists2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/notifications_list.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/notifications_mark_seen.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/options_users.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/pdf_templates.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `api/pendenzen_delete.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/pendenzen_get.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/pendenzen_inline_save.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/pendenzen_list.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/pendenzen_preview.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/pendenzen_save.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/pendenzen_save_widths.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/pendenz_media.php` | Routenkandidat | ja | kein Marker | ja | kein Marker | ja | kein Marker |
| `api/pendenz_set_cover.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/projekt_context.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/projekt_member_list.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/projekt_member_remove.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/projekt_member_update.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/quick_insert.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `api/quick_update.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/save_pdf_mask.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/test_db.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `api/test_insert.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/unterkategorie_add.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/unterkategorie_indent.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/unterkategorie_move.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/user_invite.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `api/_bootstrap.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `app/core/autoload.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `app/modules/ai/AiService.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `app/modules/pendenzen/Service - Kopie.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `app/modules/pendenzen/Service.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `app/modules/pendenzen/ServiceFallback.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `assets/ajax/assign_membership.php` | Bibliothek/Einstieg/Asset | ja | ja | kein Marker | ja | ja | kein Marker |
| `assets/ajax/create_team.php` | Bibliothek/Einstieg/Asset | ja | ja | kein Marker | ja | ja | kein Marker |
| `assets/ajax/delete_team.php` | Bibliothek/Einstieg/Asset | ja | ja | kein Marker | ja | ja | kein Marker |
| `assets/ajax/firmen_overrides.php` | Bibliothek/Einstieg/Asset | ja | ja | kein Marker | ja | ja | ja |
| `brain_migration_columns.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `brain_migration_protokoll.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `check.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_benutzer.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_bkp_texts.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_cols.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_db.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_db_v2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_defaults.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_objekte_deep.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `check_pendenzen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_pendenzen_cols.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_protokolle_cols.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_schemas_v2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_users.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `check_wohnungen_media.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `cleanup_temp.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `config - Kopie.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `config.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `create_superadmin.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `db_fix_listen.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `db_fix_simple.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `db_fix_standalone.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `db_fix_zero_dates.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `debug_benutzer_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_chat_owner.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_cols.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_columns.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `debug_counts.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_data.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_db.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_db_sort.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_final.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_folders.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_hierarchy.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_images.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_objects.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_paths.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `debug_probe.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_schema_direct.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_schema_media.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_schema_status.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_sqlite.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_sql_mode.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_stats.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_task.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_user_id.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `debug_v_test.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `demonstration_init.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `desc_pendenzen.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `dev_list.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `dump_pendenzen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `find_sub.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `fix_bkp_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `fix_db_v2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `fix_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `fix_web.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `force_fix.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `force_login.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `force_refresh_rooms.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `force_scan.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `force_scan_final.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `get_cols.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `get_hierarchy.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `import_marbach.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `includes/audit.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `includes/Aus gesicherte dateien/nav_admin.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/auth - Kopie.php` | Kopie/Archivkandidat | ja | ja | ja | kein Marker | ja | kein Marker |
| `includes/auth.php` | Bibliothek/Einstieg/Asset | ja | ja | ja | kein Marker | ja | kein Marker |
| `includes/authz - Kopie.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/authz.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/bootstrap.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/csp.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/csrf.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | ja | kein Marker | kein Marker |
| `includes/csrf_benutzer.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | ja | kein Marker | kein Marker |
| `includes/csrf_core.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | ja | kein Marker | kein Marker |
| `includes/db.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/email_templates.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/footer - Kopie.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/footer.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/fs.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `includes/functions.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `includes/header.bak.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/header.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/layout.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/links.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/mail.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/mailer.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/media - Kopie.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/media.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/nav.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/nav_admin.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/nav_auto.php` | Bibliothek/Einstieg/Asset | kein Marker | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/nav_benutzer.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/nav_dispatch.php` | Bibliothek/Einstieg/Asset | kein Marker | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/nav_notifications.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/nav_public.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/nav_superadmin.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/pendenz_workflow.php` | Bibliothek/Einstieg/Asset | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `includes/project_ctx.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/quick_capture.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/rent.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `includes/room_taxonomy.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `includes/security_headers.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/tree_renderer.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/units_adapter.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `includes/url_helpers.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/user_folder_automation.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `includes/user_taxonomy.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `includes/user_ui.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `includes/vorgang_taxonomy - Kopie.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `includes/vorgang_taxonomy.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `index.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `index_admin.php` | Bibliothek/Einstieg/Asset | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `index_private.php` | Bibliothek/Einstieg/Asset | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `index_public.php` | Bibliothek/Einstieg/Asset | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `index_superadmin.php` | Bibliothek/Einstieg/Asset | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `info.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `Konzept/Projekt Struktur/20250923_Projektstruktur_1.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `list_all_tables.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `list_tables.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `list_tables_web.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `list_users.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `login.php` | Bibliothek/Einstieg/Asset | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `login_do.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `logout.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `maintenance/expire_invites.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `make_hash.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `migrate_live.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migrate_mieterspiegel.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migrate_protokoll_name.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migrate_time.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migrate_user_folders.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migrate_vorg.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_bkp2_data.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `migration_bkp2_full.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `migration_bkp_codes_fields.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_bkp_hierarchy.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_bkp_template_fields.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_finanzen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_mietverhaeltnisse.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_object_folders.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_online_columns.php` | Diagnose/Migration/Setup | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_pendenz_fields.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `migration_project_customization.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_realestate.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_structure_update.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `migration_user_wohnung.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `mini_check.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `mini_debug.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `model_probe.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `mysql/migrate_live.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/abnahme.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/abnahmen.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/abnahme_action.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/abnahme_edit.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/abnahme_neu.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/abnahme_save.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/accept_invite.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/admin_matrix_hub.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/ai_assistant - Kopie.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/ai_assistant.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/ajax_applicants.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/ajax_get_plans.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/ajax_quick_pendenz.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/ajax_send_pendenz_mail.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/ajax_template_preview.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/ajax_unit_applicants.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/ajax_unit_rooms.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/anfrage.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/api_smarttable.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/aus homepage/check_cols.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/aus homepage/debug_task.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/aus homepage/pdf_designer.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/aus homepage/pendenzen_.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/aus homepage/pendenzen_2.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/aus homepage/pendenzen_3.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/aus homepage/pendenz_neu.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/aus homepage/pendenz_pdf.php` | Kopie/Archivkandidat | ja | kein Marker | ja | kein Marker | kein Marker | kein Marker |
| `pages/aus homepage/pendenz_public.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/aus homepage/pendenz_show.php` | Kopie/Archivkandidat | ja | ja | ja | kein Marker | ja | kein Marker |
| `pages/aus homepage/wohnungsabnahme_protokoll.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/aus homepage/wohnungsabnahme_save.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/benachrichtigungen.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/benutzer - Kopie (2).php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/benutzer - Kopie (3).php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/benutzer - Kopie.php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | kein Marker |
| `pages/benutzer.php` | Routenkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/benutzereinstellungen.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/bewerbung.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/bkp_codes.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/brain_migration.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/chat.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/check_cols.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/check_headers.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/check_schema_bkp.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/check_task.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/check_u4.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/cleanup_temp.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/dashboard.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/debug_export.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/debug_nav.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/debug_schema_status.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/debug_schema_web.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/debug_task.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/desc_benutzer.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/dev_csp_check.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/docs.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/file.php` | Routenkandidat | ja | kein Marker | ja | kein Marker | kein Marker | kein Marker |
| `pages/files.php` | Routenkandidat | ja | kein Marker | ja | kein Marker | kein Marker | kein Marker |
| `pages/files_master.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/finanzen.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/firmen - Kopie (2).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/firmen - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/firmen.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | ja | ja |
| `pages/fix_rooms_express.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/hard_reset.php` | Diagnose/Migration/Setup | kein Marker | ja | kein Marker | kein Marker | ja | ja |
| `pages/idee.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/interessenten.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/interessenten_form.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/interessenten_form_public.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/interessent_invite_save.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/invite_accept.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/invite_open.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/kontakt.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | kein Marker | kein Marker |
| `pages/lint_all.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/listen_settings - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/listen_settings.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/list_users.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/login.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/logout.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/mieterspiegel.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/mieter_dashboard.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/mieter_zuweisen.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/neu 1invite_accept.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/objekt_edit.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/objekt_neu.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/ordnerstruktur.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/ordner_verknuepfen.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | ja | ja |
| `pages/ordner_vorlagen.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/ordner_vorlagen_edit.php` | Routenkandidat | ja | ja | kein Marker | ja | ja | kein Marker |
| `pages/ordner_vorlagen_new.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/ordner_vorlage_apply.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/abnahme.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/abnahmen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/abnahme_action.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/abnahme_edit.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/abnahme_neu.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/abnahme_save.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/accept_invite.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/admin_matrix_hub.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/ai_assistant - Kopie.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/ai_assistant.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/ajax_applicants.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/ajax_quick_pendenz.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/ajax_template_preview.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/ajax_unit_applicants.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/ajax_unit_rooms.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/anfrage.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/api_smarttable.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/baujournal2.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/baujournal_mockup_final.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/benachrichtigungen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/benutzer - Kopie (2).php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/pages per 07.05.2026/benutzer - Kopie (3).php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/pages per 07.05.2026/benutzer - Kopie.php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/benutzer.php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/pages per 07.05.2026/benutzereinstellungen.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/bewerbung.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/bkp_codes.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/brain_migration.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/chat.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/check_cols.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/check_headers.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/check_task.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/check_u4.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/cleanup_temp.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/dashboard.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/debug_export.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/debug_nav.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/debug_schema_web.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/desc_benutzer.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/dev_csp_check.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/docs.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/file.php` | Kopie/Archivkandidat | ja | kein Marker | ja | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/files.php` | Kopie/Archivkandidat | ja | kein Marker | ja | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/files_master.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/finanzen.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/firmen - Kopie (2).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/firmen - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/firmen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | ja |
| `pages/pages per 07.05.2026/fix_rooms_express.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/hard_reset.php` | Kopie/Archivkandidat | kein Marker | ja | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/idee.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/interessenten.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/interessenten_form.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/interessenten_form_public.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/interessent_invite_save.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/invite_accept.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/invite_open.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/kontakt.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/lint_all.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/listen_settings - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/listen_settings.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/list_users.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/login.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/logout.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/mieterspiegel.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/mieter_dashboard.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/mieter_zuweisen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/neu 1invite_accept.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/objekt_edit.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/objekt_neu.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/ordnerstruktur.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/ordner_verknuepfen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | ja |
| `pages/pages per 07.05.2026/ordner_vorlagen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/ordner_vorlagen_edit.php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/ordner_vorlagen_new.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/ordner_vorlage_apply.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/password_reset.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/password_reset_request.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pdf_designer.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenzen - Kopie (10).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenzen - Kopie (11).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (12).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenzen - Kopie (13).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenzen - Kopie (14).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (15).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (16).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (17).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (18).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (19).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (2).php` | Kopie/Archivkandidat | ja | ja | ja | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (20).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (21).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (22).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (23).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (3).php` | Kopie/Archivkandidat | ja | ja | ja | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (4).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenzen - Kopie (5).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenzen - Kopie (6).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenzen - Kopie (7).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenzen - Kopie (8).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie (9).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | ja | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenzen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenzen_liste.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenzen_list_pdf.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenzen_settings.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenz_inbox.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenz_invite.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_kategorien - Kopie (2).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenz_kategorien - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenz_kategorien-Kopie(2).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenz_kategorien-Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenz_kategorien.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenz_kategorien_mieter - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenz_kategorien_mieter.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenz_kategorien_unternehmer.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenz_kategorien_vermieter - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenz_kategorien_vermieter.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (2).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (3).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (4).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (5).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (6).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (7).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (8).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie (9).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu modern.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_neu.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenz_neu_modern.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_ordner.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_pdf.php` | Kopie/Archivkandidat | ja | kein Marker | ja | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenz_public.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_response.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_review.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/pendenz_show.php` | Kopie/Archivkandidat | ja | ja | ja | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/pendenz_vorlagen.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/pendenz_work.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/personen_taxonomie.php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/profil - Kopie (2).php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/pages per 07.05.2026/profil - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/profil.php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/pages per 07.05.2026/profile_fill.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/project_storage.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/projekte.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/projekt_baum.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/projekt_bearbeiten.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/projekt_dashboard.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/projekt_detail.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/projekt_neu.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/projekt_verknuepfungen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/projekt_waehlen.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/protokoll_designer.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/protokoll_fill.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/protokoll_fill_pdf.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/protokoll_manager.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/protokoll_pdf.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/protokoll_test.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/protokoll_view.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/protokoll_vorlagen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/public_profile.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/quick_folder_editor.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/run_seed.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/schema_check.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/schema_to_file.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/secret_reset_temp.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/settings.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/setup.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/set_password.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/storage_manager.php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | kein Marker |
| `pages/pages per 07.05.2026/struktur_einbauen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/sync_drive_real.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/sync_v2.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/system_settings.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/tables.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/table_rows.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/teams.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/terminprogramm.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/test_context.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/test_schema.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/test_schema2.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/ueber_uns.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/unterkategorie_bearbeiten.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/unterkategorie_loeschen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/unterkategorie_neu.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/user_events.php` | Kopie/Archivkandidat | kein Marker | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/user_taxonomy.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/vermietungseinheiten.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/vertrag_gen.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/vertrag_save.php` | Kopie/Archivkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/vorgangsart_settings - Kopie (2).php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/vorgangsart_settings - Kopie.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/vorgangsart_settings.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/pages per 07.05.2026/vorlagen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/vorlage_bearbeiten.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/vorlage_loeschen.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/vorlage_reset_neu.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/vorlage_sync_fs.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/vorlage_tree.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/wohnungen_import_fs.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/wohnungen_liste.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pages per 07.05.2026/wohnung_detail.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/wohnung_edit.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pages per 07.05.2026/wohnung_neu.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/password_reset.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/password_reset_request.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pdf_designer.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pendenzen - Kopie (10).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenzen - Kopie (11).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (12).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenzen - Kopie (13).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenzen - Kopie (14).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (15).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (16).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (17).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (18).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (19).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (2).php` | Kopie/Archivkandidat | ja | ja | ja | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (20).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (21).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (22).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (23).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (3).php` | Kopie/Archivkandidat | ja | ja | ja | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (4).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenzen - Kopie (5).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenzen - Kopie (6).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenzen - Kopie (7).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenzen - Kopie (8).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie (9).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | ja | kein Marker | ja | kein Marker |
| `pages/pendenzen.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenzen_liste.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pendenzen_list_pdf.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pendenzen_settings.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenz_inbox.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pendenz_invite.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/pendenz_kategorien - Kopie (2).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenz_kategorien - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pendenz_kategorien-Kopie(2).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenz_kategorien-Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pendenz_kategorien.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pendenz_kategorien_mieter - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenz_kategorien_mieter.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenz_kategorien_unternehmer.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pendenz_kategorien_vermieter - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenz_kategorien_vermieter.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/pendenz_neu - Kopie (2).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu - Kopie (3).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu - Kopie (4).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu - Kopie (5).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu - Kopie (6).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu - Kopie (7).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu - Kopie (8).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu - Kopie (9).php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu modern.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu_modern.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_neu__.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_ordner.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_pdf.php` | Routenkandidat | ja | kein Marker | ja | kein Marker | kein Marker | kein Marker |
| `pages/pendenz_public.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_response.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_review.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/pendenz_show.php` | Routenkandidat | ja | ja | ja | kein Marker | ja | ja |
| `pages/pendenz_vorlagen.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/pendenz_work.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/personen_taxonomie.php` | Routenkandidat | ja | ja | kein Marker | ja | ja | kein Marker |
| `pages/plan_zonen_edit.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/profil - Kopie (2).php` | Kopie/Archivkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/profil - Kopie.php` | Kopie/Archivkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/profil.php` | Routenkandidat | ja | ja | kein Marker | ja | ja | ja |
| `pages/profile_fill.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/project_storage.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/projekte.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/projekt_baum.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/projekt_bearbeiten.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/projekt_dashboard.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | ja | kein Marker |
| `pages/projekt_detail.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/projekt_neu.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/projekt_plaene.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/projekt_verknuepfungen.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | kein Marker | kein Marker |
| `pages/projekt_waehlen.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/public_profile.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/quick_folder_editor.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/schema_check.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/schema_to_file.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/secret_reset_temp.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/settings.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/setup.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/set_password.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/storage_manager.php` | Routenkandidat | ja | ja | kein Marker | ja | ja | kein Marker |
| `pages/struktur_einbauen.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/sync_drive_real.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/sync_v2.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/system_settings.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/tables.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/table_rows.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/teams.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/terminprogramm.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/test_context.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/test_delete_diagnostic.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/test_path.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/test_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/test_schema2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/ueber_uns.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/unterkategorie_bearbeiten.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/unterkategorie_loeschen.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/unterkategorie_neu.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/user_events.php` | Routenkandidat | kein Marker | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/user_taxonomy.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/vermietungseinheiten.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | ja |
| `pages/vertrag_gen.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/vertrag_save.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/vorgangsart_settings - Kopie (2).php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/vorgangsart_settings - Kopie.php` | Kopie/Archivkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `pages/vorgangsart_settings.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/vorlagen.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/vorlage_bearbeiten.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/vorlage_loeschen.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/vorlage_reset_neu.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/vorlage_sync_fs.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/vorlage_tree.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/wohnungen_import_fs.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/wohnungen_liste.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/wohnungsabnahme_protokoll.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `pages/wohnungsabnahme_save.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/wohnung_detail.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `pages/wohnung_edit.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | ja |
| `pages/wohnung_neu.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `patch_pendenz.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `phpinfo.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `portfolio_master.php` | Bibliothek/Einstieg/Asset | ja | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `public/impressum.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `public/index.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `register.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `rename_col.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `rename_table.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `repair_paths.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `run_migration.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `run_reset.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scan_drive.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/add_columns.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/check_benutzer_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_benutzer_tables.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_bkp_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_cols.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch/check_data.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_indexes.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_latest.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_listen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_lists.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_lists_v2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_logos.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_pendenzen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_pendenzen_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_schema_bkp.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/check_tables.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_task_87.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_task_9.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_types.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_vermieter.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/check_vermieter_data.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/compare_sql.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/create_pdf_templates_table.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/create_settings.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/db_cleanup_templates.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/db_debug.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/db_diag.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/db_inspect.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/db_inspect_v2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/db_reinforce.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/diagnose_all.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/dump_local_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/dump_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/export_templates.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch/fix_keller_forever.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch/fix_passwort_null.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/fix_pendenzen_rooms.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/get_settings_sql.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/init_vorgang.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/inspect_cache.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/inspect_cache_body.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/inspect_db.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/inspect_foreign_keys.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/inspect_pendenzen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/inspect_schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/list_dbs.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/list_dbs_pdo.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/list_tables.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/migrate_listen_spalten.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/parse_cache.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/reset_template_72.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch/schema.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/search_tables.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/seed_templates.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch/setup_abnahme_table.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/setup_settings.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch/sync_drive_to_db.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch/test_api.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/test_cache.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/test_delete_diagnostic.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch/test_pdf_generation.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/test_proxy.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch/test_resize.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch/update_template_72.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch/update_types.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch_audit_p1.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_catch_all.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_check_all_types.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_check_deps.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_check_icons.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_check_listen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_check_p2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_check_projekte.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_check_types.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_check_unit.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_check_units_p1.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_cleanup_unit.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch_count_all.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_db_check.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_db_inspect.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_db_pendenzen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_db_repair.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `scratch_debug_objects.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_desc_wohnungen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_detail_audit.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_final_audit.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_find_all_ghosts.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_find_deps.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_fix_and_check.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch_global_audit.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_global_cleanup.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch_global_cleanup_v2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch_pdo_check.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_restore_listen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch_schema_check.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `scratch_test_delete.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `scratch_test_query.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `seed_bkp_hierarchy.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `seed_bkp_hierarchy_full.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `seed_protocol_templates.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `setup_pins_db.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `setup_plaene_db.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `show_cols.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `show_tables.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `simulate.php` | Bibliothek/Einstieg/Asset | kein Marker | ja | kein Marker | kein Marker | kein Marker | kein Marker |
| `test.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_ajax.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_cols.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_db.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_db2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_dompdf.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_err.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_err2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_fs.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_hash.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_json.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_pendenzen.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_save.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `test_srv.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_srv2.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_srv3.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_srv4.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_sync.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `test_tables.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tmp/check_col.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tmp/check_db_diag.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tmp/db_check.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tmp/dump_tax.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tmp/fix_db_migration_simple.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `tmp/fix_db_saas.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `tmp/fix_status_column.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `tmp/kill_locks.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tmp/migration_web.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `tmp.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tmp2.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tools/check_assets.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tools/konto_verwaltung/export.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tools/konto_verwaltung/import.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `tools/konto_verwaltung/index.php` | Routenkandidat | ja | kein Marker | kein Marker | ja | ja | ja |
| `tools/konto_verwaltung/konto_verwaltung.php` | Routenkandidat | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tools/mieterspiegel/index.php` | Routenkandidat | ja | kein Marker | kein Marker | kein Marker | ja | kein Marker |
| `tools/mietkontrolle/index.php` | Routenkandidat | ja | ja | kein Marker | kein Marker | ja | kein Marker |
| `tools/seed_demo_users.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | ja | ja |
| `tools/test_deepseek.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `tools/test_openai.php` | Diagnose/Migration/Setup | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
| `widgets/projekt_einheiten_panel.php` | Bibliothek/Einstieg/Asset | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker | kein Marker |
