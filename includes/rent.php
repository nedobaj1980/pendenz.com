<?php
// includes/rent.php
// Miettarife (Historie) & Konto-Buchungen Helferfunktionen
// --------------------------------------------------------
// Verwendung (Beispiele):
//   require_once __DIR__.'/config.php';
//   require_once __DIR__.'/rent.php';
//   rent_tables_ensure($mysqli); // optional, schützt vor fehlenden Tabellen
//
//   // 1) neuen Tarif ab 2025-10-01 setzen (schließt alten offen bis Vortag):
//   set_rent_period($mysqli, $ordner_link_id, '2025-10-01', 1350.00, 180.00, 'Erhöhung gem. Schreiben');
//
//   // 2) Tarif zu bestimmtem Datum holen:
//   $t = rent_on($mysqli, $ordner_link_id, '2025-11-01'); // ['mietzins_netto'=>..., 'nk_akonto'=>..., 'valid_from'=>..., 'valid_to'=>...]
//
//   // 3) Monatliche Sollstellung (bucht Miete+NK, idempotent durch Doppelbuchungscheck):
//   sollstellung_monat($mysqli, $project_id, $ordner_link_id, 2025, 11);
//
//   // 4) Zahlung verbuchen (negativer Betrag):
//   konto_buchen($mysqli, $project_id, $ordner_link_id, '2025-11-05', 'zahlung', -1350.00, 'E-Banking', 'Miete November');
//
//   // 5) Saldo bis Datum:
//   $saldo = saldo_bis($mysqli, $ordner_link_id, '2025-11-30');
//
// --------------------------------------------------------

/**
 * Legt benötigte Tabellen an (falls noch nicht existieren).
 * Sicher aufrufbar; in Prod kannst du es auch weglassen, wenn du die SQLs bereits importiert hast.
 */
