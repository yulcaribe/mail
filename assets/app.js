'use strict';

const state = {
    username: '',
    csrf: '',
    folders: [],
    activeFolder: null,
    messages: [],
    selected: new Set(),
    readerMessageId: '',
    loading: false,
    processing: false,
};

const elements = {
    loginView: document.querySelector('#loginView'),
    mailView: document.querySelector('#mailView'),
    loginForm: document.querySelector('#loginForm'),
    loginButton: document.querySelector('#loginButton'),
    loginError: document.querySelector('#loginError'),
    username: document.querySelector('#username'),
    password: document.querySelector('#password'),
    passwordToggle: document.querySelector('#passwordToggle'),
    accountName: document.querySelector('#accountName'),
    avatar: document.querySelector('#avatar'),
    folderList: document.querySelector('#folderList'),
    folderTitle: document.querySelector('#folderTitle'),
    messageList: document.querySelector('#messageList'),
    messageCount: document.querySelector('#messageCount'),
    pageInfo: document.querySelector('#pageInfo'),
    searchInput: document.querySelector('#searchInput'),
    refreshButton: document.querySelector('#refreshButton'),
    logoutButton: document.querySelector('#logoutButton'),
    menuButton: document.querySelector('#menuButton'),
    sidebar: document.querySelector('#sidebar'),
    pageShade: document.querySelector('#pageShade'),
    toast: document.querySelector('#toast'),
    selectAll: document.querySelector('#selectAll'),
    bulkToolbar: document.querySelector('#bulkToolbar'),
    selectedCount: document.querySelector('#selectedCount'),
    markReadButton: document.querySelector('#markReadButton'),
    markUnreadButton: document.querySelector('#markUnreadButton'),
    moveTarget: document.querySelector('#moveTarget'),
    moveButton: document.querySelector('#moveButton'),
    deleteButton: document.querySelector('#deleteButton'),
    clearSelectionButton: document.querySelector('#clearSelectionButton'),
    readerOverlay: document.querySelector('#readerOverlay'),
    readerBackdrop: document.querySelector('#readerBackdrop'),
    readerClose: document.querySelector('#readerClose'),
    readerPrevious: document.querySelector('#readerPrevious'),
    readerNext: document.querySelector('#readerNext'),
    readerUnread: document.querySelector('#readerUnread'),
    readerMoveTarget: document.querySelector('#readerMoveTarget'),
    readerMoveButton: document.querySelector('#readerMoveButton'),
    readerDelete: document.querySelector('#readerDelete'),
    readerFolder: document.querySelector('#readerFolder'),
    readerSubject: document.querySelector('#readerSubject'),
    readerAvatar: document.querySelector('#readerAvatar'),
    readerFrom: document.querySelector('#readerFrom'),
    readerTo: document.querySelector('#readerTo'),
    readerCc: document.querySelector('#readerCc'),
    readerCcRow: document.querySelector('#readerCcRow'),
    readerDate: document.querySelector('#readerDate'),
    readerAttachments: document.querySelector('#readerAttachments'),
    readerBody: document.querySelector('#readerBody'),
    recipientToggle: document.querySelector('#recipientToggle'),
    recipientDetails: document.querySelector('#recipientDetails'),
};

const roleIcons = {
    inbox: '↓',
    sent: '↗',
    drafts: '▤',
    outbox: '⌁',
    trash: '⌫',
    other: '□',
};

async function api(action, options = {}) {
    const query = new URLSearchParams({ action, ...(options.query || {}) });
    const method = options.method || 'GET';
    const request = {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
    };

    if (method !== 'GET' && action !== 'login' && state.csrf) {
        request.headers['X-CSRF-Token'] = state.csrf;
    }
    if (options.body) {
        request.headers['Content-Type'] = 'application/json';
        request.body = JSON.stringify(options.body);
    }

    const response = await fetch(`api.php?${query}`, request);
    let data;
    try {
        data = await response.json();
    } catch (_) {
        throw new Error('Sunucu geçerli bir yanıt vermedi. PHP ayarlarını kontrol edin.');
    }

    if (!response.ok || !data.ok) {
        const error = new Error(data.message || 'İşlem tamamlanamadı.');
        error.status = response.status;
        throw error;
    }
    return data;
}

