from pathlib import Path

path = Path('tools/audit_patch_pendenzen.py')
text = path.read_text(encoding='utf-8')
old = "headers: {'Content-Type': 'application/json'}"
new = "headers: { 'Content-Type': 'application/json' }"
if old not in text:
    raise SystemExit('profile header matcher not found in patcher')
path.write_text(text.replace(old, new, 1), encoding='utf-8')
print('audit patcher matcher fixed')
