# Analisis Keamanan & Kesesuaian API — `agumil/satusehat-sdk` (v2)

**Tanggal:** 27 Oktober 2026
**Commit yang dianalisis:** `eafaddc` — *feat: dukung environment development di seluruh modul*
**Perubahan sejak analisis v1:** +19 commit, +1883 / −206 baris, 71 file
**Metode:** pembacaan kode + **eksekusi nyata** (Guzzle MockHandler, server lokal, reflection). Semua temuan di bawah punya bukti output.

> **Penting:** analisis v1 (commit `5353959`) sudah **kedaluwarsa**. 13 dari 16 temuan lama sudah diperbaiki di kode. Dokumen ini menilai kode **sekarang**, bukan mengulang yang lama.

---

## 0. Status Perbaikan P0 — 28 Oktober 2026

Keempat temuan P0 beserta satu jebakan pager **sudah diperbaiki dan diverifikasi dengan test**, bukan hanya diubah di kode.

| # | Temuan | Perubahan | Bukti |
|---|---|---|---|
| N1 | Endpoint token tanpa timeout | `Oauth2::generateToken()` kini memakai `timeout`/`connect_timeout` (default 15s/5s, bisa diatur), menangkap `GuzzleException` sehingga `ConnectException`/`ServerException` menjadi `SSOauth2Exception`, dan menyaring `client_secret` dari pesan error. Ada seam `createClient()` untuk pengujian. | Server lokal yang menahan 8 detik: **2,1 dtk** dengan `timeout=2` (sebelumnya 8,1 dtk tanpa batas) dan `SSOauth2Exception`, bukan exception Guzzle. |
| N2 | `PhiRedactor` membocorkan PHI | Diubah dari daftar hitam kunci menjadi **default-deny**: semua nilai jadi `[REDACTED]`, hanya nama kunci + `resourceType`/`status`/parameter paging yang tersisa. `id` resource di path URL juga disamarkan. | `subject.display`, `text.div`, `note.text`, dan `/Patient/100000030009` kini `[REDACTED]`; test integrasi memastikan payload nyata yang melewati `HttpRequest` tidak membawa nama/NIK ke logger. |
| N3 | `X-Debug-Mode` KYC di production | `Kyc::generateChallengeCode()` melempar `SSEnvException` sebelum request dikirim bila environment `production`. | Test memastikan tidak ada request terkirim; header tetap dikirim di staging/development. |
| N4 | `FileTokenStore` permission & integritas | Mode file **diverifikasi** lewat `fileperms()` (bukan dipercaya dari nilai balik `chmod()`), file sementara dibuat dengan `umask 0077`, dan isi token ditandatangani **HMAC-SHA256** dengan `client_secret` (`KeyedTokenStoreInterface`, diisi otomatis oleh `Oauth2`). | File `{"token":"palsu"}` dan file yang ditandatangani kunci lain ditolak (`get()` → `null`). Di Windows, jalur verifikasi diuji lewat `PosixFileTokenStore`: chmod yang tidak berefek kini **gagal keras**, bukan diam-diam menghasilkan 0666. |
| N5 | `BundlePager::isFinished()` | `page >= max_pages` kini dihitung selesai. | `while (! $pager->isFinished())` berhenti tepat di `max_pages`, tidak lagi berputar selamanya. |

**Test permanen:** `composer test` — 43 test, 78 assertion, tanpa jaringan (Guzzle handler di-mock). Dua test permission di-skip di Windows karena yang diuji adalah bit POSIX; jalur verifikasinya tetap diuji lewat `PosixFileTokenStore`.

**Temuan baru yang ikut ditutup:** `composer audit` melaporkan **13 advisory (1 high, CVE-2026-69246)** pada Guzzle 7.8.1 dan psr7 2.6.2. Lock dinaikkan ke Guzzle **7.16.1** / psr7 **2.13.2** / promises **2.6.0** → **0 advisory**. Batas bawah dependensi ikut dinaikkan (`guzzle ^7.16`, `psr7 ^2.13`, `promises ^2.6`); ini **breaking** untuk pemakai Guzzle 6, yang sudah EOL dan tidak punya perbaikan untuk advisory tersebut. Guzzle 8 belum didukung karena memaksa psr7 3 + promises 3 — migrasi major terpisah, bukan sekadar naik versi.

