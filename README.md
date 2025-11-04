## 🔧 Cara Menjalankan

Berikut langkah-langkah untuk menjalankan proyek ini di lokal:

## 1. Clone repository
```bash
## git clone nama repo berikut
https://github.com/RiskyAdit06/worker-test-backend-csi

## masuk ke folder repo cd worker-test-backend-csi

## Install dependencies
Jalankan composer install

## Setup environment
Buat file .env berdasarkan .env.example yang sudah ada

## Migrasi database
jalankan perintah php artisan migrate

## Jalankan API
jalankan perintah
php artisan serve

## Jalankan Worker
buka di powershell lain dan jalankan perintah
php artisan jobs:worker --sleep=1 

## 2. 🛠 Penjelasan Keputusan Teknis
1. Polling Worker + DB Transaction
- Worker dijalankan secara terus-menerus menggunakan looping (while(true)), mengambil job yang statusnya PENDING atau RETRY.
- Setiap pengambilan job dibungkus dalam transaction, sehingga status update menjadi atomik. Jika terjadi error di tengah proses, DB akan rollback otomatis.
- Ini memastikan reliability, karena job tidak hilang atau diproses secara ganda.

2. lockForUpdate()
- Saat worker mengambil job, digunakan lockForUpdate() untuk mengunci row di database.
- Tujuannya: prevent race condition ketika ada lebih dari satu worker berjalan paralel. Hanya satu worker yang bisa mengklaim job untuk diproses sehingga lebih aman dari double-processing.

3. Idempotency Key
- API enqueue job menerima idempotency_key.
- Jika ada request dengan idempotency_key yang sama dan payload identik, tidak membuat job baru, tetapi mengembalikan job existing.Ini mencegah duplikasi job akibat retry client atau request duplikat → idempotency.

4. Sederhana & konsisten
- Kode memanfaatkan DB sebagai single source of truth. Tidak menggunakan queue eksternal seperti RabbitMQ/SQS, sesuai batasan soal.
- Semua logic retry, backoff, dan status update dilakukan di worker sendiri, membuat alur lebih mudah dipahami dan di-debug.

⏱ Strategi Retry / Backoff / Jitter

1. Retry
- Job yang gagal sementara (30% chance fail) akan diupdate statusnya menjadi RETRY.
- Jumlah attempts ditambah +1 setiap gagal.
- Jika attempts >= max_attempts → job berstatus FAILED.

2. Exponential Backoff
- Delay dihitung sebagai:
    delay = 2^(attempts - 1) detik
- Contoh:
    Attempt 1 → 1 detik
    Attempt 2 → 2 detik
    Attempt 3 → 4 detik, dst.
- Ini dapat mengurangi beban dari sistem jika banyak job gagal secara bersamaan.

3. Jitter
- Ditambahkan random 0–30% ke delay:
    delay = base * (1 + jitter)
- Contoh: 4 detik → 4–5.2 detik
- Tujuannya: menghindari thundering herd, yaitu semua worker mencoba job sekaligus setelah delay yang sama.

4. Pengaturan next_run_at
- Worker hanya mengambil job yang next_run_at <= now().
- Jadi job yang di-retry otomatis akan menunggu sesuai delay sebelum diambil kembali.

🔒 Mekanisme Anti Double-Processing

1. Row-level Locking
- lockForUpdate() berfungsi untuk memastikan satu worker hanya bisa mengklaim satu job pada satu waktu.
- Kemudian worker lain harus menunggu commit atau rollback untuk row yang sama.

2. Status Transition Atomik
- Setelah mengambil job, worker langsung mengupdate status menjadi PROCESSING.
- Ini menandai job sedang diproses dan worker lain tidak akan memproses job yang sama.

3. Transaction Safety
- Semua data diupdate yang statusnya (PROCESSING, SUCCESS, RETRY, FAILED) dan itu dilakukan di dalam transaksi.
- Jika worker crash saat memproses job, transaksi akan rollback dan job tetap bisa diambil worker lain pada polling berikutnya dan begitu seterusnya.

4. Idempotency
- API dan worker sama-sama mengandalkan DB sebagai single source of truth.
- Tidak ada job yang bisa diproses secara ganda, bahkan jika ada banyak worker paralel atau restart worker di tengah proses.

📬 Sample Requests
1️⃣ Enqueue Job (POST /api/notifications)

POST http://localhost:8000/api/notifications
Content-Type: application/json
{`
  "recipient": "user@example.com",
  "channel": "email",
  "message": "Halo dari sistem worker!",
  "idempotency_key": "test-key-1"
}`

## Expected Response
{`
  "job_id": 1,
  "status": "PENDING"
}`

2️⃣ Enqueue Duplicate Job (Idempotency Test)
POST http://localhost:8000/api/notifications
Content-Type: application/json

{`
  "recipient": "user@example.com",
  "channel": "email",
  "message": "Halo dari sistem worker!",
  "idempotency_key": "test-key-1"
}`

## Expected Response
`{
  "job_id": 1,
  "status": "PENDING"
}`

3️⃣ Queue Stats (GET /internal/queue/stats)
http://127.0.0.1:8000/api/internal/queue/stats

## Expected Response
{`
  "pending": 5,
  "retry": 2,
  "processing": 1,
  "success": 120,
  "failed": 4,
  "avg_attempts_success": 1.4
}`
