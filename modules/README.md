# Tenant Backend Modules

Production-ready backend modules must be copied into:

- `tenant-backend/modules/<module-slug>/`

Each module needs a `module.json` at its root.

Install/list commands:

```bash
cd tenant-backend
php artisan modules:install --list
php artisan modules:install <module-slug>
php artisan modules:enable <module-slug>
php artisan modules:disable <module-slug>
php artisan modules:upgrade <module-slug>
php artisan modules:uninstall <module-slug>
```

Manifest paths are resolved relative to the module directory.

Optional lifecycle in `module.json`:

```json
{
  "lifecycle": {
    "file": "backend/tenant/AnalyticsModuleLifecycle.php",
    "class": "Modules\\Analytics\\Tenant\\AnalyticsModuleLifecycle"
  }
}
```

The lifecycle class must implement `App\\Modules\\Contracts\\ModuleLifecycle`.
