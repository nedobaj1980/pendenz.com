CREATE TABLE IF NOT EXISTS finance_groups (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
