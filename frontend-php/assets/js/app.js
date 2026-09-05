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

// Which list the sidebar is showing. WhatsApp Web keeps archived chats out of
// the main list entirely and only reveals them inside their own view.
let showingArchived = false;

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

function toggleArchivedView(sessionId) {
    showingArchived = !showingArchived;
    renderChatList(sessionId, allChatsData);
    const search = document.getElementById('chatSearch');
    if (search) { search.value = ''; }
}

function renderChatList(sessionId, chats) {
    const chatList = document.getElementById('chatList');
    if (!chatList) return;

    const archived = chats.filter(c => c.archived);
    const active = chats.filter(c => !c.archived);
    const visible = showingArchived ? archived : active;

    let html = '';

    if (showingArchived) {
        html += `
            <div class="chat-list-header" onclick="toggleArchivedView('${escapeAttr(sessionId)}')">
                <i class="bi bi-arrow-left me-2"></i><span>Archived</span>
                <span class="chat-list-count">${archived.length}</span>
            </div>
        `;
    } else if (archived.length > 0) {
        // Only offered when there is something archived, so the row is never a
        // dead end.
        html += `
            <div class="chat-list-header" onclick="toggleArchivedView('${escapeAttr(sessionId)}')">
                <i class="bi bi-archive me-2"></i><span>Archived</span>
                <span class="chat-list-count">${archived.length}</span>
            </div>
        `;
    }

    if (visible.length === 0) {
        html += `<div class="p-3 text-center text-muted small">${showingArchived ? 'No archived chats' : 'No chats yet'}</div>`;
        chatList.innerHTML = html;
        return;
    }

    html += visible.map(chat => {
        // The server guarantees a human-readable name ("Group chat", "+92…",
        // "Unknown contact"), so there is no JID fallback to do here.
        const name = escapeHtml(chat.name || '');
        const initial = chat.isGroup ? '' : (chat.name || '?').charAt(0).toUpperCase();
        const avatar = chat.isGroup
            ? '<i class="bi bi-people-fill"></i>'
            : initial;
        const preview = escapeHtml(chat.lastMessage || '');
        const time = formatChatTime(chat.lastTime);
        const isActive = chat.id === currentChatId;
        return `
            <div class="chat-list-item ${isActive ? 'active' : ''}" onclick="openChat('${escapeAttr(sessionId)}', '${escapeAttr(chat.id)}', this)">
                <div class="chat-avatar ${chat.isGroup ? 'chat-avatar-group' : ''}">${avatar}</div>
                <div class="chat-info">
                    <div class="chat-info-top">
                        <span class="chat-name">${name}</span>
                        <span class="chat-time">${time}</span>
                    </div>
                    <div class="chat-preview">${preview}</div>
                </div>
            </div>
        `;
    }).join('');

    chatList.innerHTML = html;
}

