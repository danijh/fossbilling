# Deploy Sangatta Cloud Billing di balik Nginx Proxy Manager

Panduan ini menjalankan FOSSBilling dan MariaDB `latest` pada host Debian
`192.168.1.22`. Nginx Proxy Manager (NPM) berada di VM terpisah dan
meneruskan HTTPS ke aplikasi melalui jaringan LAN. MariaDB hanya dapat
diakses dari network internal Docker; jangan publikasikan port `3306`.

Hostname yang direncanakan:

- Landing page: `danijh.my.id`
- Nextcloud: `cloud.danijh.my.id`
- FOSSBilling: `billing.danijh.my.id`

Sesuaikan hostname dengan yang tersedia dan pastikan sertifikat NPM mencakup
setiap nama yang dipakai. Sertifikat untuk `danijh.my.id` saja tidak otomatis
berlaku bagi subdomain. Gunakan sertifikat wildcard/SAN atau sertifikat terpisah.

## 1. Siapkan host aplikasi

Tetapkan alamat LAN `192.168.1.22` melalui reservasi DHCP atau konfigurasi IP
statis. Batasi firewall port TCP `8085` agar hanya VM NPM yang dapat
mengaksesnya. Port `80`/`443` publik masuk ke NPM; database tidak dibuka.

Pasang Docker Engine dan Compose plugin dari repository resmi Docker untuk
Debian:

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

Clone branch white-label:

```sh
sudo git clone --branch sangatta-cloud-white-label --single-branch \
  https://github.com/danijh/fossbilling.git /opt/sangatta-cloud
sudo chown -R "$USER":"$(id -gn)" /opt/sangatta-cloud
cd /opt/sangatta-cloud/deploy
```

Jika source sudah di-clone:

```sh
cd /opt/sangatta-cloud
git fetch origin
git switch sangatta-cloud-white-label
git pull --ff-only origin sangatta-cloud-white-label
cd deploy
```

Jalankan perintah Docker dengan `sudo` bila akun belum memiliki akses Docker.
Keanggotaan grup `docker` setara dengan hak root.

## 2. Siapkan password database

```sh
cp .env.example .env
chmod 600 .env
openssl rand -hex 32
nano .env
```

Jalankan `openssl rand -hex 32` dua kali dan masukkan hasil yang berbeda ke
`MARIADB_PASSWORD` dan `MARIADB_ROOT_PASSWORD` di `.env`. Pastikan
`BILLING_BIND_IP=192.168.1.22`. Jangan kirim atau commit `.env`; file itu sudah
diabaikan Git. `MARIADB_ROOT_PASSWORD` adalah password administrator database;
aplikasi menggunakan user terbatas dari `MARIADB_USER`.

## 3. Siapkan proxy host pada NPM

NPM berada di VM berbeda, jadi **jangan mencoba memakai Docker network
bersama** antar-VM. NPM akan mengakses port LAN yang dipublikasikan Compose.

Di AdGuard DNS, buat rewrite `billing.danijh.my.id` ke alamat LAN VM NPM (bukan
ke `192.168.1.22`). Ulangi untuk hostname Nextcloud dan landing page sesuai
alamat NPM. Jika domain juga harus dapat diakses dari internet, atur DNS publik
ke IP publik NPM dan teruskan port `80` serta `443` di router ke VM NPM.

Di NPM, buat Proxy Host:

- Domain Names: `billing.danijh.my.id`
- Scheme: `http`
- Forward Hostname / IP: `192.168.1.22`
- Forward Port: `8085`
- SSL: pilih sertifikat yang mencakup `billing.danijh.my.id`, lalu aktifkan
  Force SSL

Untuk nama internal yang tidak dapat diverifikasi melalui HTTP publik, NPM
dapat memakai sertifikat DNS challenge jika provider DNS didukung. Periksa
bahwa sertifikat benar-benar mencakup hostname, bukan hanya domain akar.

## 4. Build dan jalankan FOSSBilling

