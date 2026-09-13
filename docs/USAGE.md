# Usage

## 1. One-time setup

```bash
composer install
```

## 2. Configure and build the receiver

The receiver's real source lives in `receiver/src/*.php` (testable classes).
It gets built into a single dependency-free file — that build output is the
*only* file you ever upload to cPanel.

```bash
php receiver/build.php
```

This produces `receiver/dist/deploy-receiver.php`. Open it and fill in the
`CONFIGURATION` block near the top:

```bash
php -r "echo bin2hex(random_bytes(32));"        # your API token - save it somewhere safe
php -r "echo hash('sha256', 'YOUR_TOKEN');"     # -> TOKEN_HASH
php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT);"  # -> PASSWORD_HASH
```

Edit the constants at the top of `receiver/dist/deploy-receiver.php`:

```php
const TOKEN_HASH = '...';
const PASSWORD_HASH = '...';
```

Then upload that one file to your cPanel public directory (e.g. via File
Manager or FTP, a single small upload). Optionally rename it to something
unguessable (e.g. `deploy-x7f2a9.php`) for extra obscurity.

**Recommended:** place `.deployer-work`, `.deployer-backups`, and
`.deployer-log.json` outside the public web root, or protect them with a
`.htaccess` "Deny from all" if they must stay inside it — these hold deploy
history and file backups you don't want publicly downloadable.

## 3. Packaging a deploy

From your project's git repository:

```bash
php sender/deploy.php --repo=/path/to/project --from=<git-ref> --to=<git-ref> --out=deploy.zip --chunk-size=2097152
```

- `--from` / `--to` - any git refs (tags, branches, commit SHAs).
- `--out` - where to write the resulting zip.
- `--chunk-size` - optional, bytes (default 2MB). This is a hint recorded in
  the manifest; it does not change how the browser chunks the upload (that's
  fixed client-side), but documents what the packager assumed.

### Deploying from monorepos

If your project is a monorepo with multiple independent services (e.g.
`Backend/` and `Frontend/` subdirectories), use `--path` and `--strip-prefix`
to deploy only one subtree:

```bash
php sender/deploy.php --repo=/path/to/monorepo \
  --from=<git-ref> --to=<git-ref> \
  --path=Backend --strip-prefix=Backend \
  --out=backend-deploy.zip
```

- `--path=<pathspec>` - only diff changes under this subtree (e.g. `Backend`);
  other changes (e.g. `Frontend/`) are ignored.
- `--strip-prefix=<prefix>` - remove the prefix from paths before packaging,
  so `Backend/app/index.php` becomes `app/index.php` in the zip (the layout
  the server expects for its doc root).

Both flags are optional; omit them for the default behavior (full-repo diff,
paths as-is).

### Deploying into a chosen subfolder (`--dest-prefix`)

If the server's document root shouldn't have Backend's files dumped directly
into it (e.g. you don't want `app/` and `vendor/` sitting next to your own
`index.php`), add `--dest-prefix` to land them under a folder of your
choosing instead:

```bash
php sender/deploy.php --repo=/path/to/monorepo \
  --from=<git-ref> --to=<git-ref> \
  --path=Backend --strip-prefix=Backend --dest-prefix=api \
  --out=backend-deploy.zip
```

- `--dest-prefix=<folder>` - prepends this folder to every destination path
  *after* `--strip-prefix` has been applied, so `Backend/app/index.php`
  becomes `api/app/index.php` in the zip and lands at
  `<web_root>/api/app/index.php` on the server.

`--dest-prefix` can be used without `--strip-prefix` too (it just prepends
to whatever paths the diff produced).

## 4. Deploying

Open the uploaded receiver URL in a browser, log in with your password, then
drag and drop the zip (or use the file picker). The page will:

1. Upload the zip in chunks (works around `upload_max_filesize` limits).
2. Extract it and show a summary of pending changes.
3. Apply each change one at a time, showing progress, backing up any file
   about to be replaced or deleted first.
4. Show a final summary, including any hash mismatches.

Non-browser / scripted deploys can call the same JSON API directly with
`X-Deploy-Token: <your raw token>` instead of a session cookie.

## 5. Rolling back

The deploy history table lists past deploys. Click "Rollback" next to any
entry that has a backup (only deploys that replaced or deleted at least one
existing file create one) to restore those files to their pre-deploy state.

**Limitation:** rollback restores replaced/deleted files from the backup. It
does *not* remove files that were newly added by the deploy being rolled
back — clean those up manually if needed.

## Running the tests

```bash
composer test              # PHPUnit: unit tests + HTTP integration tests
composer test:coverage     # same, with a coverage report (requires Xdebug or PCOV)
npm install && npx playwright install chromium   # one-time
npm run test:e2e           # Playwright browser tests against the built receiver
```

The integration and E2E suites both run against the actual built
`receiver/dist/deploy-receiver.php`, so run `php receiver/build.php` first
if you've changed anything under `receiver/src/`.
