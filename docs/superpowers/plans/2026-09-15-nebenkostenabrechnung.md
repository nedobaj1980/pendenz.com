# Nebenkostenabrechnung und Eigentümer-Steuerübersicht Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (or subagent-driven-development) to implement this plan task-by-task.

**Goal:** Build a local Swiss ancillary-cost statement module with tenant allocation, owner/tax comparison, PDF/CSV output, and traceable source bookings.

**Architecture:** Add a focused PHP module under `tools/nebenkostenabrechnung/` backed by five normalized tables. Read source transactions and active/historical tenancy data without mutating them; persist only classification, allocation, and generated statement records. Render HTML with the existing shared navigation and Dompdf conventions.

**Tech Stack:** PHP 8+, mysqli, MySQL migrations, existing auth/authz/CSRF helpers, Dompdf, existing CSS/navigation patterns.

**Spec:** `docs/superpowers/specs/2026-09-15-nebenkostenabrechnung-design.md`

## Global Constraints

- Original `liegenschafts_konto` bookings remain unchanged.
- Umlagefähigkeit and tax deductibility are separate flags and calculations.
- Missing area, advance, or period data produces a visible warning and no silent estimate.
- Exports and Drive archival require an explicit user action.
- Only admin and superadmin roles may create or finalize statements.

---

### Task 1: Database migration and seed rules

**Files:**
- Create: `database/migrations/008_nebenkostenabrechnung.sql`
- Create: `tools/nebenkostenabrechnung/bootstrap.php`
- Test: `tests/nebenkosten-schema.php`

- [ ] Create the five tables from the approved spec with foreign keys where existing schema permits, decimal amounts, unique `(projekt_id,jahr)` statements, timestamps, and indexes on source booking and unit ids.
- [ ] Seed Swiss default cost classes: heating/hot water, water/sewer, electricity/common areas, waste, lift, cleaning, caretaker, insurance, administration, maintenance, capital improvements, financing, private/non-deductible.
- [ ] Encode defaults so tenant-allocable and tax-deductible flags are independent; mark administration, financing, capital improvements, and general maintenance non-allocable by default.
- [ ] Add bootstrap code that checks/creates schema idempotently for local development, matching existing migration style.
- [ ] Add a CLI schema test that asserts every table and seeded class exists.

### Task 2: Calculation service

**Files:**
- Create: `tools/nebenkostenabrechnung/lib.php`
- Test: `tests/nebenkosten-calculation.php`

- [ ] Implement pure functions for period overlap days, tenant occupancy share, allocation by area/units/persons/consumption/direct unit, and advance reconciliation.
- [ ] Implement `nk_load_bookings(mysqli $db, int $projectId, int $year)` returning normalized source rows with category and sign.
- [ ] Implement `nk_calculate_statement(...)` returning tenant results, owner categories, warnings, and effective-vs-flat tax comparison without database writes.
- [ ] Apply canton/property-mode settings and expose the chosen rate and reason in the result; never present a tax result without a review warning.
- [ ] Test full-period, mid-year move-in/out, vacancy, zero-area warning, credit, debit, and mixed allocation examples.

### Task 3: Admin UI and persistence

**Files:**
- Create: `tools/nebenkostenabrechnung/index.php`
- Modify: `partials/nav.php` or the existing finance navigation include used by `pages/finanzen.php`

- [ ] Require login, admin/superadmin authorization, and CSRF for all POST actions.
- [ ] Add project/year selector, booking classification grid, allocation-rule editor, tenancy/advance preview, and owner/tax settings.
- [ ] Persist classifications, rules, statement header, positions, and distributions in a transaction; never update source bookings.
- [ ] Display blocking warnings for missing data and a clear Swiss-law/tax review notice.
- [ ] Add explicit buttons for calculate, finalize, CSV export, PDF export, and optional Drive archive; keep destructive or external actions out of automatic flows.

### Task 4: PDF and CSV outputs

**Files:**
- Create: `tools/nebenkostenabrechnung/export.php`
- Create: `tools/nebenkostenabrechnung/templates/tenant-statement.html.php`
- Create: `tools/nebenkostenabrechnung/templates/owner-tax-report.html.php`
- Test: `tests/nebenkosten-export.php`

- [ ] Render tenant statements with period, source categories, allocation key, advances, balance, and contact/unit data.
- [ ] Render owner report with allocable/non-allocable split, effective costs, flat comparison, canton, property mode, and review note.
- [ ] Stream semicolon-separated UTF-8 CSV with stable headings and decimal values.
- [ ] Use existing Dompdf dependency and verify generated PDF has non-empty output and expected headings.

### Task 5: Local browser verification and integration

**Files:**
- Modify: `pages/finanzen.php` to add a clearly labeled link if navigation is not shared.
- Test: `tests/nebenkosten-http.cjs` or existing browser test harness.

- [ ] Open the module at `http://localhost/pendenz.com/tools/nebenkostenabrechnung/index.php` as superadmin.
- [ ] Verify project/year selection, classification save, calculation warnings, tenant results, owner comparison, PDF, and CSV.
- [ ] Verify a source booking count and checksum are unchanged before/after classification and calculation.
- [ ] Run PHP lint, schema tests, calculation tests, export tests, and browser smoke test.
- [ ] Commit each completed task with focused messages on `chatgpt/full-audit-2026-09-15`.
