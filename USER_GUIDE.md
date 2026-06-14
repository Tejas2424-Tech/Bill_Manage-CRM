# BillManage — End-User Training Guide

**A complete beginner's guide to running a shop on BillManage.**

This guide assumes you have never seen the software. We will run a real example throughout: a mobile shop called **"iShop"** that sells **iPhones and accessories**. By the end you will be able to set up a branch, add stock, sell to customers, handle credit (udhaar), move stock between shops, and read your profit reports.

> 💡 No technical knowledge needed. Just follow the menu clicks and type the sample values.

---

# PART A — Understanding the Software

## 1. What is BillManage used for?

BillManage is an all-in-one **shop management system**. It does four big jobs:

| Job | What it means for your shop |
|-----|------------------------------|
| **Billing / POS** | Make bills for customers at the counter, calculate GST, give change, print invoices. |
| **Inventory** | Always know how many of each item you have in stock, and get alerts when stock is low. |
| **Multi-Branch** | Run several shops from one login. Each branch has its own stock, staff, and sales. |
| **Credit / CRM** | Track customers who buy on "udhaar" (credit), and record their repayments over time. |

In short: **you buy stock → it sells at the counter → the system tracks the money and the stock automatically.**

## 2. Who uses it? (User roles)

There are four types of users. The menu each person sees changes based on their role.

| Role | Best described as | Can do |
|------|-------------------|--------|
| **Superadmin** | The business owner / head office | Everything, across **all branches**. Only Superadmin can create branches, create users, and run stock transfers. |
| **Branch Admin** | A single shop's manager | Full control of **their own branch**: products, purchases, billing, customers, reports. |
| **Staff** | A senior shop employee | Day-to-day operations for their branch (products, purchases, billing), with a few admin actions restricted. |
| **Cashier** | The billing counter person | **Billing and viewing only.** Cannot see Purchase, Vendors, Expenses, or Reports. |

**Who should you log in as for this guide?** The **Superadmin** (`admin@shop.com`), because only Superadmin can do every step including creating branches.

## 3. What does each menu do?

The menu is the dark sidebar on the left. Here is every item:

| Menu | What it does |
|------|--------------|
| **Dashboard** | Home screen. Shows today's sales, profit, low-stock alerts, and charts. |
| **Billing / POS** | The sales counter. Make a new bill here. |
| **Reprint Sale** | Find a past bill and print/view it again. |
| **Products** | Your catalogue. Add/edit items, prices, SKU, barcode. (Also Categories & Brands.) |
| **Inventory** | Stock levels, manual stock-in/out, low-stock and dead-stock alerts, stock history. |
| **Purchase** | Record stock bought from suppliers. **This is how stock goes UP.** |
| **Vendors** | Your suppliers' contact list. |
| **Credit Customers** | "Udhaar" customers, their ledgers, and payment collection. |
| **Expenses** | Record shop running costs (rent, electricity, etc.). |
| **Reports** | Sales, profit, inventory, tax, credit and other business reports. |
| **Branches** *(Superadmin)* | Create and manage your shop locations. |
| **Stock Transfer** *(Superadmin)* | Move stock from one branch to another. |
| **Notifications** | System alerts (e.g. a new transfer request). |
| **Settings** | Shop name, system options, audit log. |
| **Users** *(Superadmin)* | Create staff/cashier logins. |

> 🔒 Cashiers do **not** see Purchase, Vendors, Expenses, or Reports. Branches, Stock Transfer, and Users are **Superadmin only**. This is by design.

---

# PART B — Hands-On Workflow (Start from a Fresh System)

Follow these 15 steps in order. We are building up the iShop mobile business from scratch.

---

## Step 1 — Log In

**Menu path:** Open the app in your browser → `http://localhost:8000`

**What to enter:**
- Email: `admin@shop.com`
- Password: `admin123`
- Click **Login**.

**Which role to use:** Log in as the **Superadmin** account above. Superadmin can perform every step in this guide (a Cashier or Staff login would have menus hidden).