```sh
docker compose build
docker compose up -d
docker compose ps
docker compose logs -f app
```

Compose akan membuat MariaDB dan volume baru khusus project ini, tanpa
mengubah container atau volume lain di host. Volume PostgreSQL lama, jika ada,
tidak dihapus atau dikonversi; data tidak dipindahkan otomatis ke MariaDB.
Image FOSSBilling sudah menyertakan ekstensi PHP `pdo_mysql`. Tunggu sampai
service `db` sehat.

Selesaikan installer pada `https://billing.danijh.my.id/`. Gunakan detail DB:

- Driver: MySQL/MariaDB
- Host: `db`
- Port: `3306`
- Database: nilai `MARIADB_DATABASE` dari `.env`
- Username: nilai `MARIADB_USER` dari `.env`
- Password: nilai `MARIADB_PASSWORD` dari `.env`

`db` adalah nama service di network Compose. Jangan menggunakan `localhost`
untuk koneksi database dari container aplikasi.

Pakai URL HTTPS final di installer. NPM meneruskan request ke container dengan
HTTP dan mengirim header proxy. Installer dapat mendeteksi proxy dari request;
periksa bahwa proxy tepercaya yang disimpan adalah alamat VM NPM yang tepat.
Biarkan hanya VM NPM yang dapat mencapai `192.168.1.22:8085`, sehingga klien
lain tidak dapat menyamar sebagai proxy.

## 5. Persistenkan konfigurasi dan backup

Setelah installer membuat konfigurasi, simpan `config.php` di host sebelum
container aplikasi dibuat ulang:

```sh
docker compose cp app:/var/www/html/config.php ./config.php
sudo chown root:33 ./config.php
sudo chmod 660 ./config.php
```

Tambahkan mount berikut di `volumes` service `app` dalam
`docker-compose.yml`:

```yaml
      - ./config.php:/var/www/html/config.php
```

Kemudian jalankan `docker compose up -d`. UID `33` adalah user `www-data` pada
image aplikasi. Lindungi `.env`, `config.php`, dan backup database seperti
rahasia.

Buat backup MariaDB dengan permission privat:

```sh
umask 077
docker compose exec -T db sh -c \
  'mariadb-dump --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
  > fossbilling.sql
test -s fossbilling.sql
```

Backup file tersebut ke media terpisah dari VM. Named volume bukan pengganti
backup.

## 6. Operasi dan pembaruan

```sh
docker compose logs -f app
docker compose logs -f db
docker compose restart app
docker compose down
```

`docker compose down` mempertahankan volume. Jangan memakai `docker compose
down -v` kecuali memang ingin menghapus database dan data upload.

Sebelum update, backup DB dan konfigurasi. Kemudian:

```sh
cd /opt/sangatta-cloud
git pull --ff-only origin sangatta-cloud-white-label
cd deploy
docker compose pull db
docker compose build --pull
docker compose up -d
```

`mariadb:latest` mengikuti rilis terbaru. Backup database sebelum menarik
image baru, terutama sebelum perubahan versi mayor. Hindari updater bawaan
untuk memasang rilis upstream pada fork ini karena
updater dapat mengganti kustomisasi white-label.

## Integrasi API antar layanan

Jalankan Nextcloud, landing page, dan FOSSBilling sebagai stack terpisah.
Gunakan API resmi masing-masing layanan melalui HTTPS dan autentikasi yang
berbeda untuk setiap integrasi. Simpan token di konfigurasi server, beri scope
minimum yang dibutuhkan, lakukan rotasi bila bocor, dan jangan menaruh token
rahasia di JavaScript/browser atau repository.

Jika kelak layanan perlu berkomunikasi langsung di LAN, tambahkan aturan
firewall hanya untuk sumber dan port API yang diperlukan. Jangan membuka
MariaDB ke Nextcloud, landing page, atau seluruh LAN; integrasikan melalui
API FOSSBilling, bukan dengan berbagi database.
