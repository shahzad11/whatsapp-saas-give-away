function toggleSidebar() {
    document.getElementById('appSidebar').classList.toggle('show');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}

function showAlert(container, type, message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    container.prepend(alertDiv);
    setTimeout(() => alertDiv.remove(), 5000);
}

let qrPollInterval = null;

function startQrPolling(sessionId) {
    const qrImg = document.getElementById('qrImage');
    const qrStatus = document.getElementById('qrStatus');
    const qrContainer = document.getElementById('qrContainer');

    if (!qrImg || !sessionId) return;

    function poll() {
        fetch(`ajax/get-qr.php?session_id=${sessionId}`)
            .then(r => r.json())
            .then(data => {
                if (data.httpCode === 404 || data.error === 'Session not found') {
                    stopQrPolling();
                    qrImg.style.display = 'none';
                    qrStatus.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning fs-2"></i><br>' +
                        'Session expired. <a href="link.php" class="btn btn-sm btn-primary mt-2">Try Again</a>';
                    qrStatus.className = 'qr-status text-warning';
                    return;
                }
                if (data.status === 'connected') {
                    stopQrPolling();
                    qrImg.style.display = 'none';
                    qrStatus.innerHTML = '<i class="bi bi-check-circle-fill text-success fs-1"></i><br>Connected!';
                    qrStatus.className = 'qr-status text-success';
                    setTimeout(() => {
                        window.location.href = 'accounts.php';
                    }, 1500);
                } else if (data.qrImageBase64) {
                    qrImg.src = data.qrImageBase64;
                    qrImg.style.display = 'block';
                    qrStatus.textContent = 'Scan this QR code with your WhatsApp app';
                    qrStatus.className = 'qr-status text-muted';
                } else {
                    qrStatus.textContent = 'Waiting for QR code...';
                }
            })
            .catch(() => {
                qrStatus.textContent = 'Error connecting to backend';
                qrStatus.className = 'qr-status text-danger';
            });
    }

    poll();
    qrPollInterval = setInterval(poll, 3000);
}

function stopQrPolling() {
    if (qrPollInterval) {
        clearInterval(qrPollInterval);
        qrPollInterval = null;
    }
}

function syncAccountStatus(sessionId, row) {
    fetch(`ajax/get-status.php?session_id=${sessionId}`)
        .then(r => r.json())
        .then(data => {
            if (data.ok && row) {
                const badge = row.querySelector('.badge-status');
                if (badge) {
                    badge.textContent = data.status;
                    badge.className = `badge-status badge-${data.status}`;
                }
            }
        });
}

let chatPollInterval = null;
let currentChatId = null;
let currentChatName = null;
let currentSessionId = null;
let allChatsData = [];

function formatChatTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today); yesterday.setDate(yesterday.getDate() - 1);
    const msgDate = new Date(d.getFullYear(), d.getMonth(), d.getDate());

    if (msgDate.getTime() === today.getTime()) {
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true }).toUpperCase();
    } else if (msgDate.getTime() === yesterday.getTime()) {
        return 'Yesterday';
    } else if ((today - msgDate) < 7 * 86400000) {
        return d.toLocaleDateString([], { weekday: 'long' });
    } else {
        return d.toLocaleDateString([], { month: '2-digit', day: '2-digit', year: 'numeric' });
    }
}

function formatMsgTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true }).toUpperCase();
}

function formatDateSeparator(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today); yesterday.setDate(yesterday.getDate() - 1);
    const msgDate = new Date(d.getFullYear(), d.getMonth(), d.getDate());

    if (msgDate.getTime() === today.getTime()) return 'Today';
    if (msgDate.getTime() === yesterday.getTime()) return 'Yesterday';
    return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

function mediaUrl(sessionId, messageId) {
    return `ajax/get-media.php?session_id=${encodeURIComponent(sessionId)}&message_id=${encodeURIComponent(messageId)}`;
}

function openLightbox(src) {
    const overlay = document.createElement('div');
    overlay.className = 'media-lightbox';
    overlay.onclick = () => overlay.remove();
    overlay.innerHTML = `<img src="${src}" onclick="event.stopPropagation()">`;
    document.body.appendChild(overlay);
}

