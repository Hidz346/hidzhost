const path = require('path');
const crypto = require('crypto');
const express = require('express');
const multer = require('multer');
const AdmZip = require('adm-zip');
const cookieSession = require('cookie-session');
const { put, list, del } = require('@vercel/blob');
require('dotenv').config();

const app = express();
const PORT = Number(process.env.PORT || 3000);
const BASE_DOMAIN = String(process.env.BASE_DOMAIN || 'hidzproject.my.id').toLowerCase();
const HOST_PREFIX = String(process.env.HOST_PREFIX || 'h').toLowerCase();
const PUBLIC_BASE_URL = String(process.env.PUBLIC_BASE_URL || `https://${HOST_PREFIX}.${BASE_DOMAIN}`).replace(/\/$/, '');
const SESSION_SECRET = process.env.SESSION_SECRET || crypto.randomBytes(48).toString('hex');
const MAX_UPLOAD_MB = Math.min(4, Math.max(1, Number(process.env.MAX_UPLOAD_MB || 4)));
const MAX_UPLOAD_BYTES = MAX_UPLOAD_MB * 1024 * 1024;
const BLOB_STORE_ID = process.env.BLOB_STORE_ID || '';
const BLOB_READ_WRITE_TOKEN = process.env.BLOB_READ_WRITE_TOKEN || '';
const VERCEL_OIDC_TOKEN = process.env.VERCEL_OIDC_TOKEN || '';

// Vercel's current Blob connection model can authenticate with OIDC + store ID.
// Keep static-token support as a fallback for older/manual Blob connections.
function blobAuthOptions() {
  const options = {};
  if (BLOB_STORE_ID) options.storeId = BLOB_STORE_ID;
  if (VERCEL_OIDC_TOKEN) options.oidcToken = VERCEL_OIDC_TOKEN;
  if (BLOB_READ_WRITE_TOKEN) options.token = BLOB_READ_WRITE_TOKEN;
  return options;
}
const INDEX_FILE = path.join(__dirname, 'index.html');
const MANAGER_FILE = path.join(__dirname, 'file_manager.html');

app.disable('x-powered-by');
app.set('trust proxy', 1);
app.use(express.json({ limit: `${MAX_UPLOAD_MB}mb` }));
app.use(express.urlencoded({ extended: true, limit: `${MAX_UPLOAD_MB}mb` }));
app.use(cookieSession({
  name: 'hidzhost_session',
  keys: [SESSION_SECRET],
  httpOnly: true,
  sameSite: 'lax',
  secure: process.env.NODE_ENV === 'production',
  maxAge: 1000 * 60 * 60 * 12
}));

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: MAX_UPLOAD_BYTES }
});

function sendJson(res, status, payload) {
  return res.status(status).json(payload);
}

function normalizeSlug(value) {
  return String(value || '')
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 63);
}

function randomSlug() {
  return crypto.randomBytes(4).toString('hex');
}

function validateSlug(slug) {
  return /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(slug);
}

function siteUrl(slug) {
  return `https://${slug}.${HOST_PREFIX}.${BASE_DOMAIN}`;
}

function managerUrl(slug) {
  return `${PUBLIC_BASE_URL}/file-manager?siteId=${encodeURIComponent(slug)}`;
}

function hostnameSlug(hostname) {
  const host = String(hostname || '').split(',')[0].trim().split(':')[0].toLowerCase().replace(/\.$/, '');
  const base = `${HOST_PREFIX}.${BASE_DOMAIN}`;
  const suffix = `.${base}`;
  if (host === base || !host.endsWith(suffix)) return null;
  const slug = host.slice(0, -suffix.length);
  return validateSlug(slug) ? slug : null;
}

function requestHostname(req) {
  const forwarded = req.headers['x-forwarded-host'];
  const host = Array.isArray(forwarded) ? forwarded[0] : (forwarded || req.headers.host || req.hostname);
  return String(host || '').split(',')[0].trim();
}

function safeRelativePath(value) {
  const raw = String(value || '').replace(/\\/g, '/');
  if (!raw || raw.startsWith('/') || raw.includes('\0')) return null;
  const parts = raw.split('/').filter(Boolean);
  if (!parts.length || parts.some(part => part === '.' || part === '..')) return null;
  return parts.join('/');
}

