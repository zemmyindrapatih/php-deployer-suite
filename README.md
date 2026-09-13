# PHP Deployer Suite

A lightweight, dependency-free deployment tool for PHP projects hosted on low-cost cPanel plans that don't offer SSH access.

Deploying to cheap shared hosting is usually painful:

- **FTP** uploads files one at a time — slow for anything but the smallest change.
- **cPanel's Git integration** can pull new/changed files but won't reliably delete files that were removed from the repo, leaving stale files behind on the server.
- **No SSH** means no `rsync`, no `git pull` on the server, no shell at all in many cases.

This project solves all three by shipping a single, small PHP file to the server once. That file receives a **zip payload** containing only the files that changed (computed from a git diff) plus a **manifest** describing what to add, replace, or delete — including a sha256 hash of every file for verification. It applies the changes, backs up anything it overwrites or removes first, and logs every deploy so you can roll back later.

## How it works

- **Sender** (runs on your machine, PHP CLI): diffs two git refs in your project and packages the changed files + a JSON manifest into a zip.
- **Receiver** (a single PHP file, uploaded once to your public directory): serves a small web UI (and a token-authenticated JSON API) that accepts the zip, extracts it, verifies file hashes, applies the changes, and backs up anything it replaces or deletes.

```
you (git repo) --diff--> sender/deploy.php --zip--> upload via browser/curl --> receiver on cPanel --> live site
```

## Features

- **No php.ini tuning required** — uploads are chunked client-side, so `upload_max_filesize`/`post_max_size`/`max_execution_time` never need to change.
- **Dual authentication** — a browser login (password) for the web UI, or an `X-Deploy-Token` header for scripted/API deploys.
- **Hash-verified deploys** — every added/replaced file's sha256 is checked after being written; mismatches are reported, not silently ignored.
- **Automatic backups + rollback** — anything about to be replaced or deleted is backed up into a zip first, so a bad deploy can be undone from the web UI.
- **Explicit deletions** — the manifest lists files to delete, solving the "git can't delete on cPanel" problem.
- **Step-based progress UI** — upload and apply progress are driven by a sequence of small, fast HTTP requests (no long-running requests, no background jobs — friendly to shared hosting timeouts).
- **Single-file receiver** — the only thing you ever upload to the server is one dependency-free PHP file.

## Requirements