function openChat(sessionId, chatId, el) {
    currentChatId = chatId;
    const chatData = allChatsData.find(c => c.id === chatId);
    const isGroup = chatId.endsWith('@g.us');
    // The name comes from the server, which never returns a JID. Falling back
    // to the JID prefix here was how raw @lid identifiers reached the header.
    currentChatName = (chatData && chatData.name) || (isGroup ? 'Group chat' : 'Unknown contact');

    document.querySelectorAll('.chat-list-item').forEach(e => e.classList.remove('active'));
    if (el) el.classList.add('active');

    const chatMain = document.getElementById('chatMain');
    if (!chatMain) return;

    const avatar = isGroup
        ? '<i class="bi bi-people-fill"></i>'
        : currentChatName.charAt(0).toUpperCase();

    // Subtext: never the JID. A group says so; a contact shows the phone number
    // only when it is not already the title.
    let subtext;
    if (isGroup) {
        subtext = 'Group';
    } else if (chatData && chatData.name && chatData.name.startsWith('+')) {
        subtext = '';
    } else if (chatId.endsWith('@s.whatsapp.net')) {
        subtext = '+' + chatId.split('@')[0].replace(/\D/g, '');
    } else {
        subtext = '';
    }
    const archivedBadge = (chatData && chatData.archived)
        ? '<span class="chat-header-archived"><i class="bi bi-archive me-1"></i>Archived</span>'
        : '';

    chatMain.innerHTML = `
        <div class="chat-main-header">
            <div class="chat-header-avatar ${isGroup ? 'chat-avatar-group' : ''}">${avatar}</div>
            <div class="chat-header-info">
                <div class="chat-header-name">${escapeHtml(currentChatName)}${archivedBadge}</div>
                <div class="chat-header-status">${escapeHtml(subtext)}</div>
            </div>
        </div>
        <div class="chat-messages" id="chatMessages">
            <div class="chat-loading"><div class="spinner-border spinner-border-sm"></div> Loading messages...</div>
        </div>
        <div class="composer">
            <div class="composer-error" id="composerError" style="display:none;"></div>
            <div class="composer-preview" id="composerPreview" style="display:none;"></div>
            <div class="composer-recording" id="composerRecording" style="display:none;">
                <span class="rec-dot"></span>
                <span class="rec-label">Recording</span>
                <span class="rec-timer" id="recTimer">0:00</span>
                <button class="btn btn-link btn-sm rec-cancel" onclick="cancelVoiceRecording()">Cancel</button>
            </div>
            <div class="chat-input-area">
                <div class="dropup composer-attach">
                    <button class="btn btn-attach" data-bs-toggle="dropdown" title="Attach">
                        <i class="bi bi-paperclip"></i>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="pickAttachment('image');return false;"><i class="bi bi-image me-2"></i>Photo</a></li>
                        <li><a class="dropdown-item" href="#" onclick="pickAttachment('video');return false;"><i class="bi bi-camera-video me-2"></i>Video</a></li>
                        <li><a class="dropdown-item" href="#" onclick="pickAttachment('camera');return false;"><i class="bi bi-camera me-2"></i>Camera</a></li>
                        <li><a class="dropdown-item" href="#" onclick="pickAttachment('audio');return false;"><i class="bi bi-music-note-beamed me-2"></i>Audio file</a></li>
                        <li><a class="dropdown-item" href="#" onclick="pickAttachment('document');return false;"><i class="bi bi-file-earmark me-2"></i>Document</a></li>
                    </ul>
                    <input type="file" id="attachInput" class="d-none" onchange="attachmentChosen(this)">
                </div>
                <input type="text" class="form-control" id="messageInput" placeholder="Type a message..."
                       onkeypress="if(event.key==='Enter')composerSend('${escapeAttr(sessionId)}','${escapeAttr(chatId)}')">
                <button class="btn btn-mic" id="micButton" onclick="toggleVoiceRecording('${escapeAttr(sessionId)}','${escapeAttr(chatId)}')" title="Record a voice note">
                    <i class="bi bi-mic-fill"></i>
                </button>
                <button class="btn btn-send" id="sendButton" onclick="composerSend('${escapeAttr(sessionId)}','${escapeAttr(chatId)}')">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
        </div>
    `;

    // Switching chats abandons anything staged in the old composer — the markup
    // above has just replaced it, so leaving the state behind would attach a
    // file to the wrong conversation.
    discardVoiceRecording();
    pendingAttachment = null;

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
            let lastSender = null;
            const inGroup = !!(currentChatId && currentChatId.endsWith('@g.us'));

            for (const msg of msgs) {
                const msgDateStr = msg.time ? msg.time.split(' ')[0] : '';
                if (msgDateStr && msgDateStr !== lastDate) {
                    lastDate = msgDateStr;
                    lastSender = null; // repeat the name after a date break
                    html += `<div class="date-separator"><span>${formatDateSeparator(msg.time)}</span></div>`;
                }

                const content = renderMediaContent(msg);
                const time = formatMsgTime(msg.time);
                const bubbleClass = msg.fromMe ? 'outgoing' : 'incoming';
                const tickMark = msg.fromMe ? ' <i class="bi bi-check2-all tick-mark"></i>' : '';

                html += `<div class="msg-row ${msg.fromMe ? 'msg-out' : 'msg-in'}">`;
                html += `<div class="chat-bubble ${bubbleClass}">`;
                // Group threads label the sender, once per run of consecutive
                // messages from the same person — as WhatsApp does. The server
                // only populates senderName for group messages.
                if (inGroup && !msg.fromMe && msg.senderName && msg.senderName !== lastSender) {
                    html += `<div class="bubble-sender">${escapeHtml(msg.senderName)}</div>`;
                }
                lastSender = msg.fromMe ? null : (msg.senderName || null);
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
            } else {
                // A failed text send used to vanish silently, taking the typed
                // message with it. Put it back and say why.
                input.value = text;
                showComposerError(data.error || 'Message could not be sent');
            }
        })
        .catch(() => {
            input.value = text;
            showComposerError('Message could not be sent');
        });
}

