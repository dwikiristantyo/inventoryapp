# 🥚 Egg Inventory System

Sistem Informasi Manajemen Inventaris Telur berbasis web menggunakan **PHP (Native)**, **MySQL/MariaDB**, dan **UwAmp Server Stack**. Aplikasi ini dirancang untuk mengelola data barang, transaksi masuk/keluar, pelaporan matriks harian, serta pengaturan hak akses pengguna berbasis grup.

---

## 🚀 Fitur Utama

- **Dashboard**: Memvisualisasikan perbandingan tren transaksi masuk (IN) dan keluar (OUT) per mingguan menggunakan Chart.js.
- **Master Data**:
  - Master Warehouse (`whmast`)
  - Master Item (`itemast`)
  - Master Category (`invmaster`)
- **Transaksi**:
  - Transaksi Masuk (`othinmas` / `othindet`)
  - Transaksi Keluar (`othoutmas` / `othoutdet`)
  - Lock Period (Kunci periode transaksi)
- **Laporan**:
  - Reports General
  - Reports Daily Matrix (Laporan matriks harian per gudang/farm)
- **System Admin**:
  - Manajemen User (`sysuser`)
  - Manajemen User Group & Hak Akses Dynamic (`sysgroupdet`)

---

## 🛠️ Persyaratan Sistem (Requirements)

- **Web Server**: Apache / UwAmp Server
- **PHP Version**: 7.4 / 8.x
- **Database**: MySQL / MariaDB
- **Browser**: Google Chrome, Mozilla Firefox, atau Microsoft Edge (Rekomendasi terbaru)

---

## 📂 Struktur Direktori Proyek

```text
inventoryapp/
├── config/
│   └── database.php         # Koneksi PDO ke MySQL Database
├── includes/
│   └── layout.php           # Template Sidebar, Navigation Bar, & Header/Footer
├── modules/
│   ├── auth/                # Login & Logout Handler
│   │   ├── login.php
│   │   └── logout.php
│   ├── warehouse/           # Master Warehouse
│   ├── itemast/             # Master Item
│   ├── category/            # Master Category
│   ├── transactions/        # Transaksi Masuk & Keluar
│   ├── lock_period/         # Fitur Kunci Periode Transaksi
│   ├── reports/             # Halaman Laporan & Reports Daily Matrix
│   ├── user/                # Master User
│   └── user_group/          # Master User Group & Permissions
├── index.php                # Main Dashboard Page
└── README.md                # Dokumentasi Proyek