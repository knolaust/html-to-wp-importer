# HTML → WP Importer (Admin UI)

Import a folder/zip of static **HTML (.html/.htm)** into WordPress as posts/pages — **no CLI required**. Runs in **AJAX batches** to avoid timeouts, optionally moves local assets (images/PDFs) into Media Library and rewrites links.

- Admin screen: **Tools → HTML → WP Importer**
- Works on typical shared hosting + Local (by Flywheel)

---

## Features

- Upload a **.zip** or point to a **server folder**
- Recursively scans `.html/.htm`
- Extracts **Title** from `<title>` (fallback: first `<h1>` or filename)
- Imports **images/PDFs**, rewrites `<img src>` and media links to WP URLs
- **Featured image** = first imported image (optional)
- **Keep dates** from file modified time (optional)
- **Dry run** mode (no writes) to validate before importing
- **AJAX batches** with progress bar, log, and resume-safe job state
- Slug generated from relative file path (stable permalinks)
- Works with any **public** post type

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- `ZipArchive` PHP extension (only if using ZIP uploads)
- Admin capability: `manage_options`

---

## Installation

1. Copy the plugin folder into:
   ```
   wp-content/plugins/html-to-wp-importer/
   ```
2. Ensure these files exist:
   ```
   html-to-wp-importer.php
   /includes/Admin.php
   /includes/Importer.php
   /assets/js/admin.js     (optional UI script)
   /assets/admin.css       (optional styles)
   ```
3. Activate **HTML → WP Importer (Admin UI)** in **Plugins**.
4. Open **Tools → HTML → WP Importer**.

> Using **Local by Flywheel**? The WordPress root is typically:
> `/Users/<you>/Local Sites/<site>/app/public`

---

## Usage

### 1) Choose a source
- **Upload ZIP** containing your `.html`/`.htm` and any asset folders, or
- Enter a **Server folder path**:
  - Accepts **absolute** paths (e.g., `/Users/you/Local Sites/site/app/public/html`)
  - Accepts **relative to WP root** (e.g., `/html` or `html/` → resolved against `ABSPATH`)

### 2) Set options
- **Post Type**: post/page/custom
- **Status**: draft, publish, private
- **Author**: post author ID
- **Category** (posts only): existing category slug
- **Base URL**: only if your HTML uses absolute URLs under your domain (used to map assets on disk)
- **Keep file modified dates**: use file mtime for `post_date`
- **Set featured image**: first imported image
- **Dry run**: parse only, no posts/media created

### 3) Run import
- Click **Prepare Import** → you’ll be redirected back with a job ID
- The progress panel starts automatically (batches of ~15 files by default)
- Review logs as it runs

---

## How it works (under the hood)

- The form posts to `admin-post.php?action=h2wpi_prepare`
- A “job” is created and stored in options:
  - `{job_id}_opts`: options (post type, base path, etc.)
  - `{job_id}_state`: queue, counts, log
- JS calls:
  - `wp_ajax_h2wpi_start_job` (returns state)
  - `wp_ajax_h2wpi_run_batch` (processes N files per tick)
- Each file:
  - Parse DOM (`DOMDocument`) → title/body
  - Map local assets to Media Library (if relative or under `Base URL`)
  - Rewrite content URLs to attachment URLs
  - Insert post with generated slug; set featured image (optional)

---

## Paths & assets

- **Relative paths** like `/img/photo.jpg` or `images/a.png` are resolved against the chosen **Server folder** (or ZIP extraction root).
- **Absolute URLs** (e.g., `https://example.com/img/photo.jpg`) are imported only if `Base URL` matches; otherwise left as-is.
- External URLs (CDN, other domains) are **not** imported.

---

## Troubleshooting

**“Cannot load h2wpi.” after submit**  
Fixed by design: the form posts to `admin-post.php` and redirects to `tools.php?page=h2wpi&job=...`. If you still see this:
- Make sure the plugin is activated.
- Confirm you’re an Administrator.
- Check `wp-content/debug.log` (enable in `wp-config.php`):
  ```php
  define('WP_DEBUG', true);
  define('WP_DEBUG_LOG', true);
  define('WP_DEBUG_DISPLAY', false);
  ```

**ZIP upload fails**  
Install/enable `ZipArchive`, or use the **Server folder path** option.

**Stalling on large sites**  
Lower batch size in `/assets/js/admin.js` (the `batch:` value in the AJAX call).  
Ensure the upload directory is writable.

**Wrong paths on Local**  
Use **Reveal in Finder** in Local → copy the absolute path to your `html/` folder, or just use `/html` (relative to WP root).

---

## FAQ

**Can I create hierarchical pages from folders?**  
Not yet. Current version flattens to slugs. (Roadmap below.)

**Will it strip headers/footers from old pages?**  
No. It imports the body as-is. Add your own cleanup/pass if you have boilerplate to remove.

**Can I re-run safely?**  
Dry run first. For repeated runs, you may want to delete created posts or add custom dedupe logic.

---

## Roadmap

- Option to create **parent/child pages** based on directory structure
- Custom **cleanup rules** (strip old nav/footer wrappers)
- Map **meta fields** (e.g., keep legacy path in `_h2wpi_source`)
- Better **conflict handling** for duplicate slugs

---

## Development

- Namespace: `H2WPI\`
- Main classes:
  - `includes/Admin.php` — screen, form, admin-post, AJAX
  - `includes/Importer.php` — parsing, media import, post creation
- Build assets (optional): place your `admin.js` / `admin.css` in `/assets/`

**Suggested `.gitattributes`** (keeps GitHub release zips lean):
```
/tests           export-ignore
/node_modules    export-ignore
/.gitignore      export-ignore
/.editorconfig   export-ignore
/.vscode         export-ignore
/.github         export-ignore
```

**Suggested `.gitignore`** (macOS + plugin dev):
```
.DS_Store
__MACOSX/
node_modules/
build/
dist/
*.log
*.tmp
.idea/
.vscode/
```

---

## Changelog

### 1.0.1
- Switch form post to **admin-post** flow to avoid “Cannot load …” edge cases
- Accept **relative server paths** (e.g., `/html`) resolved against `ABSPATH`
- Minor hardening and status messages

### 1.0.0
- Initial release

---

## License

GPL-2.0-or-later. See: https://www.gnu.org/licenses/gpl-2.0.html

---

## Credits

Built by **Knol Aust.