function renderMediaContent(msg) {
    const t = msg.mediaType || 'text';
    const text = escapeHtml(msg.text || '');
    const sid = currentSessionId;
    const mid = msg.id;
    const mUrl = sid ? mediaUrl(sid, mid) : '';

    if (t === 'text') return text || '';

    let html = '';

    if ((t === 'image' || t === 'sticker') && mUrl) {
        const cls = t === 'sticker' ? 'media-sticker' : 'media-image';
        html += `<div class="${cls}">`;
        html += `<img src="${mUrl}" loading="lazy" onclick="openLightbox('${mUrl}')" onerror="this.parentElement.innerHTML='<div class=media-indicator><i class=bi bi-image></i> Photo</div>'">`;
        html += `</div>`;
        if (text) html += `<div class="media-caption">${text}</div>`;
        return html;
    }

    if (t === 'video' && mUrl) {
        html += `<div class="media-video">`;
        html += `<video controls preload="metadata" src="${mUrl}" onerror="this.parentElement.innerHTML='<div class=media-indicator><i class=bi bi-camera-video></i> Video</div>'"></video>`;
        html += `</div>`;
        if (text) html += `<div class="media-caption">${text}</div>`;
        return html;
    }

    if ((t === 'audio' || t === 'voice') && mUrl) {
        html += `<div class="media-audio ${t === 'voice' ? 'media-voice' : ''}">`;
        html += `<i class="bi ${t === 'voice' ? 'bi-mic-fill' : 'bi-music-note-beamed'} me-2"></i>`;
        html += `<audio controls preload="metadata" src="${mUrl}"></audio>`;
        html += `</div>`;
        return html;
    }

    if (t === 'document' && mUrl) {
        const fname = msg.mediaFilename || 'Document';
        html += `<a href="${mUrl}" target="_blank" download="${escapeHtml(fname)}" class="media-document">`;
        html += `<i class="bi bi-file-earmark-arrow-down"></i>`;
        html += `<div class="media-doc-info"><span class="media-doc-name">${escapeHtml(fname)}</span>`;
        if (msg.mediaMime) html += `<span class="media-doc-type">${escapeHtml(msg.mediaMime)}</span>`;
        html += `</div></a>`;
        if (text) html += `<div class="media-caption">${text}</div>`;
        return html;
    }

    if (t === 'contact') {
        const contacts = msg.contactInfo || [];
        if (contacts.length > 0) {
            html += '<div class="media-contacts">';
            for (const c of contacts) {
                const phone = (c.vcard || '').match(/TEL[^:]*:(\+?[\d\s-]+)/i);
                html += `<div class="media-contact-card">`;
                html += `<i class="bi bi-person-circle"></i>`;
                html += `<div><strong>${escapeHtml(c.displayName || 'Contact')}</strong>`;
                if (phone) html += `<br><small>${escapeHtml(phone[1].trim())}</small>`;
                html += `</div></div>`;
            }
            html += '</div>';
            return html;
        }
    }

    if (t === 'location') {
        const loc = msg.locationInfo;
        if (loc && loc.latitude) {
            const mapUrl = `https://www.google.com/maps?q=${loc.latitude},${loc.longitude}`;
            html += `<a href="${mapUrl}" target="_blank" class="media-location">`;
            html += `<div class="media-loc-map"><i class="bi bi-geo-alt-fill"></i></div>`;
            html += `<div class="media-loc-info"><i class="bi bi-geo-alt-fill"></i>`;
            if (loc.name) html += ` <strong>${escapeHtml(loc.name)}</strong>`;
            else html += ` <strong>Open in Google Maps</strong>`;
            if (loc.address) html += `<br><small>${escapeHtml(loc.address)}</small>`;
            html += `<br><small class="text-muted">${loc.latitude.toFixed(5)}, ${loc.longitude.toFixed(5)}</small>`;
            html += `</div></a>`;
            return html;
        }
    }

    // Fallback for unsupported or failed media
    const icons = { image: 'bi-image', video: 'bi-camera-video', audio: 'bi-music-note-beamed', voice: 'bi-mic', document: 'bi-file-earmark', sticker: 'bi-emoji-smile', contact: 'bi-person', location: 'bi-geo-alt' };
    const labels = { image: 'Photo', video: 'Video', audio: 'Audio', voice: 'Voice message', document: 'Document', sticker: 'Sticker', contact: 'Contact', location: 'Location' };
    html += `<div class="media-indicator"><i class="bi ${icons[t] || 'bi-file-earmark'}"></i> <span class="media-label">${labels[t] || t}</span></div>`;
    if (text) html += `<div class="media-caption">${text}</div>`;
    return html;
}

function renderMediaPreview(msg) {
    const t = msg.mediaType || 'text';
    const text = msg.text || '';
    const icons = { image: '📷', video: '🎥', audio: '🎵', voice: '🎤', document: '📄', sticker: '🏷️', contact: '👤', location: '📍' };
    const labels = { image: 'Photo', video: 'Video', audio: 'Audio', voice: 'Voice message', document: 'Document', sticker: 'Sticker', contact: 'Contact', location: 'Location' };

    if (t === 'text') return escapeHtml(text);
    if (text) return `${icons[t] || ''} ${escapeHtml(text)}`;
    return `${icons[t] || ''} ${labels[t] || t}`;
}

function loadChats(sessionId) {
    if (!sessionId) return;
    fetch(`ajax/get-chats.php?session_id=${sessionId}`)
        .then(r => r.json())
        .then(data => {
            const chatList = document.getElementById('chatList');
            if (!chatList) return;
            if (!data.ok || !data.chats || data.chats.length === 0) {
                chatList.innerHTML = '<div class="p-3 text-center text-muted small">No chats yet</div>';
                return;
            }
            allChatsData = data.chats;
            renderChatList(sessionId, data.chats);
        });
}

