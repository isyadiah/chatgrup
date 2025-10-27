<?php
session_start();
$error = '';
$success = '';

include 'db_config.php';

// Cek apakah pengguna sudah login (cek semua session)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['profile_pic'])) {
    header("Location: index.php"); 
    exit;
}

$current_user_id = $_SESSION['user_id'];
$room_id = null;
$room_name = 'Room Tidak Ditemukan';
$is_member = false;

// 1. Cek apakah ID room ada di URL
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $room_id = (int)$_GET['id'];
    
    // 2. Cek apakah room ada dan pengguna adalah anggotanya
    $stmt = $conn->prepare("
        SELECT r.name, rm.is_creator 
        FROM rooms r
        JOIN room_members rm ON r.id = rm.room_id
        WHERE r.id = ? AND rm.user_id = ?
    ");
    $stmt->bind_param("ii", $room_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $room_data = $result->fetch_assoc();
        $room_name = $room_data['name'];
        $is_creator = $room_data['is_creator'];
        $is_member = true;
    }
    $stmt->close();
}

// Jika pengguna bukan anggota atau room tidak ditemukan, lempar kembali
if (!$is_member) {
    // Gunakan peringatan kustom daripada header agar pesan error terlihat
    $error = "Akses ditolak. Room tidak ditemukan atau Anda bukan anggota room ini.";
    // Jika tidak ada room ID, tampilkan pesan error ini
}


// --- 3. PROSES TAMBAH ANGGOTA (Hanya untuk Anggota Room) ---
if ($is_member && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_member') {
    $target_user_id_padded = trim($_POST['target_user_id']);
    
    if (empty($target_user_id_padded)) {
        $error = 'ID pengguna target tidak boleh kosong.';
    } else {
        // Konversi ID 6-digit kembali ke integer ID (dengan menghilangkan padding 0)
        $target_user_id = (int)ltrim($target_user_id_padded, '0');
        
        // Validasi: Cek apakah ID target valid (ada di tabel users)
        $stmt_check = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmt_check->bind_param("i", $target_user_id);
        $stmt_check->execute();
        $user_result = $stmt_check->get_result();
        
        if ($user_result->num_rows === 0) {
            $error = "ID Pengguna <strong>" . htmlspecialchars($target_user_id_padded) . "</strong> tidak ditemukan.";
        } else {
            $target_username = $user_result->fetch_assoc()['username'];

            // Validasi: Cek apakah pengguna target adalah diri sendiri
            if ($target_user_id == $current_user_id) {
                $error = "Anda sudah berada di room ini.";
            } else {
                // Tambahkan ke room_members
                $stmt_add = $conn->prepare("INSERT INTO room_members (room_id, user_id, is_creator) VALUES (?, ?, 0)");
                $stmt_add->bind_param("ii", $room_id, $target_user_id);

                if ($stmt_add->execute()) {
                    $success = "Pengguna <strong>" . htmlspecialchars($target_username) . " (ID: " . htmlspecialchars($target_user_id_padded) . ")</strong> berhasil ditambahkan!";
                } else {
                    // Cek error duplikasi (Unique Key room_user_unique)
                    if ($conn->errno == 1062) {
                        $error = "Pengguna <strong>" . htmlspecialchars($target_username) . "</strong> sudah menjadi anggota room ini.";
                    } else {
                        $error = "Gagal menambahkan pengguna. (Error: " . $stmt_add->error . ")";
                    }
                }
                $stmt_add->close();
            }
        }
        $stmt_check->close();
    }
}

