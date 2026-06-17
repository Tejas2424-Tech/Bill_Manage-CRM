# Gap Analysis — Bill-CRM vs. Garment Shop Management Requirements

**Project:** Bill-CRM (multi-branch PHP/PDO + MySQL billing-POS-CRM, no framework)
**Requirements source:** "Garment Shop Management Web Application — Final Requirement List" (12 modules)
**Method:** Every conclusion verified against actual code (`modules/`, `includes/`) and `database.sql`. No feature assumed to exist without a file/table citation.

> Stack note: There are **no** `ajax/`, `api/`, or `migrations/` directories. Each `modules/<x>/*.php` is both controller and view. Single schema file: `database.sql` (18 tables).

---

## 1. Feature Coverage Summary

Coverage scored as `(Exists + 0.5 × Partial) / 12 modules`.

| Bucket | Count | Modules |
|---|---|---|
| ✅ Exists (full) | **0** | — (none fully meet the garment spec as written) |
| ⚠️ Partial | **8** | Billing, Customer Search, Product Master, Stock Management, Stock Check, Today's Sales, Reports, Dashboard |
| ❌ Missing | **4** | Exchange, Defective Replacement, Birthday, Alteration Management |

**Overall coverage ≈ (0 + 0.5×8) / 12 = 33%.**

Interpretation: the *foundation* (billing engine, products, stock-in, reports framework, role system) is solid and reusable, but **no module fully satisfies the garment-specific requirements**, and 4 modules are entirely absent. The largest single gap theme is **garment retail returns logic** (Exchange + Defective) and **garment attributes** (Size, DOB-driven birthday marketing, Alterations).

---

## 2. Module Comparison Table

| Requirement Module | Exists | Partial | Missing | Evidence (files) | Notes |
|---|:--:|:--:|:--:|---|---|
| Billing | | ⚠️ | | `modules/billing/create.php`, `cancel.php`, `invoice.php`, `thermal.php`; tables `bills`, `bill_items` | Core POS works (cash/online/credit at `create.php:258`). Missing: DOB, Size, discount auto-bands, alteration fields, Draft/Confirmed lifecycle, FY bill numbering, admin-only cancel + reason, NO-RETURN print footer. |
| Customer Search | | ⚠️ | | `modules/customers/index.php:122` | Name/phone filter over `credit_customers` only. Spec wants search-by-mobile → bills + purchase + exchange + defective history. |
| Exchange | | | ❌ | — | No table, no page. Only whole-bill cancel exists (`cancel.php`). |
| Defective Replacement | | | ❌ | — | No table, no defective-stock bucket, no page. |
| Product Master | | ⚠️ | | `modules/products/add.php`, `edit.php`; table `products` | Add/Edit/Category/Prices/Qty present. **No `size` column.** |
| Stock Management | | ⚠️ | | `modules/inventory/stock_in.php`, `modules/purchase/add.php`; tables `inventory_log`, `purchases` | Increase/update stock works. **No Defective Stock type/bucket.** |
| Stock Check | | ⚠️ | | `modules/inventory/index.php`, `adjust.php` | Search by name; available qty + price shown. Missing: Size filter, Defective-quantity column. |
| Today's Sales | | ⚠️ | | `modules/dashboard/index.php:29`, `modules/reports/sales.php` | Total sales + profit present. Missing: total bills count, cash-vs-online split, category-wise sales. |
| Birthday | | | ❌ | — | No DOB column anywhere; nothing to list. |
| Alteration Management | | | ❌ | — | No table, no page, no reports. |
| Reports | | ⚠️ | | `modules/reports/` (sales, profit, inventory, credit, tax, stock_movement, vendor_purchases, cashier_performance, expenses_report) | Missing: Discount, Exchange, Defective, Alteration, Product-Sales reports; FY filter & Mon–Sun weekly logic. |
| Dashboard | | ⚠️ | | `modules/dashboard/index.php` | Admin: today sales/profit + branch perf. Missing: Defective Stock tile, Birthday Customers, category context; no dedicated Cashier dashboard with Exchange/Defective/Birthday/Customer-Search actions. |

---

## 3. Existing Features (file paths + tables + related pages)

