'use strict';

const state = {
    username: '',
    csrf: '',
    folders: [],
    expandedFolderIds: new Set(),
    activeFolder: null,
    messages: [],
    selected: new Set(),
    readerMessageId: '',
    rules: [],
    ruleFolders: [],
    rulesLoaded: false,
    rulesLoading: false,
    rulesBusy: false,
    outlookRuleBlobExists: false,
    cleanupBusy: false,
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
    mailboxCleanupButton: document.querySelector('#mailboxCleanupButton'),
    emptyTrashButton: document.querySelector('#emptyTrashButton'),
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
    settingsButton: document.querySelector('#settingsButton'),
    settingsOverlay: document.querySelector('#settingsOverlay'),
    settingsBackdrop: document.querySelector('#settingsBackdrop'),
    settingsClose: document.querySelector('#settingsClose'),
    settingsAvatar: document.querySelector('#settingsAvatar'),
    settingsUsername: document.querySelector('#settingsUsername'),
    cleanupOverlay: document.querySelector('#cleanupOverlay'),
    cleanupBackdrop: document.querySelector('#cleanupBackdrop'),
    cleanupClose: document.querySelector('#cleanupClose'),
    cleanupForm: document.querySelector('#cleanupForm'),
    cleanupHours: document.querySelector('#cleanupHours'),
    cleanupCutoffText: document.querySelector('#cleanupCutoffText'),
    cleanupProgress: document.querySelector('#cleanupProgress'),
    cleanupError: document.querySelector('#cleanupError'),
    cleanupCancel: document.querySelector('#cleanupCancel'),
    cleanupSubmit: document.querySelector('#cleanupSubmit'),
    rulesWarning: document.querySelector('#rulesWarning'),
    rulesStatus: document.querySelector('#rulesStatus'),
    rulesList: document.querySelector('#rulesList'),
    addRuleButton: document.querySelector('#addRuleButton'),
    ruleEditorOverlay: document.querySelector('#ruleEditorOverlay'),
    ruleEditorBackdrop: document.querySelector('#ruleEditorBackdrop'),
    ruleEditorClose: document.querySelector('#ruleEditorClose'),
    ruleEditorTitle: document.querySelector('#ruleEditorTitle'),
    ruleForm: document.querySelector('#ruleForm'),
    ruleId: document.querySelector('#ruleId'),
    ruleName: document.querySelector('#ruleName'),
    ruleFrom: document.querySelector('#ruleFrom'),
    ruleSubject: document.querySelector('#ruleSubject'),
    ruleHasAttachments: document.querySelector('#ruleHasAttachments'),
    ruleAction: document.querySelector('#ruleAction'),
    ruleMoveRow: document.querySelector('#ruleMoveRow'),
    ruleMoveFolder: document.querySelector('#ruleMoveFolder'),
    ruleMarkReadRow: document.querySelector('#ruleMarkReadRow'),
    ruleMarkAsRead: document.querySelector('#ruleMarkAsRead'),
    ruleStopProcessing: document.querySelector('#ruleStopProcessing'),
    ruleEnabled: document.querySelector('#ruleEnabled'),
    ruleFormError: document.querySelector('#ruleFormError'),
    ruleCancelButton: document.querySelector('#ruleCancelButton'),
    ruleSaveButton: document.querySelector('#ruleSaveButton'),
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
        error.data = data;
        throw error;
    }
    return data;
}

