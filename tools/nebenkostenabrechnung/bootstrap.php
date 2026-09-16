<?php
declare(strict_types=1);
function nk_bootstrap(mysqli $db): void {
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }

    // If tables already exist, no need to run migration scripts
    $check = @$db->query("SHOW TABLES LIKE 'nk_kostenarten'");
    $hasNk = ($check && $check->num_rows > 0);
    $checkFg = @$db->query("SHOW TABLES LIKE 'finance_groups'");
    $hasFg = ($checkFg && $checkFg->num_rows > 0);

    if ($hasNk && $hasFg) {
        $bootstrapped = true;
        return;
    }

    $migrations = [
        '008_nebenkostenabrechnung.sql' => [
            "CREATE TABLE IF NOT EXISTS nk_kostenarten (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL UNIQUE,
                tenant_allocable TINYINT(1) NOT NULL DEFAULT 0,
                tax_deductible TINYINT(1) NOT NULL DEFAULT 0,
                default_key ENUM('area','units','persons','consumption','direct') NOT NULL DEFAULT 'area',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS nk_abrechnungen (
                id INT AUTO_INCREMENT PRIMARY KEY,
                projekt_id INT NOT NULL,
                jahr SMALLINT NOT NULL,
                von DATE NOT NULL,
                bis DATE NOT NULL,
                status ENUM('entwurf','berechnet','freigegeben') NOT NULL DEFAULT 'entwurf',
                kanton CHAR(2) NOT NULL DEFAULT 'SG',
                vermoegen ENUM('privat','geschaeft') NOT NULL DEFAULT 'privat',
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_nk_project_year (projekt_id,jahr),
                KEY idx_nk_project (projekt_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS nk_positionen (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                abrechnung_id INT NOT NULL,
                booking_id BIGINT NULL,
                kostenart_id INT NOT NULL,
                betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
                umlageanteil DECIMAL(12,2) NOT NULL DEFAULT 0,
                steuerklasse ENUM('unterhalt','investition','verwaltung','finanzierung','privat','unbekannt') NOT NULL DEFAULT 'unbekannt',
                notiz VARCHAR(500) NULL,
                KEY idx_nk_pos_statement (abrechnung_id),
                KEY idx_nk_pos_booking (booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS nk_verteilungen (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                abrechnung_id INT NOT NULL,
                wohnung_id INT NOT NULL,
                mietverhaeltnis_id INT NULL,
                anteil DECIMAL(12,6) NOT NULL DEFAULT 0,
                umlage DECIMAL(12,2) NOT NULL DEFAULT 0,
                akonto DECIMAL(12,2) NOT NULL DEFAULT 0,
                saldo DECIMAL(12,2) NOT NULL DEFAULT 0,
                KEY idx_nk_dist_statement (abrechnung_id),
                KEY idx_nk_dist_unit (wohnung_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS nk_regeln (
                id INT AUTO_INCREMENT PRIMARY KEY,
                projekt_id INT NOT NULL,
                kostenart_id INT NULL,
                verteilerschluessel ENUM('area','units','persons','consumption','direct') NOT NULL DEFAULT 'area',
                kanton CHAR(2) NOT NULL DEFAULT 'SG',
                vermoegen ENUM('privat','geschaeft') NOT NULL DEFAULT 'privat',
                pauschal_jung DECIMAL(5,2) NOT NULL DEFAULT 10.00,
                pauschal_alt DECIMAL(5,2) NOT NULL DEFAULT 20.00,
                gebaeudealter INT NULL,
                UNIQUE KEY uq_nk_rule (projekt_id,kostenart_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "INSERT IGNORE INTO nk_kostenarten (name,tenant_allocable,tax_deductible,default_key) VALUES
             ('Heizung und Warmwasser',1,0,'consumption'),('Wasser und Abwasser',1,0,'consumption'),
             ('Allgemeinstrom',1,0,'units'),('Kehricht und Abgaben',1,0,'units'),
             ('Lift',1,0,'units'),('Reinigung',1,0,'units'),('Hauswartung',1,0,'area'),
             ('Versicherungen',1,1,'area'),('Verwaltung',0,1,'area'),('Unterhalt',0,1,'area'),
             ('Wertvermehrende Investition',0,0,'direct'),('Finanzierung',0,0,'direct'),
             ('Privat / nicht abzugsfähig',0,0,'direct')"
        ],
        '009_finanzgruppen.sql' => [
            "CREATE TABLE IF NOT EXISTS finance_groups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                projekt_id INT NULL,
                tool ENUM('nebenkosten','liegenschaft','beide') NOT NULL DEFAULT 'beide',
                parent_id INT NULL,
                name VARCHAR(160) NOT NULL,
                color CHAR(7) NOT NULL DEFAULT '#64748b',
                sort_order INT NOT NULL DEFAULT 100,
                tenant_allocable TINYINT(1) NOT NULL DEFAULT 0,
                tax_relevant TINYINT(1) NOT NULL DEFAULT 0,
                tax_class ENUM('unterhalt','investition','verwaltung','finanzierung','privat','unbekannt') NOT NULL DEFAULT 'unbekannt',
                distribution_key ENUM('area','units','persons','consumption','direct') NOT NULL DEFAULT 'area',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_finance_groups_project (projekt_id), KEY idx_finance_groups_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ]
    ];

    foreach ($migrations as $filename => $statements) {
        $filePath = __DIR__ . '/../../database/migrations/' . $filename;
        if (file_exists($filePath)) {
            $sql = @file_get_contents($filePath);
            if ($sql) {
                foreach (preg_split('/;\s*(?:\r?\n|$)/', (string)$sql) as $st) {
                    $st = trim($st);
                    if ($st !== '') {
                        try {
                            @$db->query($st);
                        } catch (\Throwable $e) {}
                    }
                }
                continue;
            }
        }

        // Inline fallback if migration file is not present on the server
        foreach ($statements as $st) {
            try {
                @$db->query($st);
            } catch (\Throwable $e) {}
        }
    }

    $bootstrapped = true;
}
