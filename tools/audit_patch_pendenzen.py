from pathlib import Path
import re

path = Path('pages/pendenzen.php')
text = path.read_text(encoding='utf-8')
original = text


def require_once(old: str, new: str, label: str) -> None:
    global text
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly 1 match, got {count}')
    text = text.replace(old, new, 1)


# CSRF helper must be available before the early JSON handlers.
require_once(
    "require_once __DIR__ . '/../includes/functions.php';\n",
    "require_once __DIR__ . '/../includes/functions.php';\nrequire_once __DIR__ . '/../includes/csrf.php';\n",
    'csrf include',
)

# Replace state-changing GET handlers and the unowned layout writer with one
# authenticated, CSRF-protected JSON POST handler.
start_marker = '// --- AJAX Handler für Glocke zurücksetzen ---'
end_marker = "$id = (int) ($_GET['id'] ?? 0);"
start = text.find(start_marker)
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('early AJAX handler block not found')

secure_ajax = r'''// --- Sichere JSON-Aktionen (nur POST + CSRF + Berechtigung) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $ajaxInput = csrf_json_body() ?? [];
    $ajaxAction = (string) ($ajaxInput['action'] ?? '');

    if (in_array($ajaxAction, ['reset_unt_bell', 'save_profile_layout'], true)) {
        csrf_validate();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $ajaxUserId = (int) (current_user_id() ?? 0);

        if ($ajaxAction === 'reset_unt_bell') {
            $pendenzId = (int) ($ajaxInput['id'] ?? 0);
            if ($pendenzId <= 0) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Ungültige Pendenz.']);
                exit;
            }

            $stmtAccess = $mysqli->prepare('SELECT * FROM pendenzen WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            $stmtAccess->bind_param('i', $pendenzId);
            $stmtAccess->execute();
            $pendenzAccess = $stmtAccess->get_result()->fetch_assoc();
            $stmtAccess->close();

            if (!$pendenzAccess || !can_view_pendenz($mysqli, $pendenzAccess, $ajaxUserId)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung.']);
                exit;
            }

            $stmtBell = $mysqli->prepare('UPDATE pendenzen SET unt_new_input = 0 WHERE id = ? LIMIT 1');
            $stmtBell->bind_param('i', $pendenzId);
            $stmtBell->execute();
            $stmtBell->close();
            echo json_encode(['ok' => true]);
            exit;
        }

        $profileId = (int) ($ajaxInput['profile_id'] ?? 0);
        $columns = $ajaxInput['columns'] ?? [];
        if ($profileId <= 0 || !is_array($columns) || $columns === []) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Listenprofil.']);
            exit;
        }

        $stmtProfile = $mysqli->prepare("SELECT owner_id FROM listen WHERE id = ? AND table_name = 'pendenzen' LIMIT 1");
        $stmtProfile->bind_param('i', $profileId);
        $stmtProfile->execute();
        $profile = $stmtProfile->get_result()->fetch_assoc();
        $stmtProfile->close();

        if (!$profile || (!is_admin() && (int) ($profile['owner_id'] ?? 0) !== $ajaxUserId)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Dieses Listenprofil darf nicht geändert werden.']);
            exit;
        }

        $normalizeWidth = static function (mixed $value): ?string {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            return preg_match('/^(?:\d{1,4}(?:\.\d+)?(?:px|%|rem|em|vw)?|auto|min-content|max-content)$/i', $value)
                ? $value
                : null;
        };

        $mysqli->begin_transaction();
        try {
            $stmtDelete = $mysqli->prepare('DELETE FROM listen_spalten WHERE listen_id = ?');
            $stmtDelete->bind_param('i', $profileId);
            $stmtDelete->execute();
            $stmtDelete->close();

            $stmtColumn = $mysqli->prepare(
                'INSERT INTO listen_spalten '
                . '(listen_id, col_name, sort_order, width_desktop, width_ipad, width_mobile, visible_desktop, visible_ipad, visible_mobile) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order), width_desktop=VALUES(width_desktop), '
                . 'width_ipad=VALUES(width_ipad), width_mobile=VALUES(width_mobile), visible_desktop=VALUES(visible_desktop), '
                . 'visible_ipad=VALUES(visible_ipad), visible_mobile=VALUES(visible_mobile)'
            );

            foreach ($columns as $column) {
                if (!is_array($column)) {
                    continue;
                }
                $key = trim((string) ($column['key'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $key)) {
                    continue;
                }

                $sortOrder = max(0, min(10000, (int) ($column['order'] ?? 0)));
                $widthDesktop = $normalizeWidth($column['width_desktop'] ?? null);
                $widthIpad = $normalizeWidth($column['width_ipad'] ?? null);
                $widthMobile = $normalizeWidth($column['width_mobile'] ?? null);
                $visibleDesktop = !empty($column['visible_desktop']) ? 1 : 0;
                $visibleIpad = !empty($column['visible_ipad']) ? 1 : 0;
                $visibleMobile = !empty($column['visible_mobile']) ? 1 : 0;

                $stmtColumn->bind_param(
                    'isisssiii',
                    $profileId,
                    $key,
                    $sortOrder,
                    $widthDesktop,
                    $widthIpad,
                    $widthMobile,
                    $visibleDesktop,
                    $visibleIpad,
                    $visibleMobile
                );
                $stmtColumn->execute();
            }
            $stmtColumn->close();
            $mysqli->commit();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            $mysqli->rollback();
            error_log('[pendenzen] Listenprofil konnte nicht gespeichert werden: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Listenprofil konnte nicht gespeichert werden.']);
        }
        exit;
    }
}

'''
text = text[:start] + secure_ajax + text[end:]