**What happens after:** You land on the **Dashboard**. The sidebar appears on the left. Because you are Superadmin, you can see **all** menu items including Branches, Stock Transfer, and Users.

**Records created/updated:** A login record is written to the activity/audit log. Nothing else changes.

**Common mistakes to avoid:**
- Wrong password → you stay on the login page. Use exactly `admin123`.
- If you logged in as a Cashier, you would not see Purchase or Reports — that's normal, not a bug.

---

## Step 2 — Create a Branch

**Menu path:** Sidebar → **Branches** → **Add Branch** button.

**Why branches are needed:** A "branch" is one physical shop. **Every product, sale, and customer belongs to a branch.** Before you can add products or make a sale, at least one branch must exist. If you run two shops (say Mumbai and Pune), each keeps its **own separate stock, staff, and sales reports** — that's the whole point of branches.

**What to enter (our example):**
| Field | Value | Notes |
|-------|-------|-------|
| Branch Name * | `iShop Mumbai` | The shop's display name. |
| Branch Code * | `MUM01` | 2–10 letters/numbers, **must be unique**. Auto-uppercases. Used inside bill numbers. |
| Manager Name | `Amit Patel` | Optional. |
| Contact Number | `9820011111` | Optional. |
| Address | `Shop 5, Linking Road, Bandra` | Optional. |
| Status | `Active` | Keep Active so it can be used. |

Click **Create Branch**.

**What happens after:** Green message *"Branch created successfully"* and you return to the Branches list, where **iShop Mumbai (MUM01)** now appears.

**Records created/updated:** A new row is added to the **Branches** list.

**Common mistakes to avoid:**
- **Duplicate code:** If `MUM01` already exists, you'll get an error — pick a different code.
- Code must be 2–10 characters, letters/numbers only (no spaces or symbols).

> ℹ️ As Superadmin you are **not tied to any one branch**. When you add products or bills, you'll be asked which branch they belong to. A Branch Admin/Cashier is locked to their own branch automatically.

---

## Step 3 — Create Categories and Brands

These group your products so they're easy to find and report on. **Categories and brands are shared across all branches.**

### Create Categories
**Menu path:** Sidebar → **Products** → open **Categories** (link on the Products page / "+ Add new category").

**What to enter:** In the "Add New Category" box on the left:
- Category Name: `Smartphones` → click **Save Category**.
- Repeat: Category Name: `Accessories` → **Save Category**.

(Description is optional, e.g. "Phones and handsets".)

### Create Brands
**Menu path:** Sidebar → **Products** → open **Brands** ("+ Add new brand").

**What to enter:**
- Brand Name: `Apple` → Save.
- Brand Name: `Anker` → Save.

**What happens after:** Each saved item appears instantly in the list on the right. A *"Category added"* / *"Brand added"* message shows.

**Records created/updated:** New rows in the **Categories** and **Brands** lists.

**Common mistakes to avoid:**
- These are optional on a product, but creating them now means tidy dropdowns later.
- Don't create duplicates ("Apple" and "apple") — keep naming consistent.

---

## Step 4 — Add Products

**Menu path:** Sidebar → **Products** → **Add Product** button.

This creates the item in your catalogue. Let's add our first iPhone. **Every field explained:**

| Field | Sample value | What it means |
|-------|--------------|---------------|
| **Target Branch** * | `iShop Mumbai` | (Superadmin only.) Which shop this product belongs to. **Locked after saving** — you can't move a product to another branch later. |
| **Product Name** * | `iPhone 15 128GB` | What appears on bills and search. |
| **SKU** | click **Auto** → e.g. `IPH1234` | Your internal item code. "Auto" generates one from the name. |
| **Barcode** | click **Gen** → e.g. `2000…` | The scannable code. "Gen" creates one and shows a barcode preview. You can also type a real barcode from the box. |
| **Category** | `Smartphones` | From Step 3. |
| **Brand** | `Apple` | From Step 3. |
| **Unit** | `pcs` | How it's counted (pcs/kg/L/dozen/box/strip/other). Phones = pcs. |
| **Purchase Price (₹)** * | `65000` | What **you** pay for it (cost). |
| **Selling Price (₹)** * | `79900` | What the **customer** pays. The screen shows a live **Profit Margin** as you type. |
| **Current Stock** | `0` | How many you have **right now**. Start at **0** — we'll add stock properly via a Purchase in Step 6. |
| **Alert Quantity** | `3` | When stock falls to/below this, you get a low-stock alert. |
| **Dead Stock Days** | `90` | If unsold for this many days, it's flagged as "dead stock". |
| **Status** | `Active` | Only Active products appear at the billing counter. |
| **Product Image** | (optional) | JPG/PNG/GIF/WEBP, max 2 MB. |

