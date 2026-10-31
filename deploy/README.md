# Deploy Sangatta Cloud Billing dengan Docker

Panduan berurutan untuk menjalankan FOSSBilling + MariaDB di satu server Debian
(simulasi VPS), dari persiapan server sampai pembaruan yang aman dari GitHub.

Prinsip utama:

- **Kode** ada di image Docker dan berasal dari GitHub. Aman diganti kapan saja.
- **Data** ada di volume Docker (`mariadb_data`, `fossbilling_data`) dan
  **tidak pernah disentuh** oleh `git pull` maupun `docker compose build/up`.
- **Konfigurasi** (`.env`, `config.php`) ada di host, diabaikan Git, dan
  dipasang ke container. Tanpa `config.php` yang di-mount, FOSSBilling
  menganggap belum terpasang dan membuka installer.

Contoh di bawah memakai `192.168.1.29` sebagai IP server. Ganti sesuai server
Anda.

## 1. Persiapan server

Gunakan Debian dengan akun non-root yang punya `sudo`. Pasang Docker Engine dan
Compose plugin:

```sh
sudo apt-get update
sudo apt-get install ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg \
  -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin git
sudo systemctl enable --now docker
sudo docker run hello-world
```

Jalankan perintah Docker dengan `sudo` bila akun belum punya akses Docker.
Keanggotaan grup `docker` setara dengan hak root.

Jika server dapat diakses dari jaringan lain, batasi firewall hanya ke port
yang dibutuhkan (SSH, dan `80`/`443` bila memakai reverse proxy). Jangan
membuka `3306`.

## 2. Ambil source dari GitHub

```sh
sudo git clone --branch sangatta-cloud-billing-mdb --single-branch \
  https://github.com/danijh/fossbilling.git /opt/sangatta-cloud
sudo chown -R "$USER":"$(id -gn)" /opt/sangatta-cloud
cd /opt/sangatta-cloud/deploy
```

## 3. Siapkan `.env`

```sh
cp .env.example .env
chmod 600 .env
openssl rand -hex 32     # jalankan dua kali, satu hasil untuk tiap password
nano .env
```

Isi:

| Variabel | Keterangan |
|---|---|
| `BILLING_BIND_IP` | `127.0.0.1` bila hanya diakses dari server itu sendiri atau lewat reverse proxy di server yang sama. `192.168.1.29` (IP LAN) bila ingin diakses langsung dari perangkat lain. |
| `MARIADB_DATABASE`, `MARIADB_USER` | Nama database dan user aplikasi. |
| `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD` | Dua password acak yang berbeda. |

`.env` sudah diabaikan Git. Jangan dikirim atau di-commit. Aplikasi memakai user
terbatas dari `MARIADB_USER`; root hanya untuk administrasi.

> `.env` baru dibaca saat container dibuat. Jika Anda mengubahnya, jalankan
> `docker compose up -d` lagi.

## 4. Build dan jalankan

```sh
docker compose build
docker compose up -d
docker compose ps
docker compose logs -f app
```

Port aplikasi: `${BILLING_BIND_IP}:8086`. MariaDB hanya dapat dicapai dari
network internal Docker. Tunggu sampai service `db` berstatus sehat.
Peringatan Apache `AH00558` tentang `ServerName` tidak berbahaya.

## 5. Install pertama kali

Buka alamat yang benar-benar akan dipakai, misalnya `http://192.168.1.29:8086/`
(atau domain HTTPS jika sudah ada). **Alamat ini disimpan sebagai `url`** di
konfigurasi, jadi jangan mencampur `localhost`, IP, dan domain.

Detail database di installer:

- Driver: MySQL/MariaDB
- Host: `db` (nama service Compose, bukan `localhost`)
- Port: `3306`
- Database / Username / Password: nilai dari `.env`

Jika memakai reverse proxy, isi proxy tepercaya dengan alamat proxy yang tepat
(misalnya `127.0.0.1` untuk nginx di server yang sama) dan batasi akses ke port
`8086` agar klien lain tidak bisa menyamar sebagai proxy.

## 6. Simpan `config.php` di host (wajib, jangan dilewati)

Installer menulis `config.php` **di dalam container**. Jika dibiarkan di sana,
file itu hilang saat container dibuat ulang dan installer muncul lagi. Salin ke
host dan pasang sebagai mount:

```sh
cd /opt/sangatta-cloud/deploy
docker compose cp app:/var/www/html/config.php ./config.php
sudo chown root:33 ./config.php     # UID 33 = www-data di image
sudo chmod 660 ./config.php
test -s ./config.php && echo OK
```

Buat `docker-compose.override.yml` (otomatis dibaca Compose, diabaikan Git
sehingga `git pull` tidak pernah berbenturan dengannya):

```yaml
services:
  app:
    volumes:
      - ./config.php:/var/www/html/config.php
```

```sh
docker compose up -d
docker compose exec app ls -la /var/www/html/config.php
```

Urutannya penting: **buat file `config.php` di host lebih dulu**, baru pasang
mount. Jika file belum ada, Docker membuat *folder* bernama `config.php` dan
aplikasi gagal berjalan.

Simpan salinan `config.php` dan `.env` di luar server. Keduanya berisi rahasia
(termasuk `salt`); jangan di-commit.

## 7. Backup

Lakukan sebelum setiap update, dan jadwalkan rutin:

```sh
cd /opt/sangatta-cloud/deploy
umask 077
mkdir -p ~/backup
docker compose exec -T db sh -c \
  'mariadb-dump --user=root --password="$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' \
  > ~/backup/fossbilling-$(date +%F-%H%M).sql
test -s ~/backup/fossbilling-*.sql && ls -la ~/backup
cp config.php .env ~/backup/
```

