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

## Open
- WordPress.org submission: only after the popup plugin's review is done (WP.org allows one pending submission per account).
- Not tested yet: real shared hosting (nginx without .htaccess, low memory_limit), MySQL 8 → MariaDB collation path, very large sites (>2 GB, 32-bit PHP).
- Ideas: scheduled backups, remote storage (SFTP/S3), exclude list in UI.
- Test stack on CT 270: `cd /opt/rsm && docker compose down -v` when no longer needed.