function showLogin(message = '') {
    state.username = '';
    state.csrf = '';
    state.folders = [];
    state.expandedFolderIds.clear();
    state.activeFolder = null;
    state.messages = [];
    state.selected.clear();
    state.rules = [];
    state.ruleFolders = [];
    state.rulesLoaded = false;
    state.outlookRuleBlobExists = false;
    state.cleanupBusy = false;
    closeReader();
    closeRuleEditor();
    closeSettings();
    closeCleanup(true);
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
    state.expandedFolderIds = new Set(
        folders
            .map((folder) => folder.parentId)
            .filter((parentId) => folders.some((folder) => folder.id === parentId)),
    );
    state.rulesLoaded = false;
    elements.loginView.hidden = true;
    elements.mailView.hidden = false;
    elements.accountName.textContent = username;
    elements.avatar.textContent = (username[0] || 'B').toLocaleUpperCase('tr-TR');
    elements.settingsUsername.textContent = username;
    elements.settingsAvatar.textContent = (username[0] || 'B').toLocaleUpperCase('tr-TR');
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

    const foldersById = new Map(state.folders.map((folder) => [folder.id, folder]));
    const childrenByParent = new Map();
    const roots = [];

    for (const folder of state.folders) {
        if (folder.parentId && folder.parentId !== folder.id && foldersById.has(folder.parentId)) {
            const children = childrenByParent.get(folder.parentId) || [];
            children.push(folder);
            childrenByParent.set(folder.parentId, children);
        } else {
            roots.push(folder);
        }
    }

    const rendered = new Set();
    const renderBranch = (folder, depth = 0) => {
        if (rendered.has(folder.id)) return null;
        rendered.add(folder.id);

        const children = childrenByParent.get(folder.id) || [];
        const branch = document.createElement('div');
        branch.className = 'folder-branch';
        branch.style.setProperty('--folder-depth', String(depth));

        const row = document.createElement('div');
        row.className = 'folder-row';

        const expander = document.createElement('button');
        expander.type = 'button';
        expander.className = 'folder-expander';
        expander.setAttribute('aria-label', `${folder.name} alt klasörlerini aç veya kapat`);
        expander.setAttribute('aria-expanded', String(state.expandedFolderIds.has(folder.id)));
        expander.textContent = '›';
        if (children.length === 0) {
            expander.classList.add('placeholder');
            expander.setAttribute('aria-hidden', 'true');
            expander.tabIndex = -1;
        }

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

        row.append(expander, button);
        branch.append(row);

        if (children.length) {
            const childList = document.createElement('div');
            childList.className = 'folder-children';
            childList.hidden = !state.expandedFolderIds.has(folder.id);
            for (const child of children) {
                const childBranch = renderBranch(child, depth + 1);
                if (childBranch) childList.append(childBranch);
            }
            branch.append(childList);

            expander.addEventListener('click', () => {
                const expanded = !state.expandedFolderIds.has(folder.id);
                if (expanded) state.expandedFolderIds.add(folder.id);
                else state.expandedFolderIds.delete(folder.id);
                childList.hidden = !expanded;
                expander.setAttribute('aria-expanded', String(expanded));
            });
        }

        return branch;
    };

    for (const folder of roots) {
        const branch = renderBranch(folder);
        if (branch) elements.folderList.append(branch);
    }
    // Bozuk veya döngüsel bir parentId gelirse klasörü görünmez bırakma.
    for (const folder of state.folders) {
        if (!rendered.has(folder.id)) {
            const branch = renderBranch(folder);
            if (branch) elements.folderList.append(branch);
        }
    }
    markActiveFolder();
}