function cleanUploadedPath(input, fallback) {
  const raw = String(input || fallback || '').replace(/\\/g, '/');
  const parts = raw.split('/').filter(Boolean).filter(part => part !== '.' && part !== '..');
  return parts.length ? parts.join('/') : null;
}

function blobKey(slug, relative) {
  return `sites/${slug}/${relative}`;
}

function metadataKey(slug) {
  return `sites/${slug}/.hidzhost.json`;
}

async function findBlob(pathname) {
  const result = await list({ prefix: pathname, limit: 10, ...blobAuthOptions() });
  return result.blobs.find(blob => blob.pathname === pathname) || result.blobs[0] || null;
}

async function readBlobBuffer(blob) {
  const response = await fetch(blob.url);
  if (!response.ok) throw new Error(`Blob read failed: ${response.status}`);
  return Buffer.from(await response.arrayBuffer());
}

async function readMetadata(slug) {
  const blob = await findBlob(metadataKey(slug));
  if (!blob) return null;
  try {
    return JSON.parse((await readBlobBuffer(blob)).toString('utf8'));
  } catch (_) {
    return null;
  }
}

async function writeMetadata(slug, password) {
  const existing = await readMetadata(slug);
  const salt = crypto.randomBytes(16);
  const digest = crypto.scryptSync(String(password), salt, 64).toString('hex');
  const payload = {
    siteId: slug,
    created_at: existing?.created_at || new Date().toISOString(),
    updated_at: new Date().toISOString(),
    password_hash: `scrypt$${salt.toString('hex')}$${digest}`
  };
  await put(metadataKey(slug), JSON.stringify(payload), {
    access: 'public',
    ...blobAuthOptions(),
    contentType: 'application/json',
    addRandomSuffix: false,
    allowOverwrite: true,
    cacheControlMaxAge: 0
  });
}

function verifyHash(password, stored) {
  if (!stored) return false;
  if (stored.startsWith('scrypt$')) {
    const [, salt, digest] = stored.split('$');
    try {
      const derived = crypto.scryptSync(String(password), Buffer.from(salt, 'hex'), 64).toString('hex');
      return crypto.timingSafeEqual(Buffer.from(derived), Buffer.from(digest));
    } catch (_) {
      return false;
    }
  }
  if (/^[a-f0-9]{64}$/i.test(stored)) {
    return crypto.timingSafeEqual(
      Buffer.from(crypto.createHash('sha256').update(String(password)).digest('hex')),
      Buffer.from(stored)
    );
  }
  return false;
}

async function verifyPassword(slug, password) {
  const meta = await readMetadata(slug);
  return !!meta && verifyHash(password, meta.password_hash);
}

async function siteExists(slug) {
  return !!(await findBlob(metadataKey(slug)));
}

async function ensureSite(slug, password) {
  if (await siteExists(slug)) {
    return { ok: false, error: 'Slug "' + slug + '" sudah digunakan. Silakan pilih Custom Slug lain.' };
  }
  await writeMetadata(slug, password);
  return { ok: true };
}

async function makeUniqueSlug(preferred) {
  const base = normalizeSlug(preferred) || randomSlug();
  let candidate = base;
  let counter = 2;
  while (await siteExists(candidate)) {
    candidate = `${base}-${counter}`;
    counter += 1;
  }
  return candidate;
}

function getMime(filePath) {
  const ext = path.extname(filePath).toLowerCase();
  const map = {
    '.html': 'text/html; charset=utf-8', '.htm': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8',
    '.mjs': 'application/javascript; charset=utf-8', '.json': 'application/json; charset=utf-8',
    '.txt': 'text/plain; charset=utf-8', '.xml': 'application/xml; charset=utf-8',
    '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
    '.gif': 'image/gif', '.webp': 'image/webp', '.ico': 'image/x-icon', '.pdf': 'application/pdf',
    '.mp3': 'audio/mpeg', '.wav': 'audio/wav', '.ogg': 'audio/ogg', '.mp4': 'video/mp4',
    '.webm': 'video/webm', '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf',
    '.zip': 'application/zip', '.csv': 'text/csv; charset=utf-8', '.md': 'text/markdown; charset=utf-8'
  };
  return map[ext] || 'application/octet-stream';
}

