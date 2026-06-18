# Bill-CRM → Garment Shop Management — Validated Implementation Plan

Companion to **[GAP_ANALYSIS.md](GAP_ANALYSIS.md)**. This document validates that analysis against the
live source/DB, then turns it into an executable build plan: migrations, effort, DB architecture, and
file-by-file changes.

**Verification basis:** files in `modules/`, `includes/`, `database.sql` were re-read, and the live
`billmanage` DB was queried (`SHOW COLUMNS`, `@@sql_mode`). `sql_mode` = `...STRICT_TRANS_TABLES...`
(strict — bad ENUM/empty values hard-fail).

---

## 1. Validation Summary

### 1A. Incorrect findings in the first-pass gap analysis (now corrected)

| # | Original claim | Verdict | Evidence |
|---|---|---|---|
| 1 | Billing "cash/online/credit supported" | **Wrong — it's a live bug.** `bills.bill_type` = `enum('cash','credit')`; POS posts `online`/`card`. STRICT mode ⇒ INSERT "Data truncated" ⇒ rollback. Only cash/credit store. | `database.sql:147`; live `SHOW COLUMNS FROM bills LIKE 'bill_type'` → `enum('cash','credit')`; `create.php:258,262`; `SELECT bill_type,COUNT(*)…` → only `cash` |
| 2 | "Product Sales Report — Missing" | **Partial, not Missing.** Embedded top-10 product table exists. | `reports/sales.php:62-71`, rendered `:192` |
| 3 | "Category-wise sales — Missing" | **Exists in the Sales Report;** missing only from the *today* dashboard tile. | `reports/sales.php:74-84` |
| 4 | "Mon–Sun weekly not implemented" | **Monday-start weekly exists.** Missing = Yearly + Financial-Year presets. | `reports/sales.php:25` (`strtotime('monday this week')`), `:19-32` |
| 5 | "No dedicated Cashier dashboard" | **Partial** — a non-admin quick-action block exists (just lacks spec actions; links a blocked Stock-In). | `dashboard/index.php:116-121` |
| 6 | Print footer "absent" | **Nuance** — A4 invoice footer is admin-configurable; thermal is hardcoded. No Marathi/NO-RETURN default. | `invoice.php:185` (`invoice_footer` setting), `thermal.php:125` |

### 1B. Overlooked gaps (added)

- **🐞 bill_type ENUM bug** (above) — blocks the spec's Cash/**Online** requirement; must be fixed first.
- **Undocumented `card` option** in the POS (not in spec, not storable).
- **No `customers` master / no customer FK** — bills store `customer_name`/`customer_phone` as free
  text; `bill_items.product_id` is `ON DELETE SET NULL`. Exchange/Defective must key off
  `bill_items.id` and tolerate null `product_id`.
- **`inventory_log.type` = `enum('in','out','adjustment')`** — defective moves need a convention
  (this plan reuses `'adjustment'` + a `reference_type='defective'` note rather than altering the enum).
- **`generateBillNumber()` = COUNT+1** (`functions.php:52`) — race-prone; the FY change also fixes it
  via a `bill_counters` table.
- **Cash/Online split in Today's Sales depends on the bill_type fix** (hard sequencing constraint).

### 1C. Confirmed-correct first-pass findings (unchanged)

Missing modules **Exchange, Defective Replacement, Birthday, Alteration** (no table, no page — verified
absent in `database.sql` and `modules/`); **no `products.size`**; **no defective stock bucket**; **no
DOB column**; **FY bill numbering absent**; **cancel not admin-only + no reason**; **discount
auto-selection bands absent**; **Draft/Confirmed lifecycle absent** (stock drops on save, `create.php:83`).

---

## 2. Migration Scripts

> ⚠️ Apply to a **copy first**. STRICT mode will hard-fail on any pre-existing bad data. Run in this
> order (FK dependencies). Style matches the existing `-- Migration for existing installs` lines in
> `database.sql`.

### Step 1 — Customer master + link
```sql
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    date_of_birth DATE NULL,
    address TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cust_branch_mobile (branch_id, mobile),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE bills            ADD COLUMN customer_id INT NULL AFTER branch_id,
                             ADD FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL;
ALTER TABLE credit_customers ADD COLUMN customer_id INT NULL AFTER branch_id,
                             ADD FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
                             ADD COLUMN date_of_birth DATE NULL AFTER phone;
```