/* ===== Composer attachments =====
 *
 * The staged file lives here rather than in the DOM: the file input is cleared
 * as soon as a file is chosen so re-picking the same file still fires change,
 * and a Blob from MediaRecorder was never in an input to begin with.
 */
let pendingAttachment = null;   // { kind, file, name, size, previewUrl }
let mediaRecorder = null;
let recordedChunks = [];
let recordingTimer = null;
let recordingStartedAt = 0;

// Mirrors MAX_ATTACHMENT_BYTES in config/app.php. This copy is UX only — the
// authoritative checks are in send-media.php and the backend, which a caller
// bypassing this page still has to pass.
const MAX_ATTACHMENT_BYTES = 16 * 1024 * 1024;

// Server-side accept lists reject on sniffed content, so these hints only steer
// the picker; 'camera' is a photo whose input asks a phone for the camera app.
const ATTACH_ACCEPT = {
    image: 'image/*',
    video: 'video/*',
    camera: 'image/*',
    audio: 'audio/*',
    document: ''
};

function showComposerError(message) {
    const box = document.getElementById('composerError');
    if (!box) return;
    box.textContent = message;
    box.style.display = '';
    clearTimeout(showComposerError._t);
    showComposerError._t = setTimeout(() => { box.style.display = 'none'; }, 8000);
}

function clearComposerError() {
    const box = document.getElementById('composerError');
    if (box) box.style.display = 'none';
}

function humanSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
}

function pickAttachment(kind) {
    const input = document.getElementById('attachInput');
    if (!input) return;
    input.accept = ATTACH_ACCEPT[kind] ?? '';
    if (kind === 'camera') input.setAttribute('capture', 'environment');
    else input.removeAttribute('capture');
    input.dataset.kind = kind;
    input.value = '';
    input.click();
}

function attachmentChosen(input) {
    const file = input.files && input.files[0];
    // 'camera' is only a picker hint; a photo from it is an image send.
    const picked = input.dataset.kind === 'camera' ? 'image' : input.dataset.kind;
    input.value = '';
    if (!file) return;

    if (file.size === 0) return showComposerError('That file is empty.');
    if (file.size > MAX_ATTACHMENT_BYTES) {
        return showComposerError(`"${file.name}" is ${humanSize(file.size)} — the limit is ${humanSize(MAX_ATTACHMENT_BYTES)}.`);
    }

    stageAttachment({ kind: picked, file, name: file.name, size: file.size });
}

