// Prepares a runnable copy of the built receiver for Playwright E2E tests:
// copies receiver/dist/deploy-receiver.php into tests/e2e/.fixture/ and
// fills in known test credentials (CHANGE_ME placeholders) so the E2E
// suite can log in and deploy without touching the real build output.

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const DIST_PATH = path.join(ROOT, 'receiver', 'dist', 'deploy-receiver.php');
const FIXTURE_DIR = path.join(__dirname, '.fixture');

const E2E_TOKEN = 'e2e-test-token';
const E2E_PASSWORD = 'e2e-test-password';

if (!fs.existsSync(DIST_PATH)) {
  console.error('Missing receiver/dist/deploy-receiver.php - run `php receiver/build.php` first.');
  process.exit(1);
}

fs.mkdirSync(FIXTURE_DIR, { recursive: true });

let code = fs.readFileSync(DIST_PATH, 'utf8');

const tokenHash = execSync(`php -r "echo hash('sha256', '${E2E_TOKEN}');"`).toString().trim();
const passwordHash = execSync(`php -r "echo password_hash('${E2E_PASSWORD}', PASSWORD_DEFAULT);"`).toString().trim();

code = code.replace("const TOKEN_HASH = 'CHANGE_ME';", `const TOKEN_HASH = '${tokenHash}';`);
code = code.replace("const PASSWORD_HASH = 'CHANGE_ME';", `const PASSWORD_HASH = '${passwordHash.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}';`);

fs.writeFileSync(path.join(FIXTURE_DIR, 'deploy-receiver.php'), code);

console.log('E2E fixture prepared at', FIXTURE_DIR);
console.log('Password:', E2E_PASSWORD);
