# Resenje Site Mover – Backup & Migration 🚚

Free WordPress plugin that backs up a whole site into **one file** and moves it to **another hosting or domain**.
No premium version, no size limits — kept alive by donations ☕.

## ✨ Features

- One-click backup: database + complete `wp-content` (themes, plugins, media) in one `.rsm` file
- Import / migration: URLs and server paths are replaced automatically — also inside **serialized data** (widgets, theme options, page builders) and **JSON** (block editor)
- Different table prefix on the target site is handled
- Runs in small steps, so it works with short PHP time limits on shared hosting
- Large backups are uploaded in parts (hosting upload limit does not matter)
- Safe import: data goes into temporary tables first and replaces the live tables only at the very end
- Optional: leave out the media library
- **Scheduled backups** (hourly / daily / weekly / monthly) with retention on the server and on remote storage, e-mail on failure
- **Remote storage:** SFTP / SSH (password or private key, host key pinning), Amazon S3 and S3-compatible (Backblaze B2, Wasabi, Cloudflare R2, MinIO, DigitalOcean Spaces …), FTP / FTPS — resumable uploads with retries, credentials stored encrypted
- Backups in a protected folder with a random name (`.htaccess`, `web.config`, `index.php`); download / restore / delete from the list
- WP-CLI: `wp site-mover export [--exclude-media]`, `wp site-mover import <file> [--yes]`, `wp site-mover list`, `wp site-mover scheduled-backup`, `wp site-mover test-remote`
- No external services, no tracking, no account

## ⚙️ How to move a site

1. **Old site:** Site Mover → *Create backup* → *Download*.
2. **New hosting:** install a fresh WordPress and Resenje Site Mover.
3. **New site:** Site Mover → *Import* → choose the file → *Upload and import*.
4. Log in with the **old site's** user and password.
5. Done — permalinks / `.htaccess` are rebuilt automatically (nginx: the host must use the standard WordPress rules).

## ⏰ Scheduled backups

Site Mover → *Scheduled backups*: choose how often, where to copy the backups (SFTP, S3, FTP or only this server) and how many to keep. WordPress runs schedules when the site gets visits; on quiet sites add a server cron job that calls `wp-cron.php` or runs `wp site-mover scheduled-backup`. The background job continues itself with short requests to the site (and a cron fallback), so it also works with short PHP time limits.

## ⚠️ Limits

- Multisite is not supported.
- WordPress core and `wp-config.php` are not in the backup; the new site keeps its own and needs the same or a newer WordPress version.
- Cache folders and other plugins' backup folders are skipped; files that exist only on the target are not deleted.

## 🧩 Archive format (`.rsm`)

`RSMOVER1` magic, then entries: `RSME` · uint32 path length · path · uint64 size · uint32 mtime · data.
Entries: `__rsmover__/database.jsonl` (one JSON record per line: table definition or a batch of rows), `content/<path>` for files, `__rsmover__/manifest.json` (source URLs, paths, table prefix, versions) as the last entry.

## 🧪 Tested on

- WordPress 7.1 (requires 6.2+), PHP 7.4+ (tested on 8.3), MariaDB 11
- Plugin Check and PHPCS (WordPress Coding Standards)

## 🛠 Development

- `php tests/replacer-test.php` — tests for the serialized-safe search & replace
- `bash build-zip.sh` → `dist/resenje-site-mover.zip`

Bug reports and pull requests are welcome.

## 📄 License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

Bundled libraries (in `vendor/`, used only for SFTP): [phpseclib 3](https://github.com/phpseclib/phpseclib) and [paragonie/constant_time_encoding](https://github.com/paragonie/constant_time_encoding), both MIT licensed (license files included).

## ☕ Support development

The plugin is free. If it saved you time, please consider a donation:

- [Buy Me a Coffee ☕](https://buymeacoffee.com/stefanzeljkic)
- [Ko-fi ❤️](https://ko-fi.com/stefanzeljkic)
- [Patreon 💸](https://www.patreon.com/user?u=76138587)

## 🧑‍💻 Author

**Stefan Zeljkic**
