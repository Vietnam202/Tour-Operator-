> Historical v2.x reference only. For this v3 staging candidate, follow V3_STAGING_UPGRADE.md and V3_AUDIT.md instead of the deployment instructions below.

# VTA v2 Phase 1 — CyberPanel deployment guide

## Recommended deployment approach

Do not overwrite the current V1.39 production app during the first Phase-1 test. Install V2 in a staging subdomain or protected staging directory first.

Suggested staging host:

```text
v2.quote.vietnamtraveladvisor.com.vn
```

or another private staging URL that you control.

## 1. Create the database

In CyberPanel create a MariaDB/MySQL database and a dedicated database user. Suggested names:

```text
Database: vta_os
User:     vta_os_user
```

Use a strong unique password. Do not reuse the CyberPanel admin password.

## 2. Create private server storage outside public_html

Example:

```bash
mkdir -p /home/quote.vietnamtraveladvisor.com.vn/vta_private/documents
chmod 750 /home/quote.vietnamtraveladvisor.com.vn/vta_private
```

The exact home path for a staging subdomain may differ. Use the actual CyberPanel website home path.

## 3. Create private config

Copy:

```text
api/config.example.php
```

to a path outside public_html, for example:

```text
/home/quote.vietnamtraveladvisor.com.vn/vta_private/config.php
```

Edit database credentials, base URL and storage configuration there.

Do not put the real config or Google credentials in a publicly downloadable folder.

## 4. Configure Google Drive Document Vault

The code supports two server-side authentication methods.

### Preferred for a normal VTA-owned Drive account: OAuth refresh token

Fill these private config fields:

```php
'google_oauth_client_id' => '...',
'google_oauth_client_secret' => '...',
'google_oauth_refresh_token' => '...',
'google_drive_folder_id' => '...',
```

### Alternative: Service account

Place the service-account JSON outside public_html and configure:

```php
'google_service_account_json' => '/private/path/google-service-account.json',
'google_drive_folder_id' => '...',
```

For a service account, the target Drive arrangement must permit that service account to create files. A Shared Drive / managed Workspace setup is generally safer than assuming a personal My Drive folder will provide storage quota to the service account.

### Temporary staging fallback

For initial UI/database testing only, private local storage can be used:

```php
'driver' => 'local',
'local_path' => '/home/.../vta_private/documents',
```

Before Product cutover, switch to the approved Google Drive Document Vault architecture and re-test uploads.

## 5. Set the private config path

For CLI commands:

```bash
export VTA_CONFIG_FILE=/home/quote.vietnamtraveladvisor.com.vn/vta_private/config.php
```

For PHP-FPM/web requests, configure the same environment variable in the site/PHP-FPM environment, or place the config at the default path expected by the build:

```text
/home/quote.vietnamtraveladvisor.com.vn/vta_private/config.php
```

If the site home differs, set `VTA_CONFIG_FILE` explicitly.

## 6. Install schema, roles and first administrator

Avoid writing the actual password in shell history:

```bash
read -s VTA_ADMIN_PASSWORD
export VTA_ADMIN_PASSWORD
php /path/to/public_html/api/bin/install.php \
  --email=your-admin-email@example.com \
  --name="VTA Administrator"
unset VTA_ADMIN_PASSWORD
```

The installer creates the Phase-1 schema, core roles/permissions and the first Administrator.

## 7. Run health check

```bash
php /path/to/public_html/api/bin/healthcheck.php
```

The database checks should show `PASS`. If Google Drive is configured, the health check verifies that authorization configuration and a folder ID are present; the first real upload remains the end-to-end Drive test.

## 8. Configure the scheduled Phase-1 automation worker

At minimum run hourly:

```text
0 * * * * VTA_CONFIG_FILE=/home/.../vta_private/config.php /usr/bin/php /path/to/public_html/api/bin/cron.php >> /home/.../vta_private/cron.log 2>&1
```

Current Phase-1 cron behavior creates/deduplicates 30-day Rate Expiry tasks and auto-closes them when the condition is no longer true.

## 9. Test before real supplier files

Use one non-sensitive test contract/rate sheet and verify:

```text
Login
→ Create Supplier
→ Upload Supplier File
→ Original file stored
→ Review extraction
→ Create structured Rate
→ Approve Rate Version
→ Search Rate
→ Open source document
```

Do not migrate all business data until this flow passes.

## 10. Production cutover rule

V2 Phase 1 must not replace V1.39 solely because the UI loads. Product cutover requires:

```text
Database backup PASS
Login PASS
Permissions PASS
Supplier workflow PASS
Drive upload/download PASS
Rate approval/versioning PASS
Audit PASS
Concurrency PASS
Restore procedure confirmed
```

V1.39 remains the operational fallback until those conditions are met.