function showLogin(message = '') {
    state.username = '';
    state.csrf = '';
    state.folders = [];
    state.activeFolder = null;
    state.messages = [];
    state.selected.clear();
    closeReader();
    elements.mailView.hidden = true;
    elements.loginView.hidden = false;
    elements.loginError.textContent = message;
    elements.loginError.hidden = !message;
    elements.password.value = '';
    requestAnimationFrame(() => elements.username.focus());
}

function showMail(username, folders, csrf) {
    state.username = username;
    state.csrf = csrf || '';
    state.folders = folders;
    elements.loginView.hidden = true;
    elements.mailView.hidden = false;
    elements.accountName.textContent = username;
    elements.avatar.textContent = (username[0] || 'B').toLocaleUpperCase('tr-TR');
    renderFolders();

    const first = folders.find((folder) => folder.role === 'inbox') || folders[0];
    if (first) {
        selectFolder(first);
    } else {
        elements.folderTitle.textContent = 'Posta kutusu';
        renderEmpty('Klasör bulunamadı', 'Exchange hesabınız hiçbir posta klasörü döndürmedi.');
    }
}

function renderFolders() {
    elements.folderList.replaceChildren();
    for (const folder of state.folders) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'folder-button';
        button.dataset.folderId = folder.id;

        const icon = document.createElement('span');
        icon.className = 'folder-icon';
        icon.textContent = roleIcons[folder.role] || roleIcons.other;

        const label = document.createElement('span');
        label.className = 'folder-name';
        label.textContent = folder.name;

        button.append(icon, label);
        button.addEventListener('click', () => selectFolder(folder));
        elements.folderList.append(button);
    }
    markActiveFolder();
}

function markActiveFolder() {
    for (const button of elements.folderList.querySelectorAll('.folder-button')) {
        button.classList.toggle('active', button.dataset.folderId === state.activeFolder?.id);
    }
}

function renderMoveTargets() {
    for (const select of [elements.moveTarget, elements.readerMoveTarget]) {
        const previous = select.value;
        select.replaceChildren();

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Klasöre taşı…';
        select.append(placeholder);

        for (const folder of state.folders) {
            if (folder.id === state.activeFolder?.id) continue;
            const option = document.createElement('option');
            option.value = folder.id;
            option.textContent = folder.name;
            select.append(option);
        }

        if ([...select.options].some((option) => option.value === previous)) {
            select.value = previous;
        }
    }
}

async function selectFolder(folder) {
    if (state.loading || state.processing) return;
    closeReader();
    clearSelection();
    state.activeFolder = folder;
    elements.folderTitle.textContent = folder.name;
    elements.searchInput.value = '';
    markActiveFolder();
    renderMoveTargets();
    closeMobileMenu();
    await loadMessages();
}

async function loadMessages() {
    if (!state.activeFolder || state.loading) return;
    state.loading = true;
    clearSelection();
    elements.refreshButton.classList.add('spinning');
    elements.messageCount.textContent = 'Mailler yükleniyor…';
    elements.pageInfo.textContent = '';
    renderLoading();

    try {
        const data = await api('messages', { query: { folderId: state.activeFolder.id } });
        state.messages = data.messages || [];
        renderMessages();
        elements.pageInfo.textContent = data.moreAvailable ? 'İlk sonuçlar gösteriliyor' : '';
    } catch (error) {
        if (error.status === 401) {
            showLogin('Oturumunuz sona erdi. Yeniden giriş yapın.');
            return;
        }
        state.messages = [];
        renderEmpty('Mailler alınamadı', error.message, true);
        elements.messageCount.textContent = 'Bağlantı hatası';
    } finally {
        state.loading = false;
        elements.refreshButton.classList.remove('spinning');
        updateSelectionUi();
    }
}

function filteredMessages() {
    const term = elements.searchInput.value.trim().toLocaleLowerCase('tr-TR');
    if (!term) return state.messages;
    return state.messages.filter((message) => [message.from, message.to, message.subject, message.preview, message.body]
        .some((value) => String(value || '').toLocaleLowerCase('tr-TR').includes(term)));
}

