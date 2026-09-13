// Builds a small deploy zip fixture used by the E2E tests (via PHP's ZipArchive,
// so we don't need a Node zip dependency).

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const FIXTURE_DIR = path.join(__dirname, '.fixture');
const ZIP_PATH = path.join(FIXTURE_DIR, 'test-deploy.zip');

fs.mkdirSync(FIXTURE_DIR, { recursive: true });

const CONTENT = 'e2e-hello-' + Date.now();

const php = `<?php
$zip = new ZipArchive();
$rc = $zip->open('${ZIP_PATH.replace(/\\/g, '/')}', ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($rc !== true) { fwrite(STDERR, "zip open failed: $rc\\n"); exit(1); }
$zip->addFromString('manifest.json', json_encode([
  'version' => 1, 'from_ref' => 'a', 'to_ref' => 'b',
  'add' => [['path' => 'e2e-hello.txt', 'sha256' => hash('sha256', '${CONTENT}')]],
  'replace' => [], 'delete' => [],
]));
$zip->addFromString('files/e2e-hello.txt', '${CONTENT}');
$zip->close();
echo 'ok';
`;

const scriptPath = path.join(FIXTURE_DIR, '_build-zip.php');
fs.writeFileSync(scriptPath, php);
execSync(`php ${JSON.stringify(scriptPath)}`, { stdio: 'inherit' });
fs.rmSync(scriptPath);

module.exports = { ZIP_PATH, CONTENT };

if (require.main === module) {
  console.log('Built test zip at', ZIP_PATH, 'with content', CONTENT);
}
