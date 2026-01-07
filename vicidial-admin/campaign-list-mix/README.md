# Campaign List Mix (VICIdial)

This repository contains custom VICIdial admin scripts used to manage and view **campaign list mixes** from the admin panel.

These scripts extend the standard VICIdial admin interface and are intended for internal/admin use only.

---

## Files

- **admin_campaign_list_mix_live.php**  
  Main admin UI page for viewing and managing live campaign list mixes. This file integrates with the VICIdial admin framework and displays campaign-related list mix data.

- **admin_campaign_list_mix_lookup.php**  
  Read-only helper/lookup script used to fetch list-related information (e.g., list names) from the database. This is typically called internally by the main live page.

---

## Requirements

- VICIdial installed and configured
- PHP (compatible with the VICIdial version in use)
- MySQL/MariaDB (VICIdial database)
- Valid VICIdial admin access

These scripts rely on existing VICIdial core files such as:
- `functions.php`
- `admin_header.php`

---

## Installation

1. Copy both PHP files into your VICIdial admin directory:
   ```
   /var/www/html/vicidial/
   ```
   (or the equivalent admin path used in your setup)

2. Ensure file permissions match other VICIdial admin PHP files.

3. Log in to the VICIdial admin panel as an admin user.

4. Access the script directly via browser or link it from an existing admin menu if needed.

---

## Usage

- Use **admin_campaign_list_mix_live.php** to view campaign list mix data in real time.
- The lookup script runs in the background and should not be accessed directly.

---

## Notes

- This is a **custom extension** and not part of the default VICIdial distribution.
- Changes to the VICIdial database schema or core admin files may require updates to these scripts.
- Always test in a staging environment before deploying to production.

---

## License

Internal / Proprietary. Use and modify as required for your VICIdial installation.