function renderMessages() {
    const messages = filteredMessages();
    const validIds = new Set(state.messages.map((message) => message.id));
    for (const id of state.selected) {
        if (!validIds.has(id)) state.selected.delete(id);
    }

    elements.messageList.replaceChildren();
    const total = state.messages.length;
    elements.messageCount.textContent = elements.searchInput.value.trim()
        ? `${messages.length} / ${total} mail`
        : `${total} mail`;

    if (!messages.length) {
        renderEmpty(
            elements.searchInput.value.trim() ? 'Eşleşen mail yok' : 'Bu klasör boş',
            elements.searchInput.value.trim() ? 'Arama kelimenizi değiştirip tekrar deneyin.' : 'Bu klasörde gösterilecek bir mail bulunamadı.'
        );
        updateSelectionUi();
        return;
    }

    const fragment = document.createDocumentFragment();
    messages.forEach((message, index) => fragment.append(createMessageRow(message, index)));
    elements.messageList.append(fragment);
    updateSelectionUi();
}

function createMessageRow(message, index) {
    const article = document.createElement('article');
    article.className = `message-row${message.read ? '' : ' unread'}${state.selected.has(message.id) ? ' selected' : ''}`;
    article.style.setProperty('--delay', `${Math.min(index, 12) * 18}ms`);
    article.tabIndex = 0;
    article.setAttribute('aria-label', `${friendlySender(message.from)}: ${message.subject || 'Konu yok'}`);

    const checkbox = document.createElement('input');
    checkbox.className = 'message-checkbox';
    checkbox.type = 'checkbox';
    checkbox.checked = state.selected.has(message.id);
    checkbox.setAttribute('aria-label', `${message.subject || 'Konu yok'} mailini seç`);
    checkbox.addEventListener('click', (event) => event.stopPropagation());
    checkbox.addEventListener('change', () => {
        if (checkbox.checked) state.selected.add(message.id);
        else state.selected.delete(message.id);
        article.classList.toggle('selected', checkbox.checked);
        updateSelectionUi();
    });

    const unread = document.createElement('span');
    unread.className = 'unread-dot';
    unread.setAttribute('aria-label', message.read ? 'Okundu' : 'Okunmadı');

    const sender = document.createElement('div');
    sender.className = 'sender';
    sender.textContent = friendlySender(message.from);
    sender.title = message.from || '';

    const content = document.createElement('div');
    content.className = 'message-content';

    const subject = document.createElement('h2');
    subject.textContent = message.subject || '(Konu yok)';

    const preview = document.createElement('p');
    preview.textContent = message.preview || 'İleti önizlemesi bulunmuyor.';
    content.append(subject, preview);

    const meta = document.createElement('div');
    meta.className = 'message-meta';

    if (Array.isArray(message.attachments) && message.attachments.length) {
        const attachment = document.createElement('span');
        attachment.className = 'attachment';
        attachment.textContent = '⌇';
        attachment.title = `${message.attachments.length} ek`;
        meta.append(attachment);
    }

    const time = document.createElement('time');
    time.dateTime = message.date || '';
    time.textContent = formatDate(message.date);
    meta.append(time);

    article.append(checkbox, unread, sender, content, meta);
    article.addEventListener('click', () => openMessage(message));
    article.addEventListener('keydown', (event) => {
        if ((event.key === 'Enter' || event.key === ' ') && event.target === article) {
            event.preventDefault();
            openMessage(message);
        }
    });
    return article;
}

function updateSelectionUi() {
    const visible = filteredMessages();
    const visibleSelected = visible.filter((message) => state.selected.has(message.id)).length;
    elements.selectAll.disabled = state.loading || visible.length === 0;
    elements.selectAll.checked = visible.length > 0 && visibleSelected === visible.length;
    elements.selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visible.length;

    const count = state.selected.size;
    elements.bulkToolbar.hidden = count === 0;
    elements.selectedCount.textContent = `${count} mail seçildi`;
    elements.moveButton.disabled = state.processing || !elements.moveTarget.value;
    elements.markReadButton.disabled = state.processing;
    elements.markUnreadButton.disabled = state.processing;
    elements.deleteButton.disabled = state.processing;
    elements.clearSelectionButton.disabled = state.processing;
}

