# Multi-Branch Billing and Stock Management System

A plain PHP-based multi-branch billing and stock management system with a modern Bootstrap 5 UI.

## Features

- **Multi-Branch Support**: Manage multiple branches from a single system.
- **Role-Based Access Control**: Superadmin, Branch Admin, and Staff roles.
- **Inventory Management**: Track stock levels, stock-ins, stock-outs, and low stock alerts.
- **Billing / POS**: Quick billing with GST support and invoice generation.
- **Customer Management**: Maintain customer records and credit ledgers.
- **Purchase Management**: Manage vendor purchases and stock updates.
- **Reports**: Sales, profit, inventory, and credit reports.
- **Audit Logs**: Track user activities for security.

## Setup Instructions

1. **Database Setup**:
   - Create a database named `billmanage` in MySQL.
   - Import the `database.sql` file provided in the root directory.

2. **Configuration**:
   - Open `config/db.php` and update the database credentials if necessary:
     ```php
     $host = 'localhost';
     $db   = 'billmanage';
     $user = 'root';
     $pass = '';
     ```

3. **Web Server**:
   - Place the project folder in your web server's root (e.g., `xampp/htdocs/`).
   - Access the system via `http://localhost/billmanage`.

4. **Default Login**:
   - **Email**: `admin@shop.com`
   - **Password**: `admin123`

## Directory Structure

- `assets/`: CSS, JS, and images.
- `config/`: Database connection and configuration.
- `includes/`: Reusable UI components (header, footer, sidebar, topbar) and functions.
- `modules/`: Core functional modules of the system.
- `index.php`: Main entry point (redirects to login/dashboard).

## Tech Stack

- **Frontend**: Bootstrap 5, Font Awesome 6, JQuery.
- **Backend**: Plain PHP (PDO).
- **Database**: MySQL.

## License

MIT