function fileType(filePath) {
  const ext = path.extname(filePath).slice(1).toLowerCase();
  const image = new Set(['png','jpg','jpeg','gif','webp','bmp','ico','svg','avif','tiff','tif']);
  const audio = new Set(['mp3','wav','ogg','m4a','flac','aac']);
  const video = new Set(['mp4','webm','avi','mov','flv','mkv']);
  const text = new Set(['html','htm','css','js','mjs','cjs','ts','tsx','jsx','scss','sass','less','vue','json','xml','yaml','yml','toml','csv','md','txt','log','conf','env','htaccess','sql','sh','bat','c','cpp','h','java','rs','py','rb','go','php','ini','kt','swift','dart','pl']);
  if (image.has(ext)) return 'image';
  if (audio.has(ext)) return 'audio';
  if (video.has(ext)) return 'video';
  if (text.has(ext)) return 'text';
  return 'binary';
}

// Ekstensi ini tetap dianggap "aset website" walau bukan html — browser/halaman
// lain mungkin benar-benar fetch/pakai isinya apa adanya, jadi jangan dibungkus
// jadi halaman kode.
const WEB_ASSET_EXTS = new Set(['html', 'htm', 'css', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'scss', 'sass', 'less', 'vue', 'json', 'xml', 'svg']);

// File teks/kode yang tidak mungkin jalan sebagai website statis (mis. .php, .py,
// .sql, dll) — ini yang perlu ditampilkan sebagai halaman kode + salin/download,
// bukan diunduh mentah sebagai octet-stream atau 404.
function isCodeViewOnly(filePath) {
  return fileType(filePath) === 'text' && !WEB_ASSET_EXTS.has(path.extname(filePath).slice(1).toLowerCase());
}

function iconFor(name) {
  const ext = path.extname(name).slice(1).toLowerCase();
  const icons = { html:'🌐',htm:'🌐',css:'🎨',scss:'🎨',sass:'🎨',less:'🎨',js:'⚡',mjs:'⚡',cjs:'⚡',ts:'⚡',tsx:'⚡',jsx:'⚡',vue:'⚡',json:'📋',yaml:'📋',yml:'📋',xml:'📋',toml:'📋',csv:'📊',md:'📝',txt:'📝',log:'📝',jpg:'🖼️',jpeg:'🖼️',png:'🖼️',gif:'🖼️',webp:'🖼️',ico:'🖼️',bmp:'🖼️',avif:'🖼️',svg:'🖼️',tiff:'🖼️',tif:'🖼️',pdf:'📕',doc:'📘',docx:'📘',xls:'📗',xlsx:'📗',ppt:'📙',pptx:'📙',mp4:'🎬',webm:'🎬',mov:'🎬',mkv:'🎬',avi:'🎬',flv:'🎬',mp3:'🎵',wav:'🎵',ogg:'🎵',flac:'🎵',m4a:'🎵',aac:'🎵',zip:'📦',tar:'📦',gz:'📦',rar:'📦','7z':'📦',sql:'🗄️',db:'🗄️',sqlite:'🗄️',py:'💻',rb:'💻',go:'💻',sh:'💻',bat:'💻',rs:'💻',c:'💻',cpp:'💻',h:'💻',php:'💻',kt:'💻',swift:'💻',dart:'💻',pl:'💻',ini:'⚙️',jar:'☕',war:'☕',java:'☕',dll:'⚙️',conf:'⚙️',htaccess:'⚙️',ttf:'🔤',woff:'🔤',woff2:'🔤',otf:'🔤',env:'🔒' };
  return icons[ext] || '📄';
}

async function listSiteFiles(slug) {
  const prefix = `sites/${slug}/`;
  const blobs = [];
  let cursor;
  do {
    const result = await list({ prefix, cursor, limit: 1000, ...blobAuthOptions() });
    blobs.push(...result.blobs);
    cursor = result.hasMore ? result.cursor : undefined;
  } while (cursor);
  return blobs.filter(blob => !blob.pathname.endsWith('/.hidzhost.json'));
}