| Feature | Files | DB tables | Related pages/APIs |
|---|---|---|---|
| POS / Billing engine | `modules/billing/create.php` | `bills`, `bill_items`, `inventory_log` | `invoice.php`, `thermal.php`, `search_product.php` (JSON), `history.php`, `cancel.php` |
| Bill numbering | `includes/functions.php:47` `generateBillNumber()` | `bills` | — (used by create.php) |
| Payment modes | `modules/billing/create.php:258` | `bills.bill_type` (cash/online/credit) | — |
| Product Master | `modules/products/add.php`, `edit.php`, `delete.php`, `index.php` | `products`, `brands`, `categories` | `barcode.php`, `bulk_barcode.php`, `brands.php`, `categories.php` |
| Stock-in / Purchase | `modules/inventory/stock_in.php`, `modules/purchase/add.php` | `inventory_log`, `purchases`, `purchase_items` | `inventory/history.php`, `purchase/view.php` |
| Stock adjustment | `modules/inventory/adjust.php` | `products.quantity`, `inventory_log` | `inventory/alerts.php` |
| Customer (credit) | `modules/customers/index.php`, `add.php`, `edit.php`, `ledger.php` | `credit_customers`, `credit_payments` | `collect_payment.php`, `get.php` |
| Reports | `modules/reports/*.php` (10 reports) | `bills`, `bill_items`, `products`, `inventory_log`, `expenses` | `reports/index.php` hub |
| Dashboard | `modules/dashboard/index.php` | `bills`, `bill_items`, `products` | — |
| Roles / Auth | `includes/auth.php` | `users` | guards: `requireLogin`, `requireRole`, `requireNotCashier`, `isAdmin`, `isCashier` |

---

## 4. Partial Features — What Exists / What's Missing / Effort

### 4.1 Billing  — *Effort: L (largest single workstream)*
- **Exists:** cart-based POS, cash/online/credit, discount amount/percent, GST, stock decrement + `inventory_log`, invoice + thermal print, transactional integrity.
- **Missing:**
  - Customer **Date of Birth** capture (needed for Birthday module).
  - **Size** per line item.
  - **Discount auto-selection bands** (15–19→15% … 50+→50%) — no banded logic in the create.php JS.
  - **Alteration in bill**: Required (Y/N), New Length, Alteration Charge.
  - **Bill lifecycle Draft → Confirmed → Cancelled**: current `bills.status` enum = paid/credit/partial/cancelled, and **stock reduces immediately** on insert (`create.php:83`), not "only after confirmation."
  - **Financial-Year bill numbering**: `functions.php:49` uses calendar `date('Y')`; spec needs FY (1 Apr–31 Mar) restarting at 1.
  - **Cancel = admin-only + reason required**: today cashiers can cancel same-day bills (`cancel.php:37`) and no reason is stored.
  - **Print footer** "NO RETURN • NO EXCHANGE • NO REFUND" (English + Marathi) — absent from `thermal.php`/`invoice.php`.
- **Effort:** ~5–8 dev-days (schema changes to `bills`/`bill_items`, UI fields, FY numbering, status state machine, deferred stock).

### 4.2 Customer Search — *Effort: M*
- **Exists:** `customers/index.php:122` JS filter by name/phone over `credit_customers`.
- **Missing:** dedicated search-by-mobile screen returning customer details + **previous bills** (`bills` by `customer_phone`) + purchase history + **exchange/defective history** (depends on those new modules).
- **Effort:** ~2 days (1 page joining `bills`/`bill_items`; exchange/defective sections gated on §5 modules).

### 4.3 Product Master — *Effort: S*
- **Exists:** Add/Edit, category, purchase/selling price, quantity, image, barcode.
- **Missing:** **Size** field (`products.size`), plus Size in add/edit forms and listings.
- **Effort:** ~0.5 day.

### 4.4 Stock Management — *Effort: M*
- **Exists:** increase/update stock, purchase stock-in, `inventory_log` ledger.
- **Missing:** **Defective Stock** as a separate non-sellable bucket (`products.defective_quantity` or `defective_stock` table) and movement into it from the Defective module.
- **Effort:** ~1–2 days (schema + ledger integration).

### 4.5 Stock Check — *Effort: S*
- **Exists:** `inventory/index.php` list with available qty + selling price; `adjust.php` physical count.
- **Missing:** search by **Size**, **Defective Quantity** column.
- **Effort:** ~0.5–1 day (depends on §4.4).

### 4.6 Today's Sales — *Effort: M*
- **Exists:** `dashboard/index.php:29` total sales + today profit.
- **Missing:** **Total Bills** count, **Cash vs Online collection split**, **category-wise sales** (Shirt/T-Shirt/Pant/Jeans/Others).
- **Effort:** ~1–2 days (category requires reliable product→category mapping).

