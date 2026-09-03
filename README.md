# HidzHost — HidzProject / Vercel

HidzHost adalah hosting instan untuk HidzProject. Versi ini sudah dipindahkan dari PHP dan filesystem lokal ke Node.js + Express + Vercel Blob agar dapat dipasang di GitHub lalu dideploy ke Vercel.

## Format URL

Aplikasi HidzHost:

```text
https://h.hidzproject.my.id
```

Website yang dibuat user:

```text
https://h.my-file.hidzproject.my.id
https://h.project-final.hidzproject.my.id
```

Slug berasal dari nama file yang diupload. Paste HTML tanpa nama file menggunakan random slug.

## Arsitektur

```text
Browser
  ↓
Vercel
  ├── Express API
  └── Vercel Blob
        ↓
  sites/<slug>/<file>
```

Vercel Blob dipakai sebagai storage permanen. Jangan mengandalkan folder lokal `sites/` pada deployment Vercel.

## Batas upload di Vercel

Endpoint serverless Vercel mempunyai batas payload 4.5 MB. Karena project ini mempertahankan alur upload yang sudah ada, `MAX_UPLOAD_MB` default dibuat 4 MB agar request tidak melewati batas platform. Untuk file lebih besar, implementasi berikutnya sebaiknya menggunakan direct client upload ke Vercel Blob.

## 1. Push ke GitHub

Buat repository baru, lalu dari folder project:

```bash
git init
git add .
git commit -m "Prepare HidzHost for Vercel"
git branch -M main
git remote add origin https://github.com/USERNAME/REPOSITORY.git
git push -u origin main
```

## 2. Import ke Vercel

1. Buka dashboard Vercel.
2. Pilih **Add New → Project**.
3. Import repository HidzHost dari GitHub.
4. Framework preset boleh dibiarkan otomatis / Other.
5. Build command tidak diperlukan.
6. Deploy.

## 3. Buat Vercel Blob Store

Di project Vercel buka **Storage → Blob → Create Store**, lalu hubungkan store tersebut ke project HidzHost.

Gunakan access mode **Public**, karena file hasil hosting memang harus dapat dibaca publik melalui URL HidzHost. Token `BLOB_READ_WRITE_TOKEN` harus tersedia di environment project jika dashboard Vercel tidak memasangnya otomatis.

## 4. Environment Variables

Tambahkan:

```text
BASE_DOMAIN=hidzproject.my.id
HOST_PREFIX=h
PUBLIC_BASE_URL=https://h.hidzproject.my.id
SESSION_SECRET=<random-secret-panjang>
MAX_UPLOAD_MB=4
BLOB_READ_WRITE_TOKEN=<token-dari-vercel-blob>
```

Setelah mengubah environment variables, lakukan redeploy.

## 5. Sambungkan domain utama HidzHost

Di **Vercel → Project → Settings → Domains**, tambahkan:

```text
h.hidzproject.my.id
```

Kemudian ikuti record DNS yang diberikan Vercel.

Untuk URL hasil hosting, HidzHost membutuhkan wildcard di bawah hostname HidzHost:

```text
*.h.hidzproject.my.id
```

Tambahkan wildcard domain tersebut pada konfigurasi domain/project Vercel jika tersedia pada plan akun Anda. Vercel mendukung pola multi-tenant dengan wildcard custom domain; pastikan fitur wildcard domain tersedia pada plan/project Anda.

Jangan mengubah `s.hidzproject.my.id`.

## 6. Test

Buka:

```text
https://h.hidzproject.my.id
```

Paste HTML atau upload file ZIP kecil (maksimal sekitar 4 MB pada alur server ini).

Contoh hasil:

```text
https://h.my-project.hidzproject.my.id
```

Buka URL tersebut. Request akan membaca hostname, mengambil slug `my-project`, mencari objek Blob yang sesuai, lalu menyajikan `index.html` atau file yang diminta.

## Catatan penting

- Tidak ada PHP.
- Tidak menggunakan API Vercel Deployments atau API Netlify.
- Vercel dipakai sebagai platform aplikasi dan Vercel Blob sebagai persistent storage.
- `h.hidzproject.my.id` adalah entry point HidzHost.
- `h.<slug>.hidzproject.my.id` adalah URL hosting yang sebenarnya.
- `s.hidzproject.my.id` tidak disentuh oleh project ini.