function rent_tables_ensure(mysqli $db): void {
  $db->query("
    CREATE TABLE IF NOT EXISTS mieten_tarife (
      id INT AUTO_INCREMENT PRIMARY KEY,
      ordner_link_id INT NOT NULL,
      valid_from DATE NOT NULL,
      valid_to DATE NULL,
      mietzins_netto DECIMAL(12,2) NOT NULL,
      nk_akonto DECIMAL(12,2) DEFAULT NULL,
      bemerkung TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_mt_link FOREIGN KEY (ordner_link_id) REFERENCES ordner_links(id) ON DELETE CASCADE,
      INDEX (ordner_link_id, valid_from),
      CHECK (valid_to IS NULL OR valid_to >= valid_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $db->query("
    CREATE TABLE IF NOT EXISTS konto_buchungen (
      id INT AUTO_INCREMENT PRIMARY KEY,
      project_id INT NOT NULL,
      ordner_link_id INT NOT NULL,
      datum DATE NOT NULL,
      typ ENUM('miete','nk','nachzahlung','reduktion','gutschrift','zahlung','kaution','kaution_rueck') NOT NULL,
      betrag DECIMAL(12,2) NOT NULL,
      referenz VARCHAR(255) NULL,
      bemerkung TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX (ordner_link_id, datum),
      CONSTRAINT fk_kb_link FOREIGN KEY (ordner_link_id) REFERENCES ordner_links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}

/** Formatiert/prüft ein Datum (YYYY-MM-DD). Wirft Exception bei Ungültigkeit. */
function _assert_date(string $d): string {
  $ts = strtotime($d);
  if ($ts === false) throw new Exception("Ungültiges Datum: $d");
  return date('Y-m-d', $ts);
}

/** Liefert (valid_from, valid_to) des aktuell offenen Tarifs, sonst null. */
function _current_open_period(mysqli $db, int $linkId): ?array {
  $st=$db->prepare("SELECT id, valid_from, valid_to FROM mieten_tarife WHERE ordner_link_id=? AND valid_to IS NULL ORDER BY valid_from DESC LIMIT 1");
  $st->bind_param('i',$linkId); $st->execute();
  $res=$st->get_result(); $row=$res?$res->fetch_assoc():null;
  if ($res) $res->free();
  $st->close();
  return $row ?: null;
}

/** Prüft, ob ab $from eine Überlappung mit bestehenden Perioden entsteht (außer der offenen, die geschlossen wird). */
function _has_overlap_from(mysqli $db, int $linkId, string $from): bool {
  $st=$db->prepare("
    SELECT 1
    FROM mieten_tarife
    WHERE ordner_link_id=? AND (
      (valid_to IS NULL AND valid_from > ?) OR
      (valid_to IS NOT NULL AND valid_to >= ?)
    )
    LIMIT 1
  ");
  $st->bind_param('iss',$linkId,$from,$from);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}

/**
 * Setzt einen neuen Tarif ab $from.
 * - schließt offenen Tarif (falls vorhanden) bis Vortag
 * - fügt neuen ein
 * - verhindert Überlappung
 */
function set_rent_period(mysqli $db, int $linkId, string $from, ?float $netto, ?float $nk, string $note=''): void {
  $from = _assert_date($from);
  if ($netto === null) $netto = 0.0;
  if ($nk === null)    $nk    = 0.0;

  $db->begin_transaction();
  try {
    // Offenen Tarif schließen (bis Vortag)
    $open = _current_open_period($db, $linkId);
    if ($open) {
      $yesterday = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
      if ($yesterday < $open['valid_from']) {
        // Wenn From == open.valid_from => schließe auf gleichen Tag (sinnvoller Fallback)
        $yesterday = $open['valid_from'];
      }
      $u=$db->prepare("UPDATE mieten_tarife SET valid_to=? WHERE id=?");
      $u->bind_param('si',$yesterday,$open['id']); $u->execute(); $u->close();
    }

    // Keine Überlappung (konservativ): es darf keine andere Periode geben, deren Ende >= from
    // (außer die oben gerade geschlossene).
    // Da wir die offene Periode geschlossen haben, genügt einfacher Check auf >from-Start.
    $st=$db->prepare("SELECT 1 FROM mieten_tarife WHERE ordner_link_id=? AND valid_to IS NOT NULL AND valid_to >= ? LIMIT 1");
    $st->bind_param('is',$linkId,$from); $st->execute();
    $overlap=(bool)$st->get_result()->fetch_row(); $st->close();
    if ($overlap) throw new Exception('Es existiert bereits eine Periode, die über den Start hinausgeht (Überlappung).');

    // Neue Periode einfügen (offen)
    $ins=$db->prepare("INSERT INTO mieten_tarife (ordner_link_id, valid_from, valid_to, mietzins_netto, nk_akonto, bemerkung) VALUES (?,?,?,?,?,?)");
    $null=null;
    $ins->bind_param('issdds', $linkId, $from, $null, $netto, $nk, $note);
    $ins->execute(); $ins->close();

    $db->commit();
  } catch(Throwable $e){
    $db->rollback();
    throw $e;
  }
}

/** Gibt den gültigen Tarif (Miete+NK) für ein Datum zurück (oder null). */
function rent_on(mysqli $db, int $linkId, string $date): ?array {
  $date = _assert_date($date);
  $st=$db->prepare("SELECT mietzins_netto, nk_akonto, valid_from, valid_to
                    FROM mieten_tarife
                    WHERE ordner_link_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?)
                    ORDER BY valid_from DESC
                    LIMIT 1");
  $st->bind_param('iss',$linkId,$date,$date);
  $st->execute(); $res=$st->get_result()->fetch_assoc();
  $st->close();
  return $res ?: null;
}

/** Listet alle Zeiträume (Historie) für eine Verknüpfung, neueste zuerst. */
function rent_history(mysqli $db, int $linkId): array {
  $st=$db->prepare("SELECT id, valid_from, valid_to, mietzins_netto, nk_akonto, bemerkung
                    FROM mieten_tarife
                    WHERE ordner_link_id=?
                    ORDER BY valid_from DESC, id DESC");
  $st->bind_param('i',$linkId); $st->execute();
  $res=$st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
  return $res ?: [];
}

/**
 * Bucht eine einzelne Buchung.
 * Regel: Forderungen (z. B. Miete, NK, Nachzahlung) POSITIV,
 *        Zahlungen/Gutschriften/Reduktionen NEGATIV.
 */
function konto_buchen(mysqli $db, int $projectId, int $linkId, string $datum, string $typ, float $betrag, string $ref='', string $note=''): void {
  $datum = _assert_date($datum);
  $allowed = ['miete','nk','nachzahlung','reduktion','gutschrift','zahlung','kaution','kaution_rueck'];
  if (!in_array($typ, $allowed, true)) throw new Exception("Unbekannter Buchungstyp: $typ");
  $st=$db->prepare("INSERT INTO konto_buchungen (project_id, ordner_link_id, datum, typ, betrag, referenz, bemerkung)
                    VALUES (?,?,?,?,?,?,?)");
  $st->bind_param('iissdss', $projectId, $linkId, $datum, $typ, $betrag, $ref, $note);
  if(!$st->execute()) throw new Exception('Buchung fehlgeschlagen: '.$st->error);
  $st->close();
}

/** Liste Buchungen (optional mit Zeitraumfilter). */
function konto_list(mysqli $db, int $linkId, ?string $from=null, ?string $to=null): array {
  if ($from) $from=_assert_date($from);
  if ($to)   $to=_assert_date($to);

  if ($from && $to) {
    $st=$db->prepare("SELECT id, project_id, ordner_link_id, datum, typ, betrag, referenz, bemerkung
                      FROM konto_buchungen
                      WHERE ordner_link_id=? AND datum BETWEEN ? AND ?
                      ORDER BY datum ASC, id ASC");
    $st->bind_param('iss',$linkId,$from,$to);
  } elseif ($from) {
    $st=$db->prepare("SELECT id, project_id, ordner_link_id, datum, typ, betrag, referenz, bemerkung
                      FROM konto_buchungen
                      WHERE ordner_link_id=? AND datum>=?
                      ORDER BY datum ASC, id ASC");
    $st->bind_param('is',$linkId,$from);
  } elseif ($to) {
    $st=$db->prepare("SELECT id, project_id, ordner_link_id, datum, typ, betrag, referenz, bemerkung
                      FROM konto_buchungen
                      WHERE ordner_link_id=? AND datum<=?
                      ORDER BY datum ASC, id ASC");
    $st->bind_param('is',$linkId,$to);
  } else {
    $st=$db->prepare("SELECT id, project_id, ordner_link_id, datum, typ, betrag, referenz, bemerkung
                      FROM konto_buchungen
                      WHERE ordner_link_id=?
                      ORDER BY datum ASC, id ASC");
    $st->bind_param('i',$linkId);
  }
  $st->execute(); $res=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  return $res ?: [];
}

/** Saldo (Summe) bis inkl. Datum $datum. */
function saldo_bis(mysqli $db, int $linkId, string $datum): float {
  $datum = _assert_date($datum);
  $st=$db->prepare("SELECT COALESCE(SUM(betrag),0) FROM konto_buchungen WHERE ordner_link_id=? AND datum<=?");
  $st->bind_param('is',$linkId,$datum); $st->execute();
  $sum=(float)$st->get_result()->fetch_column(); $st->close();
  return $sum;
}

/**
 * Monatliche Sollstellung:
 * - ermittelt Tarif am 1. des Monats (Miete + NK)
 * - bucht diese (POSITIV) als 'miete' und 'nk'
 * - idempotent: verhindert Doppelbuchungen, indem nach bereits vorhandenen Buchungen
 *   für diesen Monat + Typ gesucht wird
 *
 * Rückgabe: ['miete'=>gebucht_oder_0.0, 'nk'=>gebucht_oder_0.0]
 */
function sollstellung_monat(mysqli $db, int $projectId, int $linkId, int $year, int $month): array {
  if ($month < 1 || $month > 12) throw new Exception('Ungültiger Monat');
  $first = sprintf('%04d-%02d-01', $year, $month);

  // Tarif am Monatsanfang
  $tarif = rent_on($db, $linkId, $first);
  if (!$tarif) return ['miete'=>0.0,'nk'=>0.0];

  $miete = (float)($tarif['mietzins_netto'] ?? 0);
  $nk    = (float)($tarif['nk_akonto'] ?? 0);

  // Idempotenz: gibt es schon eine 'miete' und 'nk' für diesen Monat?
  $exists = function(string $typ) use ($db,$linkId,$year,$month): bool {
    $st=$db->prepare("SELECT 1 FROM konto_buchungen
                      WHERE ordner_link_id=? AND typ=? AND YEAR(datum)=? AND MONTH(datum)=?
                      LIMIT 1");
    $st->bind_param('isii',$linkId,$typ,$year,$month);
    $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close();
    return $ok;
  };

  $doneM = 0.0;
  $doneN = 0.0;

  if ($miete > 0 && !$exists('miete')) {
    konto_buchen($db, $projectId, $linkId, $first, 'miete', $miete, sprintf('SOLL %04d-%02d',$year,$month), 'Monatliche Sollstellung Miete');
    $doneM = $miete;
  }
  if ($nk > 0 && !$exists('nk')) {
    konto_buchen($db, $projectId, $linkId, $first, 'nk', $nk, sprintf('SOLL %04d-%02d',$year,$month), 'Monatliche Sollstellung NK');
    $doneN = $nk;
  }

  return ['miete'=>$doneM,'nk'=>$doneN];
}