function stageAttachment(attachment) {
    clearComposerError();
    if (pendingAttachment?.previewUrl) URL.revokeObjectURL(pendingAttachment.previewUrl);

    const isVisual = attachment.kind === 'image' || attachment.kind === 'video';
    attachment.previewUrl = isVisual ? URL.createObjectURL(attachment.file) : null;
    pendingAttachment = attachment;

    const box = document.getElementById('composerPreview');
    if (!box) return;

    const thumb = attachment.kind === 'image'
        ? `<img src="${attachment.previewUrl}" alt="">`
        : `<i class="bi ${{ video: 'bi-camera-video', audio: 'bi-music-note-beamed', voice: 'bi-mic-fill' }[attachment.kind] || 'bi-file-earmark'}"></i>`;

    box.innerHTML = `
        <div class="composer-preview-thumb">${thumb}</div>
        <div class="composer-preview-info">
            <div class="composer-preview-name">${escapeHtml(attachment.name)}</div>
            <div class="composer-preview-meta">${humanSize(attachment.size)}</div>
            <div class="composer-progress" id="composerProgress" style="display:none;"><div class="composer-progress-bar" id="composerProgressBar"></div></div>
        </div>
        <button class="btn btn-link btn-sm composer-preview-remove" onclick="clearAttachment()" title="Remove">
            <i class="bi bi-x-lg"></i>
        </button>
    `;
    box.style.display = '';

    const input = document.getElementById('messageInput');
    if (input) {
        // Captions are not supported on audio: WhatsApp drops them.
        const captionable = attachment.kind !== 'audio' && attachment.kind !== 'voice';
        input.placeholder = captionable ? 'Add a caption...' : 'Send without a caption';
        input.disabled = !captionable;
        if (captionable) input.focus();
    }
}

function clearAttachment() {
    if (pendingAttachment?.previewUrl) URL.revokeObjectURL(pendingAttachment.previewUrl);
    pendingAttachment = null;

    const box = document.getElementById('composerPreview');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
    const input = document.getElementById('messageInput');
    if (input) { input.placeholder = 'Type a message...'; input.disabled = false; }
    clearComposerError();
}

// One send button for both cases: with something staged it sends the
// attachment, otherwise the typed text.
function composerSend(sessionId, chatId) {
    if (pendingAttachment) return sendAttachment(sessionId, chatId);
    return sendMessage(sessionId, chatId);
}

function sendAttachment(sessionId, chatId) {
    const attachment = pendingAttachment;
    if (!attachment) return;

    const input = document.getElementById('messageInput');
    const caption = input && !input.disabled ? input.value.trim() : '';

    const form = new FormData();
    form.append('csrf_token', window.waCsrfToken || '');
    form.append('session_id', sessionId);
    form.append('chat_id', chatId);
    form.append('kind', attachment.kind);
    form.append('caption', caption);
    form.append('file', attachment.file, attachment.name);

    setComposerBusy(true);
    const progress = document.getElementById('composerProgress');
    const bar = document.getElementById('composerProgressBar');
    if (progress) progress.style.display = '';

    // XHR rather than fetch: upload progress is the whole point, and fetch still
    // cannot report it.
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'ajax/send-media.php');
    xhr.upload.onprogress = e => {
        if (!bar || !e.lengthComputable) return;
        bar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
    };
    xhr.onload = () => {
        setComposerBusy(false);
        let data = null;
        try { data = JSON.parse(xhr.responseText); } catch (_) {}

        if (data && data.ok) {
            if (input) input.value = '';
            clearAttachment();
            loadMessages(sessionId, chatId);
            return;
        }
        // The file stays staged so the send can be retried without re-picking.
        if (progress) progress.style.display = 'none';
        showComposerError((data && data.error) || `Upload failed (HTTP ${xhr.status})`);
    };
    xhr.onerror = () => {
        setComposerBusy(false);
        if (progress) progress.style.display = 'none';
        showComposerError('Upload failed — check your connection and try again.');
    };
    xhr.send(form);
}

function setComposerBusy(busy) {
    for (const id of ['sendButton', 'micButton']) {
        const el = document.getElementById(id);
        if (el) el.disabled = busy;
    }
    const send = document.getElementById('sendButton');
    if (send) {
        send.innerHTML = busy
            ? '<span class="spinner-border spinner-border-sm"></span>'
            : '<i class="bi bi-send-fill"></i>';
    }
}

/* ===== Voice notes =====
 *
 * MediaRecorder's output container is browser-dependent (webm/opus in Chrome,
 * ogg/opus in Firefox, mp4/aac in Safari). None of that is decided here: the
 * backend transcodes whatever arrives to ogg/opus, which is the only format
 * WhatsApp renders as a voice message.
 */
