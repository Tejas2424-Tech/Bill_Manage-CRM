# BillManage Deployment Guide

## Prerequisites
- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher
- **Web Server**: Apache with `mod_rewrite` and `mod_headers` enabled.

## Setup Instructions

1.  **Clone the Repository**:
    Upload the files to your web server (e.g., `/var/www/html` or `htdocs`).

2.  **Database Configuration**:
    - Create a new MySQL database (e.g., `billmanage`).
    - Import the `database.sql` file located in the project root into your new database.

3.  **Configure DB Connection**:
    - Edit `config/db.php`.
    - Update `$host`, `$db`, `$user`, and `$pass` with your database credentials.
    - The `BASE_URL` is automatically detected, but ensure your server's `DOCUMENT_ROOT` is correctly configured.

4.  **Permissions**:
    - Ensure the `assets/images/` directory is writable by the web server:
      ```bash
      chmod -R 755 assets/images
      ```

5.  **First Login**:
    - **URL**: `http://your-domain.com/modules/auth/login.php`
    - **Email**: `admin@shop.com`
    - **Password**: `admin123`
    - **Action**: Change the password immediately in the **Settings** or **Users** module.

## Production Checklist
- [ ] **SSL**: Enable HTTPS for all traffic.
- [ ] **DB Security**: Use a strong, unique password for the MySQL user.
- [ ] **PHP Config**: Set `display_errors = Off` and `log_errors = On` in `php.ini`.
- [ ] **Logo**: Upload your company logo in **Settings > Business Info**.
- [ ] **Branding**: Update company name and address in **Settings**.

## Security Hardening
The project includes a `.htaccess` file that:
- Prevents directory listing (`Options -Indexes`).
- Denies access to sensitive file types (`.env`, `.log`, `.sql`).
- Protects the `config/` and `includes/` directories.
- Sets security headers (`X-Frame-Options`, `X-Content-Type-Options`).