# Never expose PHP warnings/notices to authenticated users in production.
text = text.replace("ini_set('display_errors', '1');", "ini_set('display_errors', '0');")
text = text.replace("ini_set('display_startup_errors', '1');", "ini_set('display_startup_errors', '0');")

# External/public access must be opt-in, never an implicit default.
require_once("    'external_can_view' => 1,", "    'external_can_view' => 0,", 'safe external default')
require_once(
    'external_can_view = 1, external_can_upload = 1, public_enabled = 1,',
    'external_can_view = ?, external_can_upload = ?, public_enabled = ?,',
    'update public flags',
)
require_once(
    "'assignee', 1, 1, 1, ?, ?, 'user', ?",
    "'assignee', ?, ?, ?, ?, ?, 'user', ?",
    'insert public flags',
)

# Update bind: add the three opt-in booleans after confirmation_required.
old_update_types = "'iiiiissssssissssiiiisi',"
new_update_types = "'iiiiissssssissssiiiiiiisi',"
require_once(old_update_types, new_update_types, 'update bind types')
old_update_args = """                    $input['confirmation_required'],
                    $input['bkp_id'],"""
new_update_args = """                    $input['confirmation_required'],
                    $input['external_can_view'],
                    $input['external_can_upload'],
                    $input['public_enabled'],
                    $input['bkp_id'],"""
require_once(old_update_args, new_update_args, 'update bind args')

# Insert bind: add the three opt-in booleans after confirmation_by.
# Locate the bind block by its current type string and patch its argument sequence.
insert_types_match = re.search(r"(\$stmtInsert->bind_param\(\s*)'([^']+)'", text)
if not insert_types_match:
    raise SystemExit('insert bind types not found')
insert_types = insert_types_match.group(2)
# Existing INSERT has three hard-coded ints converted to placeholders. Insert three i types
# immediately before the trailing token/extra/bkp segment by deriving from actual argument patch below.
insert_arg_old = """                    $input['confirmation_required'],
                    $newToken,"""
insert_arg_new = """                    $input['confirmation_required'],
                    $input['external_can_view'],
                    $input['external_can_upload'],
                    $input['public_enabled'],
                    $newToken,"""
