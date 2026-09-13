<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/../config.php';
  require_once __DIR__ . '/../includes/auth.php';
  
  if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'unauthorized']);
    exit;
  }

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) { echo json_encode(['ok'=>false,'error'=>'Bad payload']); exit; }

  $pid = (int)($data['projekt_id'] ?? 0);
  $titel = trim($data['titel'] ?? '');
  if (!$pid || $titel === '') { echo json_encode(['ok'=>false,'error'=>'Projekt oder Titel fehlt']); exit; }

  $start = !empty($data['startdatum']) ? $data['startdatum'] : null;
  $end = !empty($data['enddatum']) ? $data['enddatum'] : null;
  $dauer = trim($data['dauer'] ?? '');
  
  // Extract number from Dauer string for unified logic
  $d_val = 0;
  if (!empty($dauer)) {
      $d_val = (int)preg_replace('/[^0-9]/', '', $dauer);
  }
  
  $vorgaenger_id = trim($data['vorgaenger_id'] ?? '');

  // 1. Calculate Startdatum or Enddatum based on MS-Project Predecessor Notation
  if (!empty($vorgaenger_id)) {
      $v_raw = strtolower(str_replace(' ', '', $vorgaenger_id));
      $chunks = array_filter(explode(',', $v_raw));
      $formatted_vorg_parts = [];
      
      foreach ($chunks as $chunk) {
          // Parse e.g. "15", "15ea", "15ea-2", "15aa+3", "15ee", "13ea-1tag", "11ea2"
          if (preg_match('/^([0-9]+)(ea|ee|aa|ae)?([+-]?[0-9]+)?/', $chunk, $m)) {
              $v_id = (int)$m[1];
              $v_type = !empty($m[2]) ? $m[2] : 'ea'; // default MS project: Ende-Anfang
              $v_offset = !empty($m[3]) ? (int)$m[3] : 0;
              
              $v_res = $mysqli->query("SELECT startdatum, enddatum FROM pendenzen WHERE id = $v_id");
              if ($v_res && ($v_row = $v_res->fetch_assoc())) {
                  $p_start = $v_row['startdatum'] ?: date('Y-m-d');
                  $p_end   = $v_row['enddatum'] ?: $p_start;
                  
                  // Base Date Anchor
                  $base_date = ($v_type === 'ea' || $v_type === 'ee') ? $p_end : $p_start;
                  
                  if ($base_date && $base_date !== '0000-00-00 00:00:00' && $base_date !== '0000-00-00') {
                      $computed = date('Y-m-d', strtotime($base_date . ($v_offset >= 0 ? " +$v_offset" : " $v_offset") . " days"));
                      
                      // Apply to Start or End depending on dependency type
                      if ($v_type === 'ea' || $v_type === 'aa') {
                          $start = $computed;
                          if ($d_val > 0) {
                              $end = date('Y-m-d', strtotime($start . " + {$d_val} days"));
                          }
                      } else {
                          $end = $computed;
                          if ($d_val > 0) {
                              $start = date('Y-m-d', strtotime($end . " - {$d_val} days"));
                          }
                      }
                  }
              }
              
              // Automatically Format the typed constraint into rigorous visual string
              $type_str = !empty($m[2]) ? strtolower($m[2]) : 'ea';
              $suffix = '';
              if ($v_offset != 0) {
                  $suffix = ($v_offset > 0 ? '+' : '') . $v_offset;
              }
              $formatted_vorg_parts[] = $v_id . $type_str . $suffix;
              
              break; // Auto-calculate only from the primary (first valid) dependency
          }
      }
      
      // Override the string right before the DB INSERT happens
      if (!empty($formatted_vorg_parts)) {
          $vorgaenger_id = implode(', ', $formatted_vorg_parts);
      }
  }

  // 2. Backward Calculation: If END is known but START is missing, calculate backwards!
  if (!empty($end) && empty($start) && $d_val > 0) {
      $start = date('Y-m-d', strtotime($end . " - {$d_val} days"));
  }

  // 3. Global Fallback for Start
  if (empty($start)) {
      $start = date('Y-m-d');
  }

  // 4. Forward Calculation for End
  if ($d_val > 0 && empty($end)) {
      $end = date('Y-m-d', strtotime($start . " + {$d_val} days"));
  }
  if (empty($end)) {
      $end = $start;
  }

  $status = $data['status'] ?? 'offen';
  $objekt = (int)($data['objekt_id'] ?? 0) ?: null;
  $wohnung = (int)($data['wohnung_id'] ?? 0) ?: null;
  $zustaendig = (int)($data['zustaendig_id'] ?? 0) ?: null;

  $stmt = $mysqli->prepare("INSERT INTO pendenzen (projekt_id, titel, startdatum, enddatum, dauer, vorgaenger_id, status, objekt_id, wohnung_id, zustaendig_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
  $stmt->bind_param('issssssiii', $pid, $titel, $start, $end, $dauer, $vorgaenger_id, $status, $objekt, $wohnung, $zustaendig);
  $ok = $stmt->execute();
  $id = $ok ? $stmt->insert_id : 0;
  $stmt->close();

  echo json_encode(['ok'=>$ok, 'id'=>$id]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