function buildTree(blobs) {
  const root = [];
  const dirs = new Map();
  for (const blob of blobs) {
    const relative = blob.pathname.replace(/^sites\/[^/]+\//, '');
    const parts = relative.split('/').filter(Boolean);
    if (!parts.length) continue;
    let current = root;
    let currentPath = '';
    parts.forEach((part, index) => {
      currentPath = currentPath ? `${currentPath}/${part}` : part;
      const isLast = index === parts.length - 1;
      if (isLast) {
        current.push({ name: part, path: currentPath, is_dir: false, size: blob.size || 0, icon: iconFor(part) });
        return;
      }
      let dir = dirs.get(currentPath);
      if (!dir) {
        dir = { name: part, path: currentPath, is_dir: true, children: [] };
        dirs.set(currentPath, dir);
        current.push(dir);
      }
      current = dir.children;
    });
  }
  const sortTree = items => items.sort((a, b) => a.is_dir !== b.is_dir ? (a.is_dir ? -1 : 1) : a.name.localeCompare(b.name));
  const walk = items => { sortTree(items); items.filter(x => x.is_dir).forEach(x => walk(x.children)); };
  walk(root);
  return root;
}

async function putSiteFile(slug, relative, content) {
  const clean = safeRelativePath(relative);
  if (!clean) throw new Error('Nama berkas tidak valid.');
  return put(blobKey(slug, clean), content, {
    access: 'public',
    ...blobAuthOptions(),
    contentType: getMime(clean),
    addRandomSuffix: false,
    allowOverwrite: true,
    cacheControlMaxAge: 0
  });
}

async function deleteSitePath(slug, relative) {
  const clean = safeRelativePath(relative);
  if (!clean) return false;
  const prefix = `${blobKey(slug, clean)}`;
  const result = await list({ prefix, limit: 1000, ...blobAuthOptions() });
  const exact = result.blobs.filter(blob => blob.pathname === prefix || blob.pathname.startsWith(`${prefix}/`));
  if (exact.length) await del(exact.map(blob => blob.url), blobAuthOptions());
  return exact.length > 0;
}

async function extractZipBuffer(buffer, slug) {
  const zip = new AdmZip(buffer);
  const entries = zip.getEntries().filter(entry => {
    const name = String(entry.entryName || '').replace(/\\/g, '/');
    return name && !name.startsWith('__MACOSX/') && !name.includes('.DS_Store') && !name.split('/').includes('..');
  });
  let prefix = '';
  const firstFile = entries.find(entry => !entry.isDirectory);
  if (firstFile) {
    const parts = firstFile.entryName.replace(/\\/g, '/').split('/');
    if (parts.length > 1) {
      const candidate = `${parts[0]}/`;
      if (entries.every(entry => entry.entryName.replace(/\\/g, '/').startsWith(candidate))) prefix = candidate;
    }
  }
  let count = 0;
  for (const entry of entries) {
    if (entry.isDirectory) continue;
    let relative = entry.entryName.replace(/\\/g, '/');
    if (prefix && relative.startsWith(prefix)) relative = relative.slice(prefix.length);
    const clean = safeRelativePath(relative);
    if (!clean) continue;
    await putSiteFile(slug, clean, entry.getData());
    count++;
  }
  return count;
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatBytes(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

function renderCodeViewerPage({ filename, code, size, icon, downloadHref }) {
  const safeName = escapeHtml(filename);
  const safeCodeJson = JSON.stringify(code);
  const lineCount = code.split('\n').length;
  return `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>${safeName} — HidzHost</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #FFF5F9; --card-bg: #FFFFFF; --text-dark: #1a1a2e;
    --yellow: #FFE600; --lime: #BFFF00; --pink: #FF2D78;
    --border: 3px solid #1a1a2e; --shadow: 6px 6px 0px #1a1a2e; --radius: 14px;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text-dark); font-family: 'JetBrains Mono', monospace; padding: 16px; }
  .bar { background: var(--yellow); border: var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
         padding: 14px 18px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .bar .name { font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 16px; word-break: break-all; }
  .bar .meta { font-size: 12px; opacity: 0.75; margin-left: auto; }
  .actions { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
  .btn { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 14px; cursor: pointer;
         border: var(--border); border-radius: 10px; box-shadow: 4px 4px 0px #1a1a2e; padding: 10px 18px;
         background: var(--card-bg); color: var(--text-dark); text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
         transition: transform 0.1s; }
  .btn:active { transform: translate(2px, 2px); box-shadow: 2px 2px 0px #1a1a2e; }
  .btn.copy { background: var(--lime); }
  .btn.dl { background: var(--pink); color: #fff; }
  pre { background: var(--card-bg); border: var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
        padding: 18px; overflow-x: auto; margin: 0; }
  code { font-family: 'JetBrains Mono', monospace; font-size: 13px; line-height: 1.6; white-space: pre; }
  footer { text-align: center; font-size: 11px; opacity: 0.5; margin-top: 20px; font-family: 'Space Grotesk', sans-serif; }
</style>
</head>
<body>
  <div class="bar">
    <span>${icon}</span>
    <span class="name">${safeName}</span>
    <span class="meta">${formatBytes(size)} · ${lineCount} baris</span>
  </div>
  <div class="actions">
    <button class="btn copy" id="copyBtn" onclick="copyCode()">📋 Salin Kode</button>
    <a class="btn dl" href="${downloadHref}" download="${safeName}">⬇️ Download</a>
  </div>
  <pre><code id="codeBlock"></code></pre>
  <footer>Disajikan oleh HidzHost — berkas ini tidak bisa dijalankan sebagai website, jadi ditampilkan sebagai kode.</footer>
  <script id="code-data" type="application/json">${safeCodeJson}</script>
  <script>
    const codeText = JSON.parse(document.getElementById('code-data').textContent);
    document.getElementById('codeBlock').textContent = codeText;
    function copyCode() {
      navigator.clipboard.writeText(codeText).then(() => {
        const btn = document.getElementById('copyBtn');
        const original = btn.innerHTML;
        btn.innerHTML = '✅ Tersalin!';
        setTimeout(() => { btn.innerHTML = original; }, 1500);
      });
    }
  </script>
</body>
</html>`;
}

async function requireManagerAuth(req, res, next) {
  const slug = req.session?.siteId;
  if (!slug || !validateSlug(slug) || !(await siteExists(slug))) {
    return sendJson(res, 401, { error: 'Sesi pengelola berkas tidak ditemukan. Silakan masuk kembali.' });
  }
  req.siteId = slug;
  next();
}

app.post('/api/deploy', async (req, res) => {
  try {
    const { siteId, password, files } = req.body || {};
    const slug = normalizeSlug(siteId);
    if (!slug || !validateSlug(slug)) return sendJson(res, 400, { error: 'Nama website tidak valid.' });
    if (!password || String(password).length < 4) return sendJson(res, 400, { error: 'Kata sandi minimal 4 karakter.' });
    if (!Array.isArray(files) || !files.length) return sendJson(res, 400, { error: 'Tidak ada berkas untuk ditayangkan.' });
    const result = await ensureSite(slug, password);
    if (!result.ok) return sendJson(res, 409, { error: result.error });
    let written = 0;
    for (const file of files) {
      const relative = safeRelativePath(file.path);
      if (!relative) continue;
      const content = file.encoding === 'base64' ? Buffer.from(String(file.content || ''), 'base64') : Buffer.from(String(file.content || ''), 'utf8');
      if (content.length > MAX_UPLOAD_BYTES) return sendJson(res, 413, { error: `Berkas melebihi batas ${MAX_UPLOAD_MB} MB.` });
      await putSiteFile(slug, relative, content);
      written++;
    }
    return res.json({ success: true, siteId: slug, files_written: written, url: siteUrl(slug), managerUrl: managerUrl(slug) });
  } catch (error) {
    console.error(error);
    return sendJson(res, 500, { error: 'Gagal menayangkan website.' });
  }
});

app.post('/api/file-deploy', upload.array('files'), async (req, res) => {
  try {
    const slug = normalizeSlug(req.body.siteId);
    const password = String(req.body.password || '');
    if (!slug || !validateSlug(slug)) return sendJson(res, 400, { error: 'Nama website tidak valid.' });
    if (password.length < 4) return sendJson(res, 400, { error: 'Kata sandi minimal 4 karakter.' });
    if (!req.files?.length) return sendJson(res, 400, { error: 'Tidak ada berkas yang dipilih.' });
    const result = await ensureSite(slug, password);
    if (!result.ok) return sendJson(res, 409, { error: result.error });
    const paths = Array.isArray(req.body.paths) ? req.body.paths : (req.body.paths ? [req.body.paths] : []);
    let uploaded = 0;
    let failed = 0;
    for (const [index, file] of req.files.entries()) {
      const relative = cleanUploadedPath(paths[index], file.originalname);
      if (!relative) { failed++; continue; }
      try { await putSiteFile(slug, relative, file.buffer); uploaded++; } catch (_) { failed++; }
    }
    if (!uploaded) return sendJson(res, 400, { error: 'Tidak ada berkas yang berhasil diunggah.' });
    return res.json({ success: true, siteId: slug, files_uploaded: uploaded, files_failed: failed, url: siteUrl(slug), managerUrl: managerUrl(slug) });
  } catch (error) {
    console.error(error);
    return sendJson(res, 500, { error: 'Gagal mengunggah berkas.' });
  }
});

app.post('/api/zip-deploy', upload.single('zipfile'), async (req, res) => {
  try {
    const slug = normalizeSlug(req.body.siteId);
    const password = String(req.body.password || '');
    if (!slug || !validateSlug(slug)) return sendJson(res, 400, { error: 'Nama website tidak valid.' });
    if (password.length < 4) return sendJson(res, 400, { error: 'Kata sandi minimal 4 karakter.' });
    if (!req.file) return sendJson(res, 400, { error: 'Berkas ZIP tidak ditemukan.' });
    const result = await ensureSite(slug, password);
    if (!result.ok) return sendJson(res, 409, { error: result.error });
    const count = await extractZipBuffer(req.file.buffer, slug);
    return res.json({ success: true, siteId: slug, files_extracted: count, url: siteUrl(slug), managerUrl: managerUrl(slug) });
  } catch (error) {
    console.error(error);
    return sendJson(res, 400, { error: 'Berkas ZIP tidak dapat diproses.' });
  }
});

app.post('/manager/login', async (req, res) => {
  const slug = normalizeSlug(req.body.site_id);
  const password = String(req.body.password || '');
  if (!slug || !password) return sendJson(res, 400, { error: 'Nama website dan kata sandi wajib diisi.' });
  if (!(await siteExists(slug))) return sendJson(res, 404, { error: 'Website tidak ditemukan.' });
  if (!(await verifyPassword(slug, password))) return sendJson(res, 401, { error: 'Kata sandi tidak sesuai.' });
  req.session.siteId = slug;
  return res.json({ success: true, siteId: slug });
});

app.post('/manager/logout', (req, res) => { req.session = null; res.json({ success: true }); });

app.get('/manager/session', async (req, res) => {
  const slug = req.session?.siteId;
  if (!slug || !validateSlug(slug) || !(await siteExists(slug))) return res.json({ authenticated: false });
  res.json({ authenticated: true, siteId: slug, siteUrl: siteUrl(slug) });
});

app.get('/manager/api/tree', requireManagerAuth, async (req, res) => {
  try { res.json({ tree: buildTree(await listSiteFiles(req.siteId)) }); }
  catch (error) { console.error(error); sendJson(res, 500, { error: 'Gagal memuat daftar berkas.' }); }
});

app.get('/manager/api/get-file', requireManagerAuth, async (req, res) => {
  const relative = safeRelativePath(req.query.file);
  if (!relative) return sendJson(res, 400, { error: 'Nama berkas tidak valid.' });
  try {
    const blob = await findBlob(blobKey(req.siteId, relative));
    if (!blob || blob.pathname !== blobKey(req.siteId, relative)) return sendJson(res, 404, { error: 'Berkas tidak ditemukan.' });
    const type = fileType(relative);
    const mime = getMime(relative);
    const buffer = await readBlobBuffer(blob);
    if (type === 'image') return res.json({ success: true, filename: relative, file_type: type, mime, content: buffer.toString('base64'), size: buffer.length, ext: path.extname(relative).slice(1).toLowerCase() });
    if (type === 'text') return res.json({ success: true, filename: relative, file_type: type, mime, content: buffer.toString('utf8'), size: buffer.length, ext: path.extname(relative).slice(1).toLowerCase() });
    return res.json({ success: true, filename: relative, file_type: type, mime, content: '', size: buffer.length, ext: path.extname(relative).slice(1).toLowerCase() });
  } catch (error) { console.error(error); sendJson(res, 500, { error: 'Gagal membaca berkas.' }); }
});

app.post('/manager/api/save-file', requireManagerAuth, async (req, res) => {
  const relative = safeRelativePath(req.body?.file);
  if (!relative || path.basename(relative) === '.hidzhost.json') return sendJson(res, 403, { error: 'Berkas keamanan dilindungi.' });
  try {
    const blob = await findBlob(blobKey(req.siteId, relative));
    if (!blob || blob.pathname !== blobKey(req.siteId, relative)) return sendJson(res, 404, { error: 'Berkas tidak ditemukan.' });
    await putSiteFile(req.siteId, relative, Buffer.from(String(req.body?.content || ''), 'utf8'));
    res.json({ success: true });
  } catch (error) { console.error(error); sendJson(res, 500, { error: 'Gagal menyimpan berkas.' }); }
});

app.post('/manager/api/create-file', requireManagerAuth, async (req, res) => {
  const relative = safeRelativePath(req.body?.filename);
  if (!relative) return sendJson(res, 400, { error: 'Nama berkas tidak valid.' });
  try {
    if (await findBlob(blobKey(req.siteId, relative))) return sendJson(res, 409, { error: 'Berkas dengan nama tersebut sudah ada.' });
    await putSiteFile(req.siteId, relative, Buffer.alloc(0));
    res.json({ success: true });
  } catch (error) { console.error(error); sendJson(res, 500, { error: 'Gagal membuat berkas.' }); }
});

app.post('/manager/api/upload-file', requireManagerAuth, upload.single('file'), async (req, res) => {
  try {
    if (!req.file) return sendJson(res, 400, { error: 'Tidak ada berkas yang dipilih.' });
    const folder = String(req.body?.path || '').replace(/\\/g, '/');
    const relativeFolder = folder ? safeRelativePath(folder) : '';
    if (folder && !relativeFolder) return sendJson(res, 400, { error: 'Lokasi folder tidak valid.' });
    const relative = relativeFolder ? `${relativeFolder}/${path.basename(req.file.originalname)}` : path.basename(req.file.originalname);
    const clean = safeRelativePath(relative);
    if (!clean) return sendJson(res, 400, { error: 'Nama berkas tidak valid.' });
    const existing = await findBlob(blobKey(req.siteId, clean));
    if (existing && req.body?.overwrite !== '1') return sendJson(res, 409, { error: 'exists', needs_confirm: true, filename: clean });
    await putSiteFile(req.siteId, clean, req.file.buffer);
    res.json({ success: true, filename: clean });
  } catch (error) { console.error(error); sendJson(res, 500, { error: 'Gagal menyimpan berkas.' }); }
});

app.post('/manager/api/delete-file', requireManagerAuth, async (req, res) => {
  const relative = safeRelativePath(req.body?.file);
  if (!relative) return sendJson(res, 400, { error: 'Izin menghapus ditolak.' });
  try {
    if (!(await deleteSitePath(req.siteId, relative))) return sendJson(res, 404, { error: 'Berkas tidak ditemukan.' });
    res.json({ success: true });
  } catch (error) { console.error(error); sendJson(res, 500, { error: 'Gagal menghapus berkas.' }); }
});

app.get('/manager/api/download-file', requireManagerAuth, async (req, res) => {
  const relative = safeRelativePath(req.query.file);
  if (!relative) return res.status(400).send('Nama berkas tidak valid.');
  try {
    const blob = await findBlob(blobKey(req.siteId, relative));
    if (!blob || blob.pathname !== blobKey(req.siteId, relative)) return res.status(404).send('Berkas tidak ditemukan.');
    const response = await fetch(blob.url);
    if (!response.ok) return res.status(404).send('Berkas tidak ditemukan.');
    res.setHeader('Content-Type', blob.contentType || getMime(relative));
    res.setHeader('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(path.basename(relative))}`);
    res.setHeader('Cache-Control', 'no-cache');
    res.end(Buffer.from(await response.arrayBuffer()));
  } catch (error) { console.error(error); res.status(500).send('Gagal mengunduh berkas.'); }
});

app.get('/file-manager', (req, res) => res.sendFile(MANAGER_FILE));
app.get('/file_manager.html', (req, res) => res.sendFile(MANAGER_FILE));

app.use(async (req, res, next) => {
  const slug = hostnameSlug(requestHostname(req));
  if (!slug) return next();
  try {
    if (!(await siteExists(slug))) return res.status(404).send('Website tidak ditemukan.');
    let relative = decodeURIComponent(req.path).replace(/^\/+/, '');
    if (!relative) relative = 'index.html';
    const clean = safeRelativePath(relative);
    if (!clean) return res.status(400).send('Path tidak valid.');
    const blob = await findBlob(blobKey(slug, clean));
    if (blob && blob.pathname === blobKey(slug, clean)) {
      const response = await fetch(blob.url);
      if (!response.ok) return res.status(404).send('Berkas tidak ditemukan.');
      const buffer = Buffer.from(await response.arrayBuffer());
      const wantsRaw = req.query.raw !== undefined;
      if (!wantsRaw && isCodeViewOnly(clean) && buffer.length <= 2 * 1024 * 1024) {
        // Berkas tidak bisa jadi website (mis. .php/.py/.sql/dll): tampilkan sebagai
        // halaman kode dengan tombol salin & download, bukan diunduh mentah.
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.setHeader('Cache-Control', 'no-cache');
        return res.send(renderCodeViewerPage({
          filename: clean,
          code: buffer.toString('utf8'),
          size: blob.size,
          icon: iconFor(clean),
          downloadHref: `/${clean.split('/').map(encodeURIComponent).join('/')}?raw=1`
        }));
      }
      res.setHeader('Content-Type', blob.contentType || getMime(clean));
      if (wantsRaw && isCodeViewOnly(clean)) {
        res.setHeader('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(path.basename(clean))}`);
      }
      res.setHeader('Cache-Control', 'public, max-age=0, s-maxage=31536000, stale-while-revalidate=86400');
      return res.end(buffer);
    }
    if (!path.extname(clean)) {
      const indexBlob = await findBlob(blobKey(slug, 'index.html'));
      if (indexBlob && indexBlob.pathname === blobKey(slug, 'index.html')) {
        const response = await fetch(indexBlob.url);
        if (response.ok) {
          res.setHeader('Content-Type', indexBlob.contentType || 'text/html; charset=utf-8');
          res.setHeader('Cache-Control', 'public, max-age=0, s-maxage=31536000, stale-while-revalidate=86400');
          return res.end(Buffer.from(await response.arrayBuffer()));
        }
      }
    }
    if (req.path === '/') {
      // Situs tanpa index.html (mis. hasil upload 1 file tunggal: foto/video/docx/kode/dll).
      const files = await listSiteFiles(slug);
      if (files.length === 1) {
        const only = files[0];
        const relPath = only.pathname.slice(blobKey(slug, '').length);
        const ext = path.extname(relPath).slice(1).toLowerCase();
        const response = await fetch(only.url);
        if (response.ok) {
          const buffer = Buffer.from(await response.arrayBuffer());
          if (ext === 'html' || ext === 'htm') {
            // Tetap bisa jadi website kalau memang berupa halaman HTML.
            res.setHeader('Content-Type', only.contentType || getMime(relPath));
            res.setHeader('Cache-Control', 'public, max-age=0, s-maxage=31536000, stale-while-revalidate=86400');
            return res.end(buffer);
          }
          if (isCodeViewOnly(relPath) && buffer.length <= 2 * 1024 * 1024) {
            // Berkas kode/teks yang tidak bisa jadi website: tampilkan sebagai kode + tombol salin/download.
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.setHeader('Cache-Control', 'no-cache');
            return res.send(renderCodeViewerPage({
              filename: relPath,
              code: buffer.toString('utf8'),
              size: only.size,
              icon: iconFor(relPath),
              downloadHref: `/${relPath.split('/').map(encodeURIComponent).join('/')}?raw=1`
            }));
          }
          // Gambar/video/audio/dokumen/binary lain: sajikan langsung apa adanya.
          res.setHeader('Content-Type', only.contentType || getMime(relPath));
          res.setHeader('Cache-Control', 'public, max-age=0, s-maxage=31536000, stale-while-revalidate=86400');
          return res.end(buffer);
        }
      }
    }
    return res.status(404).send('Berkas tidak ditemukan.');
  } catch (error) {
    console.error(error);
    return res.status(500).send('Gagal memuat website.');
  }
});

app.get('/', (req, res) => res.sendFile(INDEX_FILE));
app.get('/index.html', (req, res) => res.sendFile(INDEX_FILE));

app.use((err, req, res, next) => {
  if (err instanceof multer.MulterError) {
    if (err.code === 'LIMIT_FILE_SIZE') return sendJson(res, 413, { error: `Ukuran berkas melebihi batas ${MAX_UPLOAD_MB} MB.` });
    return sendJson(res, 400, { error: 'Upload gagal diproses.' });
  }
  console.error(err);
  return sendJson(res, 500, { error: 'Terjadi kesalahan pada server.' });
});

if (require.main === module) {
  app.listen(PORT, () => {
    console.log(`HidzHost berjalan di port ${PORT}`);
    console.log(`Base: ${PUBLIC_BASE_URL}`);
    console.log(`Wildcard: https://<slug>.${HOST_PREFIX}.${BASE_DOMAIN}`);
  });
}

module.exports = app;
