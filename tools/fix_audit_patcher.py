from pathlib import Path

path = Path('tools/audit_patch_pendenzen.py')
text = path.read_text(encoding='utf-8')
start = text.find('profile_old = ')
end_marker = "require_once(profile_old, profile_new, 'profile fetch')"
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit('profile matcher block not found in patcher')
end += len(end_marker)

replacement = r'''profile_pattern = re.compile(
    r"fetch\(`pendenzen\.php\?action=save_profile_layout`,\s*\{\s*"
    r"method:\s*'POST',\s*"
    r"headers:\s*\{\s*'Content-Type':\s*'application/json'\s*\},\s*"
    r"body:\s*JSON\.stringify\(\{\s*"
    r"profile_id:\s*activeProfileId,",
    re.S,
)
profile_replacement = "fetch('pendenzen.php', {\n                    method: 'POST',\n                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': pendenzenCsrfToken},\n                    body: JSON.stringify({\n                        action: 'save_profile_layout',\n                        profile_id: activeProfileId,"
text, profile_count = profile_pattern.subn(profile_replacement, text, count=1)
if profile_count != 1:
    raise SystemExit(f'profile fetch: expected 1 match, got {profile_count}')'''

path.write_text(text[:start] + replacement + text[end:], encoding='utf-8')
print('audit patcher matcher fixed')