### Step 2 — Backfill customers from existing data (branch + phone)
```sql
-- One row per (branch, non-empty phone) seen on bills or credit_customers
INSERT INTO customers (branch_id, name, mobile, created_at)
SELECT branch_id, MAX(name) AS name, phone, MIN(created_at)
FROM (
    SELECT branch_id, customer_name AS name, customer_phone AS phone, created_at
      FROM bills           WHERE customer_phone <> '' AND customer_phone IS NOT NULL
    UNION ALL
    SELECT branch_id, name, phone, created_at
      FROM credit_customers WHERE phone <> '' AND phone IS NOT NULL
) src
GROUP BY branch_id, phone;

UPDATE bills b
   JOIN customers c ON c.branch_id = b.branch_id AND c.mobile = b.customer_phone
   SET b.customer_id = c.id
 WHERE b.customer_phone <> '';

UPDATE credit_customers cc
   JOIN customers c ON c.branch_id = cc.branch_id AND c.mobile = cc.phone
   SET cc.customer_id = c.id
 WHERE cc.phone <> '';
```

### Step 3 — Billing fixes (enum bug + state machine + cancel audit)
```sql
ALTER TABLE bills MODIFY COLUMN bill_type ENUM('cash','online','card','credit') NOT NULL DEFAULT 'cash';
ALTER TABLE bills ADD COLUMN bill_state ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed' AFTER status,
                  ADD COLUMN cancel_reason VARCHAR(255) NULL,
                  ADD COLUMN cancelled_by  INT NULL,
                  ADD COLUMN cancelled_at  TIMESTAMP NULL,
                  ADD FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL;
-- Existing rows default to 'confirmed' so historical bills stay valid.
```

### Step 4 — Garment attributes (size + alteration + defective bucket)
```sql
ALTER TABLE products   ADD COLUMN size VARCHAR(20) NULL AFTER name,
                       ADD COLUMN defective_quantity INT NOT NULL DEFAULT 0 AFTER quantity;
ALTER TABLE bill_items ADD COLUMN size VARCHAR(20) NULL AFTER product_name,
                       ADD COLUMN alteration_required TINYINT NOT NULL DEFAULT 0,
                       ADD COLUMN alteration_length VARCHAR(50) NULL,
                       ADD COLUMN alteration_charge DECIMAL(10,2) NOT NULL DEFAULT 0;
```

