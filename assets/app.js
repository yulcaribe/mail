'use strict';

const state = {
    username: '',
    folders: [],
    activeFolder: null,
    messages: [],
    loading: false,
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
    const request = {
        method: options.method || 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    };

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
    state.folders = [];
    state.activeFolder = null;
    state.messages = [];
    elements.mailView.hidden = true;
    elements.loginView.hidden = false;
    elements.loginError.textContent = message;
    elements.loginError.hidden = !message;
    elements.password.value = '';
    requestAnimationFrame(() => elements.username.focus());
}

function showMail(username, folders) {
    state.username = username;
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

async function selectFolder(folder, force = false) {
    if (state.loading && !force) return;
    state.activeFolder = folder;
    elements.folderTitle.textContent = folder.name;
    elements.searchInput.value = '';
    markActiveFolder();
    closeMobileMenu();
    await loadMessages();
}

async function loadMessages() {
    if (!state.activeFolder) return;
    state.loading = true;
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
    }
}

function filteredMessages() {
    const term = elements.searchInput.value.trim().toLocaleLowerCase('tr-TR');
    if (!term) return state.messages;
    return state.messages.filter((message) => [message.from, message.subject, message.preview]
        .some((value) => String(value || '').toLocaleLowerCase('tr-TR').includes(term)));
}

function renderMessages() {
    const messages = filteredMessages();
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
        return;
    }

    const fragment = document.createDocumentFragment();
    messages.forEach((message, index) => fragment.append(createMessageRow(message, index)));
    elements.messageList.append(fragment);
}

function createMessageRow(message, index) {
    const article = document.createElement('article');
    article.className = `message-row${message.read ? '' : ' unread'}`;
    article.style.setProperty('--delay', `${Math.min(index, 12) * 18}ms`);

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

    article.append(unread, sender, content, meta);
    return article;
}

function friendlySender(value) {
    if (!value) return 'Bilinmeyen gönderen';
    const match = String(value).match(/^\s*"?([^"<]+?)"?\s*</);
    if (match) return match[1].trim();
    return String(value).replace(/[<>]/g, '').trim();
}

function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    const now = new Date();
    const sameDay = date.toDateString() === now.toDateString();
    if (sameDay) {
        return new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit' }).format(date);
    }
    if (date.getFullYear() === now.getFullYear()) {
        return new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'short' }).format(date);
    }
    return new Intl.DateTimeFormat('tr-TR', { day: '2-digit', month: '2-digit', year: '2-digit' }).format(date);
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
    showToast.timer = window.setTimeout(() => elements.toast.classList.remove('show'), 2600);
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
        showMail(data.username, data.folders || []);
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
    if (!state.loading) loadMessages();
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
    if (event.key === 'Escape') closeMobileMenu();
});

(async function boot() {
    try {
        const data = await api('folders');
        showMail(data.username, data.folders || []);
    } catch (_) {
        showLogin();
    }
})();