function clearSelection() {
    state.selected.clear();
    updateSelectionUi();
    for (const checkbox of elements.messageList.querySelectorAll('.message-checkbox')) {
        checkbox.checked = false;
        checkbox.closest('.message-row')?.classList.remove('selected');
    }
}

function friendlySender(value) {
    if (!value) return 'Bilinmeyen gönderen';
    const match = String(value).match(/^\s*"?([^"<]+?)"?\s*</);
    if (match) return match[1].trim();
    return String(value).replace(/[<>]/g, '').trim();
}

function formatDate(value, full = false) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    if (full) {
        return new Intl.DateTimeFormat('tr-TR', {
            day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
        }).format(date);
    }

    const now = new Date();
    if (date.toDateString() === now.toDateString()) {
        return new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit' }).format(date);
    }
    if (date.getFullYear() === now.getFullYear()) {
        return new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'short' }).format(date);
    }
    return new Intl.DateTimeFormat('tr-TR', { day: '2-digit', month: '2-digit', year: '2-digit' }).format(date);
}

function readableBody(value) {
    const raw = String(value || '').trim();
    if (!raw) return 'Bu mailin metin içeriği bulunmuyor.';
    if (/<\/?[a-z][\s\S]*>/i.test(raw)) {
        const documentFromMail = new DOMParser().parseFromString(raw, 'text/html');
        return (documentFromMail.body.textContent || '').replace(/\n\s*\n\s*\n/g, '\n\n').trim();
    }
    return raw;
}

function openMessage(message) {
    state.readerMessageId = message.id;
    elements.readerFolder.textContent = state.activeFolder?.name || '';
    elements.readerSubject.textContent = message.subject || '(Konu yok)';
    elements.readerFrom.textContent = message.from || 'Bilinmeyen gönderen';
    elements.readerFrom.title = message.from || '';
    elements.readerTo.textContent = message.to || '—';
    elements.readerCc.textContent = message.cc || '—';
    elements.readerCcRow.hidden = !message.cc;
    elements.readerDate.textContent = formatDate(message.date, true);
    elements.readerDate.dateTime = message.date || '';
    elements.readerAvatar.textContent = (friendlySender(message.from)[0] || '?').toLocaleUpperCase('tr-TR');
    elements.readerBody.textContent = readableBody(message.body || message.preview);
    elements.recipientDetails.hidden = true;
    elements.recipientToggle.setAttribute('aria-expanded', 'false');
    renderReaderAttachments(message.attachments || []);
    updateReaderNavigation();
    elements.readerOverlay.hidden = false;
    document.body.classList.add('reader-open');
    requestAnimationFrame(() => elements.readerClose.focus());

    if (!message.read && !state.processing) {
        markMessages([message.id], true, true);
    }
}

function closeReader() {
    if (!elements.readerOverlay) return;
    elements.readerOverlay.hidden = true;
    state.readerMessageId = '';
    document.body.classList.remove('reader-open');
}

function readerMessage() {
    return state.messages.find((message) => message.id === state.readerMessageId) || null;
}

function renderReaderAttachments(attachments) {
    elements.readerAttachments.replaceChildren();
    elements.readerAttachments.hidden = attachments.length === 0;
    for (const attachment of attachments) {
        const chip = document.createElement('span');
        chip.className = 'attachment-chip';
        const name = document.createElement('strong');
        name.textContent = `⌇ ${attachment.name || 'Ek'}`;
        const size = document.createElement('small');
        size.textContent = attachment.size ? formatBytes(attachment.size) : '';
        chip.append(name, size);
        elements.readerAttachments.append(chip);
    }
}

