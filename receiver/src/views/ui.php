<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Deployer</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 16px; }
  h1 { font-size: 1.4rem; }
  .card { border: 1px solid #8884; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
  #dropzone { border: 2px dashed #8888; border-radius: 8px; padding: 32px; text-align: center; cursor: pointer; }
  #dropzone.drag { background: #4443; }
  progress { width: 100%; height: 20px; }
  table { width: 100%; border-collapse: collapse; }
  td, th { text-align: left; padding: 4px 8px; border-bottom: 1px solid #8883; }
  .error { color: #c0392b; }
  .ok { color: #27ae60; }
  button { padding: 6px 14px; cursor: pointer; }
  input[type=password] { padding: 6px; width: 100%; box-sizing: border-box; }
</style>
</head>
<body>
<h1>Deployer</h1>

<div class="card" id="login-card">
  <h2>Login</h2>
  <input type="password" id="password" placeholder="Deploy password">
  <button id="login-btn">Log in</button>
  <p class="error" id="login-error" hidden></p>
</div>

<div class="card" id="deploy-card" hidden>
  <h2>Deploy</h2>
  <div id="dropzone">Drag &amp; drop a deploy zip here, or click to choose</div>
  <input type="file" id="file-input" accept=".zip" hidden>
  <p>Upload progress</p>
  <progress id="upload-progress" value="0" max="100"></progress>
  <p>Apply progress</p>
  <progress id="apply-progress" value="0" max="100"></progress>
  <div id="result"></div>
</div>

<div class="card" id="history-card" hidden>
  <h2>Deploy history</h2>
  <table id="history-table"><thead><tr><th>Time</th><th>Changes</th><th></th></tr></thead><tbody></tbody></table>
</div>

<script>
const CHUNK_SIZE_DEFAULT = 2 * 1024 * 1024;

async function api(action, params, rawBody) {
  const url = new URL(window.location.href);
  url.searchParams.set('action', action);
  const opts = { method: 'POST' };
  if (rawBody !== undefined) {
    const qs = new URLSearchParams(params).toString();
    url.search = url.search + '&' + qs;
    opts.body = rawBody;
    opts.headers = { 'Content-Type': 'application/octet-stream' };
  } else {
    opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    opts.body = new URLSearchParams(params).toString();
  }
  const res = await fetch(url.toString(), opts);
  return res.json();
}

document.getElementById('login-btn').addEventListener('click', async () => {
  const password = document.getElementById('password').value;
  const result = await api('login', { password });
  if (result.ok) {
    document.getElementById('login-card').hidden = true;
    document.getElementById('deploy-card').hidden = false;
    document.getElementById('history-card').hidden = false;
    loadHistory();
  } else {
    const err = document.getElementById('login-error');
    err.textContent = 'Invalid password';
    err.hidden = false;
  }
});

const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('file-input');
dropzone.addEventListener('click', () => fileInput.click());
dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('drag'); });
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag'));
dropzone.addEventListener('drop', e => {
  e.preventDefault();
  dropzone.classList.remove('drag');
  if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
});
fileInput.addEventListener('change', () => {
  if (fileInput.files.length) handleFile(fileInput.files[0]);
});

async function handleFile(file) {
  const chunkSize = CHUNK_SIZE_DEFAULT;
  const totalChunks = Math.ceil(file.size / chunkSize);

  const initRes = await api('init_upload', {
    filename: file.name,
    total_size: file.size,
    chunk_size: chunkSize,
    total_chunks: totalChunks,
  });
  const deployId = initRes.deploy_id;

  const uploadProgress = document.getElementById('upload-progress');
  uploadProgress.max = totalChunks;

  for (let i = 0; i < totalChunks; i++) {
    const start = i * chunkSize;
    const end = Math.min(start + chunkSize, file.size);
    const blob = file.slice(start, end);
    const buf = await blob.arrayBuffer();
    await api('upload_chunk', { deploy_id: deployId, index: i }, buf);
    uploadProgress.value = i + 1;
  }

  await api('finalize_upload', { deploy_id: deployId });
  const extractRes = await api('extract', { deploy_id: deployId });

  const applyProgress = document.getElementById('apply-progress');
  applyProgress.max = extractRes.total || 1;

  const results = [];
  for (let i = 0; i < extractRes.total; i++) {
    const stepRes = await api('backup_and_apply_step', { deploy_id: deployId, index: i });
    results.push(stepRes);
    applyProgress.value = i + 1;
  }

  const finishRes = await api('finish', { deploy_id: deployId });

  renderResult(finishRes, results);
  loadHistory();
}

function renderResult(finishRes, stepResults) {
  const mismatches = stepResults.filter(r => r.status === 'hash_mismatch');
  const el = document.getElementById('result');
  const summary = finishRes.summary || {};
  el.innerHTML = `<p class="${mismatches.length ? 'error' : 'ok'}">
    Added: ${summary.add ?? 0}, Replaced: ${summary.replace ?? 0}, Deleted: ${summary.delete ?? 0}
    ${mismatches.length ? `, Hash mismatches: ${mismatches.length}` : ''}
  </p>`;
}

async function loadHistory() {
  const res = await api('history', {});
  const tbody = document.querySelector('#history-table tbody');
  tbody.innerHTML = '';
  (res.entries || []).slice().reverse().forEach(entry => {
    const tr = document.createElement('tr');
    const rollbackBtn = entry.backup ? `<button data-backup="${entry.backup}">Rollback</button>` : '';
    tr.innerHTML = `<td>${entry.timestamp}</td><td>+${entry.add} ~${entry.replace} -${entry.delete}</td>
      <td>${rollbackBtn}</td>`;
    tbody.appendChild(tr);
  });
  tbody.querySelectorAll('button[data-backup]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const res = await api('rollback', { backup: btn.dataset.backup });
      alert(res.ok ? `Restored ${res.restored.length} file(s)` : `Rollback failed: ${res.error}`);
    });
  });
}
</script>
</body>
</html>
