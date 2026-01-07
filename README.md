# VICIdial – Custom Admin Extensions

This repository contains **custom-built extensions, tools, and integrations** for the VICIdial platform.

It includes:
- Custom **VICIdial admin PHP scripts** used inside the VICIdial admin panel
- Supporting services and applications (e.g., Node/Nest-based tools)

The goal of this repo is to keep all VICIdial-related custom development **organized, version-controlled, and easy to deploy**.

---

## Repository Structure

```
vicidial/
│
├── vicidial-admin/          # Deployable VICIdial admin PHP scripts
│   ├── advanced-rules/
│   │   ├── admin_advanced_rules.php
│   │   └── README.md
│   │
│   └── campaign-list-mix/
│       ├── admin_campaign_list_mix_live.php
│       ├── admin_campaign_list_mix_lookup.php
│       └── README.md
│
├── advanced-rules/          # Node / NestJS application (non-VICIdial PHP)
│   ├── src/
│   ├── package.json
│   └── README.md
│
└── README.md                # This file
```

---

## VICIdial Admin Scripts

All PHP scripts intended to run inside the VICIdial admin panel live under:

```
vicidial-admin/
```

Each feature/module has its own folder and README explaining:
- Purpose
- Files included
- Installation and usage

Only the contents of `vicidial-admin/` should be deployed to the VICIdial web directory.

---

## Deployment

### Recommended (rsync)

```bash
rsync -av vicidial-admin/ /var/www/html/vicidial/
```

### Alternative (symlink – development only)

```bash
ln -s /path/to/vicidial/vicidial-admin/* /var/www/html/vicidial/
```

---

## Requirements

- VICIdial installed and configured
- PHP compatible with your VICIdial version
- MySQL / MariaDB (VICIdial database)
- Admin-level VICIdial access

---

## Notes

- These are **custom extensions** and not part of the official VICIdial distribution.
- Always test changes in a staging environment before deploying to production.
- Updates to VICIdial core files or database schema may require script updates.

---

## License

Internal / Proprietary. Intended for internal use and maintenance.

