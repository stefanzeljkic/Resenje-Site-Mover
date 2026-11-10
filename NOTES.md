# NOTES — Resenje Site Mover (routine status)

## 2026-11-09 — 1.0.0 written
- New public repo stefanzeljkic/Resenje-Site-Mover (Stefan's request: free WP backup + host-to-host migration plugin, donation links like the popup plugin).
- Design: own appendable archive format `.rsm` (see README), step engine (export: db → scan → files → manifest; import: files → db into `rsmt_` temp tables with
  serialized-safe URL/path replacement + prefix rename → swap at the end). Job state in a file inside the random-named backup dir (survives the DB swap).
  Chunked upload (size from wp_max_upload_size), download via admin-post + nonce, WP-CLI `wp site-mover export|import|list`.
- Donations (WP.org Guideline 11 compliant, no nagging everywhere): box on the plugin page, box after every successful job, Donate links in the plugin row,
  readme Donate link, one Dashboard/Plugins notice after the first success with permanent "Don't show again". Links checked 2026-11-09: all three work.
- Tests (CT 270, /opt/rsm, sites A :8093 prefix wp_ and B :8094 prefix nb_, B with upload limit 2 MB; WordPress 7.1.4, PHP 8.3, MariaDB 11):
  - tests/replacer-test.php on PHP 7.4: all passed.
  - CLI migration A→B: URLs in posts/serialized/JSON replaced, wp_→nb_ (usermeta capabilities, user_roles), users + passwords work, 40 MB file md5 identical, front page only B URLs.
  - 0.002 s steps: export 55 steps, import 123 steps, file still identical (resume inside files works).
  - Browser (via SSH tunnel to localhost): AJAX backup, upload in 29 parts of 2 MB + import, logout + login with source credentials, donation notice + dismiss.
  - Backup dir: 403 for dir, .rsm, job-state.php (Apache); download without nonce 400; AJAX without login 0.
  - Plugin Check (incl. experimental): no errors. PHPCS WordPress: clean. PHPCompatibilityWP 7.4+: OK. Gitleaks: 0.

## 2026-11-10 — 1.1.0 released (pushed + GitHub release on Stefan's ok)
- Stefan tested 1.0.0 on real hosting: migration worked but every page except the home page gave 404 (LiteSpeed). Cause: the target had no WordPress
  block in .htaccess and rewrite rules were only deleted. Fix: after the swap the option rsmover_flush_rewrite is set; the next WEB request (init 99)
  runs flush_rewrite_rules(true) and writes .htaccess (skipped under WP-CLI so the flag waits for a web request). Verified: B without .htaccess,
  pretty permalinks → after import the first request writes .htaccess, /sample-page/ 200 (before: 404).
- New (Stefan's request): scheduled backups (hourly/daily/weekly/monthly, site time zone) + remote storage SFTP (bundled phpseclib 3.0.58 MIT,
  password or key, host key pinned on first use), S3 / S3-compatible (own SigV4 client over wp_remote_request, multipart 8 MB+ parts, path-style option),
  FTP/FTPS via cURL (PHP ftp ext fails on FTPS with TLS 1.3 session reuse against pure-ftpd = cPanel, and is missing on many hosts).
  Retention local (only auto-* files of this site, manual backups never) and remote (strict name pattern auto-<host>-YYYYMMDD-HHMMSS-xxxxxxxx.rsm),
  e-mail on failure/always/never, log of the last 30 runs, secrets encrypted with sodium secretbox (key from wp_salt(auth)).
  Runner: WP-Cron single event → steps for one budget → non-blocking loopback to admin-ajax rsmover_continue (random job token, 403 otherwise)
  + rsmover_cron_step fallback after 60 s; GET_LOCK serializes steps (browser tab, cron, loopback). Upload errors retried 3x via the cron fallback.
  Import keeps the TARGET site's schedule settings/log and clears imported cron events. WP-CLI: scheduled-backup, test-remote. Filter rsmover_step_budget.
- Tests (CT 270 /opt/rsm, added services sftp (atmoz), rustfs + versitygw (S3; minio images are no longer public), ftp (pure-ftpd TLS)):
  SFTP password + key, host key mismatch, 0.2 s budgets (23 steps) md5-identical; S3 on rustfs and versitygw incl. multipart + retention, an S3
  object downloaded with aws-cli imported into B fine; FTPS + plain FTP md5-identical, retention, wrong password message; persistent cURL handle
  + 16 MB parts: 70 MB FTPS 53 s → 5 s; web path: home visit → spawn cron → backup + loopback continue → SFTP; token endpoint 403/200;
  browser: settings save, destination/frequency toggles, Test remote storage (S3), Run now, manual backup still fine.
  PHP 7.4 lint OK, PHPCS (WPCS 3 + PHPCompatibilityWP 7.4+) clean after phpcbf, Plugin Check no errors (one documented phpcs:ignore for the
  AWS default endpoint false positive "Offloading"), Gitleaks: 2 false positives = PEM header strings inside phpseclib, git history clean.
- Patreon: Stefan created his creator page 2026-11-10; https://www.patreon.com/user?u=76138587 now redirects to /cw/u76138587 (works,
  no link change needed in either plugin). Chrome extension blocks patreon.com, so Stefan did it himself.

## Open
- Stefan tests 1.1.0 on real hosting (SFTP/S3/FTP, schedules, 404 fix).
- WordPress.org submission: only after the popup plugin's review is done (WP.org allows one pending submission per account).
- Not tested yet: real shared hosting (nginx without .htaccess, low memory_limit), MySQL 8 → MariaDB collation path, very large sites (>2 GB, 32-bit PHP).
- Ideas: exclude list in UI, Google Drive / Dropbox (need OAuth app registration), restore directly from remote storage.
- Not tested on real AWS / B2 / R2 (no account; S3 only against rustfs + versitygw).
- CT 270 apache in container a was changed for tests (Listen 8093, ftp ext, max_execution_time=8) — gone on recreate.
- Test stack on CT 270: `cd /opt/rsm && docker compose down -v` when no longer needed.