**CI:** `.github/workflows/ci.yml` menjalankan empat job — matriks PHP 8.0–8.4, `composer update --prefer-lowest` (membuktikan batas bawah dependensi benar-benar bisa dipasang), `composer audit`, dan `php -l` untuk seluruh file. Sebelum workflow ini ditulis, setiap jalur diuji lokal dengan PHPUnit 9.6 / 10.5 / 11.5 dan dengan `--prefer-lowest` (psr/log 1.1.0) agar run pertama tidak merah.

**Skor setelah perbaikan: 8/10.** Sisa risiko bukan lagi "bocor/berhenti", melainkan kelengkapan: N6–N9, resource FHIR yang belum ada, dan tidak adanya CI (test ada, tetapi belum dijalankan otomatis).

---

## 1. Ringkasan Eksekutif

**Skor kesiapan produksi: 7/10** (v1: 4/10).

Perbaikan besar dan tepat sasaran: `PATCH` + `updatePatient()`, validasi `id` di seluruh resource, retry 429/5xx dengan `Retry-After`, token store dengan single-flight, logger PSR-3, `BundlePager`, modul KYC, dan dukungan environment `development`. Semua saya verifikasi berjalan, bukan hanya ada di kode.

Tiga masalah masih menghalangi produksi:

| # | Temuan | Mengapa penting |
|---|---|---|
| **N1** | Endpoint token **tanpa timeout** dan exception Guzzle bocor mentah | Titik paling kritis: semua request bergantung padanya. Terbukti menunggu 8,1 detik tanpa batas. |
| **N2** | `PhiRedactor` **tidak menutup PHI yang sebenarnya** | Nama pasien tetap masuk log lewat `display`, `text.div`, `note.text`. Rasa aman palsu untuk kepatuhan UU PDP. |
| **N4** | `FileTokenStore` menerima file token dari pihak lain; 0666 di Windows | Token bisa disuntik → request memakai token organisasi lain. |

Ditambah satu risiko kepatuhan alur: **N3** — `X-Debug-Mode` KYC tetap bisa dikirim di production.

---

## 2. Status Temuan v1 (diverifikasi ulang)

| v1 | Temuan | Status | Bukti |
|---|---|---|---|
| S1 | `required()` tidak pernah melempar | ✅ **Fixed** | `null`/`''`/`' '` kini ditolak |
| S2 | Exception builder ditelan → body `{}` | ✅ **Fixed** | Deteksi tipe eksplisit; exception naik |
| S3 | Path injection `{id}` | ✅ **Fixed** | `resourceId()` di 59 tempat, 58 interpolasi |
| S4 | Body 401 termakan | ✅ **Fixed** | Body 67 byte utuh; pesan error muncul |
| S5 | 5xx bocor ke pemanggil | ✅ **Fixed** | 502 → `Response` + "Bad Gateway" |
| S6 | Token tak di-cache lintas proses | ✅ **Fixed** | `FileTokenStore` + single-flight (fetch 1×) |
| S7 | Tidak ada audit log | ⚠️ **Sebagian** | Logger ada, tapi redaksinya bocor (N2) |
| S8 | `rand()` bukan CSPRNG | ✅ **Fixed** | `random_int` di backoff, `random_bytes` di store |
| S9 | `EnvHelper` salah direktori | ❌ **Belum** | Masih `createImmutable(__DIR__)` |
| S10 | `minimum-stability: dev`, Guzzle 6 | ❌ **Belum** | `composer.json` tak berubah |
| F1 | `PATCH` tidak ada | ✅ **Fixed** | `HttpRequest::patch()`, body array → JSON |
| F2 | `Patient` tanpa update | ✅ **Fixed** | `updatePatient()` via PATCH |
| F3 | 22 resource hilang | ❌ **Belum** | Masih 29 resource FHIR |
| F4 | `Consent` tidak diekspos | ✅ **Fixed** | `getConsent()`, `updateConsentRaw()` |
| F5 | Getter hilang | ✅ **Fixed** | `getSlot()`, `getMedication()`, `getImagingStudyById()` |
| F6 | Tidak ada paging | ✅ **Fixed** | `BundlePager` + `BundleHelper` |
| F7 | Tanpa ETag/If-Match | ❌ **Belum** | Tidak ada di kode |
| F8 | Tanpa retry 429/5xx | ✅ **Fixed** | 3 percobaan, `Retry-After` dihormati |
| F9 | Tanpa `connect_timeout` | ❌ **Belum** | Tidak ada di kode |
| F10 | Validasi pencarian Patient | ❌ **Belum** | `$params` diteruskan apa adanya |
| F11 | `php >=7.4` vs union type | ✅ **Fixed** | Kini `>=8.0` |
| F12 | `KFA\Base` TypeError | ✅ **Fixed** | Penimpaan dihapus |
| F13 | `false` dibuang, `identifier` tak wajib | ✅ **Fixed** | `!== null` + identifier wajib |
| F14 | Tanpa integrasi terminology | ❌ **Belum** | Masih konstanta lokal |

