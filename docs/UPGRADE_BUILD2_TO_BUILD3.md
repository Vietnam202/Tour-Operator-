> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# Upgrade — VTA v2 Phase 1 Build 2 → Build 3

## Important

Upgrade **staging first**. Do not overwrite V1.39 production as part of this procedure.

Build 3 adds database tables and alters rate-import status enums. Back up the Build 2 database before applying migration 003.

## 1. Back up staging database and private configuration

Keep copies of:

- the MariaDB/MySQL database;
- `vta_private/config.php`;
- any private Google credential files;
- local fallback document folder if it is still being used.

## 2. Replace application code

Upload/extract the Build 3 package into the VTA v2 **staging** document root. Keep the private config outside `public_html` unchanged.

## 3. Run the installer/migration runner

Use the same admin email. Supply the password through an environment variable rather than command history.

```bash
export VTA_CONFIG_FILE=/home/.../vta_private/config.php
read -s VTA_ADMIN_PASSWORD
export VTA_ADMIN_PASSWORD
php /path/to/vta-v2-staging/api/bin/install.php \
  --email=your-admin-email@example.com \
  --name="VTA Administrator"
unset VTA_ADMIN_PASSWORD
```

The runner applies any missing ordered migrations, including:

```text
003_phase1_contracting_rules_reconciliation.sql
```

It also updates role-permission grants for Admin/Product.

## 4. Run health check

```bash
php /path/to/vta-v2-staging/api/bin/healthcheck.php
```

Then sign in to staging and confirm the footer/version displays **Phase 1 Build 3**.

## 5. Smoke test Build 3

Use non-sensitive test data first:

```text
Create Guide / Tour / Attraction / Meal rate
→ add structured Season/Blackout/Child/FOC rule
→ create draft
→ open Rate Detail
→ Test Travel Date / Child / FOC
→ review source
→ approve only after source/conflict review
```

Then test:

```text
Supplier Document
→ create/import draft rates
→ Compare With Previous Approved Rates
```

Finally test a legacy import containing at least one deliberately incomplete row:

```text
Legacy Import
→ skipped row
→ Import Reconciliation
→ map correct fields/source
→ Create Draft Rate & Resolve Row
```

## 6. Hard refresh / PWA cache

Build 3 uses a new service-worker cache key. After deployment use Ctrl+Shift+R / Ctrl+F5. If an installed PWA still shows old assets, fully close/reopen it; reinstall only if the old service worker remains stuck.

## 7. Do not cut over Product yet unless checklist passes

Use `STAGING_CUTOVER_CHECKLIST.md`. Build 3 code completion is not the same as production readiness.