### 4.7 Reports — *Effort: M–L*
- **Exists:** Sales, Profit, Inventory/Stock, Credit, Tax, Stock-movement, Vendor-purchases, Cashier-performance, Expenses.
- **Missing required:** **Discount Report**, **Exchange Report**, **Defective Report**, **Alteration Report**, **Product Sales Report**; plus **Financial-Year filter** and **Mon–Sun weekly** date logic across reports.
- **Effort:** ~3–5 days (exchange/defective/alteration reports depend on §5).

### 4.8 Dashboard — *Effort: M*
- **Exists:** Admin tiles (today sales/profit), branch performance.
- **Missing:** **Defective Stock** tile, **Birthday Customers**, category context; a dedicated **Cashier Dashboard** with New Bill / Exchange / Defective / Customer Search / Today's Sales / Birthday actions.
- **Effort:** ~2 days.

---

## 5. Missing Features — Required Tables / Pages / Reports / Dependencies

### 5.1 Exchange Module ❌
- **Tables:** `exchanges` (id, branch_id, original_bill_id, original_bill_item_id, old_product_id, new_product_id, old_final_price, new_final_price, old_discount_pct, new_discount_pct, return_value, difference_paid, payment_mode, created_by, created_at).
- **Pages:** `modules/exchange/index.php` (mobile lookup → bill list → product select → two-panel old/new), action endpoint to commit exchange.
- **Reports:** Exchange Report (daily/weekly/monthly/yearly/custom).
- **Stock movement:** old product +1, new product −1 (both via `inventory_log`).
- **Rules:** same/different product & category allowed; new value ≥ return value (lower not allowed, no refund/cashback); old discount auto-copied, editable.
- **Dependencies:** `bills`/`bill_items` (read), `products` (stock), `inventory_log` (ledger), branch isolation.

### 5.2 Defective Replacement Module ❌
- **Tables:** `defective_replacements` (id, branch_id, original_bill_id, original_bill_item_id, defective_product_id, replacement_product_id, defect_reason, original_price, original_discount, final_sale_price, replacement_discount_pct, created_by, created_at) + **defective stock bucket** (`products.defective_quantity` or `defective_stock`).
- **Pages:** `modules/defective/index.php` (mobile → bill → product → replacement, two-panel), commit endpoint.
- **Reports:** Defective Report (daily/weekly/monthly/yearly/custom).
- **Stock movement:** defective product → **defective stock +1** (non-sellable); replacement → **normal stock −1**. **Sales amount & quantity unchanged.**
- **Rules:** same/different product/category allowed; no admin approval; replacement value ≥ original; original discount auto-copied, editable.
- **Dependencies:** §4.4 defective bucket, `bills`/`bill_items`, `inventory_log`.

