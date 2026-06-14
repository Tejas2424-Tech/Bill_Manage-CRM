# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A multi-branch retail **billing/POS + inventory + CRM** system in **plain PHP (PDO) + MySQL**. No framework, no Composer, no router, no build step. Each `.php` file under `modules/` is simultaneously its own controller AND view: forms POST to the same file that renders them. The frontend is custom CSS with vanilla JS (the README's "Bootstrap 5 / jQuery" claim is outdated).

## Running locally

The README documents an XAMPP/Apache deployment, but local dev uses the PHP built-in server + Homebrew MySQL:

```bash
# MySQL (Homebrew)
brew services start mysql

# One-time DB setup (schema + seed). Schema hardcodes `USE billmanage`.
mysql -u root < database.sql

# Serve the app
php -S localhost:8000          # then open http://localhost:8000
```

- DB connection lives in `config/db.php` (`localhost` / db `billmanage` / user `root` / empty password for local dev).
- Default login: `admin@shop.com` / `admin123`.
- There is **no test suite, linter, or build tooling**. Verify changes by exercising flows in the browser and inspecting the DB (`mysql -u root billmanage`).
- `.htaccess` (security headers, blocks `config/`+`includes/`, denies `.sql`/`.md`) only applies under Apache — it has NO effect under `php -S`.

## Bootstrap chain (read this first)

There is no front controller. The web server maps URLs directly to files on disk, and every page re-bootstraps itself. The top of essentially every module file is:

```php
require_once __DIR__ . '/../../includes/auth.php';      // pulls in config/db.php, starts session, defines guards
require_once __DIR__ . '/../../includes/functions.php'; // shared utilities
requireLogin();                                          // or requireNotCashier() / requireRole(...)
```

- `config/db.php` creates the global `$pdo` (PDO/MySQL, exceptions on, real prepares), defines `BASE_URL` + `CURRENCY` (`₹`), and calls `session_start()`. `$pdo` is used everywhere via `global $pdo;`.
- Views render by `include`-ing `includes/header.php` (opens HTML, pulls in `includes/sidebar.php` + `topbar.php`) and `includes/footer.php`.
- Entry point `index.php` redirects to the dashboard or `modules/auth/login.php` based on `$_SESSION['user_id']`.

## Authentication & authorization model

- Pure PHP sessions; no JWT/tokens. Login (`modules/auth/login.php`) verifies bcrypt via `password_verify`, then `session_regenerate_id(true)`.
- **Authorization is enforced per-page, not centrally.** Guards live in `includes/auth.php`: `requireLogin()`, `requireRole($roles)`, `requireNotCashier()`, plus role predicates `isAdmin()` (superadmin only), `isBranchAdmin()` (superadmin+branch_admin), `isCashier()`. If a file omits its guard, it is unprotected — always add the correct guard at the top of new module files.
- Roles in DB: `superadmin`, `branch_admin`, `staff`, `cashier`.
- Navigation visibility is gated in `includes/sidebar.php` (e.g. `if (!isCashier())`, `if (isAdmin())`).

## Multi-tenancy (branch isolation) — critical invariant

- Branch separation is enforced **only in PHP**, by hand-appending `AND branch_id = ?` to queries for non-admins. The database does NOT enforce it. **Superadmin has `branch_id = NULL` and bypasses these filters.** When writing any query that reads/writes branch-scoped data, you must add the branch filter yourself or you create a cross-branch data leak.
- A product belongs to exactly ONE branch (`products.branch_id`). The "same" item in two branches is two separate `products` rows (this is why stock transfer matches/creates rows by SKU+branch).

## Stock/inventory invariant

`products.quantity` is a live running balance mutated in place (`quantity = quantity ± ?`). EVERY code path that changes it must also append a row to `inventory_log` (`type` = in/out/adjustment, `reference_type` = bill/purchase/transfer, `reference_id`) **inside the same PDO transaction**. `inventory_log` is the append-only ledger of why stock changed; keeping it in lockstep with `quantity` is the backbone of the system. All multi-table writes use `beginTransaction`/`commit`/`rollBack`.

## Where the core logic lives

- **Billing/POS engine:** `modules/billing/create.php` — the canonical example of the transaction pattern (insert `bills` → per line: insert `bill_items`, re-check DB stock, decrement `products.quantity`, insert `inventory_log` 'out'). Cart/totals are built client-side in JS and serialized into a hidden `cart_json` field. Supporting: `invoice.php`, `thermal.php`, `search_product.php` (JSON API).
- **Purchase (supplier stock-in):** `modules/purchase/add.php` — insert `purchases`/`purchase_items`, `quantity = quantity + ?`, `inventory_log` 'in'.
- **Inventory adjustments:** `modules/inventory/` (`stock_in.php`, `adjust.php` sets an absolute physical count).
- **Credit customers:** `modules/customers/collect_payment.php` — outstanding debt lives on the `bills` row (`total_amount − paid_amount`); bills are linked to a customer **by phone string, not a foreign key**.
- **Stock transfer state machine:** `modules/branches/transfer_action.php` — `pending→approved→dispatched→received`/`cancelled`; stock only physically moves on `receive`.
- **Shared utilities:** `includes/functions.php` — `generateCSRFToken`/`verifyCSRFToken`, `generateBillNumber`, `formatCurrency`, `logAudit`, `createNotification`, `sanitize`, flash messages, CSV export.

## Conventions to follow

- CSRF: POST handlers use `verifyCSRFToken($_POST['csrf_token'])` and forms embed `generateCSRFToken()`. Match this on any new POST form (note: the login form currently lacks it).
- Escaping: `sanitize()` is `htmlspecialchars`. Escape on **output** when echoing DB values (some existing reads echo raw — don't copy that).
- Server-side validation: do not trust client-computed totals; re-validate/recompute on the server (existing billing only re-checks stock quantity, not money totals).
- File layout for a new feature: add `modules/<feature>/index.php` (list) + `add.php`/`edit.php`/action endpoints, each starting with the bootstrap+guard block, ending by including header/footer.

## Known fragile areas

- `generateBillNumber()` uses COUNT+1 → race-prone under concurrency.
- Credit ledger keyed on `customer_phone` string → shared/blank phones corrupt balances.
- Stock transfer `receive` deducts source without re-checking current stock → can go negative.
- Dashboard builds its 7-day chart with one query per day (N+1).
