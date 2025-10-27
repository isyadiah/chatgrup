<?php
session_start();

$error = '';
$user_id = null;
$username = null;
$profile_pic = null;
$rooms = [];

include 'db_config.php'; // Selalu sertakan koneksi

// Cek jika pengguna sudah login
if (isset($_SESSION['user_id']) && isset($_SESSION['username']) && isset($_SESSION['profile_pic'])) {
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'];
    $profile_pic = $_SESSION['profile_pic'];
    
    // --- ID BARU DENGAN PADDING 6 DIGIT UNTUK DISPLAY DI HEADER ---
    $display_user_id = str_pad($user_id, 6, '0', STR_PAD_LEFT);

    // Jika login, ambil daftar room HANYA yang merupakan anggotanya
    $stmt = $conn->prepare("
        SELECT r.* FROM rooms r
        JOIN room_members rm ON r.id = rm.room_id
        WHERE rm.user_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rooms[] = $row;
        }
    }
    $stmt->close();

} else {
    // --- PROSES JIKA BELUM LOGIN (REGISTER / LOGIN) ---
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['form_type'])) {
        
        // --- PROSES REGISTRASI ---
        if ($_POST['form_type'] == 'register') {
            $reg_user = trim($_POST['username']);
            $reg_pass = $_POST['password'];
            $default_pic = 'uploads/profiles/default.png'; // Gambar default
            $profile_pic_path = $default_pic;

            if (empty($reg_user) || empty($reg_pass)) {
                $error = 'Nama pengguna dan password tidak boleh kosong.';
            } else {
                // Cek username
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->bind_param("s", $reg_user);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $error = 'Nama pengguna sudah terpakai.';
                } else {
                    // --- Proses Upload Foto Profil (Opsional) ---
                    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
                        $file = $_FILES['profile_pic'];
                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                        $max_size = 5 * 1024 * 1024; // 5MB

                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime_type = finfo_file($finfo, $file['tmp_name']);
                        finfo_close($finfo);

                        if (in_array($mime_type, $allowed_types) && $file['size'] <= $max_size) {
                            $upload_dir = 'uploads/profiles/';
                            $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                            $file_name = uniqid('profile_') . '.' . $file_ext;
                            
                            if (move_uploaded_file($file['tmp_name'], $upload_dir . $file_name)) {
                                $profile_pic_path = $upload_dir . $file_name;
                            } else {
                                $error = 'Gagal mengupload foto profil.';
                            }
                        } else {
                            $error = 'Foto profil tidak valid (Hanya JPEG/PNG/GIF, maks 5MB).';
                        }
                    }

                    // Lanjutkan registrasi jika tidak ada error upload
                    if (empty($error)) {
                        $password_hash = password_hash($reg_pass, PASSWORD_DEFAULT);

                        $stmt_insert = $conn->prepare("INSERT INTO users (username, password_hash, profile_pic) VALUES (?, ?, ?)");
                        $stmt_insert->bind_param("sss", $reg_user, $password_hash, $profile_pic_path);
                        
                        if ($stmt_insert->execute()) {
                            $_SESSION['user_id'] = $stmt_insert->insert_id;
                            $_SESSION['username'] = $reg_user;
                            $_SESSION['profile_pic'] = $profile_pic_path;
                            header("Location: index.php");
                            exit;
                        } else {
                            $error = 'Gagal mendaftar.';
                        }
                        $stmt_insert->close();
                    }
                }
                $stmt->close();
            }
        }
        
        // --- PROSES LOGIN ---
        if ($_POST['form_type'] == 'login') {
            $login_user = trim($_POST['username']);
            $login_pass = $_POST['password'];

            $stmt = $conn->prepare("SELECT id, username, password_hash, profile_pic FROM users WHERE username = ?");
            $stmt->bind_param("s", $login_user);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                if (password_verify($login_pass, $user['password_hash'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['profile_pic'] = $user['profile_pic'];
                    header("Location: index.php");
                    exit;
                } else {
                    $error = 'Password salah.';
                }
            } else {
                $error = 'Nama pengguna tidak ditemukan.';
            }
            $stmt->close();
        }
    }
}