### 5.3 Birthday Module ❌
- **Schema:** customer **`date_of_birth`** column (extend `credit_customers`, or a new `customers` master if billing should store every customer's DOB — see Risk §8).
- **Pages:** `modules/birthday/index.php` — default = today, with custom-date search; output Name, Mobile, DOB.
- **Dependencies:** DOB must be captured in Billing (§4.1) and/or customer master.
- **Reuse:** `createNotification()` in `includes/functions.php` could later drive birthday reminders.

### 5.4 Alteration Management Module ❌
- **Tables:** `alterations` (id, branch_id, customer_name, mobile, bill_id, product_name, alteration_type, alteration_charge, alteration_date, created_by, created_at).
- **Pages:** `modules/alteration/index.php` + add/list.
- **Reports:** Alteration Report (daily/weekly/monthly/yearly).
- **Dependencies:** optional link to `bills`; alteration charge also captured inline in Billing (§4.1).

---

## 6. Reuse Assessment

| Area | Reusable asset | How it serves the garment spec |
|---|---|---|
| Customer Management | `credit_customers` + `customers/*` | Extend with `date_of_birth`; reuse for Birthday + Customer Search. Note: billing currently stores customer as free text on `bills` (no FK) — a unified customer master may be needed (Risk §8). |
| Inventory | `inventory_log` ledger + transaction pattern (`create.php`, `purchase/add.php`) | Directly reusable for Exchange/Defective stock movements and the defective bucket. |
| Sales | `bills`/`bill_items` + billing engine | Base for Today's Sales splits, Product Sales / Discount reports, Exchange/Defective bill lookups. |
| Purchase | `modules/purchase/*` | Stock-in already integrated with ledger; no garment-specific change needed beyond Size. |
| Reports | `modules/reports/index.php` hub + CSV export in `functions.php` | New reports (Discount/Exchange/Defective/Alteration/Product-Sales) slot into existing pattern; add FY + Mon–Sun helpers. |
| User Roles | `includes/auth.php` (superadmin/branch_admin/staff/cashier) | Maps to spec Admin/Cashier. **Action needed:** several target pages use `requireNotCashier()` — Exchange, Defective, Stock Check, Today's Sales, Birthday must be granted to **cashier** per spec. |

---

## 7. Implementation Roadmap

### Phase 1 — Quick Wins (low effort, high clarity)
1. **Product `size`** field + forms/listing (§4.3).
2. **FY-based bill numbering** in `generateBillNumber()` (§4.1).
3. **Cancel = admin-only + reason** + `bills.cancel_reason` (§4.1).
4. **Print footer** (English + Marathi NO RETURN/EXCHANGE/REFUND) in `thermal.php`/`invoice.php`.
5. **Discount auto-selection bands** in billing JS (§4.1).
6. **Customer DOB** column + capture in billing (unblocks Birthday).

### Phase 2 — Medium Effort
7. **Birthday Module** (§5.3) — depends on #6.
8. **Today's Sales** enhancements: total bills, cash/online split, category-wise (§4.6).
9. **Customer Search** dedicated screen with bill/purchase history (§4.2).
10. **Defective Stock bucket** in Stock Management + Stock Check column (§4.4, §4.5).
11. **Cashier Dashboard** + Admin dashboard tiles (§4.8) + cashier role grants (§6).
12. **Alteration Management** module + report (§5.4).

### Phase 3 — Major Features
13. **Exchange Module** end-to-end + Exchange Report (§5.1).
14. **Defective Replacement Module** end-to-end + Defective Report (§5.2) — depends on #10.
15. **Bill Draft → Confirmed lifecycle** with deferred stock reduction (§4.1) — touches core POS; do last, with regression testing.
16. **Reports completion**: Discount, Product-Sales, Exchange, Defective, Alteration + FY filter + Mon–Sun weekly logic (§4.7).

---

## 8. Missing DB Changes / Missing UI Pages / Risks / Recommended Order

### Missing DB changes
- **New tables:** `exchanges`, `defective_replacements`, `alterations`.
- **New defective bucket:** `products.defective_quantity` (or `defective_stock` table).
- **Column additions:** `credit_customers.date_of_birth` (or new `customers` master); `products.size`; `bill_items.size`; `bills.cancel_reason`; alteration fields on `bills` (or via `alterations`); status enum `bills.status` += `draft`, `confirmed`.
- **Logic (not schema):** FY-aware bill numbering; Mon–Sun weekly + FY date filters.

### Missing UI pages
- `modules/exchange/index.php` (+ commit endpoint)
- `modules/defective/index.php` (+ commit endpoint)
- `modules/birthday/index.php`
- `modules/alteration/index.php` (+ add)
- Dedicated **Customer Search** page
- **Cashier Dashboard** variant
- New report pages: discount, product-sales, exchange, defective, alteration

### Risk areas
- **Customer identity:** bills store customer as free-text (`customer_name`/`customer_phone`, no FK). DOB, exchange/defective history, and Customer Search all assume a reliable customer key. Introducing a `customers` master (or enforcing phone) is a cross-cutting decision — affects billing, search, birthday, exchange.
- **Stock semantics change:** moving from "reduce on save" to "reduce on confirm" (Draft/Confirmed) rewrites the core billing transaction — highest regression risk; isolate and test last.
- **Defective stock invariant:** must keep `inventory_log` in lockstep when moving units to a non-sellable bucket (per project stock invariant).
- **Branch isolation:** every new table/query needs the manual `AND branch_id = ?` filter (DB does not enforce it) — easy to leak cross-branch data.
- **Role grants:** cashier currently blocked from several pages by `requireNotCashier()`; spec requires cashier access to Exchange/Defective/Stock Check/Today's Sales/Birthday.
- **STRICT_TRANS_TABLES:** ENUM/empty-value inserts fail hard — validate new ENUM fields (size, status, alteration_type) server-side.

### Recommended development order
Phase 1 (1–6) → DOB/customer-master decision → Phase 2 (7–12) → Phase 3 Exchange (13) → Defective + defective bucket (10→14) → Bill lifecycle (15) → Reports completion (16).

---

*Generated from source inspection on the `dev` branch. All "Exists/Partial" rows cite files opened during analysis; all "Missing" rows verified absent in both `database.sql` and `modules/`.*