function preferredRecordingMime() {
    const candidates = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm', 'audio/mp4'];
    if (typeof MediaRecorder === 'undefined') return null;
    return candidates.find(m => MediaRecorder.isTypeSupported(m)) || '';
}

function toggleVoiceRecording(sessionId, chatId) {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        // Stop and send; onstop stages the blob.
        mediaRecorder.stop();
        return;
    }
    startVoiceRecording();
}

function startVoiceRecording() {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        return showComposerError('This browser cannot record audio. Attach an audio file instead.');
    }

    clearComposerError();
    const mimeType = preferredRecordingMime();

    navigator.mediaDevices.getUserMedia({ audio: true })
        .then(stream => {
            recordedChunks = [];
            mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
            mediaRecorder.ondataavailable = e => { if (e.data.size > 0) recordedChunks.push(e.data); };
            mediaRecorder.onstop = () => {
                stream.getTracks().forEach(t => t.stop());
                stopRecordingUi();
                const type = mediaRecorder?.mimeType || mimeType || 'audio/webm';
                mediaRecorder = null;

                const blob = new Blob(recordedChunks, { type });
                recordedChunks = [];
                if (blob.size === 0) return showComposerError('Nothing was recorded.');
                if (blob.size > MAX_ATTACHMENT_BYTES) {
                    return showComposerError(`That recording is ${humanSize(blob.size)} — the limit is ${humanSize(MAX_ATTACHMENT_BYTES)}.`);
                }

                // Extension follows the container so the server's sniffed type
                // and the filename agree.
                const ext = type.includes('ogg') ? 'ogg' : type.includes('mp4') ? 'm4a' : 'webm';
                stageAttachment({
                    kind: 'voice',
                    file: blob,
                    name: `voice-note.${ext}`,
                    size: blob.size
                });
            };
            mediaRecorder.start();
            startRecordingUi();
        })
        .catch(err => {
            showComposerError(err && err.name === 'NotAllowedError'
                ? 'Microphone access was blocked. Allow it in your browser to record voice notes.'
                : 'Could not start recording: ' + (err?.message || 'unknown error'));
        });
}

// Abandons a recording without staging it. Also the cleanup path when the user
// switches chats mid-recording.
function cancelVoiceRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.onstop = null;
        const stream = mediaRecorder.stream;
        mediaRecorder.stop();
        stream?.getTracks().forEach(t => t.stop());
    }
    mediaRecorder = null;
    recordedChunks = [];
    stopRecordingUi();
}

function discardVoiceRecording() {
    cancelVoiceRecording();
}

function startRecordingUi() {
    recordingStartedAt = Date.now();
    const bar = document.getElementById('composerRecording');
    const mic = document.getElementById('micButton');
    if (bar) bar.style.display = '';
    if (mic) mic.classList.add('recording');

    const tick = () => {
        const el = document.getElementById('recTimer');
        if (!el) return;
        const secs = Math.floor((Date.now() - recordingStartedAt) / 1000);
        el.textContent = `${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, '0')}`;
    };
    tick();
    clearInterval(recordingTimer);
    recordingTimer = setInterval(tick, 1000);
}

function stopRecordingUi() {
    clearInterval(recordingTimer);
    recordingTimer = null;
    const bar = document.getElementById('composerRecording');
    const mic = document.getElementById('micButton');
    if (bar) bar.style.display = 'none';
    if (mic) mic.classList.remove('recording');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// For a value interpolated into an inline handler, e.g.
// onclick="openChat('...', '<here>')".
//
// escapeHtml() is not enough: it leaves the single quote alone, so a value
// containing one would close the JS string literal and everything after it
// would be executed. WhatsApp JIDs cannot contain quotes today, which makes
// this theoretical — but the ids flow from a remote party through the database
// into markup, and that is exactly the path that stops being theoretical when
// someone later reuses the helper for a user-supplied value.
function escapeAttr(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}