function formatBytes(bytes) {
    const value = Number(bytes);
    if (!Number.isFinite(value) || value <= 0) return '';
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${Math.round(value / 1024)} KB`;
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function updateReaderNavigation() {
    const list = filteredMessages();
    const index = list.findIndex((message) => message.id === state.readerMessageId);
    elements.readerPrevious.disabled = index <= 0;
    elements.readerNext.disabled = index < 0 || index >= list.length - 1;
    elements.readerUnread.disabled = state.processing;
    elements.readerDelete.disabled = state.processing;
    elements.readerMoveButton.disabled = state.processing || !elements.readerMoveTarget.value;
}

function navigateReader(direction) {
    const list = filteredMessages();
    const index = list.findIndex((message) => message.id === state.readerMessageId);
    const target = list[index + direction];
    if (target) openMessage(target);
}

async function markMessages(ids, read, quiet = false) {
    if (!state.activeFolder || !ids.length || state.processing) return;
    setProcessing(true);
    try {
        await api('mark', {
            method: 'POST',
            body: { folderId: state.activeFolder.id, ids, read },
        });
        const changed = new Set(ids);
        for (const message of state.messages) {
            if (changed.has(message.id)) message.read = read;
        }
        renderMessages();
        if (!quiet) showToast(`${ids.length} mail ${read ? 'okundu' : 'okunmadı'} olarak işaretlendi.`);
    } catch (error) {
        handleOperationError(error);
    } finally {
        setProcessing(false);
    }
}

async function moveMessages(ids, destinationFolderId) {
    if (!state.activeFolder || !ids.length || !destinationFolderId || state.processing) return;
    const destination = state.folders.find((folder) => folder.id === destinationFolderId);
    if (!destination) {
        showToast('Hedef klasörü seçin.');
        return;
    }

    setProcessing(true);
    try {
        await api('move', {
            method: 'POST',
            body: {
                folderId: state.activeFolder.id,
                destinationFolderId,
                ids,
            },
        });
        removeMessagesLocally(ids);
        elements.moveTarget.value = '';
        elements.readerMoveTarget.value = '';
        showToast(`${ids.length} mail “${destination.name}” klasörüne taşındı.`);
    } catch (error) {
        handleOperationError(error);
    } finally {
        setProcessing(false);
    }
}

async function deleteMessages(ids) {
    if (!state.activeFolder || !ids.length || state.processing) return;
    const permanent = state.activeFolder.role === 'trash';
    if (permanent && !window.confirm(`${ids.length} mail kalıcı olarak silinsin mi? Bu işlem geri alınamaz.`)) {
        return;
    }

    setProcessing(true);
    try {
        await api('delete', {
            method: 'POST',
            body: { folderId: state.activeFolder.id, ids, permanent },
        });
        removeMessagesLocally(ids);
        showToast(permanent ? `${ids.length} mail kalıcı olarak silindi.` : `${ids.length} mail çöp kutusuna taşındı.`);
    } catch (error) {
        handleOperationError(error);
    } finally {
        setProcessing(false);
    }
}

function removeMessagesLocally(ids) {
    const removed = new Set(ids);
    state.messages = state.messages.filter((message) => !removed.has(message.id));
    for (const id of ids) state.selected.delete(id);
    if (removed.has(state.readerMessageId)) closeReader();
    renderMessages();
}

function setProcessing(processing) {
    state.processing = processing;
    elements.bulkToolbar.classList.toggle('busy', processing);
    updateSelectionUi();
    updateReaderNavigation();
}

function handleOperationError(error) {
    if (error.status === 401) {
        showLogin('Oturumunuz sona erdi. Yeniden giriş yapın.');
        return;
    }
    showToast(`Hata: ${error.message}`);
}

function renderLoading() {
    const wrap = document.createElement('div');
    wrap.className = 'loading-state';
    const spinner = document.createElement('span');
    spinner.className = 'spinner';
    const text = document.createElement('p');
    text.textContent = 'Postalar alınıyor…';
    wrap.append(spinner, text);
    elements.messageList.replaceChildren(wrap);
}

function renderEmpty(title, message, retry = false) {
    const wrap = document.createElement('div');
    wrap.className = 'empty-state';
    const icon = document.createElement('span');
    icon.className = 'empty-icon';
    icon.textContent = '✉';
    const heading = document.createElement('h2');
    heading.textContent = title;
    const copy = document.createElement('p');
    copy.textContent = message;
    wrap.append(icon, heading, copy);
    if (retry) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Tekrar dene';
        button.addEventListener('click', loadMessages);
        wrap.append(button);
    }
    elements.messageList.replaceChildren(wrap);
}

function setLoginBusy(busy) {
    elements.loginButton.disabled = busy;
    elements.loginButton.querySelector('span').textContent = busy ? 'Bağlanıyor…' : 'Giriş yap';
}

function showToast(message) {
    elements.toast.textContent = message;
    elements.toast.classList.add('show');
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => elements.toast.classList.remove('show'), 3200);
}

function closeMobileMenu() {
    elements.sidebar.classList.remove('open');
    elements.pageShade.classList.remove('show');
}

elements.loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    elements.loginError.hidden = true;
    setLoginBusy(true);
    try {
        const data = await api('login', {
            method: 'POST',
            body: { username: elements.username.value, password: elements.password.value },
        });
        elements.password.value = '';
        showMail(data.username, data.folders || [], data.csrf);
    } catch (error) {
        elements.loginError.textContent = error.message;
        elements.loginError.hidden = false;
        elements.password.select();
    } finally {
        setLoginBusy(false);
    }
});

elements.passwordToggle.addEventListener('click', () => {
    const visible = elements.password.type === 'text';
    elements.password.type = visible ? 'password' : 'text';
    elements.passwordToggle.textContent = visible ? 'Göster' : 'Gizle';
    elements.passwordToggle.setAttribute('aria-label', visible ? 'Parolayı göster' : 'Parolayı gizle');
});

elements.searchInput.addEventListener('input', renderMessages);
elements.refreshButton.addEventListener('click', () => {
    if (!state.loading && !state.processing) loadMessages();
});
elements.selectAll.addEventListener('change', () => {
    for (const message of filteredMessages()) {
        if (elements.selectAll.checked) state.selected.add(message.id);
        else state.selected.delete(message.id);
    }
    renderMessages();
});
elements.clearSelectionButton.addEventListener('click', clearSelection);
elements.markReadButton.addEventListener('click', () => markMessages([...state.selected], true));
elements.markUnreadButton.addEventListener('click', () => markMessages([...state.selected], false));
elements.moveTarget.addEventListener('change', updateSelectionUi);
elements.moveButton.addEventListener('click', () => moveMessages([...state.selected], elements.moveTarget.value));
elements.deleteButton.addEventListener('click', () => deleteMessages([...state.selected]));

elements.readerClose.addEventListener('click', closeReader);
elements.readerBackdrop.addEventListener('click', closeReader);
elements.readerPrevious.addEventListener('click', () => navigateReader(-1));
elements.readerNext.addEventListener('click', () => navigateReader(1));
elements.readerUnread.addEventListener('click', () => {
    const message = readerMessage();
    if (message) markMessages([message.id], false);
});
elements.readerMoveTarget.addEventListener('change', updateReaderNavigation);
elements.readerMoveButton.addEventListener('click', () => {
    const message = readerMessage();
    if (message) moveMessages([message.id], elements.readerMoveTarget.value);
});
elements.readerDelete.addEventListener('click', () => {
    const message = readerMessage();
    if (message) deleteMessages([message.id]);
});
elements.recipientToggle.addEventListener('click', () => {
    const expanded = elements.recipientToggle.getAttribute('aria-expanded') === 'true';
    elements.recipientToggle.setAttribute('aria-expanded', String(!expanded));
    elements.recipientDetails.hidden = expanded;
});

elements.logoutButton.addEventListener('click', async () => {
    try {
        await api('logout', { method: 'POST' });
    } catch (_) {
        // Sunucuya ulaşılamasa da istemci görünümünü kapat.
    }
    showLogin();
    showToast('Oturum kapatıldı.');
});
elements.menuButton.addEventListener('click', () => {
    elements.sidebar.classList.add('open');
    elements.pageShade.classList.add('show');
});
elements.pageShade.addEventListener('click', closeMobileMenu);

document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLocaleLowerCase('tr-TR') === 'k' && !elements.mailView.hidden) {
        event.preventDefault();
        elements.searchInput.focus();
    }
    if (event.key === 'Escape') {
        if (!elements.readerOverlay.hidden) closeReader();
        else closeMobileMenu();
    }
});

(async function boot() {
    try {
        const data = await api('folders');
        showMail(data.username, data.folders || [], data.csrf);
    } catch (_) {
        showLogin();
    }
})();