function folderPath(folder) {
    const foldersById = new Map(state.folders.map((item) => [item.id, item]));
    const names = [];
    const visited = new Set();
    let current = folder;

    while (current && !visited.has(current.id) && names.length < 20) {
        visited.add(current.id);
        names.unshift(current.name);
        current = foldersById.get(current.parentId);
    }

    return names.join(' › ');
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
            option.textContent = folderPath(folder);
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
    elements.folderTitle.textContent = folderPath(folder);
    elements.searchInput.value = '';
    markActiveFolder();
    updateMailboxActions();
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
    elements.readerFolder.textContent = state.activeFolder ? folderPath(state.activeFolder) : '';
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
        const chip = document.createElement('a');
        chip.className = 'attachment-chip';
        chip.href = `api.php?${new URLSearchParams({ action: 'attachment', id: attachment.id || '', name: attachment.name || 'ek' })}`;
        chip.setAttribute('download', attachment.name || 'ek');
        chip.title = 'Eki indir';
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
        showToast(`${ids.length} mail “${folderPath(destination)}” klasörüne taşındı.`);
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
    elements.mailboxCleanupButton.disabled = processing || state.cleanupBusy;
    elements.emptyTrashButton.disabled = processing;
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

function updateMailboxActions() {
    elements.emptyTrashButton.hidden = state.activeFolder?.role !== 'trash';
}

function updateCleanupCutoff() {
    const hours = Number(elements.cleanupHours.value) || 1;
    const cutoff = new Date(Date.now() - hours * 60 * 60 * 1000);
    elements.cleanupCutoffText.textContent = new Intl.DateTimeFormat('tr-TR', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(cutoff);
}

function openCleanup() {
    if (state.processing || state.cleanupBusy) return;
    closeReader();
    closeMobileMenu();
    elements.cleanupError.hidden = true;
    elements.cleanupProgress.hidden = true;
    elements.cleanupProgress.textContent = '';
    updateCleanupCutoff();
    elements.cleanupOverlay.hidden = false;
    document.body.classList.add('cleanup-open');
    requestAnimationFrame(() => elements.cleanupHours.focus());
}

function closeCleanup(force = false) {
    if (!elements.cleanupOverlay || (state.cleanupBusy && !force)) return;
    elements.cleanupOverlay.hidden = true;
    document.body.classList.remove('cleanup-open');
}

function setCleanupBusy(busy) {
    state.cleanupBusy = busy;
    elements.cleanupHours.disabled = busy;
    elements.cleanupClose.disabled = busy;
    elements.cleanupCancel.disabled = busy;
    elements.cleanupSubmit.disabled = busy;
    elements.cleanupSubmit.textContent = busy ? 'Mailler taşınıyor…' : 'Eski mailleri Çöp Kutusu’na taşı';
    elements.mailboxCleanupButton.disabled = busy || state.processing;
}

async function cleanMailbox() {
    if (state.cleanupBusy || state.processing) return;
    const hours = Number(elements.cleanupHours.value);
    if (!Number.isInteger(hours) || hours < 1) {
        elements.cleanupError.textContent = 'Geçerli bir saat aralığı seçin.';
        elements.cleanupError.hidden = false;
        return;
    }

    let total = 0;
    let round = 0;
    let completed = false;
    elements.cleanupError.hidden = true;
    elements.cleanupProgress.hidden = false;
    elements.cleanupProgress.textContent = 'Exchange klasörleri taranıyor…';
    setCleanupBusy(true);
    setProcessing(true);

    try {
        while (round < 100) {
            const data = await api('mailbox-cleanup', {
                method: 'POST',
                body: { hours, confirmCleanup: true },
            });
            total += Number(data.count) || 0;
            round += 1;
            elements.cleanupProgress.textContent = data.hasMore
                ? `${total} mail Çöp Kutusu’na taşındı; kalanlar taranıyor…`
                : `${total} mail Çöp Kutusu’na taşındı.`;
            if (!data.hasMore) {
                completed = true;
                break;
            }
        }

        if (!completed) {
            throw new Error('Tek seferde güvenli işlem sınırına ulaşıldı. Kalan mailler için işlemi yeniden başlatın.');
        }
        closeCleanup(true);
        showToast(total > 0 ? `${total} eski mail Çöp Kutusu’na taşındı.` : 'Seçilen süreden eski mail bulunamadı.');
    } catch (error) {
        if (error.status === 401) {
            showLogin('Oturumunuz sona erdi. Yeniden giriş yapın.');
            return;
        }
        elements.cleanupError.textContent = error.message || 'Posta kutusu temizlenemedi.';
        elements.cleanupError.hidden = false;
    } finally {
        setProcessing(false);
        setCleanupBusy(false);
    }

    if (completed && state.activeFolder) await loadMessages();
}

async function emptyTrash() {
    if (state.activeFolder?.role !== 'trash' || state.processing || state.cleanupBusy) return;
    const confirmed = window.confirm('Çöp Kutusu’ndaki TÜM mailler kalıcı olarak silinsin mi? Ekrandaki 200 maille sınırlı değildir ve işlem geri alınamaz.');
    if (!confirmed) return;

    let emptied = false;
    setProcessing(true);
    try {
        await api('empty-trash', {
            method: 'POST',
            body: { confirmPermanent: true },
        });
        emptied = true;
        state.messages = [];
        clearSelection();
        renderMessages();
        showToast('Çöp Kutusu tamamen ve kalıcı olarak boşaltıldı.');
    } catch (error) {
        handleOperationError(error);
    } finally {
        setProcessing(false);
    }

    if (emptied && state.activeFolder) await loadMessages();
}

function openSettings() {
    closeReader();
    closeMobileMenu();
    elements.settingsUsername.textContent = state.username;
    elements.settingsAvatar.textContent = (state.username[0] || 'B').toLocaleUpperCase('tr-TR');
    elements.settingsOverlay.hidden = false;
    document.body.classList.add('settings-open');
    requestAnimationFrame(() => elements.settingsClose.focus());
    if (!state.rulesLoaded && !state.rulesLoading) loadRules();
}

function closeSettings() {
    if (!elements.settingsOverlay) return;
    closeRuleEditor();
    elements.settingsOverlay.hidden = true;
    document.body.classList.remove('settings-open');
}

async function loadRules() {
    if (state.rulesLoading) return;
    state.rulesLoading = true;
    elements.addRuleButton.disabled = true;
    elements.rulesList.replaceChildren();
    elements.rulesStatus.hidden = false;
    elements.rulesStatus.classList.remove('error');
    elements.rulesStatus.querySelector('p').textContent = 'Exchange kuralları okunuyor…';
    const statusSpinner = elements.rulesStatus.querySelector('.spinner');
    if (statusSpinner) statusSpinner.hidden = false;

    try {
        const data = await api('rules');
        state.rules = data.rules || [];
        state.ruleFolders = data.folders || [];
        state.outlookRuleBlobExists = Boolean(data.outlookRuleBlobExists);
        state.rulesLoaded = true;
        elements.rulesWarning.hidden = !state.outlookRuleBlobExists;
        renderRules();
        elements.rulesStatus.hidden = true;
    } catch (error) {
        state.rulesLoaded = false;
        if (error.status === 401) {
            showLogin('Oturumunuz sona erdi veya EWS erişiminiz bulunmuyor.');
            return;
        }
        elements.rulesStatus.hidden = false;
        elements.rulesStatus.classList.add('error');
        if (statusSpinner) statusSpinner.hidden = true;
        elements.rulesStatus.querySelector('p').textContent = error.message;
    } finally {
        state.rulesLoading = false;
        elements.addRuleButton.disabled = !state.rulesLoaded;
    }
}

function renderRules() {
    elements.rulesList.replaceChildren();
    if (!state.rules.length) {
        const empty = document.createElement('div');
        empty.className = 'rules-empty';
        const icon = document.createElement('span');
        icon.textContent = '◇';
        const title = document.createElement('strong');
        title.textContent = 'Henüz posta kutusu kuralı yok';
        const copy = document.createElement('p');
        copy.textContent = 'Yeni bir kural ekleyerek gelen mailleri otomatik yönetebilirsiniz.';
        empty.append(icon, title, copy);
        elements.rulesList.append(empty);
        return;
    }

    const fragment = document.createDocumentFragment();
    for (const rule of state.rules) fragment.append(createRuleCard(rule));
    elements.rulesList.append(fragment);
}

function createRuleCard(rule) {
    const card = document.createElement('article');
    card.className = `rule-card${rule.enabled ? '' : ' disabled'}${rule.inError ? ' rule-error' : ''}`;

    const order = document.createElement('span');
    order.className = 'rule-priority';
    order.textContent = String(rule.priority || '—');

    const body = document.createElement('div');
    body.className = 'rule-card-body';
    const titleRow = document.createElement('div');
    titleRow.className = 'rule-title-row';
    const title = document.createElement('h3');
    title.textContent = rule.name;
    titleRow.append(title);

    if (!rule.editable) {
        const badge = document.createElement('span');
        badge.className = 'rule-badge';
        badge.textContent = rule.notSupported ? 'Exchange desteklemiyor' : 'Karmaşık · salt okunur';
        titleRow.append(badge);
    }
    if (rule.inError) {
        const errorBadge = document.createElement('span');
        errorBadge.className = 'rule-badge error';
        errorBadge.textContent = 'Kural hatalı';
        titleRow.append(errorBadge);
    }

    const summary = document.createElement('div');
    summary.className = 'rule-summary';
    summary.append(ruleSummaryRow('EĞER', rule.conditions || []));
    summary.append(ruleSummaryRow('YAP', rule.actions || []));
    if (Array.isArray(rule.exceptions) && rule.exceptions.length) {
        summary.append(ruleSummaryRow('HARİÇ', rule.exceptions));
    }
    body.append(titleRow, summary);

    const controls = document.createElement('div');
    controls.className = 'rule-controls';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = `rule-switch${rule.enabled ? ' on' : ''}`;
    toggle.setAttribute('role', 'switch');
    toggle.setAttribute('aria-checked', String(Boolean(rule.enabled)));
    toggle.setAttribute('aria-label', `${rule.name} kuralını ${rule.enabled ? 'devre dışı bırak' : 'etkinleştir'}`);
    toggle.disabled = state.rulesBusy || rule.notSupported;
    const knob = document.createElement('span');
    toggle.append(knob);
    toggle.addEventListener('click', () => toggleInboxRule(rule));

    const menu = document.createElement('div');
    menu.className = 'rule-card-actions';
    if (rule.editable) {
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.textContent = 'Düzenle';
        edit.disabled = state.rulesBusy;
        edit.addEventListener('click', () => openRuleEditor(rule));
        menu.append(edit);
    }
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'remove-rule';
    remove.textContent = 'Sil';
    remove.disabled = state.rulesBusy;
    remove.addEventListener('click', () => deleteInboxRule(rule));
    menu.append(remove);
    controls.append(toggle, menu);

    card.append(order, body, controls);
    return card;
}

function ruleSummaryRow(label, values) {
    const row = document.createElement('p');
    const key = document.createElement('span');
    key.textContent = label;
    const value = document.createElement('b');
    value.textContent = values.join(' · ');
    row.append(key, value);
    return row;
}

function openRuleEditor(rule = null) {
    if (!state.rulesLoaded || state.rulesBusy) return;
    elements.ruleForm.reset();
    elements.ruleId.value = rule?.id || '';
    elements.ruleEditorTitle.textContent = rule ? 'Kuralı düzenle' : 'Yeni kural';
    elements.ruleName.value = rule?.form?.name || '';
    elements.ruleFrom.value = rule?.form?.fromAddress || '';
    elements.ruleSubject.value = rule?.form?.subjectContains || '';
    elements.ruleHasAttachments.checked = Boolean(rule?.form?.hasAttachments);
    elements.ruleAction.value = rule?.form?.action || 'move';
    elements.ruleMarkAsRead.checked = Boolean(rule?.form?.markAsRead);
    elements.ruleStopProcessing.checked = rule ? Boolean(rule.form?.stopProcessing) : true;
    elements.ruleEnabled.checked = rule ? Boolean(rule.form?.enabled) : true;
    elements.ruleFormError.hidden = true;
    populateRuleFolderOptions(rule?.form?.moveFolderId || '');
    updateRuleActionFields();
    elements.ruleEditorOverlay.hidden = false;
    requestAnimationFrame(() => elements.ruleName.focus());
}

function closeRuleEditor() {
    if (!elements.ruleEditorOverlay) return;
    elements.ruleEditorOverlay.hidden = true;
    elements.ruleFormError.hidden = true;
}

function populateRuleFolderOptions(selectedId = '') {
    elements.ruleMoveFolder.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Klasör seçin';
    elements.ruleMoveFolder.append(placeholder);
    for (const folder of state.ruleFolders) {
        const option = document.createElement('option');
        option.value = folder.id;
        option.textContent = folder.path || folder.name;
        elements.ruleMoveFolder.append(option);
    }
    elements.ruleMoveFolder.value = selectedId;
}

function updateRuleActionFields() {
    const action = elements.ruleAction.value;
    elements.ruleMoveRow.hidden = action !== 'move';
    elements.ruleMarkReadRow.hidden = action === 'markRead';
    if (action === 'markRead') elements.ruleMarkAsRead.checked = true;
}

function confirmRuleBlobChange() {
    if (!state.outlookRuleBlobExists) return true;
    return window.confirm(
        'Exchange bu posta kutusunda Outlook kural verisi bulunduğunu bildiriyor. Devam edilirse bazı kapalı veya yalnızca Outlook’ta çalışan kurallar kalıcı olarak kaybolabilir. Yine de devam edilsin mi?'
    );
}

async function runRuleMutation(action, body) {
    if (state.rulesBusy || !confirmRuleBlobChange()) return false;
    setRulesBusy(true);
    try {
        await api(action, {
            method: 'POST',
            body: {
                ...body,
                confirmOutlookRuleBlobRemoval: state.outlookRuleBlobExists,
            },
        });
        state.outlookRuleBlobExists = false;
        state.rulesLoaded = false;
        await loadRules();
        return true;
    } catch (error) {
        if (error.status === 401) {
            showLogin('Oturumunuz sona erdi veya EWS erişiminiz bulunmuyor.');
            return false;
        }
        showToast(`Kural hatası: ${error.message}`);
        return false;
    } finally {
        setRulesBusy(false);
    }
}

function setRulesBusy(busy) {
    state.rulesBusy = busy;
    elements.addRuleButton.disabled = busy || !state.rulesLoaded;
    elements.ruleSaveButton.disabled = busy;
    elements.ruleSaveButton.textContent = busy ? 'Kaydediliyor…' : 'Kuralı kaydet';
    if (busy) {
        for (const button of elements.rulesList.querySelectorAll('button')) button.disabled = true;
    } else if (state.rulesLoaded) {
        // Kartları yeniden kurarak Exchange'in değiştirmeye izin vermediği
        // kurallardaki düğmelerin yanlışlıkla etkinleşmesini önle.
        renderRules();
    }
}

async function toggleInboxRule(rule) {
    const success = await runRuleMutation('rule-toggle', { ruleId: rule.id, enabled: !rule.enabled });
    if (success) showToast(`Kural ${rule.enabled ? 'devre dışı bırakıldı' : 'etkinleştirildi'}.`);
}

async function deleteInboxRule(rule) {
    if (!window.confirm(`“${rule.name}” kuralı silinsin mi?`)) return;
    const success = await runRuleMutation('rule-delete', { ruleId: rule.id });
    if (success) showToast('Kural silindi.');
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
elements.mailboxCleanupButton.addEventListener('click', openCleanup);
elements.emptyTrashButton.addEventListener('click', emptyTrash);
elements.cleanupBackdrop.addEventListener('click', closeCleanup);
elements.cleanupClose.addEventListener('click', closeCleanup);
elements.cleanupCancel.addEventListener('click', closeCleanup);
elements.cleanupHours.addEventListener('change', updateCleanupCutoff);
elements.cleanupForm.addEventListener('submit', (event) => {
    event.preventDefault();
    cleanMailbox();
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

elements.settingsButton.addEventListener('click', openSettings);
elements.settingsClose.addEventListener('click', closeSettings);
elements.settingsBackdrop.addEventListener('click', closeSettings);
elements.addRuleButton.addEventListener('click', () => openRuleEditor());
elements.ruleEditorClose.addEventListener('click', closeRuleEditor);
elements.ruleEditorBackdrop.addEventListener('click', closeRuleEditor);
elements.ruleCancelButton.addEventListener('click', closeRuleEditor);
elements.ruleAction.addEventListener('change', updateRuleActionFields);
elements.ruleForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fromAddress = elements.ruleFrom.value.trim();
    const subjectContains = elements.ruleSubject.value.trim();
    const hasAttachments = elements.ruleHasAttachments.checked;
    const action = elements.ruleAction.value;
    const moveFolderId = elements.ruleMoveFolder.value;

    elements.ruleFormError.hidden = true;
    if (!fromAddress && !subjectContains && !hasAttachments) {
        elements.ruleFormError.textContent = 'En az bir koşul yazın veya “Mail ek içeriyor” seçeneğini işaretleyin.';
        elements.ruleFormError.hidden = false;
        return;
    }
    if (action === 'move' && !moveFolderId) {
        elements.ruleFormError.textContent = 'Mailin taşınacağı klasörü seçin.';
        elements.ruleFormError.hidden = false;
        return;
    }

    const rule = {
        name: elements.ruleName.value.trim(),
        fromAddress,
        subjectContains,
        hasAttachments,
        action,
        moveFolderId,
        markAsRead: elements.ruleMarkAsRead.checked,
        stopProcessing: elements.ruleStopProcessing.checked,
        enabled: elements.ruleEnabled.checked,
    };
    const ruleId = elements.ruleId.value;
    const success = await runRuleMutation(ruleId ? 'rule-update' : 'rule-create', {
        ruleId,
        rule,
    });
    if (success) {
        closeRuleEditor();
        showToast(ruleId ? 'Kural güncellendi.' : 'Yeni kural eklendi.');
    }
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
        if (!elements.cleanupOverlay.hidden) closeCleanup();
        else if (!elements.ruleEditorOverlay.hidden) closeRuleEditor();
        else if (!elements.settingsOverlay.hidden) closeSettings();
        else if (!elements.readerOverlay.hidden) closeReader();
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
