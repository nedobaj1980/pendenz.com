<?php
$file = __DIR__ . '/pages/pendenz_neu.php';
$content = file_get_contents($file);

// 1. Add fields to $input initialization
$search_input = "'erstellt_von' => ";
$replace_input = "'plan_id' => isset(\$_POST['plan_id']) && \$_POST['plan_id'] !== '' ? (int)\$_POST['plan_id'] : null,\n    'pin_x' => isset(\$_POST['pin_x']) && \$_POST['pin_x'] !== '' ? (float)\$_POST['pin_x'] : null,\n    'pin_y' => isset(\$_POST['pin_y']) && \$_POST['pin_y'] !== '' ? (float)\$_POST['pin_y'] : null,\n    'erstellt_von' => ";
$content = str_replace($search_input, $replace_input, $content);

// 2. Add to UPDATE query
// Search for: 'dauer'                     => $input['dauer'],
// Replace with: 'dauer' => ..., 'plan_id' => ..., 'pin_x' => ..., 'pin_y' => ...,
$search_upd = "'dauer'                     => \$input['dauer'],";
$replace_upd = "'dauer'                     => \$input['dauer'],\n              'plan_id' => \$input['plan_id'],\n              'pin_x' => \$input['pin_x'],\n              'pin_y' => \$input['pin_y'],";
$content = str_replace($search_upd, $replace_upd, $content);

// Also add to the binding in UPDATE
// "UPDATE pendenzen SET projekt_id=?, vorgangsart_id=?, ...
$search_upd_sql = "dauer=?, send_now=?,";
$replace_upd_sql = "dauer=?, send_now=?, plan_id=?, pin_x=?, pin_y=?,";
$content = str_replace($search_upd_sql, $replace_upd_sql, $content);

$search_upd_bind = "\$updateData['dauer'],\n              \$updateData['send_now'],";
$replace_upd_bind = "\$updateData['dauer'],\n              \$updateData['send_now'],\n              \$updateData['plan_id'],\n              \$updateData['pin_x'],\n              \$updateData['pin_y'],";
$content = str_replace($search_upd_bind, $replace_upd_bind, $content);

// The bind string for update: "iisssiisssssssisiiiiiisis" -> needs "idd" added.
// Wait, the bind string is dynamically built or static?
// Let's check how update is bound. If it's static, I must change it. Let's just do it directly.

// 3. Add to INSERT query
$search_ins1 = "dauer,\n                  send_now,";
$replace_ins1 = "dauer,\n                  send_now,\n                  plan_id,\n                  pin_x,\n                  pin_y,";
$content = str_replace($search_ins1, $replace_ins1, $content);

$search_ins2 = "?, ?, ?, ?, ?,\n                ?, ?";
$replace_ins2 = "?, ?, ?, ?, ?,\n                ?, ?, ?, ?, ?";
$content = str_replace($search_ins2, $replace_ins2, $content);

$search_ins3 = "\$input['dauer'],\n              \$input['send_now'],";
$replace_ins3 = "\$input['dauer'],\n              \$input['send_now'],\n              \$input['plan_id'],\n              \$input['pin_x'],\n              \$input['pin_y'],";
$content = str_replace($search_ins3, $replace_ins3, $content);

$search_ins4 = "\$input['empfaenger_typ'] ?? null\n          );";
// If they use bind_param, the types string might be hardcoded like "iisss..."
$search_ins_types = "'iisssiisssssssisiiiiiisis'";
$replace_ins_types = "'iisssiisssssssisiiiiiisisidd'"; // Wait, let's just replace all `bind_param(` to use an array or dynamically handle if needed.
// Actually, pendenz_neu.php is using a helper? No, `$stmt->bind_param(...)`.
// We will look at it closer.

file_put_contents($file . '.bak', $content);
echo "OK";
