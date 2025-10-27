<?php
/*
================================
KONEKSI DATABASE
================================
Ganti nilai-nilai ini agar sesuai dengan pengaturan server MySQL Anda.
*/
$db_host = 'localhost';   // Biasanya 'localhost'
$db_user = 'root';        // User default XAMPP
$db_pass = '';            // Password default XAMPP (kosong)
$db_name = 'database';    // Nama database yang Anda buat

// Buat koneksi
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Cek koneksi
if ($conn->connect_error) {
    die("Koneksi Gagal: " . $conn->connect_error);
}

// Atur charset ke utf8mb4 untuk dukungan emoji
$conn->set_charset("utf8mb4");
?>
