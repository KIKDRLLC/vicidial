# Advanced Rules (VICIdial)

This repository contains a custom VICIdial admin script used to manage **advanced dialing or campaign rules** from the admin interface.

The script extends the standard VICIdial admin panel and is intended for administrative use only.

---

## File

- **admin_advanced_rules.php**  
  Admin interface for creating, viewing, and managing advanced rules related to VICIdial campaigns. This script integrates with the VICIdial admin framework and interacts directly with the VICIdial database.

---

## Requirements

- VICIdial installed and configured
- PHP (compatible with your VICIdial version)
- MySQL/MariaDB (VICIdial database)
- Admin-level VICIdial access

The script depends on standard VICIdial admin includes such as:
- `functions.php`
- `admin_header.php`

---

## Installation

1. Copy the file into your VICIdial admin directory:
   ```
   /var/www/html/vicidial/
   ```
   (or the appropriate admin path in your environment)

2. Ensure file permissions are consistent with other VICIdial admin PHP files.

3. Log in to the VICIdial admin panel with admin privileges.

4. Access the script directly via browser or add a link to it in the admin menu if required.

---

## Usage

- Use this page to configure and manage advanced rules for campaigns.
- Any changes made through this interface take effect immediately, depending on campaign configuration.

---

## Notes

- This is a **custom script** and not part of the default VICIdial installation.
- Always test changes in a staging environment before deploying to production.
- Updates to VICIdial core files or database structure may require adjustments to this script.

---

## License

Internal / Proprietary. Use and modify as needed for your VICIdial setup.
