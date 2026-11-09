=== Resenje Site Mover – Backup & Migration ===
Contributors: stefanzeljkic
Donate link: https://buymeacoffee.com/stefanzeljkic
Tags: migration, backup, restore, move site, clone
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up a whole WordPress site into one file and move it to another hosting or domain. Free, no size limits, no premium version.

== Description ==

Resenje Site Mover packs the database and the complete wp-content folder (themes, plugins, media) into one `.rsm` file. Import that file on any other WordPress site and it becomes an exact copy of the original – with the new address and server paths changed everywhere automatically.

**Completely free.** There is no premium version, no file size limit and no feature that is locked. The plugin is kept alive by donations.

= Features =

* One-click backup of database + wp-content into a single file
* Import on another hosting or domain: URLs and server paths are replaced automatically, also inside serialized data (widgets, theme options, page builders) and JSON (block editor)
* Different table prefix on the new site? Handled automatically
* Works within short PHP time limits of shared hosting: every job runs in small steps
* Large backups are uploaded in parts, so the hosting upload limit does not matter
* Safe import: the database is loaded into temporary tables and only switched in at the very end, so a failed import does not break the site
* Optional: leave out the media library
* Backups are stored in a protected folder with a random name; download, restore or delete them from the list
* WP-CLI: `wp site-mover export`, `wp site-mover import <file>`, `wp site-mover list`
* No external services, no tracking, no account needed

= How to move a site =

1. On the old site: Site Mover → Create backup → Download.
2. On the new hosting: install a fresh WordPress and Resenje Site Mover.
3. On the new site: Site Mover → Import → choose the file → Upload and import.
4. Log in with the user and password of the old site.
5. Open Settings → Permalinks and click Save once.

= Limits =

* Multisite networks are not supported.
* WordPress core files and wp-config.php are not part of the backup (the new site keeps its own); the new site must run the same or a newer WordPress version.
* Cache folders and backup folders of other plugins are skipped.
* Files on the new site that do not exist in the backup are not deleted.

== Installation ==

1. Install the plugin through the 'Plugins' screen (or upload the `resenje-site-mover` folder to `/wp-content/plugins/`).
2. Activate it.
3. Open "Site Mover" in the admin menu.

== Frequently Asked Questions ==

= Is it really free? =

Yes. Everything is included, without limits. If the plugin saved you time or a migration fee, please consider a donation (Buy Me a Coffee, Ko-fi or Patreon – links on the plugin page).

= Where are the backups stored? =

In `wp-content/rsmover-backups-<random>/`. The folder is protected against web access (.htaccess, web.config and a random name for servers that ignore those files). Download your backups and keep a copy somewhere else.

= My import was interrupted. Is my site broken? =

No. Until the very last step only temporary tables are written; your live database is replaced only at the end. Files that were already extracted stay in place. Start the import again.

= Why am I logged out after the import? =

The users now come from the backup. Log in with a user and password of the source site.

= Can I import with WP-CLI? =

Yes: `wp site-mover import /path/to/backup.rsm --yes`.

= What happens when I delete the plugin? =

Its settings and the backup folder (with all backups in it) are removed. Download the backups you want to keep first.

== Screenshots ==

1. Backup, import and the list of backups
2. Progress of a running import

== Changelog ==

= 1.0.0 =
* First release: backup, download, import / migration with URL and path replacement, chunked upload, WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
First release.