**13 fixed, 8 belum.** Semua yang "Fixed" diuji ulang, bukan sekadar dibaca.

---

## 3. Temuan yang Masih Terbuka

> **Status 28 Oktober 2026:** N1–N5 sudah diperbaiki (lihat §0). Uraian di bawah dipertahankan sebagai catatan asal-usul temuan dan cara mereproduksinya — berguna kalau regresi muncul lagi. Yang benar-benar masih terbuka: **N6–N9**.

### N1 — Endpoint token tanpa timeout, dan exception Guzzle bocor (HIGH) — ✅ diperbaiki

`src/Auth/Oauth2.php:210-224`

```php
$guzzle = new Client();
$options['query']['grant_type'] = 'client_credentials';
$options['form_params']['client_id'] = $this->client_id;
$options['form_params']['client_secret'] = $this->client_secret;
$response = $guzzle->post($url, $options);   // tanpa 'timeout'
} catch (ClientException $e) {               // hanya 4xx
```

Dua masalah sekaligus. **Pertama**, tidak ada `timeout` sama sekali → Guzzle memakai default 0 = tanpa batas. Dibuktikan dengan server lokal yang menahan 8 detik:

```
HttpRequest timeout=2s              2.0 detik   exception=-
Oauth2 token (tanpa timeout)        8.1 detik   exception=-
```

`HttpRequest` berhenti tepat 2 detik sesuai konfigurasi; `Oauth2` menunggu selama server mau menahan. Endpoint token yang menggantung akan **memblokir proses PHP tanpa batas** — dan ini terjadi di awal setiap request, sebelum timeout apa pun dari `HttpRequest` berlaku.

**Kedua**, hanya `ClientException` (4xx) yang ditangkap. 5xx dan kegagalan koneksi melempar exception Guzzle mentah:

```
Oauth2 ke endpoint token 500   → GuzzleHttp\Exception\ServerException (bukan SSOauth2Exception)
Oauth2 ke port mati            → GuzzleHttp\Exception\ServerException
```

Ini merusak kontrak SDK yang sudah rapi di `HttpRequest` ("selalu mengembalikan `Response`", atau `SSOauth2Exception` untuk kegagalan auth). Pemanggil yang menangkap `SSOauth2Exception` akan melewatkan kegagalan ini.

**Perbaikan:** set `timeout` + `connect_timeout` (mis. 10s/5s), tangkap `RequestException` dan `ConnectException`, bungkus jadi `SSOauth2Exception`, dan hormati `retry_attempts` untuk 429/5xx.

---

### N2 — `PhiRedactor` tidak menutup PHI yang sebenarnya (HIGH) — ✅ diperbaiki

`src/Helper/PhiRedactor.php` memakai **blacklist nama kunci**: `identifier`, `nik`, `name`, `birthdate`, `telecom`, `address`, `photo`, `contact`.

Masalahnya: FHIR menaruh identitas pasien di tempat yang **tidak** bernama itu. Hasil uji pada payload `Encounter` realistis:

```
identifier     : "[REDACTED]"                                  ← tertutup
subject        : {"reference":"Patient/P024","display":"Budi Santoso"}      ← BOCOR
participant    : [{"individual":{"display":"dr. Alexander"}}]               ← BOCOR
text.div       : "<div>Pasien Budi Santoso, NIK 3171022809990001, ...</div>" ← BOCOR
note           : [{"text":"Pasien mengaku demam 3 hari"}]                   ← BOCOR
reasonCode     : [{"text":"Demam berdarah"}]                                ← BOCOR
extension      : [{"valueString":"Alamat: Jl. Merdeka 1"}]                  ← BOCOR

>>> 'display' masih memuat nama? YA — BOCOR
```