### Step 5 — Exchange / Defective / Alteration tables
```sql
CREATE TABLE exchanges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    customer_id INT NULL,
    original_bill_id INT NOT NULL,
    original_bill_item_id INT NOT NULL,
    old_product_id INT NULL,
    old_size VARCHAR(20) NULL,
    old_mrp DECIMAL(10,2) NOT NULL,
    old_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    return_value DECIMAL(10,2) NOT NULL,
    new_product_id INT NOT NULL,
    new_size VARCHAR(20) NULL,
    new_mrp DECIMAL(10,2) NOT NULL,
    new_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    new_final_price DECIMAL(10,2) NOT NULL,
    difference_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_mode ENUM('cash','online','card') NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (original_bill_id) REFERENCES bills(id) ON DELETE CASCADE,
    FOREIGN KEY (new_product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE defective_replacements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    customer_id INT NULL,
    original_bill_id INT NOT NULL,
    original_bill_item_id INT NOT NULL,
    defective_product_id INT NULL,
    defect_reason VARCHAR(255) NOT NULL,
    original_price DECIMAL(10,2) NOT NULL,
    original_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    final_sale_price DECIMAL(10,2) NOT NULL,
    replacement_product_id INT NOT NULL,
    replacement_size VARCHAR(20) NULL,
    replacement_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (original_bill_id) REFERENCES bills(id) ON DELETE CASCADE,
    FOREIGN KEY (replacement_product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE alterations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    customer_id INT NULL,
    customer_name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15) NULL,
    bill_id INT NULL,
    product_name VARCHAR(200) NULL,
    alteration_type VARCHAR(100) NOT NULL,
    alteration_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
    alteration_date DATE NOT NULL,
    status ENUM('pending','ready','delivered') NOT NULL DEFAULT 'pending',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

### Step 6 — Financial-Year bill counter (atomic numbering)
```sql
CREATE TABLE bill_counters (
    branch_id INT NOT NULL,
    fy VARCHAR(7) NOT NULL,          -- e.g. '2026-27'
    last_no INT NOT NULL DEFAULT 0,
    PRIMARY KEY (branch_id, fy),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

## 3. Effort Estimates

Assumes one mid-level PHP dev fluent in this codebase. "d" = ideal dev-day.

| # | Work item | Effort (d) | Notes / dependencies |
|---|---|---:|---|
| 0 | **bill_type enum fix** (migration + remove `card` or widen) | 0.5 | Unblocks online billing + Today's-Sales split |
| 0b | **`customers` master** + backfill + billing capture (name/mobile/DOB) | 2.5 | Foundation for #B,#C,#E,#H |
| A | Product `size` (schema + add/edit forms + listing) | 0.5–1 | |
| B | Billing: DOB + size on cart/line + invoice/thermal | 1.5 | needs #0b, #A |
| C | Discount **auto-selection bands** (JS + server clamp) | 1 | |
| D | Alteration **fields in bill** (`bill_items`) + print | 1 | |
| E | **FY bill numbering** via `bill_counters` (atomic) | 1 | replaces COUNT+1 |
| F | Cancel = **admin-only + reason** (+ audit columns) | 0.5 | |
| G | **Print footer** NO-RETURN EN+Marathi (thermal + invoice default) | 0.5 | |
| H | **Customer Search** page (by mobile → bills/exchange/defective) | 2 | needs #0b, exchange/defective for those tabs |
| I | **Defective stock bucket** (`products.defective_quantity`) + Stock Check column + Size filter | 1.5 | |
| J | **Today's Sales** enhancements (bills count, cash/online split, category tile) | 1.5 | needs #0; reuse `sales.php` category query |
| K | **Birthday module** (page + today/custom search) | 1 | needs #0b DOB |
| L | **Alteration management** module + report | 2 | |
| M | **Cashier dashboard** actions + tiles (defective/birthday) + role grants | 2 | |
| N | **Exchange module** end-to-end + Exchange report | 4 | needs #0b, #A, #I-ledger |
| O | **Defective Replacement** module + report | 3.5 | needs #I |
| P | **Bill Draft→Confirmed** lifecycle (defer stock to confirm) | 3 | core POS rewrite; highest risk; do last |
| Q | **Reports**: standalone Discount, Product-Sales, Exchange, Defective, Alteration + Yearly/FY presets | 4 | reuse `sales.php` patterns + CSV export |

**Phase totals:** Phase 1 (0,0b,A,C,E,F,G) ≈ **6.5–7d** · Phase 2 (B,D,H,I,J,K,M,L) ≈ **12.5d** ·
Phase 3 (N,O,P,Q) ≈ **14.5d**. **Grand total ≈ 33–34 dev-days** (~7 calendar weeks solo), excluding QA.

---

## 4. Database Architecture (rationale)

**Why a `customers` master (not DOB-on-bills):** the spec captures Name/Mobile/DOB for *every* billing
customer and Customer Search is by mobile across *all* history. A first-class `customers` row keyed
`UNIQUE(branch_id, mobile)` gives one identity to attach DOB (Birthday), bills, exchanges, defective,
and alterations — without it, DOB would be duplicated per bill and search stays phone-string-fragile.
`bills.customer_name`/`customer_phone` are **kept** (denormalized) so reprints are stable even if a
customer is later edited; `bills.customer_id` is the new join key. `credit_customers` is preserved for
the existing ledger and simply gains a `customer_id` link.

**`bill_state` vs `status` (don't overload):** the existing `bills.status`
(`paid/credit/partial/cancelled`) is a **payment** state used by dashboards, credit, and reports.
Stuffing `draft`/`confirmed` into it would break those aggregations. Add a **separate**
`bill_state enum('draft','confirmed','cancelled')`; existing rows backfill to `confirmed`. Stock moves
only on the `draft → confirmed` transition (Phase 3).

**Defective bucket as a column, not a parallel inventory:** `products.defective_quantity` keeps the
sellable `quantity` clean while tracking non-sellable units. Movements still write `inventory_log`
(`type='adjustment'`, `reference_type='defective'`, `note=...`) to honour the project's
"every quantity change logs a ledger row" invariant — no enum change needed.

**FY numbering:** `bill_counters(branch_id, fy, last_no)` with `UPDATE … last_no = last_no + 1` inside
the bill transaction is atomic per branch+FY, fixing both the calendar-year bug and the COUNT+1 race.
FY string derived as: month ≥ 4 → `Y-(Y+1)`, else `(Y-1)-Y`.

**ER (new/changed edges):**
`customers 1—* bills`, `customers 1—* credit_customers`, `customers 1—* exchanges/defective_replacements/alterations`;
`bills 1—* bill_items`; `exchanges/defective_replacements *—1 original_bill_id (bills)` and
`*—1 original_bill_item_id (bill_items.id)`; new products edge `products.defective_quantity` (attribute).

---

## 5. File-by-File Development Plan

Conventions for **every new page**: start with the bootstrap block (`require auth.php` + `functions.php`),
the correct guard, branch filter `AND branch_id = ?` for non-admins, CSRF on POST
(`verifyCSRFToken`/`generateCSRFToken`), transactional multi-table writes paired with `inventory_log`,
`logAudit(...)`, and end by including `header.php`/`footer.php`. Reuse `formatCurrency`,
`generateBillNumber`, `createNotification`, and the CSV-export helper in `includes/functions.php`.

### Phase 1 — Foundations & quick wins

| Item | CREATE | MODIFY | Key points |
|---|---|---|---|
| #0 enum fix | — | `database.sql` (+ migration) | Widen `bill_type`; drop or keep `card` radio in `create.php:262`. |
| #0b customers master | `modules/customers/customer_lib.php` (find-or-create by branch+mobile) | `database.sql`; `modules/billing/create.php` (capture name/mobile/DOB → `findOrCreateCustomer()` → set `bills.customer_id`); `modules/customers/add.php`/`edit.php` (write to `customers` + DOB field) | Single helper reused by billing, exchange, defective, alteration. |
| #A size | — | `database.sql`; `modules/products/add.php` (form field ~line 169 area), `edit.php`, `index.php` (column), `billing/search_product.php` (return size) | Plain `VARCHAR(20)`. |
| #C discount bands | — | `modules/billing/create.php` (JS discount handler ~`:408-505`; add band map 15-19→15%…50+→50%; server re-clamp near `:46`) | Don't trust client; clamp server-side. |
| #E FY numbering | — | `includes/functions.php:47` `generateBillNumber()`; `modules/billing/create.php:57` | Use `bill_counters`; compute FY; atomic UPDATE inside the existing txn. |
| #F cancel guard | — | `modules/billing/cancel.php` (require admin; require non-empty `reason`; write `cancel_reason/cancelled_by/cancelled_at`; set `bill_state='cancelled'`) | Keep stock-reversal logic. |
| #G footer | — | `modules/billing/thermal.php:125`, `modules/billing/invoice.php` (default `invoice_footer`); add Marathi line, bold | Reuse `invoice_footer` setting; add a `invoice_footer_mr` setting. |

### Phase 2 — Garment modules (medium)

| Item | CREATE | MODIFY | Key points |
|---|---|---|---|
| #B DOB/size in bill | — | `billing/create.php` (DOB input + per-line size), `invoice.php`/`thermal.php` (show size) | After #0b, #A. |
| #D alteration in bill | — | `billing/create.php` (per-line Alteration Y/N, New Length, Charge → `bill_items`), invoice/thermal | Adds to line total. |
| #H Customer Search | `modules/customers/search.php` | `includes/sidebar.php` (nav, cashier-visible) | Mobile → `customers` + `bills` history; Exchange/Defective tabs read new tables. Guard `requireLogin()`. |
| #I defective bucket | — | `database.sql`; `modules/inventory/index.php` (defective qty column + Size filter), `inventory/history.php` | Column + Stock-Check display. |
| #J Today's Sales | `modules/dashboard/` tiles or `modules/billing/today.php` | `modules/dashboard/index.php` | Bills count; `SUM` by `bill_type` (cash/online) — needs #0; category tile reuses `sales.php:74-84` query. |
| #K Birthday | `modules/birthday/index.php` | `includes/sidebar.php` (cashier-visible) | Default today; custom date; query `customers.date_of_birth` (MM-DD match). Guard `requireLogin()`. |
| #L Alteration mgmt | `modules/alteration/index.php`, `add.php`, `report.php` | `includes/sidebar.php` | CRUD on `alterations`; report reuses date-filter + CSV pattern. |
| #M Cashier dashboard | — | `modules/dashboard/index.php:116` (add Exchange/Defective/Customer-Search/Birthday/Today actions; remove blocked Stock-In for cashier); add Defective-stock + Birthday tiles | Also grant cashier access on new pages (use `requireLogin`, not `requireNotCashier`). |

### Phase 3 — Returns logic & lifecycle (major)

| Item | CREATE | MODIFY | Key points |
|---|---|---|---|
| #N Exchange | `modules/exchange/index.php` (mobile→bill→item→two-panel), `modules/exchange/commit.php` (txn), `modules/reports/exchange.php` | `includes/sidebar.php` | Txn: insert `exchanges`; old product `quantity +1`, new `quantity -1`; two `inventory_log` rows; enforce **new value ≥ return value**; auto-copy old discount (editable); collect `difference_paid` + `payment_mode`. Cashier-accessible. |
| #O Defective Replacement | `modules/defective/index.php`, `modules/defective/commit.php`, `modules/reports/defective.php` | `includes/sidebar.php` | Txn: insert `defective_replacements`; defective `defective_quantity +1`, replacement `quantity -1`; `inventory_log` adjustment rows; **sales totals unchanged**; replacement value ≥ original; no admin approval. |
| #P Draft→Confirmed | — | `modules/billing/create.php` (insert as `draft`, **do not** decrement stock); new `modules/billing/confirm.php` (decrement stock + `inventory_log` on confirm); `billing/index.php`/`invoice.php` (state-aware edit/delete) | Highest regression risk — stock now moves on confirm, not save. Full billing regression test. |
| #Q Reports completion | `modules/reports/discount.php`, `modules/reports/product_sales.php` (promote the embedded block to a full page), `modules/reports/exchange.php`, `modules/reports/defective.php`, `modules/reports/alteration.php` | `modules/reports/index.php` (hub links); shared date-filter helper for **Yearly + FY** presets | Reuse `sales.php` query/CSV patterns; add FY/Yearly to the existing `range` switch. |

---

## 6. Verification (per feature, before sign-off)

- **#0 enum:** after migration, create an **Online** bill → saves; `SELECT bill_type` shows `online`.
- **#0b customers:** new bill with new mobile → one `customers` row; repeat mobile → no duplicate
  (`UNIQUE(branch_id,mobile)`); backfill count = distinct branch+phone in old data.
- **#E FY numbering:** two bills in same FY → sequential; first bill after 1 Apr → resets to 1; concurrent
  inserts don't collide (counter is atomic).
- **#N exchange:** lower-value exchange → blocked with the spec message; equal/higher → old qty +1, new
  qty −1, ledger has both rows, totals reconcile.
- **#O defective:** replacement → `defective_quantity +1`, sellable `quantity −1`, **bill totals
  unchanged**; Stock Check shows the defective column.
- **#P lifecycle:** draft bill → stock unchanged; confirm → stock decrements once; cancel confirmed →
  stock restored; no double-decrement.
- **Branch isolation:** every new list/query returns only the user's branch for non-admins; superadmin
  sees all.
- **STRICT mode:** all new ENUM fields (`bill_state`, `alteration.status`, `payment_mode`) reject empty
  values — server-side validate before insert.

> Run each flow in the browser against a **DB copy**, inspect with `mysql -u root billmanage`, and keep
> `inventory_log` in lockstep with every `quantity`/`defective_quantity` change (core project invariant).