require_once(insert_arg_old, insert_arg_new, 'insert bind args')
# The old type string belongs to the old number of arguments. Add three integer bind types
# directly before the token string. We know token + extra_json are strings and bkp is int.
# Validate by counting args later with PHP lint/runtime preparation; use a targeted known suffix.
old_insert_type_literal = "'iiiiissssssissssiiiisssi'"
if old_insert_type_literal in text:
    text = text.replace(old_insert_type_literal, "'iiiiissssssissssiiiiiiisssi'", 1)
else:
    # Fallback: common current branch value discovered by parsing the bind call. Insert 3 i
    # before the final two s + i suffix if unambiguous.
    current_literal = "'" + insert_types + "'"
    if not insert_types.endswith('ssi'):
        raise SystemExit(f'unexpected insert bind type signature: {insert_types}')
    patched_types = insert_types[:-3] + 'iii' + insert_types[-3:]
    text = text[:insert_types_match.start(2)] + patched_types + text[insert_types_match.end(2):]

# Protect edit mode before loading sensitive fields/attachments.
edit_anchor = "$editPendenzId = isset($_GET['edit_id']) && ctype_digit((string) $_GET['edit_id']) ? (int) $_GET['edit_id'] : null;\n"
edit_guard = edit_anchor + r'''if ($editPendenzId !== null) {
    $stmtEditAccess = $mysqli->prepare('SELECT * FROM pendenzen WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmtEditAccess->bind_param('i', $editPendenzId);
    $stmtEditAccess->execute();
    $editAccessRow = $stmtEditAccess->get_result()->fetch_assoc();
    $stmtEditAccess->close();
    if (!$editAccessRow || !can_edit_pendenz($mysqli, $editAccessRow, (int) (current_user_id() ?? 0))) {
        http_response_code(403);
        exit('Keine Berechtigung zum Bearbeiten dieser Pendenz.');
    }
}
'''
require_once(edit_anchor, edit_guard, 'edit access guard')

# All regular POST mutations on this page need CSRF and edit authorization.
post_anchor = "if ($_SERVER['REQUEST_METHOD'] === 'POST' && postStr('form_action') === 'set_cover') {"
post_guard = r'''if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_validate();

    $mutationPendenzId = null;
    foreach (['pendenz_id', 'inline_pendenz_id'] as $mutationKey) {
        if (isset($_POST[$mutationKey]) && ctype_digit((string) $_POST[$mutationKey])) {
            $mutationPendenzId = (int) $_POST[$mutationKey];
            break;
        }
    }

    if ($mutationPendenzId !== null && $mutationPendenzId > 0) {
        $stmtMutationAccess = $mysqli->prepare('SELECT * FROM pendenzen WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmtMutationAccess->bind_param('i', $mutationPendenzId);
        $stmtMutationAccess->execute();
        $mutationAccessRow = $stmtMutationAccess->get_result()->fetch_assoc();
        $stmtMutationAccess->close();
        if (!$mutationAccessRow || !can_edit_pendenz($mysqli, $mutationAccessRow, (int) (current_user_id() ?? 0))) {
            http_response_code(403);
            exit('Keine Berechtigung zum Ändern dieser Pendenz.');
        }
    }
}

''' + post_anchor
require_once(post_anchor, post_guard, 'POST mutation guard')

# New/create/update requests may not move data into a project the user cannot access.
project_anchor = "    $input['public_enabled'] = isset($_POST['public_enabled']) ? 1 : 0;\n"
project_guard = project_anchor + r'''
    if ($input['projekt_id'] !== null && (int) $input['projekt_id'] > 0) {
        require_project_access((int) $input['projekt_id']);
    }
'''
require_once(project_anchor, project_guard, 'project access guard')

# Limit list-profile visibility to own/shared profiles (admins may manage all).
profiles_anchor = "$profiles = [];\n$res = $mysqli->query(\"\n"
profiles_replacement = r'''$profiles = [];
$profileOwnerId = (int) (current_user_id() ?? 0);
$profileScopeSql = is_admin()
    ? '1=1'
    : '(l.owner_id = ' . $profileOwnerId . ' OR COALESCE(l.shared, 0) = 1)';
$res = $mysqli->query("
'''
require_once(profiles_anchor, profiles_replacement, 'profile scope setup')
require_once(
    "    WHERE l.table_name = 'pendenzen'\n",
    "    WHERE l.table_name = 'pendenzen' AND {$profileScopeSql}\n",
    'profile scope SQL',
)