// Ambil daftar anggota room (untuk ditampilkan di panel samping)
$members = [];
if ($is_member) {
    $stmt_members = $conn->prepare("
        SELECT u.id, u.username, u.profile_pic, rm.is_creator 
        FROM room_members rm
        JOIN users u ON rm.user_id = u.id
        WHERE rm.room_id = ?
        ORDER BY u.username ASC
    ");
    $stmt_members->bind_param("i", $room_id);
    $stmt_members->execute();
    $result_members = $stmt_members->get_result();
    while ($row = $result_members->fetch_assoc()) {
        $row['display_id'] = str_pad($row['id'], 6, '0', STR_PAD_LEFT);
        $members[] = $row;
    }
    $stmt_members->close();
}


$conn->close();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($room_name); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="app-container chat-page">
        <header class="chat-header">
            <a href="index.php" class="back-btn">← Kembali ke Beranda</a>
            <h1>Room: <?php echo htmlspecialchars($room_name); ?></h1>
            <div class="user-info">
                <img src="<?php echo htmlspecialchars($_SESSION['profile_pic']); ?>" alt="Foto Profil" class="header-profile-pic">
                <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
            </div>
        </header>

        <?php if ($error && !$is_member): ?>
            <!-- Jika tidak menjadi member dan ada error akses -->
            <div class="access-denied-message">
                <p class="error-message large-error"><?php echo $error; ?></p>
                <a href="index.php" class="back-to-home-btn">Kembali ke Daftar Room</a>
            </div>
        <?php elseif ($is_member): ?>
        
        <main class="chat-main">
            
            <!-- Panel Anggota (Sidebar Kanan) -->
            <div class="chat-members-panel">
                <h2>Anggota Room (<?php echo count($members); ?>)</h2>
                
                <?php if ($error): ?>
                    <p class="error-message small-error"><?php echo $error; ?></p>
                <?php endif; ?>
                <?php if ($success): ?>
                    <p class="success-message small-success"><?php echo $success; ?></p>
                <?php endif; ?>
                
                <!-- Formulir Tambah Anggota -->
                <div class="add-member-form">
                    <h3>Tambah Anggota Baru</h3>
                    <form method="POST" action="chat_room.php?id=<?php echo $room_id; ?>">
                        <input type="hidden" name="action" value="add_member">
                        <input type="text" name="target_user_id" placeholder="Masukkan ID 6-Digit Pengguna" required minlength="1" maxlength="6" pattern="\d{1,6}">
                        <button type="submit">Tambah</button>
                    </form>
                </div>

                <div class="member-list">
                    <?php foreach ($members as $member): ?>
                        <div class="member-item <?php echo ($member['id'] == $current_user_id) ? 'current-user' : ''; ?>">
                            <img src="<?php echo htmlspecialchars($member['profile_pic']); ?>" alt="pic" class="member-profile-pic">
                            <span class="member-name">
                                <?php echo htmlspecialchars($member['username']); ?>
                                <?php echo ($member['is_creator'] == 1) ? ' 👑' : ''; // Ikon Creator ?>
                                <?php echo ($member['id'] == $current_user_id) ? ' (Anda)' : ''; ?>
                            </span>
                            <span class="member-id">ID: <?php echo htmlspecialchars($member['display_id']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Area Chat Utama -->
            <div class="chat-area">
                <div class="chat-box" id="chat-box">
                    <!-- Pesan akan dimuat di sini oleh chat.js -->
                    <p class="system-message">Memuat pesan...</p>
                </div>

                <!-- Input Pesan -->
                <form id="message-form" class="message-input-form">
                    <input type="text" id="message-input" placeholder="Ketik pesan Anda..." required>
                    
                    <div class="file-attachment-area">
                        <button type="button" id="attach-btn" title="Lampirkan File">+</button>
                        <input type="file" id="media-file-input" accept="image/*,video/*,audio/*" style="display:none;">
                    </div>

                    <button type="submit" id="send-btn">Kirim</button>
                </form>

                <div id="file-preview" class="file-preview-bar" style="display:none;">
                    <!-- Pratinjau file yang dipilih akan dimuat di sini -->
                </div>
            </div>
            
            
        </main>
        
        <!-- Data Room dan User untuk JS -->
        <div id="room-info" 
            data-room-id="<?php echo htmlspecialchars($room_id); ?>" 
            data-user-id="<?php echo htmlspecialchars($current_user_id); ?>" 
            style="display: none;">
        </div>

        <script src="chat.js"></script>

        <?php endif; ?>
    </div>

</body>
</html>