Click **Save Product**.

**Now add a second product** (an accessory) the same way:
- Name `USB-C Cable`, Branch `iShop Mumbai`, Category `Accessories`, Brand `Anker`, Unit `pcs`, Purchase `300`, Selling `699`, Current Stock `0`, Alert `5`.

**What happens after:** *"Product added successfully"* and you return to the Products list showing both items with stock **0**.

**Records created/updated:** New rows in the **Products** list (one per item), each tied to iShop Mumbai.

**Common mistakes to avoid:**
- **Selling price lower than purchase price** → negative margin (the margin turns red). Double-check your prices.
- Forgetting to pick the **Target Branch** (Superadmin must choose one).
- **Don't type the opening stock here as a habit** — use Purchase (Step 6) so the stock increase is properly recorded in the inventory history. Current Stock here is really meant for a one-time opening balance.

---

## Step 5 — Add a Vendor (Supplier)

**Menu path:** Sidebar → **Vendors** → **Add Vendor** button.

A vendor is whoever you buy stock from. You need one before recording a purchase.

**What to enter:**
| Field | Value |
|-------|-------|
| Vendor Name * | `Apple Distributor India` |
| Contact Number * | `9000012345` |
| Address | `Andheri MIDC, Mumbai` |
| GST Number | `27ABCDE1234F1Z5` (optional) |
| Preferred Payment Type | `Bank Transfer` (cash / UPI / bank) |
| Notes | `Authorised iPhone supplier` (optional) |
| Status | `Active` |

Click **Save Vendor**.

**What happens after:** *"Vendor added successfully"*, and the vendor appears in the Vendors list.

**Records created/updated:** A new row in the **Vendors** list (belongs to your current branch).

**Common mistakes to avoid:**
- Name and Contact Number are **required** — the form won't save without them.

---

## Step 6 — Create a Purchase Entry (stock goes UP)

**Menu path:** Sidebar → **Purchase** → **New Purchase** button.

This is how you **bring stock into the shop**. Recording a purchase automatically increases the product's quantity.