Salin folder backup ke media lain. Named volume bukan pengganti backup.

Pulihkan database (hanya bila perlu):

```sh
docker compose exec -T db sh -c \
  'mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
  < ~/backup/fossbilling-TANGGAL.sql
```

## 8. Update dari GitHub tanpa menimpa data

Dari mesin pengembang, commit dan push dulu ke `sangatta-cloud-billing-mdb`.
Lalu di server:

```sh
cd /opt/sangatta-cloud/deploy

# 1. Backup (bagian 7)

# 2. Tarik kode. --ff-only menolak jika ada perubahan lokal yang berbenturan.
cd /opt/sangatta-cloud
git pull --ff-only origin sangatta-cloud-billing-mdb
cd deploy

# 3. Build image baru dan ganti container aplikasi saja
docker compose build app
docker compose up -d app

# 4. Periksa
docker compose ps
docker compose logs --tail=50 app
```

Yang aman dan tidak berubah: volume `mariadb_data` dan `fossbilling_data`,
`.env`, `config.php`, dan `docker-compose.override.yml` (semuanya di luar Git
atau di volume). Container `db` tidak dibuat ulang.

Setelah update, buka area admin. Jika versi baru membawa perubahan struktur
database, FOSSBilling menampilkan layar pasca-update untuk menerapkannya.
Jalankan layar itu, dan jangan membuka installer.

Tanda-tanda ada yang salah:

- **Installer muncul:** mount `config.php` belum terpasang. Cek
  `docker compose exec app ls -la /var/www/html/config.php`. **Jangan**
  menyelesaikan installer sebelum penyebabnya jelas.
- **Error koneksi database:** cek kredensial di `config.php` dan `.env`.

Perintah yang **menghapus data** dan jangan dipakai pada instalasi berjalan:

```sh
docker compose down -v     # menghapus volume database dan upload
docker volume rm ...
```

`docker compose down` (tanpa `-v`) aman karena volume dipertahankan.

Opsional untuk pembaruan image dasar: `docker compose build --pull app`.
Perbarui MariaDB hanya dengan sengaja (`docker compose pull db && docker
compose up -d db`), setelah backup, dan hindari lompatan versi mayor tanpa
membaca catatan rilisnya. Jangan memakai updater bawaan FOSSBilling pada fork
ini, karena dapat menimpa kustomisasi white-label.

## 9. Reverse proxy dan domain (opsional, menyusul)

Sistem berjalan penuh dengan IP lokal. Tambahkan domain/HTTPS kapan saja tanpa
install ulang, lalu ubah `'url'` di `config.php` ke alamat baru dan jalankan
`docker compose restart app`.

**nginx di server yang sama** (arahkan `BILLING_BIND_IP=127.0.0.1`):

```nginx
server {
    listen 80;
    server_name billing.example.com;

    location / {
        proxy_pass http://127.0.0.1:8086;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        client_max_body_size 64m;
    }
}
```

Lalu `sudo nginx -t && sudo systemctl reload nginx`, dan pasang HTTPS
(misalnya `certbot --nginx`). Tanpa server block khusus, nginx menjawab
permintaan domain yang tidak dikenal dengan server block default, yang bisa
saja layanan lain di server yang sama.

**Nginx Proxy Manager di VM lain:** buat Proxy Host ke `http://<IP server>:8086`
(`BILLING_BIND_IP` harus IP LAN, bukan `127.0.0.1`), aktifkan Force SSL dengan
sertifikat yang mencakup hostnya, dan batasi firewall port `8086` hanya untuk
IP VM NPM. Jika NPM berupa container di server yang sama, `127.0.0.1` di dalam
container menunjuk ke container itu sendiri, jadi gunakan IP LAN server.

## 10. Hardening PHP dan Imagick

Image menyertakan ekstensi Imagick. Fungsi PHP berisiko dinonaktifkan untuk
Apache dan PHP CLI (termasuk cron) lewat file read-only
`deploy/php/fossbilling-security.ini`. Verifikasi setelah build:

```sh
docker compose exec app php -r 'var_export(["disable_functions" => ini_get("disable_functions"), "imagick" => extension_loaded("imagick")]); echo PHP_EOL;'
```

`disable_functions` harus memuat `exec,passthru,system,shell_exec,popen,proc_open`
dan `imagick` bernilai `true`. Karena `proc_open` dinonaktifkan, transport email
Sendmail tidak berjalan; gunakan SMTP. Ini hardening tambahan, bukan pengganti
pembaruan image dan pembatasan akses jaringan.

## 11. Modul Webhook Sender

Modul `Webhook` mengirim POST JSON bertanda tangan HMAC saat admin membalas
tiket (`onAfterAdminReplyTicket`). Aktifkan di Admin → Extensions, lalu tambahkan
di `config.php` (yang sudah di-mount di host):

```php
'webhook' => ['url' => 'https://penerima.example.com/hook', 'secret' => '...'],
```

Jalankan `docker compose restart app`. Penerima memverifikasi header
`X-FOSSBilling-Signature: sha256=<HMAC-SHA256 dari body>`.

## 12. Integrasi dengan layanan lain

Jalankan Nextcloud, landing page, dan FOSSBilling sebagai stack terpisah.
Integrasikan lewat API resmi masing-masing dengan HTTPS dan token berbeda untuk
setiap integrasi. Simpan token di konfigurasi server, beri scope minimum,
rotasi bila bocor, dan jangan menaruhnya di JavaScript browser atau repository.
Jangan membuka MariaDB ke layanan lain; integrasikan melalui API FOSSBilling,
bukan dengan berbagi database.
