const { test, expect } = require('@playwright/test');
const path = require('path');
const { ZIP_PATH, CONTENT } = require('./build-test-zip');
const fs = require('fs');

const E2E_PASSWORD = 'e2e-test-password';
const FIXTURE_WEB_ROOT = path.join(__dirname, '.fixture');

test.afterEach(() => {
  // clean up any files the previous test deployed into the fixture web root,
  // so tests don't leak state into each other via the shared PHP server.
  const leftovers = ['e2e-hello.txt'];
  for (const f of leftovers) {
    const p = path.join(FIXTURE_WEB_ROOT, f);
    if (fs.existsSync(p)) fs.rmSync(p);
  }
});

test('rejects wrong password with a visible error', async ({ page }) => {
  await page.goto('/deploy-receiver.php');
  await page.fill('#password', 'wrong-password');
  await page.click('#login-btn');

  await expect(page.locator('#login-error')).toBeVisible();
  await expect(page.locator('#deploy-card')).toBeHidden();
});

test('logs in and completes a full deploy via drag-and-drop upload', async ({ page }) => {
  await page.goto('/deploy-receiver.php');
  await page.fill('#password', E2E_PASSWORD);
  await page.click('#login-btn');

  await expect(page.locator('#deploy-card')).toBeVisible();
  await expect(page.locator('#history-card')).toBeVisible();

  const fileInput = page.locator('#file-input');
  await fileInput.setInputFiles(ZIP_PATH);

  await expect(page.locator('#result')).toContainText('Added: 1', { timeout: 15000 });

  const uploadProgress = page.locator('#upload-progress');
  await expect(uploadProgress).toHaveJSProperty('value', await uploadProgress.evaluate(el => el.max));

  const deployedFile = path.join(FIXTURE_WEB_ROOT, 'e2e-hello.txt');
  expect(fs.existsSync(deployedFile)).toBe(true);
  expect(fs.readFileSync(deployedFile, 'utf8')).toBe(CONTENT);
});

test('deploy history lists the completed deploy with a rollback button', async ({ page }) => {
  // A rollback button only appears once a deploy actually replaces/deletes an
  // existing file (that's what gets backed up) - an add-only deploy has
  // nothing to roll back. So deploy the add-only fixture first, then deploy
  // a second zip that replaces the file it just created.
  const { execSync } = require('child_process');
  const replaceZipPath = path.join(FIXTURE_WEB_ROOT, 'replace-deploy.zip');
  const replacePhp = `<?php
$zip = new ZipArchive();
$zip->open('${replaceZipPath.replace(/\\/g, '/')}', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('manifest.json', json_encode([
  'version' => 1, 'from_ref' => 'a', 'to_ref' => 'b',
  'add' => [], 'replace' => [['path' => 'e2e-hello.txt', 'sha256' => hash('sha256', 'replaced-content')]],
  'delete' => [],
]));
$zip->addFromString('files/e2e-hello.txt', 'replaced-content');
$zip->close();
`;
  const scriptPath = path.join(FIXTURE_WEB_ROOT, '_build-replace-zip.php');
  fs.writeFileSync(scriptPath, replacePhp);
  execSync(`php ${JSON.stringify(scriptPath)}`, { stdio: 'inherit' });
  fs.rmSync(scriptPath);

  await page.goto('/deploy-receiver.php');
  await page.fill('#password', E2E_PASSWORD);
  await page.click('#login-btn');

  await page.locator('#file-input').setInputFiles(ZIP_PATH);
  await expect(page.locator('#result')).toContainText('Added: 1', { timeout: 15000 });

  await page.reload();
  await page.fill('#password', E2E_PASSWORD);
  await page.click('#login-btn');
  await page.locator('#file-input').setInputFiles(replaceZipPath);
  await expect(page.locator('#result')).toContainText('Replaced: 1', { timeout: 15000 });

  await page.reload();
  await page.fill('#password', E2E_PASSWORD);
  await page.click('#login-btn');

  const historyRows = page.locator('#history-table tbody tr');
  await expect(historyRows.first()).toBeVisible();
  await expect(historyRows.first().locator('button')).toHaveText('Rollback');

  fs.rmSync(replaceZipPath, { force: true });
});

test('a hash mismatch surfaces a visible error instead of silently succeeding', async ({ page }) => {
  const badZipPath = path.join(FIXTURE_WEB_ROOT, 'bad-deploy.zip');
  const { execSync } = require('child_process');
  const php = `<?php
$zip = new ZipArchive();
$zip->open('${badZipPath.replace(/\\/g, '/')}', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('manifest.json', json_encode([
  'version' => 1, 'from_ref' => 'a', 'to_ref' => 'b',
  'add' => [['path' => 'corrupt.txt', 'sha256' => 'deliberately-wrong-hash']],
  'replace' => [], 'delete' => [],
]));
$zip->addFromString('files/corrupt.txt', 'actual-content');
$zip->close();
`;
  const scriptPath = path.join(FIXTURE_WEB_ROOT, '_build-bad-zip.php');
  fs.writeFileSync(scriptPath, php);
  execSync(`php ${JSON.stringify(scriptPath)}`, { stdio: 'inherit' });
  fs.rmSync(scriptPath);

  await page.goto('/deploy-receiver.php');
  await page.fill('#password', E2E_PASSWORD);
  await page.click('#login-btn');

  await page.locator('#file-input').setInputFiles(badZipPath);

  await expect(page.locator('#result .error')).toContainText('Hash mismatches: 1', { timeout: 15000 });

  fs.rmSync(badZipPath);
  fs.rmSync(path.join(FIXTURE_WEB_ROOT, 'corrupt.txt'), { force: true });
});
