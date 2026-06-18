<?php
/**
 * Customer master helpers (unified customer identity).
 *
 * Reused by Billing (and later Exchange / Defective / Alteration). Designed to be
 * called INSIDE the caller's existing PDO transaction.
 */

/**
 * Find an existing customer by (branch_id, mobile) or create one.
 *
 * - Returns null when $mobile is empty (e.g. walk-in) → no customer row created.
 * - When found, fills in name / date_of_birth only if they were previously blank
 *   (never overwrites existing data).
 *
 * @return int|null  customers.id, or null for no-mobile/walk-in
 */
function findOrCreateCustomer(PDO $pdo, int $branch_id, string $name, string $mobile, ?string $dob = null, ?int $created_by = null): ?int
{
    $mobile = trim($mobile);
    if ($mobile === '') {
        return null;
    }
    $name = trim($name);
    $dob  = ($dob !== null && trim($dob) !== '') ? trim($dob) : null;

    $stmt = $pdo->prepare("SELECT id FROM customers WHERE branch_id = ? AND mobile = ? LIMIT 1");
    $stmt->execute([$branch_id, $mobile]);
    $id = $stmt->fetchColumn();

    if ($id) {
        // Backfill name/DOB only when currently empty — don't clobber existing values.
        $upd = $pdo->prepare(
            "UPDATE customers
                SET name = CASE WHEN (name IS NULL OR name = '' OR name = 'Customer') AND ? <> '' THEN ? ELSE name END,
                    date_of_birth = CASE WHEN date_of_birth IS NULL AND ? IS NOT NULL THEN ? ELSE date_of_birth END
              WHERE id = ?"
        );
        $upd->execute([$name, $name, $dob, $dob, (int)$id]);
        return (int)$id;
    }

    $ins = $pdo->prepare(
        "INSERT INTO customers (branch_id, name, mobile, date_of_birth, created_by)
         VALUES (?, ?, ?, ?, ?)"
    );
    $ins->execute([$branch_id, ($name !== '' ? $name : 'Customer'), $mobile, $dob, $created_by]);
    return (int)$pdo->lastInsertId();
}