`display` adalah tempat standar FHIR menaruh nama manusia pada `Reference` — dan itu justru tidak masuk daftar. Begitu juga `text.div` (Narrative, berisi ringkasan lengkap termasuk NIK dan diagnosis), `note.text`, `valueCodeableConcept.text`, dan `valueString`.

Jalur paparannya nyata: `logExchange()` mencatat `payload` penuh untuk payload berbentuk array (pola umum `createEncounter($array)`). Dokumentasi README bahkan menyatakan "Query strings and JSON bodies are passed through `PhiRedactor`" — klaim yang terlalu luas.

**Perbaikan:** balik pendekatannya. Untuk log, jangan catat isi payload sama sekali — cukup `resourceType`, `id`, jumlah entry, dan ukuran byte. Kalau isi tetap diperlukan, redaksi berbasis nilai (pola NIK 16 digit, `display`/`text`/`div` selalu di-mask), bukan berbasis nama kunci.

---

### N3 — `X-Debug-Mode` KYC bisa terkirim di production (MEDIUM) — ✅ diperbaiki

`src/KYC/Kyc.php:51-65`. Dokumentasi sudah jujur bahwa mode ini "melewati verifikasi yang seharusnya dilakukan pasien" dan "server production menolaknya". Tapi SDK tidak menegakkannya sendiri. Dibuktikan:

```
environment: production
header terkirim: {"X-Frame-Token":"URLTOKEN","X-Debug-Mode":"1"}
```

Pertahanan sepenuhnya bergantung pada server. Bila perilaku server berbeda antar-environment, ada proxy yang membuang header, atau suatu saat kontrol server dilonggarkan, jalur bypass verifikasi pasien terbuka tanpa perubahan kode di sisi SDK. `Kyc` sudah tahu `$this->environment` — menolak lokal itu murah:

```php
if ($debugMode && $this->environment === 'production') {
    throw new SSDataTypeException('X-Debug-Mode is not allowed in production.');
}
```

---

### N4 — `FileTokenStore`: file token 0666 di Windows, dan token pihak lain diterima (MEDIUM) — ✅ diperbaiki

`src/Auth/FileTokenStore.php`

Uji pada mesin Windows:

```
file dibuat: ya | isi: {"token":"tok-123","expires_at":1793098375}
permission: 0666
file ditulis pihak lain -> diterima sebagai token? {"token":"palsu","expires_at":9999999999}
```

Dua hal:

1. **`chmod($tmp, 0600)` tidak berefek di Windows** — hanya mengubah flag read-only, permission tetap 0666. Komentar di kode sudah menyadari ini ("proteksi bergantung pada ACL direktori"), tetapi `mkdir($dir, 0700)` juga tidak menyetel ACL di Windows, jadi tidak ada proteksi berlapis yang benar-benar aktif.
2. **`get()` tidak memeriksa pemilik maupun permission file.** File apa pun yang berbentuk `{"token":...,"expires_at":...}` dipercaya penuh.

Dampak: siapa pun yang bisa menulis ke path itu dapat menyuntik token. Bukan pencurian data, tetapi **pengarahan data**: request aplikasi memakai token milik organisasi lain, sehingga data klinis terkirim ke organisasi yang salah — atau DoS dengan token kedaluwarsa.

**Perbaikan:** verifikasi `fileowner($path) === posix_geteuid()` (di POSIX) dan permission `& 0777 === 0600` sebelum mempercayai isi; simpan juga HMAC atas `token|expires_at` dengan kunci dari `.env`; dokumentasikan bahwa di Windows path store harus berada di direktori ber-ACL ketat, bukan `%TEMP%`.

---

### N5 — `BundlePager::isFinished()` menyesatkan saat mentok `max_pages` (LOW) — ✅ diperbaiki

Uji dengan server yang membalas `link.next` bergantian (A→B→A→B):

```
halaman diambil: 5 (max_pages=5) | berhenti: tidak
```

Perlindungan `max_pages` bekerja (`next()` mengembalikan `null`), tetapi `isFinished()` tetap `false`. Pola konsumsi yang wajar —