# Coarse SQL ACL before LIMIT 100, then authoritative helper check per row.
where_anchor = '$whereParts = ["p.deleted_at IS NULL"];\n'
where_guard = where_anchor + r'''$listUserId = (int) (current_user_id() ?? 0);
if (!is_admin()) {
    $scopeParts = [
        'p.erstellt_von = ' . $listUserId,
        'p.zustaendig_id = ' . $listUserId,
        'EXISTS (SELECT 1 FROM pendenz_acl pa_acl WHERE pa_acl.pendenz_id = p.id AND pa_acl.benutzer_id = ' . $listUserId . ' AND pa_acl.can_view = 1)'
    ];
    $allowedProjectIds = array_values(array_filter(array_map('intval', user_project_ids($mysqli, $listUserId))));
    if ($allowedProjectIds !== []) {
        $scopeParts[] = 'p.projekt_id IN (' . implode(',', $allowedProjectIds) . ')';
    }
    $whereParts[] = '(' . implode(' OR ', $scopeParts) . ')';
}
'''
require_once(where_anchor, where_guard, 'list ACL scope')

# Handwerker visibility checks need BKP data from the row.
require_once(
    "        p.raum_id,\n        p.vorgangsart_id,",
    "        p.raum_id,\n        p.bkp_id,\n        p.assignee_can_edit,\n        p.vorgangsart_id,",
    'list auth columns',
)
require_once(
    "    while ($row = $res->fetch_assoc()) {\n        $recent[] = $row;\n    }",
    "    while ($row = $res->fetch_assoc()) {\n        if (can_view_pendenz($mysqli, $row, $listUserId)) {\n            $recent[] = $row;\n        }\n    }",
    'authoritative row ACL',
)

# Add CSRF token to all regular POST forms handled by this page. Duplicate CSRF fields
# are harmless; avoid touching forms that do not declare method=post.
def add_csrf_to_form(match: re.Match[str]) -> str:
    tag = match.group(0)
    tail = text[match.end():match.end() + 120]
    if 'csrf_input()' in tail:
        return tag
    return tag + "\n                <?= csrf_input() ?>"

text = re.sub(r'<form\b[^>]*\bmethod=[\"\']post[\"\'][^>]*>', add_csrf_to_form, text, flags=re.I)

# JS calls for bell/layout are now JSON POSTs with CSRF.
if 'const pendenzenCsrfToken =' not in text:
    script_anchor = '<script>\n    const dashArtMap = '
    script_replacement = '<script>\n    const pendenzenCsrfToken = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;\n    const dashArtMap = '
    require_once(script_anchor, script_replacement, 'JS CSRF token')

bell_pattern = re.compile(r"fetch\('pendenzen\.php\?action=reset_unt_bell&id=' \+ id\)\s*\.then\(r => r\.json\(\)\)")
text, bell_count = bell_pattern.subn(
    "fetch('pendenzen.php', {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':pendenzenCsrfToken}, body:JSON.stringify({action:'reset_unt_bell', id})}).then(r => r.json())",
    text,
)
if bell_count != 1:
    raise SystemExit(f'bell fetch: expected 1 match, got {bell_count}')

profile_old = "fetch(`pendenzen.php?action=save_profile_layout`, {\n                    method: 'POST',\n                    headers: {'Content-Type': 'application/json'},\n                    body: JSON.stringify({\n                        profile_id: activeProfileId,"
profile_new = "fetch('pendenzen.php', {\n                    method: 'POST',\n                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': pendenzenCsrfToken},\n                    body: JSON.stringify({\n                        action: 'save_profile_layout',\n                        profile_id: activeProfileId,"
require_once(profile_old, profile_new, 'profile fetch')

if text == original:
    raise SystemExit('no changes produced')

path.write_text(text, encoding='utf-8')
print('pages/pendenzen.php patched successfully')
