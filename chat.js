document.addEventListener("DOMContentLoaded", () => {
    // Hanya jalankan jika kita berada di halaman chat
    const roomInfo = document.getElementById('room-info');
    if (roomInfo) {
        
        // Ambil ID dari atribut data
        const ROOM_ID = roomInfo.dataset.roomId;
        const CURRENT_USER_ID = roomInfo.dataset.userId;

        const chatBox = document.getElementById('chat-box');
        const messageForm = document.getElementById('message-form');
        const messageInput = document.getElementById('message-input');
        const attachBtn = document.getElementById('attach-btn');
        const mediaFileInput = document.getElementById('media-file-input');
        const filePreview = document.getElementById('file-preview');

        let selectedFile = null;

        // --- Fungsi untuk mengambil pesan (sekarang mengirim room_id) ---
        function getMessages() {
            // Tambahkan room_id sebagai parameter query
            fetch(`get_messages.php?room_id=${ROOM_ID}`)
                .then(response => response.json())
                .then(data => {
                    chatBox.innerHTML = ''; // Kosongkan chat box
                    if (data.success && data.messages.length > 0) {
                        data.messages.forEach(msg => {
                            displayMessage(msg);
                        });
                        // Scroll ke paling bawah
                        chatBox.scrollTop = chatBox.scrollHeight;
                    } else if (data.messages.length === 0) {
                        chatBox.innerHTML = '<p class="system-message">Belum ada pesan di room ini.</p>';
                    } else {
                        console.error("Gagal mengambil pesan:", data.error);
                    }
                })
                .catch(error => console.error('Error fetching messages:', error));
        }

        // --- Fungsi untuk menampilkan satu pesan (dengan foto profil) ---
        function displayMessage(msg) {
            const msgElement = document.createElement('div');
            msgElement.classList.add('message-wrapper'); // Wrapper baru

            // Cek apakah pesan ini milik kita
            if (msg.user_id == CURRENT_USER_ID) { 
               msgElement.classList.add('own-message');
            }

            // Foto Profil
            const profilePic = document.createElement('img');
            profilePic.src = msg.profile_pic || 'uploads/profiles/default.png';
            profilePic.alt = 'pic';
            profilePic.classList.add('message-profile-pic');
            
            // Konten Pesan
            const msgContent = document.createElement('div');
            msgContent.classList.add('message-content');

            let messageHTML = `<strong>${msg.username} (ID: ${msg.user_id})</strong><br>`;

            if (msg.message_text) {
                messageHTML += `<p>${msg.message_text}</p>`;
            }

            // Tampilkan file jika ada
            if (msg.file_path) {
                if (msg.file_type.startsWith('image/')) {
                    messageHTML += `<img src="${msg.file_path}" alt="Gambar" class="chat-media">`;
                } else if (msg.file_type.startsWith('video/')) {
                    messageHTML += `<video controls src="${msg.file_path}" class="chat-media"></video>`;
                } else if (msg.file_type.startsWith('audio/')) {
                    messageHTML += `<audio controls src="${msg.file_path}" class="chat-media"></audio>`;
                } else {
                    messageHTML += `<a href="${msg.file_path}" target="_blank">Lihat File: ${msg.file_path}</a>`;
                }
            }

            messageHTML += `<span class="timestamp">${new Date(msg.created_at).toLocaleString()}</span>`;
            msgContent.innerHTML = messageHTML;

            // Gabungkan
            msgElement.appendChild(profilePic);
            msgElement.appendChild(msgContent);
            chatBox.appendChild(msgElement);
        }

        // --- Event Listener untuk form kirim pesan (sekarang mengirim room_id) ---
        messageForm.addEventListener('submit', (e) => {
            e.preventDefault();
            
            const messageText = messageInput.value.trim();
            if (messageText === '' && !selectedFile) return;

            const formData = new FormData();
            formData.append('message', messageText);
            formData.append('room_id', ROOM_ID); // KIRIM ROOM ID
            if (selectedFile) {
                formData.append('media_file', selectedFile);
            }

            fetch('send_message.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageInput.value = '';
                    resetFileInput();
                    getMessages(); // Ambil pesan lagi (termasuk pesan kita)
                } else {
                    alert('Gagal mengirim pesan: ' + data.error);
                }
            })
            .catch(error => console.error('Error sending message:', error));
        });

        // --- Fungsi helper (sama seperti sebelumnya) ---
        attachBtn.addEventListener('click', () => mediaFileInput.click());

        mediaFileInput.addEventListener('change', () => {
            if (mediaFileInput.files.length > 0) {
                selectedFile = mediaFileInput.files[0];
                filePreview.innerHTML = `File dipilih: <strong>${selectedFile.name}</strong> <button id="cancel-file-btn">X</button>`;
                filePreview.style.display = 'block';
                // Tambahkan event listener ke tombol batal yang baru dibuat
                document.getElementById('cancel-file-btn').addEventListener('click', resetFileInput);
            }
        });

        window.resetFileInput = () => {
            selectedFile = null;
            mediaFileInput.value = ''; 
            filePreview.style.display = 'none';
            filePreview.innerHTML = '';
        }

        // --- Polling ---
        getMessages(); // Ambil pesan saat pertama kali memuat
        setInterval(getMessages, 3000); // Ambil pesan baru setiap 3 detik
    }
});