```php
while (!$pager->isFinished()) { $r = $pager->next(); ... }
```

— akan berputar selamanya dengan `$r = null`. Selain itu, perbandingan `$next_params === $params` tidak menangkap pola bolak-balik A→B→A; hanya `max_pages` yang menyelamatkan.

**Perbaikan:** set `$this->finished = true` saat `page >= max_pages`, dan simpan riwayat token halaman (set) untuk mendeteksi siklus.

---

### N6 — `EnvHelper` belum diperbaiki (LOW)

`src/Helper/EnvHelper.php:15` masih `Dotenv::createImmutable(__DIR__)` → menunjuk `src/Helper/`, bukan root proyek; konstruktornya juga tidak pernah dipanggil. Semua getter hanya membaca `$_ENV`, sehingga pada PHP-FPM dengan `variables_order=GPCS` (umum) seluruh kredensial menjadi `null` meski `.env` ada. Masih ada 80 pemakaian operator `@` di `src/`.

**Perbaikan:** hapus konstruktor (atau arahkan ke root paket), baca dengan `getenv()` lalu fallback ke `$_SERVER`/`$_ENV`.

---

### N7 — `PATCH` tidak ikut di-retry (LOW)

`retry_methods` default `['GET', 'PUT', 'DELETE']`. Karena `Patient` **hanya** punya PATCH, update pasien tidak akan diulang saat kena 429 — padahal 429 berarti request belum diproses, jadi aman diulang. Pertimbangkan menambahkan `'PATCH'` khusus untuk status 429 (bukan 5xx, karena `add` pada JSON Patch tidak idempoten).

### N8 — Anggaran waktu retry tidak dibatasi total (LOW)

`retry_attempts=3` × `timeout=60` + jeda → satu panggilan bisa memblokir hingga ~3 menit. Pada request web, ini melewati `max_execution_time` PHP dan berakhir sebagai fatal error, bukan error yang bisa ditangani. Pertimbangkan `retry_total_budget_ms`.

### N9 — Higiene dependensi & tidak ada test/CI (MEDIUM, proses) — 🟡 sebagian ditutup

- `"minimum-stability": "dev"` masih ada → dependensi versi dev bisa terpasang. Ini bukan teori: saat menaikkan Guzzle, `composer update` sempat memilih `7.7.x-dev`. Job `lowest` di CI sekarang menangkap kasus seperti ini, tetapi `minimum-stability` sebaiknya dihapus di rilis berikutnya.
- ~~`guzzlehttp/guzzle: ^6.5.8` masih diizinkan (Guzzle 6 EOL 2022).~~ Sudah dinaikkan ke `^7.16`, `psr7 ^2.13`, `promises ^2.6`.
- ~~**Tidak ada satu pun test, tidak ada `.github/`, tidak ada PHPUnit.**~~ Sekarang ada 43 test (`composer test`) dan CI 4 job: matriks PHP 8.0–8.4, `--prefer-lowest`, dan `composer audit`.
- Static analysis (PHPStan/Psalm) belum ada — masih terbuka.

---

## 4. Celah Kontrak API yang Masih Tersisa

| # | Celah | Dampak |
|---|---|---|
| F3 | **22 resource resmi belum ada**: Account, BillingStatus, ChargeItem, ChargeItemDefinition, ChargeItemResponse, Claim, ClaimResponse, Coverage, CoverageEligibilityRequest/Response, DocumentReference, Endpoint, Goal, Invoice, MedicationAdministration, MedicationStatement, NutritionOrder, PaymentNotice, PaymentReconciliation, RiskAssessment, Substance, Task | Untuk use case rujukan: `Task` (status rujukan), `DocumentReference` (lampiran), `Coverage`/`Claim` (jaminan) belum bisa dikirim |
| F7 | Tanpa `If-Match`/`meta.versionId` | Rawan *lost update* saat dua sistem menulis resource yang sama |
| F9 | Tanpa `connect_timeout` | DNS/TCP menggantung menahan proses sampai 60 detik |
| F10 | Kombinasi parameter pencarian `Patient` tidak divalidasi (`identifier`, atau `name`+`birthdate`+`gender`, atau `name`+`birthdate`+`nik`) | Kesalahan pemakaian baru terdeteksi sebagai 400 |
| F14 | `Terminology/` hanya konstanta lokal, tanpa `$validate-code`/`$lookup` | Validasi kode ke kamus resmi harus dibangun sendiri |

