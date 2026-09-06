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

    // The header badge used to be left at its initial "Waiting..." for the whole
    // flow, so it still said Waiting while the status line underneath it said
    // Connected. Two contradicting indicators is worse than one.
    function setBadge(text, cls) {
        const badge = document.getElementById('connectionBadge');
        if (badge) {
            badge.textContent = text;
            badge.className = 'badge ' + cls;
        }
    }

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
                    setBadge('Expired', 'bg-warning text-dark');
                    return;
                }
                if (data.status === 'connected') {
                    stopQrPolling();
                    qrImg.style.display = 'none';
                    qrStatus.innerHTML = '<i class="bi bi-check-circle-fill text-success fs-1"></i><br>Connected!';
                    qrStatus.className = 'qr-status text-success';
                    setBadge('Connected', 'bg-success');
                    setTimeout(() => {
                        window.location.href = 'accounts.php';
                    }, 1500);
                } else if (data.qrImageBase64) {
                    qrImg.src = data.qrImageBase64;
                    qrImg.style.display = 'block';
                    qrStatus.textContent = 'Scan this QR code with your WhatsApp app';
                    qrStatus.className = 'qr-status text-muted';
                    setBadge('Scan to link', 'bg-info text-dark');
                } else {
                    qrStatus.textContent = 'Waiting for QR code...';
                    setBadge('Waiting...', 'bg-warning text-dark');
                }
            })
            .catch(() => {
                // Not "backend": the person looking at this screen is trying to
                // link a phone and has no model of the services behind the page.
                // The poll keeps running, so this is a transient notice.
                qrStatus.textContent = 'Still trying to reach WhatsApp — leave this page open.';
                qrStatus.className = 'qr-status text-danger';
                setBadge('Reconnecting', 'bg-danger');
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

/* ===== WhatsApp text formatting =====
 *
 * Messages arrive as plain text carrying WhatsApp's markers, and we were
 * printing them literally — a bulk-forwarded job post reads as a wall of
 * asterisks instead of bold headings. Both the phone app and WhatsApp Web
 * render these, so a client that does not is visibly broken.
 *
 * Supported, matching WhatsApp: *bold*, _italic_, ~strikethrough~,
 * ```monospace blocks```, `inline code`, "> " quotes, "- " bullet lists,
 * "1. " numbered lists, and clickable links.
 *
 * The order below is not incidental. Escaping happens first, so nothing here
 * can produce markup from message content. Code spans and URLs are then lifted
 * out into placeholders *before* any inline formatting runs, because a URL
 * containing underscores (very common) would otherwise be chopped into italics,
 * and code is meant to be literal.
 */
const WA_PLACEHOLDER = '\u0000';

function waEscapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// A delimiter only counts when it hugs the text: "*bold*" formats, "* not *"
// does not. This mirrors WhatsApp, and it is what stops a bare asterisk in
// "2 * 3" from opening a run that swallows the rest of the message.
// '>' and '<' count as boundaries so *_nested_* works: by the time the italic
// pass runs, the underscore sits against the <strong> tag the bold pass added.
// A literal angle bracket from the message was escaped to &lt;/&gt; long before
// this, so the only brackets present are our own.
function applyInlineMarker(html, marker, tag) {
    const m = marker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const re = new RegExp(
        `(^|[\\s.,!?;:('"¡¿\\-—(\\[{>])${m}(\\S|\\S[\\s\\S]*?\\S)${m}(?=$|[\\s.,!?;:)'"\\-—)\\]}<])`,
        'g'
    );
    // Repeat so sibling runs on one line all match — a global regex resumes
    // after the previous match and would skip a delimiter it used as a boundary.
    let out = html;
    for (let i = 0; i < 3; i++) {
        const next = out.replace(re, `$1<${tag}>$2</${tag}>`);
        if (next === out) break;
        out = next;
    }
    return out;
}

function formatMessageText(raw) {
    if (raw === null || raw === undefined) return '';
    // Null bytes are the placeholder marker; a message may not smuggle one in.
    // CRLF is normalised because JS treats \r as a line terminator: '.' does not
    // match it, so a "1. item\r" line silently failed to be seen as a list at
    // all. Plenty of forwarded messages arrive with Windows line endings.
    let text = String(raw).replace(/\u0000/g, '').replace(/\r\n?/g, '\n');
    if (text === '') return '';

    let html = waEscapeHtml(text);

    const stash = [];
    const keep = (replacement) => {
        stash.push(replacement);
        return WA_PLACEHOLDER + (stash.length - 1) + WA_PLACEHOLDER;
    };

    // 1. Fenced monospace, then inline code. Contents stay literal.
    html = html.replace(/```([\s\S]+?)```/g, (_, code) => keep(`<pre class="wa-pre">${code}</pre>`));
    html = html.replace(/`([^`\n]+)`/g, (_, code) => keep(`<code class="wa-code">${code}</code>`));

    // 2. Links. Lifted out before inline formatting so underscores and tildes
    //    inside a URL survive; www. is included because people paste it.
    html = html.replace(/\b((?:https?:\/\/|www\.)[^\s<]+)/gi, (match) => {
        // Trailing punctuation belongs to the sentence, not the URL — and the
        // formatting markers belong to the run around it. "*see https://x.com*"
        // used to put the closing asterisk inside the href.
        const trail = match.match(/[.,;:!?)\]}'"*_~]+$/);
        const url = trail ? match.slice(0, -trail[0].length) : match;
        const href = /^www\./i.test(url) ? 'https://' + url : url;
        return keep(`<a href="${href}" target="_blank" rel="noopener noreferrer nofollow">${url}</a>`) + (trail ? trail[0] : '');
    });

    // 3. Block structure, line by line: quotes and lists.
    const lines = html.split('\n');
    const out = [];
    let list = null;      // 'ul' | 'ol' | null
    let quoting = false;

    const closeList = () => { if (list) { out.push(`</${list}>`); list = null; } };
    const closeQuote = () => { if (quoting) { out.push('</blockquote>'); quoting = false; } };

    for (const line of lines) {
        // '>' has already been escaped by this point.
        const quote = line.match(/^&gt;\s?(.*)$/);
        const bullet = line.match(/^\s*[-*]\s+(.+)$/);
        const numbered = line.match(/^\s*\d+[.)]\s+(.+)$/);

        if (quote) {
            closeList();
            if (!quoting) { out.push('<blockquote class="wa-quote">'); quoting = true; }
            out.push(formatInline(quote[1]) + '<br>');
            continue;
        }
        closeQuote();

        if (bullet || numbered) {
            const want = bullet ? 'ul' : 'ol';
            if (list !== want) { closeList(); out.push(`<${want} class="wa-list">`); list = want; }
            out.push(`<li>${formatInline((bullet || numbered)[1])}</li>`);
            continue;
        }
        closeList();
        out.push(formatInline(line) + '<br>');
    }
    closeList();
    closeQuote();

    html = out.join('')
        // A trailing <br> from the last line adds phantom height to the bubble.
        .replace(/(<br>)+$/, '');

    // 4. Put the code spans and links back.
    return html.replace(new RegExp(WA_PLACEHOLDER + '(\\d+)' + WA_PLACEHOLDER, 'g'), (_, i) => stash[Number(i)]);
}

// Two rounds, because nesting is order-dependent: in "_*name*_" the bold pass
// sees an asterisk hugged by underscores and skips it, and only after the italic
// pass has wrapped the run in a tag does that asterisk sit against a '>' it can
// use as a boundary. One round formatted "*_x_*" but left "_*x*_" literal.
// The cap is one round per marker: the worst case, "~_*x*_~", unwraps exactly
// one layer per pass. The loop exits as soon as a round changes nothing.
function formatInline(line) {
    let s = line;
    for (let round = 0; round < 3; round++) {
        const before = s;
        s = applyInlineMarker(s, '*', 'strong');
        s = applyInlineMarker(s, '_', 'em');
        s = applyInlineMarker(s, '~', 'del');
        if (s === before) break;
    }
    return s;
}

// The chat list shows one line of plain text, so markers are removed rather
// than rendered — the same thing WhatsApp Web does in its sidebar.
function stripFormatting(text) {
    if (!text) return '';
    return String(text)
        .replace(/```([\s\S]+?)```/g, '$1')
        .replace(/`([^`\n]+)`/g, '$1')
        .replace(/(^|[\s.,!?;:('"\-])([*_~])(\S|\S[\s\S]*?\S)\2(?=$|[\s.,!?;:)'"\-])/g, '$1$3')
        .replace(/^&gt;\s?|^>\s?/gm, '')
        .replace(/\s*\n\s*/g, ' ')
        .trim();
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
    // Message bodies and media captions both carry WhatsApp's markers.
    const text = formatMessageText(msg.text || '');
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
    const text = stripFormatting(msg.text || '');
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
            showContactCapNotice(data);
        })
        .catch(() => {
            // Without this the spinner span spins forever and the page looks
            // like it is still working.
            const chatList = document.getElementById('chatList');
            if (chatList && !chatList.querySelector('.chat-list-item')) {
                chatList.innerHTML =
                    '<div class="p-3 text-center text-muted small">Chats could not be loaded. Retrying…</div>';
            }
        });
}

// The plan's contact cap stops new chats being stored. Without this the list
// simply stops growing, which reads as "sync is broken" rather than "you are at
// your plan's limit".
function showContactCapNotice(data) {
    const existing = document.getElementById('contactCapNotice');
    if (!data.contactsCapped) {
        if (existing) existing.remove();
        return;
    }
    if (existing) return;

    const chatList = document.getElementById('chatList');
    if (!chatList || !chatList.parentNode) return;

    const notice = document.createElement('div');
    notice.id = 'contactCapNotice';
    notice.className = 'alert alert-warning small mb-0 rounded-0 py-2';
    notice.innerHTML = 'Contact limit reached'
        + (data.contactLimit ? ' (' + escapeHtml(String(data.contactLimit)) + ')' : '')
        + '. New chats are no longer being saved — existing ones still update.';
    chatList.parentNode.insertBefore(notice, chatList);
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
        // Markers are stripped, not rendered: the sidebar is one line of plain
        // text, as in WhatsApp Web.
        const preview = escapeHtml(stripFormatting(chat.lastMessage || ''));
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
    reapplyChatFilter();
}

// filterChats() works by hiding DOM nodes, so every re-render undoes it — and
// this list re-renders itself every 10 seconds. Typing a search and pausing used
// to show the full list again a few seconds later. Re-applying after each render
// is what makes the search survive the poll.
function reapplyChatFilter() {
    const search = document.getElementById('chatSearch');
    if (search && search.value && typeof filterChats === 'function') {
        filterChats(search.value);
    }
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
                ${canSendMedia() ? `
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
                </div>` : ''}
                <input type="text" class="form-control" id="messageInput" placeholder="Type a message..."
                       onkeypress="if(event.key==='Enter')composerSend('${escapeAttr(sessionId)}','${escapeAttr(chatId)}')">
                ${canSendMedia() ? `
                <button class="btn btn-mic" id="micButton" onclick="toggleVoiceRecording('${escapeAttr(sessionId)}','${escapeAttr(chatId)}')" title="Record a voice note">
                    <i class="bi bi-mic-fill"></i>
                </button>` : ''}
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
            // Drop a response for a chat the user has already navigated away
            // from. Clicking A then B before A's response lands used to paint
            // A's messages into B's pane — a convincing wrong thread, because
            // nothing about it looks like an error.
            if (chatId !== currentChatId) return;

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
        body: JSON.stringify({
            csrf_token: window.waCsrfToken || '',
            session_id: sessionId,
            chat_id: chatId,
            text: text
        })
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

// The plan's `media_send` lever, published by the page. Presentation only: it
// decides whether to draw the attach and mic controls, while send-media.php is
// what actually refuses. Defaults to false so a page that forgets to publish it
// hides the controls rather than offering a button that always errors.
function canSendMedia() {
    return window.waCanSendMedia === true;
}

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
    // Guards the Enter key during an upload. Without it a second press re-sends
    // the staged file, because the input stays focused and pendingAttachment is
    // only cleared once the first upload succeeds.
    if (composerBusy) return;
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

// Tracks an in-flight upload. Disabling the buttons is not sufficient on its
// own: the text input keeps its Enter handler, so pressing Enter during an
// upload called composerSend() again and sent the same file twice.
let composerBusy = false;

function setComposerBusy(busy) {
    composerBusy = busy;

    // The attach control has to go too — picking a second file mid-upload
    // replaced pendingAttachment underneath the transfer in flight.
    for (const id of ['sendButton', 'micButton', 'attachInput']) {
        const el = document.getElementById(id);
        if (el) el.disabled = busy;
    }
    const attach = document.querySelector('.composer-attach .btn-attach');
    if (attach) attach.disabled = busy;

    const input = document.getElementById('messageInput');
    if (input) {
        // Releasing restores the caption rule rather than blanket-enabling:
        // audio and voice notes take no caption, and a failed upload leaves the
        // file staged, so the box must stay disabled for those kinds.
        const kind = pendingAttachment && pendingAttachment.kind;
        input.disabled = busy || kind === 'audio' || kind === 'voice';
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