// --- PROSES JIKA SUDAH LOGIN (BUAT ROOM) ---
if ($user_id && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_room') {
    $room_name = trim($_POST['room_name']);
    if (!empty($room_name)) {
        // Mulai transaksi untuk memastikan kedua operasi berhasil
        $conn->begin_transaction();
        $room_created = false;
        
        // 1. Buat Room
        $stmt_room = $conn->prepare("INSERT INTO rooms (name, created_by_user_id) VALUES (?, ?)");
        $stmt_room->bind_param("si", $room_name, $user_id);
        
        if ($stmt_room->execute()) {
            $new_room_id = $stmt_room->insert_id;
            $stmt_room->close();
            
            // 2. Tambahkan Creator sebagai Member (is_creator = 1)
            $is_creator = 1;
            $stmt_member = $conn->prepare("INSERT INTO room_members (room_id, user_id, is_creator) VALUES (?, ?, ?)");
            $stmt_member->bind_param("iii", $new_room_id, $user_id, $is_creator);
            
            if ($stmt_member->execute()) {
                $conn->commit(); // Commit jika kedua operasi berhasil
                header("Location: chat_room.php?id=" . $new_room_id); // Langsung masuk room
                exit;
            } else {
                // Rollback jika gagal menambahkan member
                $conn->rollback();
                $error = "Gagal menambahkan creator sebagai member. (Error: " . $stmt_member->error . ")";
            }
            $stmt_member->close();

        } else {
            $error = "Gagal membuat room. (Error: " . $stmt_room->error . ")";
        }
        
    } else {
        $error = "Nama room tidak boleh kosong.";
    }
}

// Proses Logout
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $user_id ? 'Beranda' : 'Login'; ?> - Chat App</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="app-container">
        <header class="app-header">
            <h1>Aplikasi Chat Group</h1>
            <?php if ($user_id): ?>
                <div class="user-info">
                    <img src="<?php echo htmlspecialchars($profile_pic); ?>" alt="Foto Profil" class="header-profile-pic">
                    ID: <strong><?php echo htmlspecialchars($display_user_id); ?></strong> | 
                    Nama: <strong><?php echo htmlspecialchars($username); ?></strong>
                    <a href="index.php?action=logout" class="logout-btn">Logout</a>
                </div>
            <?php endif; ?>
        </header>

        <?php if (!$user_id): ?>
        
        <!-- ======================= -->
        <!--   TAMPILAN LOGIN/REG    -->
        <!-- ======================= -->
        <main class="auth-container">
            <div class="auth-form" id="login-form">
                <h2>Login</h2>
                <form action="index.php" method="POST">
                    <input type="hidden" name="form_type" value="login">
                    <div>
                        <label for="login-user">Nama Pengguna:</label>
                        <input type="text" id="login-user" name="username" required>
                    </div>
                    <div>
                        <label for="login-pass">Password:</label>
                        <input type="password" id="login-pass" name="password" required>
                    </div>
                    <button type="submit">Login</button>
                    <p class="toggle-form">Belum punya akun? <a href="#" onclick="toggleForm()">Registrasi di sini</a></p>
                </form>
            </div>

            <div class="auth-form" id="register-form" style="display:none;">
                <h2>Registrasi</h2>
                <!-- PENTING: enctype untuk upload file -->
                <form action="index.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="form_type" value="register">
                    <div>
                        <label for="reg-user">Nama Pengguna:</label>
                        <input type="text" id="reg-user" name="username" required>
                    </div>
                    <div>
                        <label for="reg-pass">Password:</label>
                        <input type="password" id="reg-pass" name="password" required>
                    </div>
                    <div>
                        <label for="reg-pic">Foto Profil (Opsional):</label>
                        <input type="file" id="reg-pic" name="profile_pic" accept="image/*">
                    </div>
                    <button type="submit">Daftar</button>
                    <p class="toggle-form">Sudah punya akun? <a href="#" onclick="toggleForm()">Login di sini</a></p>
                </form>
            </div>

            <?php if ($error): ?>
                <p class="error-message"><?php echo $error; ?></p>
            <?php endif; ?>
        </main>

        <?php else: ?>

        <!-- ======================= -->
        <!--    TAMPILAN BERANDA     -->
        <!-- ======================= -->
        <main class="beranda-container">
            <div class="create-room-form">
                <h2>Buat Room Baru</h2>
                <?php if ($error): ?>
                    <p class="error-message"><?php echo $error; ?></p>
                <?php endif; ?>
                <form action="index.php" method="POST">
                    <input type="hidden" name="action" value="create_room">
                    <input type="text" name="room_name" placeholder="Masukkan nama room..." required>
                    <button type="submit">Buat</button>
                </form>
            </div>

            <div class="room-list">
                <h2>Room Saya</h2>
                <?php if (empty($rooms)): ?>
                    <p>Anda belum menjadi anggota room manapun. Silakan buat room baru!</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($rooms as $room): ?>
                            <li>
                                <a href="chat_room.php?id=<?php echo $room['id']; ?>">
                                    <?php echo htmlspecialchars($room['name']); ?>
                                </a>
                                <span>Dibuat: <?php echo date('d M Y', strtotime($room['created_at'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </main>
        
        <?php endif; ?>

    </div>

    <?php if (!$user_id): ?>
    <script>
        // Script toggle form (sama seperti sebelumnya)
        function toggleForm() {
            var login = document.getElementById('login-form');
            var reg = document.getElementById('register-form');
            if (login.style.display === 'none') {
                login.style.display = 'block';
                reg.style.display = 'none';
            } else {
                login.style.display = 'none';
                reg.style.display = 'block';
            }
        }
    </script>
    <?php endif; ?>

</body>
</html>