**What to enter:**
| Field | Value |
|-------|-------|
| Vendor * | `Apple Distributor India` |
| Invoice Number | `INV-001` (the supplier's bill no.) |
| Purchase Date * | today's date |
| Note | `First stock` (optional) |

Then click **Add Item** and fill the line:
- Product: `iPhone 15 128GB`
- Quantity: `10`
- Unit Price (₹): `65000`

(You can add more rows for more items.) The **Grand Total** shows `₹650,000.00`. Click **Complete Purchase**.

**How stock increases:** When you save, the system **adds the purchased quantity to the product's stock**. The iPhone was at **0**; after buying 10, it becomes **10**.

**Expected result:** *"Purchase record added and stock updated."* You return to the Purchase list with the new entry.

**Records created/updated:**
1. A new **Purchase** record (with its items).
2. The product's **stock count goes up** (0 → 10).
3. An entry is written to the **Inventory Log** as a stock **"in"** (so you have a permanent history of why stock increased).
4. The product's cost price is updated to this latest purchase price.

**Common mistakes to avoid:**
- Leaving Quantity at 0 → no stock is added.
- Wrong Unit Price changes your recorded cost and therefore your profit. Enter the real cost.
- Adding the same purchase twice → stock doubles. Each delivery = one purchase entry.

---

## Step 7 — Verify Inventory

Let's confirm the stock really went up.

**Menu path (quick check):** Sidebar → **Products**. The iPhone 15 row should now show stock **10**.

**Menu path (detailed check):** Sidebar → **Inventory**. Here you can see current stock levels, plus:
- **Reports → Stock Movement** shows the **"in"** entry from your purchase (date, quantity, reason = purchase).

**What happens / records:** Nothing is changed by viewing — you're just confirming the purchase from Step 6 worked.

**Common mistakes to avoid:**
- If stock still shows 0, the purchase didn't save — redo Step 6 and watch for the green success message.
- Make sure you're looking at the **iShop Mumbai** branch (stock is per-branch).

---

## Step 8 — Create a Cash Bill (a sale)

**Menu path:** Sidebar → **Billing / POS**.

This is the sales counter. The screen has the **cart on the left** and the **controls on the right**.

**Step-by-step:**
1. In **Search / Scan Product** (top right), scan the barcode **or** type `iPhone` and click the result. It drops into the cart.
2. Set **Qty** = `1` in the cart row.

**Every field on the right panel explained:**
| Field | Sample | Meaning |
|-------|--------|---------|
| **Payment Type** | `CASH` | How the customer pays. Use **CASH** for a normal paid sale. (CREDIT = pay later — Step 11.) |
| **Customer Name** | `Walk-in` | Optional for cash sales. |
| **Customer Phone** | (blank) | Optional for cash sales. |
| **GST %** | `18` | Tax rate. Adds 18% tax to the bill. Pick 0 if you don't charge GST. |
| **Bill Discount (₹)** | `0` | A flat discount in rupees, if any. |
| **Received Amount (₹)** | `95000` | Cash handed over. The screen shows **Change / Return** automatically. |

3. Check the **Grand Total** (Subtotal − Discount + GST). For 1 iPhone at ₹79,900 + 18% GST ≈ **₹94,282**.
4. Click **Generate Bill**.

**Expected result:** The bill is saved and the **invoice opens automatically** for printing.

**What changes in stock:** The iPhone stock **drops by the quantity sold**: **10 → 9**. (Sell 1, nine remain.)

**Records created/updated:**
1. A new **Bill** (with its line items) is created.
2. The product's **stock count goes down** (10 → 9).
3. An entry is written to the **Inventory Log** as a stock **"out"** (history of the sale).
4. Today's **sales and profit on the Dashboard** go up.

**Common mistakes to avoid:**
- **Selling more than you have:** if you try to sell 12 when only 10 are in stock, the bill is rejected. Check stock first.
- **ONLINE / CARD payment types:** the buttons exist, but the system reliably stores only **Cash** or **Credit**. For dependable records, **use CASH** (for paid sales) or **CREDIT** (for pay-later). Treat Online/Card as "cash received electronically".
- Empty cart → nothing to bill. Add at least one item.

---

## Step 9 — Print / View the Invoice

**Right after a sale:** the invoice opens by itself — click your browser/Print button to print, or there's a **Thermal** (small receipt-printer) layout for counter printers.

**To reprint any past bill later:**
**Menu path:** Sidebar → **Reprint Sale** → find the bill in the list → **View / Print**.

**What happens / records:** Viewing or printing does **not** change anything — it just re-displays the saved bill.

**Common mistakes to avoid:**
- Don't "redo" a sale to get a second copy — that would double-count stock and money. Always use **Reprint Sale** instead.

---

## Step 10 — Create a Credit Customer

A "credit customer" is someone you let buy now and **pay later** (udhaar). You register them once, then link their unpaid bills to them.

**Menu path:** Sidebar → **Credit Customers** → **Add Customer** button.

**What to enter:**
| Field | Value | Notes |
|-------|-------|-------|
| Customer Name * | `Rahul Sharma` | |
| Mobile Number * | `9876543210` | **Must be exactly 10 digits.** This is the key that links their bills — so enter it carefully. |
| Address | `Powai, Mumbai` | Optional. |
| Reference Person Name | `Suresh Sharma` | Optional guarantor. |
| Relation | `Father` | Optional. |

Click **Save Customer**.

**What happens after:** *"Customer registered successfully"* and Rahul appears in the Credit Customers list.

**Records created/updated:** A new row in the **Credit Customers** list (for this branch).

**Common mistakes to avoid:**
- **Phone must be 10 digits** or it won't save.
- **Duplicate phone in the same branch** is blocked. The phone number is how credit is tracked, so don't reuse one phone for two people.

---

## Step 11 — Create a Credit Sale

Now sell something to Rahul on credit.

**Menu path:** Sidebar → **Billing / POS** (same counter as Step 8).

**Step-by-step:**
1. Add a product to the cart (e.g. `USB-C Cable`, Qty `1`).
2. Set **Payment Type** = **CREDIT**. A yellow "Find Existing Credit Customer" box appears.
3. In that box, type `Rahul` or his phone and **select him** from the results. (His current outstanding balance shows.)
4. **Goods Collector Name** (optional): if someone else is physically collecting the item, type their name.
5. Click **Generate Bill**.

**Expected result:** The bill is created and saved as **unpaid** — it counts toward Rahul's outstanding balance. Stock still decreases (he took the goods).

**Records created/updated:**
1. A new **Bill** marked as **credit / unpaid**.
2. Stock **goes down** by the quantity (and an inventory "out" entry is logged) — just like a cash sale.
3. Rahul's **outstanding balance increases**.

**Common mistakes to avoid:**
- **Always select the customer** for a credit bill, and make sure the **phone matches** their registered number — credit is tracked by phone, so a mismatch means the payment won't line up with the right person later.
- Don't use CREDIT for customers who actually paid — that leaves a false debt on the books.

---

## Step 12 — Collect Payment from a Credit Customer

When Rahul pays back some or all of his udhaar, record it here.

**Menu path:** Sidebar → **Credit Customers** → click **Rahul Sharma** → **Collect Payment** (you can also view his **Ledger** to see all bills).

**What to enter:**
- The payment **Amount** he's giving (e.g. `699` to clear the cable, or any part-payment).
- (Optionally choose which bills to apply it to; by default it clears the **oldest unpaid bill first**.)
- Save / Collect.

**What happens after:** His **outstanding balance goes down** by the amount paid. Each affected bill's status moves along:
- **credit** (fully unpaid) → **partial** (some paid) → **paid** (fully cleared).

**Records created/updated:**
1. A new **Credit Payment** record (the receipt of money).
2. The relevant **Bills** are updated (paid amount up, status changed).
3. Rahul's outstanding total drops.

**Common mistakes to avoid:**
- Entering more than is owed — collect only up to the outstanding amount.
- The payment is matched by the customer's **phone number**, so it only works correctly if the credit sale in Step 11 used the same phone.

---

## Step 13 — Transfer Stock Between Branches

This moves items from one shop to another. **You need at least two branches first.**

**First, create a second branch** (repeat Step 2): Name `iShop Pune`, Code `PUN01`. (Make sure iShop Mumbai has stock to send — e.g. the iPhones from Step 6.)

**Menu path:** Sidebar → **Stock Transfer** → **Create Transfer** tab.

**What to enter:**
| Field | Value |
|-------|-------|
| From Branch * | `iShop Mumbai` |
| To Branch * | `iShop Pune` |
| Product * | `iPhone 15 128GB` (shows available stock) |
| Quantity * | `3` |
| Note | `Restocking Pune` (optional) |

Click **Initiate Transfer**.

**Then walk the transfer through its stages** (use the action buttons in the **Transfer History** tab):
1. **Pending** — request created. Pune is notified.
2. **Approve** — the receiving side accepts the request.
3. **Dispatch** — the sending side marks the goods as shipped.
4. **Receive** — the receiving side confirms arrival. ✅ **Stock physically moves only at this final "Receive" step.**

**Expected result after Receive:** Mumbai's iPhone stock **drops by 3**, and Pune **gains 3** (Pune gets its own product record for the iPhone). Both sides get inventory-log entries (out at Mumbai, in at Pune).

**Records created/updated:** A **Stock Transfer** record that progresses through the statuses; on Receive, **both branches' stock and inventory logs** are updated.

**Common mistakes to avoid:**
- **From and To must be different branches.**
- You can't transfer more than the source branch actually has.
- **Stock does NOT move when you create the request** — only when the destination clicks **Receive**. Don't panic if numbers look unchanged at the Pending/Approved/Dispatched stages.

---

## Step 14 — View Reports

**Menu path:** Sidebar → **Reports**.

You'll find a menu of business reports. The main ones:
| Report | Answers the question |
|--------|----------------------|
| **Sales** | How much did I sell (by day/period)? |
| **Profit** | How much did I actually *earn* (selling − cost)? |
| **Inventory** | What stock do I hold and what is it worth? |
| **Credit** | Who owes me money and how much? |
| **Tax** | How much GST did I collect? |
| **Expenses** | What did I spend on running the shop? |
| **Stock Movement** | Every stock in/out, with reasons. |
| **Cashier Performance** | Sales handled by each counter person. |
| **Vendor Purchases** | What I bought from each supplier. |

Most reports let you filter by **date range** and (as Superadmin) by **branch**.

**Records created/updated:** None — reports only read data.

**Common mistakes to avoid:**
- If a report looks empty, check the **date filter** and the selected **branch**.

---

## Step 15 — Check Profit, Sales and Inventory Reports

Putting it together, here's where to read your key numbers after the day's work:

- **Sales report** → total value of bills made today/this period. After Step 8 you should see your iPhone sale here.
- **Profit report** → for each sale, profit = **selling price − purchase price**. Our iPhone bought at ₹65,000 and sold at ₹79,900 shows roughly **₹14,900 profit** on that unit. This is your real earnings, not just turnover.
- **Inventory report** → current stock on hand and its **value** (quantity × cost). Use it to see how much money is "sitting on the shelf".
- **Dashboard** also gives a fast daily snapshot (today's sales, today's profit, low-stock alerts) without opening full reports.

**Common mistakes to avoid:**
- Profit looks wrong? It depends on the **purchase price** recorded in Step 4/Step 6. If the cost was entered incorrectly, fix it on the product and future profit will be right.
- Remember stock and reports are **per branch** — switch the branch filter to see the whole business vs. one shop.

---

# PART C — Beginner Cheat-Sheet & Golden Rules

Keep these in mind and you'll avoid 90% of mistakes:

1. **Branch first.** Nothing (products, sales, customers) can exist without a branch. Create it before anything else.
2. **Add stock through Purchase, not by editing the product.** Purchases keep a proper history of how stock arrived. Editing the quantity by hand should only be a one-time opening balance.
3. **Stock moves automatically:** Purchase = stock **up**, Sale = stock **down**, Transfer = stock moves **only when Received**. You never have to adjust counts by hand for normal trading.
4. **For payments, prefer CASH or CREDIT.** The Online/Card buttons exist, but the system reliably stores only those two types. Use CASH for "paid now", CREDIT for "pay later".
5. **Credit is tracked by phone number.** Register the customer once with a correct 10-digit phone, and always use that same phone on their credit bills — otherwise their payments won't match up.
6. **Don't re-create a sale to reprint it.** Use **Reprint Sale**. Re-billing double-counts money and stock.
7. **Roles hide menus on purpose.** A Cashier not seeing Reports or Purchase is correct behaviour, not a fault.
8. **Reports are per-branch.** As the owner (Superadmin), switch the branch filter to see one shop or the whole business.

---

### Your first practice run (15 minutes)
Do these in order to learn the whole loop end-to-end:
**Login → Create branch → Add 1 product → Add vendor → Purchase 10 units → Check stock is 10 → Sell 1 for cash → Print invoice → Check stock is 9 → Open the Profit report.**

Once that feels easy, repeat with a **credit** customer (Steps 10–12) and a **branch transfer** (Step 13). That's the entire system.