Catatan positif yang jangan diubah: `Practitioner` tetap **GET-only** (sesuai dokumen, sumbernya MNI), tidak ada `DELETE` (SATUSEHAT tidak menyediakannya), TLS tidak pernah dimatikan, `base_url` tetap tidak bisa di-override (menutup SSRF), dan tidak ada secret di repo maupun histori git.

---

## 5. Prioritas

### P0 — sebelum produksi — ✅ selesai 28 Oktober 2026

| # | Item | Alasan |
|---|---|---|
| 1 | Timeout + penanganan exception di `Oauth2::generateToken()` | N1: bisa menggantung tanpa batas, exception bocor |
| 2 | Redaksi PHI: jangan catat isi payload | N2: nama pasien tetap masuk log |
| 3 | Tolak `X-Debug-Mode` bila environment production | N3: bypass verifikasi pasien |
| 4 | Verifikasi pemilik/permission file token | N4: token bisa disuntik |

Keempatnya sudah dikerjakan, ditambah N5 dan pembaruan dependensi (0 advisory). Rincian bukti ada di §0.

### P1 — segera setelahnya

| # | Item |
|---|---|
| 5 | `BundlePager::isFinished()` + deteksi siklus token (N5) |
| 6 | Perbaiki `EnvHelper` (N6) |
| 7 | Retry PATCH untuk 429 (N7) |
| 8 | Anggaran waktu total retry (N8) |
| 9 | **Tambah test + CI** untuk retry, token store, pager, redactor, KYC (N9) |
| 10 | Buang `minimum-stability: dev`, drop Guzzle 6, tambah `composer audit` (N9) |

### P2 — kelengkapan

| # | Item |
|---|---|
| 11 | Resource prioritas: `Task`, `DocumentReference`, `Coverage`, `MedicationStatement`, `Goal` |
| 12 | `If-Match`/ETag (F7) |
| 13 | `connect_timeout` (F9) |
| 14 | Validasi kombinasi pencarian `Patient` (F10) |
| 15 | Integrasi terminology (F14) |

---

## 6. Lampiran — Cara Reproduksi

Semua angka di laporan ini berasal dari skrip berikut (dijalankan pada PHP 8.4.15, Guzzle 7, Windows).

**1. Retry, 401, 5xx, redaksi, `resourceId`, KYC** — memakai `MockHandler` dan subclass `HttpRequest` (seam `createClient()` yang sudah disediakan SDK):

```
### 1. RETRY: 503,503,200 pada GET ###      status akhir: 200 | 3x kirim
### 2. RETRY: POST 503 tidak diulang ###    status: 503 | 1x kirim
### 3. 401 invalid-access-token ###         refresh 1x | body: {"ok":1}
### 4. 401 biasa ###                        body utuh 67 byte | ["UNKNOWN::Akses ditolak"]
### 5b. 5xx 502 ###                         HTTP 502 | ["Bad Gateway"]
### 6. PhiRedactor ###                      'display' masih memuat nama? YA — BOCOR
### 7. resourceId ###                       UUID & 64 char diterima; '..', '%2e%2e%2f', 65 char ditolak
### 8. KYC production ###                   X-Debug-Mode: 1 tetap terkirim
```

**2. Token store, pager, endpoint token** — dengan server lokal `php -S`:

```
### 9. FileTokenStore ###      permission 0666 (Windows) | file pihak lain diterima
### 10. single-flight ###      fetch dipanggil 1x
### 11. BundlePager ###        5 halaman, berhenti: tidak
### 12. token 500 ###          GuzzleHttp\Exception\ServerException (bukan SSOauth2Exception)
```

**3. Timeout** — server lokal yang menahan 8 detik:

```
HttpRequest timeout=2s              2.0 detik
Oauth2 token (tanpa timeout)        8.1 detik
```

**4. Konsistensi internal** — 116 pemanggilan `(new Resource(...))->method()` di `SSClient` diperiksa dengan `method_exists()`: **0 bermasalah**. `resourceId()` dipakai 59 kali untuk 58 interpolasi `{id}` — semua resource FHIR dan Consent tercakup.