- PHP 7.4+ with the `zip` extension (bundled by default in most PHP installs), on both your machine and the server.
- [Composer](https://getcomposer.org/) (local machine only, for building/testing).
- Git (local machine only, used by the sender to diff refs).
- Node.js (optional, only needed to run the Playwright end-to-end tests).

## Project structure

```
deployer/
  sender/
    deploy.php          # CLI entry point for packaging a deploy
    src/                # GitDiffer, ManifestBuilder, ZipPackager
  receiver/
    src/                # Auth, ChunkUploader, Extractor, BackupManager,
                         # Applier, DeployLog, Rollback, Router, views/ui.php
    build.php           # concatenates src/ into one dependency-free file
    dist/               # build output — deploy-receiver.php (upload this)
  tests/
    sender/             # PHPUnit unit tests
    receiver/           # PHPUnit unit + HTTP integration tests
    e2e/                # Playwright browser tests
  docs/
    MANIFEST_FORMAT.md  # manifest.json schema reference
    USAGE.md            # detailed usage walkthrough
```

## Installation & build

```bash
composer install
```

Build the single-file receiver from its (testable, multi-file) source:

```bash
php receiver/build.php
```

This produces `receiver/dist/deploy-receiver.php` — the **only** file you need to upload to your server. Before uploading, generate credentials and fill in the `CONFIGURATION` block near the top of that file:

```bash
php -r "echo bin2hex(random_bytes(32));"                        # your raw API token — save it, you'll need it for API deploys
php -r "echo hash('sha256', 'YOUR_TOKEN_HERE');"                 # -> TOKEN_HASH
php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT);"  # -> PASSWORD_HASH
```

```php
const TOKEN_HASH = '...';
const PASSWORD_HASH = '...';
```

Upload `receiver/dist/deploy-receiver.php` to your cPanel public directory (File Manager or FTP — it's one small file). Consider renaming it to something unguessable, e.g. `deploy-x7f2a9.php`.

## Usage

### 1. Package a deploy

From your project's git repository:

```bash
php sender/deploy.php --repo=/path/to/project --from=<git-ref> --to=<git-ref> --out=deploy.zip --chunk-size=2097152
```

| Option | Description |
|---|---|
| `--repo` | Path to the git repo to diff (default: current directory) |
| `--from` | Starting git ref (tag, branch, or commit SHA) |
| `--to` | Ending git ref |
| `--out` | Output zip path |
| `--chunk-size` | Optional, bytes (default `2097152` / 2MB). Recorded as a hint in the manifest; smaller chunks are safer on flaky connections, larger chunks mean fewer requests. |
| `--path` | Optional, only diff changes under this subtree (e.g. `Backend`) — useful for monorepos. |
| `--strip-prefix` | Optional, removes this prefix from packaged paths (e.g. `Backend/app/index.php` → `app/index.php`). |
| `--dest-prefix` | Optional, prepends this folder to every destination path (applied after `--strip-prefix`), to land files under a subfolder on the server instead of the doc root. |

See [`docs/USAGE.md`](docs/USAGE.md#3-packaging-a-deploy) for monorepo/subfolder examples using `--path`, `--strip-prefix`, and `--dest-prefix` together.

### 2. Deploy via the web UI

Open the uploaded receiver's URL in a browser, log in with your password, and drag-and-drop (or pick) the zip. The page will:

1. Upload it in chunks.
2. Extract it and show a summary of pending changes.
3. Apply each change one at a time with a progress bar, backing up any file about to be replaced/deleted first.
4. Show a final summary, including any hash mismatches.

### 3. Deploy via the API (scripted, no browser)

The same JSON endpoints can be called directly with the token header instead of a session cookie — see `docs/USAGE.md` and `receiver/src/Router.php` for the full `action=...` sequence (`init_upload` → `upload_chunk` → `finalize_upload` → `extract` → `backup_and_apply_step` → `finish`), authenticated via:

```bash
curl -H "X-Deploy-Token: YOUR_RAW_TOKEN" "https://your-site.example/deploy-x7f2a9.php?action=init_upload" ...
```

## Rollback

The deploy history table (visible after logging in) lists past deploys. Click **Rollback** next to any entry that has a backup — only deploys that replaced or deleted at least one existing file create one — to restore those files to their pre-deploy state.

**Limitation:** rollback restores replaced/deleted files from the backup. It does *not* remove files that were newly *added* by the deploy being rolled back — clean those up manually if needed.

## Manifest format

Each deploy zip contains a `manifest.json` (add/replace/delete lists with sha256 hashes) plus a `files/` directory with the actual content for anything being added or replaced. See [`docs/MANIFEST_FORMAT.md`](docs/MANIFEST_FORMAT.md) for the full schema.

## Testing

The project has full test coverage across three layers:

```bash
composer test              # PHPUnit: unit tests + HTTP integration tests
composer test:coverage     # same, with a coverage report (requires Xdebug or PCOV)

npm install && npx playwright install chromium   # one-time
npm run test:e2e           # Playwright browser tests against the built receiver
```

The integration and E2E suites run against the actual built `receiver/dist/deploy-receiver.php`, so run `php receiver/build.php` first if you've changed anything under `receiver/src/`.

## Security notes

- Rename the uploaded receiver file to something unguessable, and consider deleting or moving it outside the public directory between deploys if you want extra caution.
- Place `.deployer-work/`, `.deployer-backups/`, and `.deployer-log.json` outside the public web root if possible, or deny access via `.htaccess` — they hold deploy history and file backups you don't want publicly downloadable.
- Serve the receiver over HTTPS — the login password and API token are sent in requests and should not travel in plaintext.

## Documentation

- [`docs/USAGE.md`](docs/USAGE.md) — full setup and usage walkthrough.
- [`docs/MANIFEST_FORMAT.md`](docs/MANIFEST_FORMAT.md) — manifest.json schema reference.