function renderChatList(sessionId, chats) {
    const chatList = document.getElementById('chatList');
    if (!chatList) return;
    chatList.innerHTML = chats.map(chat => {
        const initial = (chat.name || chat.id).charAt(0).toUpperCase();
        const name = escapeHtml(chat.name || chat.id.split('@')[0]);
        const preview = escapeHtml(chat.lastMessage || '');
        const time = formatChatTime(chat.lastTime);
        const isActive = chat.id === currentChatId;
        const groupIcon = chat.isGroup ? '<i class="bi bi-people-fill me-1 x-small"></i>' : '';
        return `
            <div class="chat-list-item ${isActive ? 'active' : ''}" onclick="openChat('${sessionId}', '${chat.id}', this)">
                <div class="chat-avatar ${chat.isGroup ? 'chat-avatar-group' : ''}">${initial}</div>
                <div class="chat-info">
                    <div class="chat-info-top">
                        <span class="chat-name">${groupIcon}${name}</span>
                        <span class="chat-time">${time}</span>
                    </div>
                    <div class="chat-preview">${preview}</div>
                </div>
            </div>
        `;
    }).join('');
}

function openChat(sessionId, chatId, el) {
    currentChatId = chatId;
    const chatData = allChatsData.find(c => c.id === chatId);
    currentChatName = chatData ? (chatData.name || chatId.split('@')[0]) : chatId.split('@')[0];

    document.querySelectorAll('.chat-list-item').forEach(e => e.classList.remove('active'));
    if (el) el.classList.add('active');

    const chatMain = document.getElementById('chatMain');
    if (!chatMain) return;

    const isGroup = chatId.endsWith('@g.us');
    const initial = currentChatName.charAt(0).toUpperCase();
    const subtext = isGroup ? 'Group' : chatId.split('@')[0];

    chatMain.innerHTML = `
        <div class="chat-main-header">
            <div class="chat-header-avatar">${initial}</div>
            <div class="chat-header-info">
                <div class="chat-header-name">${escapeHtml(currentChatName)}</div>
                <div class="chat-header-status">${escapeHtml(subtext)}</div>
            </div>
        </div>
        <div class="chat-messages" id="chatMessages">
            <div class="chat-loading"><div class="spinner-border spinner-border-sm"></div> Loading messages...</div>
        </div>
        <div class="chat-input-area">
            <input type="text" class="form-control" id="messageInput" placeholder="Type a message..."
                   onkeypress="if(event.key==='Enter')sendMessage('${sessionId}','${chatId}')">
            <button class="btn btn-send" onclick="sendMessage('${sessionId}','${chatId}')">
                <i class="bi bi-send-fill"></i>
            </button>
        </div>
    `;

    currentSessionId = sessionId;
    loadMessages(sessionId, chatId);
    if (chatPollInterval) clearInterval(chatPollInterval);
    chatPollInterval = setInterval(() => loadMessages(sessionId, chatId), 5000);
}

function loadMessages(sessionId, chatId) {
    fetch(`ajax/get-messages.php?session_id=${sessionId}&chat_id=${encodeURIComponent(chatId)}`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('chatMessages');
            if (!container || !data.ok) return;

            if (data.sessionId) currentSessionId = data.sessionId;
            const wasAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 50;
            const msgs = data.messages || [];

            if (msgs.length === 0) {
                container.innerHTML = '<div class="chat-no-messages">No messages yet</div>';
                return;
            }

            let html = '';
            let lastDate = '';

            for (const msg of msgs) {
                const msgDateStr = msg.time ? msg.time.split(' ')[0] : '';
                if (msgDateStr && msgDateStr !== lastDate) {
                    lastDate = msgDateStr;
                    html += `<div class="date-separator"><span>${formatDateSeparator(msg.time)}</span></div>`;
                }

                const content = renderMediaContent(msg);
                const time = formatMsgTime(msg.time);
                const bubbleClass = msg.fromMe ? 'outgoing' : 'incoming';
                const tickMark = msg.fromMe ? ' <i class="bi bi-check2-all tick-mark"></i>' : '';

                html += `<div class="msg-row ${msg.fromMe ? 'msg-out' : 'msg-in'}">`;
                html += `<div class="chat-bubble ${bubbleClass}">`;
                if (!msg.fromMe && msg.senderName && currentChatId && currentChatId.endsWith('@g.us')) {
                    html += `<div class="bubble-sender">${escapeHtml(msg.senderName)}</div>`;
                }
                html += `<div class="bubble-content">${content}</div>`;
                html += `<div class="bubble-meta"><span class="bubble-time">${time}</span>${tickMark}</div>`;
                html += `</div></div>`;
            }

            container.innerHTML = html;
            if (wasAtBottom || container.dataset.firstLoad !== 'done') {
                container.scrollTop = container.scrollHeight;
                container.dataset.firstLoad = 'done';
            }
        });
}

function sendMessage(sessionId, chatId) {
    const input = document.getElementById('messageInput');
    if (!input || !input.value.trim()) return;
    const text = input.value.trim();
    input.value = '';

    fetch('ajax/send-message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: sessionId, chat_id: chatId, text: text })
    })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                loadMessages(sessionId, chatId);
            }
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
