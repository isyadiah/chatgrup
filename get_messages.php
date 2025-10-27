<?php
session_start();
include 'db_config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'messages' => []];

// Cek login DAN room_id
if (!isset($_SESSION['user_id'])) {
    $response['error'] = 'Tidak terautentikasi.';
    echo json_encode($response);
    exit;
}
if (!isset($_GET['room_id']) || !is_numeric($_GET['room_id'])) {
    $response['error'] = 'Room ID tidak ada.';
    echo json_encode($response);
    exit;
}

$room_id = (int)$_GET['room_id'];

// Ambil pesan DARI ROOM SPESIFIK dan JOIN dengan tabel users
// Kita ambil 100 pesan terakhir
$query = "SELECT m.id, m.user_id, m.message_text, m.file_path, m.file_type, m.created_at, 
                 u.username, u.profile_pic
          FROM messages m
          JOIN users u ON m.user_id = u.id
          WHERE m.room_id = ? 
          AND m.id > (SELECT IFNULL(MAX(id) - 100, 0) FROM messages WHERE room_id = ?)
          ORDER BY m.id ASC";
          
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $room_id, $room_id);

if ($stmt->execute()) {
    $result = $stmt->get_result();
    $response['success'] = true;
    while ($row = $result->fetch_assoc()) {
        // Bersihkan output
        $row['username'] = htmlspecialchars($row['username']);
        $row['message_text'] = $row['message_text'] ? htmlspecialchars($row['message_text']) : null;
        $response['messages'][] = $row;
    }
} else {
    $response['error'] = 'Gagal mengambil pesan.';
}

$stmt->close();
$conn->close();
echo json_encode($response);
?>

