<?php
session_start();
include 'db_config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'error' => ''];

// Cek login DAN room_id
if (!isset($_SESSION['user_id'])) {
    $response['error'] = 'Anda harus login.';
    echo json_encode($response);
    exit;
}
if (!isset($_POST['room_id']) || !is_numeric($_POST['room_id'])) {
    $response['error'] = 'Room ID tidak valid.';
    echo json_encode($response);
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$room_id = (int)$_POST['room_id'];
$message_text = isset($_POST['message']) ? trim($_POST['message']) : '';
$file_path = null;
$file_type = null;

// --- Proses Upload File ---
if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] == 0) {
    
    $file = $_FILES['media_file'];
    $max_size = 20 * 1024 * 1024; // 20MB
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg', 'audio/wav'];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($file['size'] <= $max_size && in_array($mime_type, $allowed_types)) {
        
        $upload_dir = 'uploads/media/'; // Folder media
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file_name = uniqid('media_') . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;

        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            $file_type = $mime_type;
        } else {
            $response['error'] = 'Gagal meng-upload file.';
            $file_path = null;
        }
    } else {
         $response['error'] = 'File tidak valid (Maks 20MB, hanya gambar/video/audio).';
         $file_path = null;
    }
}

// --- Simpan ke Database (dengan room_id) ---
if (!empty($message_text) || $file_path !== null) {
    // Cek dulu apakah room valid (meskipun seharusnya valid)
    $stmt_check = $conn->prepare("SELECT id FROM rooms WHERE id = ?");
    $stmt_check->bind_param("i", $room_id);
    $stmt_check->execute();
    $stmt_check->store_result();

    if($stmt_check->num_rows > 0) {
        $stmt_check->close();
        
        // Masukkan pesan
        $stmt_insert = $conn->prepare("INSERT INTO messages (room_id, user_id, message_text, file_path, file_type) VALUES (?, ?, ?, ?, ?)");
        $stmt_insert->bind_param("iisss", $room_id, $user_id, $message_text, $file_path, $file_type);
        
        if ($stmt_insert->execute()) {
            $response['success'] = true;
        } else {
            $response['error'] = 'Gagal menyimpan pesan ke database.';
        }
        $stmt_insert->close();
    } else {
         $response['error'] = 'Room tidak ada.';
    }

} elseif (empty($message_text) && $file_path === null && isset($_FILES['media_file'])) {
    if(empty($response['error'])) {
        $response['error'] = 'Gagal memproses file.';
    }
} else {
    $response['error'] = 'Pesan tidak boleh kosong.';
}

$conn->close();
echo json_encode($response);
?>

