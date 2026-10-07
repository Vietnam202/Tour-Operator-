# VS2.2 staging release and rollback

Deploy staging only: `https://v2quote.vietnamtraveladvisor.com.vn`. Expected baseline commit a2eb8e9c750905eb8005fd28b11dc78d13ba367b, migration ledger 26 entries ending 028. Never point these steps at production.

1. Preserve the existing application source, private configuration, private documents and consistent database dump using the established VS2.1 procedure. Record SQL/archive SHA-256 and all table counts/checksums and issued snapshot fingerprints.
2. Restore the dump into a fresh isolated database. Confirm exact table counts, every table checksum, migration ledger and issued data before cutover. Never rehearse over the live database.
3. Verify environment, URL, database and source manifest; GD/ZIP/mbstring; private writable media/font-cache location outside the webroot; existing historical migration hashes; approved canonical Git source archive/manifest. Preserve original root ownership/mode.
4. Briefly place staging in maintenance. Preserve matching baseline files. Copy the exact verified release source, retaining the existing private config. Site-scoped `.user.ini` requests upload_max_filesize=12M, post_max_size=16M, memory_limit=256M; its HTTP access is denied. Verify actual multipart behavior after cutover.
5. Run the existing migration runner once: 029_vs22_media_library then 030_vs22_proposal_links. No other migration is pending. MariaDB DDL auto-commits; an error leaves maintenance enabled and requires diagnosis, not automatic replay.
6. Verify ledger count 28/all APPLIED/exact hashes; six empty additive tables and 16 FK columns; old parent uniqueness, old quote version IDs and historical Sent/Accepted/booking bytes unchanged. Only permissions/role mappings and migration ledgers may gain the intended rows.
7. Run the runner again: NO_OP. Verify source file checksums/private configuration and expose staging only after these gates pass.
8. Run VS1/VS2.1 smoke, media upload/approval/assignment, four templates, PDF/DOCX/mobile Web, permissions and fresh synthetic Quote → Validate → Approve → Send → Accept → Booking → Create Revision. Compare all preexisting issued fingerprints again. Retire temporary test authentication and deployment cron jobs; retain private logs and backup.

On this server use process-scoped `php -d opcache.enable_cli=0` for CLI helpers and migration calls; the installed CLI OPcache has a known crash. This does not alter web PHP, global security or production configuration.

## Rollback

Before any VS2.2 business writes, restore the verified old application files in maintenance, leaving additive tables intact. The legacy source does not read them. Do not run DROP/TRUNCATE or reverse 001–028.

After VS2.2 sends/acceptances, use a forward fix while retaining the compatibility reader and frozen bundle/media bytes. Rolling all application code back would lose the new presentation/link features and is not a safe user-facing rollback. A full database restore would discard post-checkpoint changes and is destructive: stop for explicit approval and restore only the matched source/config/media/database set. Never overwrite live data automatically.

If DDL partially commits, preserve database/source/logs, keep maintenance enabled and inspect the FAILED migration entry. Do not delete its checksum record to retry blindly. No destructive rollback migration is included.
