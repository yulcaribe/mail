<?php
declare(strict_types=1);

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Beyan Mail</title>
    <link rel="stylesheet" href="assets/style.css?v=2">
    <script src="assets/app.js?v=2" defer></script>
</head>
<body>
    <noscript>Bu posta arayüzünü kullanmak için JavaScript etkin olmalıdır.</noscript>

    <section class="login-view" id="loginView" hidden>
        <div class="login-card">
            <div class="brand brand-login" aria-label="Beyan Mail">
                <span class="brand-mark">B</span>
                <span><strong>Beyan</strong><small>MAIL</small></span>
            </div>
            <div class="login-copy">
                <h1>Postanıza giriş yapın</h1>
                <p>TGS Exchange hesabınızla devam edin.</p>
            </div>
            <form id="loginForm" autocomplete="on">
                <label for="username">Kullanıcı adı</label>
                <div class="input-wrap">
                    <span class="input-icon" aria-hidden="true">@</span>
                    <input id="username" name="username" type="text" autocomplete="username" placeholder="kullanici.adi" required autofocus>
                </div>
                <label for="password">Parola</label>
                <div class="input-wrap">
                    <span class="input-icon lock" aria-hidden="true"></span>
                    <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Parolanız" required>
                    <button class="password-toggle" id="passwordToggle" type="button" aria-label="Parolayı göster">Göster</button>
                </div>
                <p class="form-error" id="loginError" role="alert" hidden></p>
                <button class="primary-button" id="loginButton" type="submit">
                    <span>Giriş yap</span>
                    <span aria-hidden="true">→</span>
                </button>
            </form>
            <p class="privacy-note">Parolanız tarayıcıda kaydedilmez.</p>
        </div>
    </section>

    <section class="mail-view" id="mailView" hidden>
        <aside class="sidebar" id="sidebar">
            <div class="brand sidebar-brand">
                <span class="brand-mark">B</span>
                <span><strong>Beyan</strong><small>MAIL</small></span>
            </div>

            <div class="account">
                <span class="avatar" id="avatar">B</span>
                <span class="account-copy">
                    <strong id="accountName">Hesap</strong>
                    <small>TGS Exchange</small>
                </span>
            </div>

            <nav class="folder-nav" aria-label="Posta klasörleri">
                <p class="nav-label">Klasörler</p>
                <div id="folderList"></div>
            </nav>

            <button class="logout-button" id="logoutButton" type="button">
                <span aria-hidden="true">↪</span>
                Oturumu kapat
            </button>
        </aside>

        <div class="page-shade" id="pageShade"></div>

        <main class="mail-main">
            <header class="mail-header">
                <div class="heading-row">
                    <button class="menu-button" id="menuButton" type="button" aria-label="Klasörleri aç">☰</button>
                    <div>
                        <p class="eyebrow">Posta kutusu</p>
                        <h1 id="folderTitle">Gelen Kutusu</h1>
                    </div>
                    <button class="refresh-button" id="refreshButton" type="button" aria-label="Yenile">↻</button>
                </div>
                <div class="search-box">
                    <span aria-hidden="true">⌕</span>
                    <input id="searchInput" type="search" placeholder="Bu klasörde ara" autocomplete="off">
                    <kbd>⌘ K</kbd>
                </div>
            </header>

            <div class="list-toolbar">
                <div class="list-summary">
                    <label class="select-all" title="Görünen maillerin tümünü seç">
                        <input id="selectAll" type="checkbox" aria-label="Görünen maillerin tümünü seç">
                        <span></span>
                    </label>
                    <p id="messageCount">Mailler yükleniyor…</p>
                </div>
                <span id="pageInfo"></span>
            </div>

            <div class="bulk-toolbar" id="bulkToolbar" hidden>
                <strong id="selectedCount">0 mail seçildi</strong>
                <div class="bulk-actions">
                    <button id="markReadButton" type="button" title="Okundu olarak işaretle">✓ Okundu</button>
                    <button id="markUnreadButton" type="button" title="Okunmadı olarak işaretle">● Okunmadı</button>
                    <div class="move-control">
                        <select id="moveTarget" aria-label="Taşınacak klasör"></select>
                        <button id="moveButton" type="button">Taşı</button>
                    </div>
                    <button class="danger-action" id="deleteButton" type="button">⌫ Sil</button>
                    <button class="clear-selection" id="clearSelectionButton" type="button" aria-label="Seçimi temizle">×</button>
                </div>
            </div>

            <section class="message-list" id="messageList" aria-live="polite">
                <div class="loading-state">
                    <span class="spinner"></span>
                    <p>Postalar alınıyor…</p>
                </div>
            </section>
        </main>

        <div class="reader-overlay" id="readerOverlay" hidden>
            <div class="reader-backdrop" id="readerBackdrop"></div>
            <article class="reader-panel" role="dialog" aria-modal="true" aria-labelledby="readerSubject">
                <header class="reader-toolbar">
                    <button class="reader-close" id="readerClose" type="button" aria-label="Maili kapat">←</button>
                    <div class="reader-nav">
                        <button id="readerPrevious" type="button" aria-label="Önceki mail">↑</button>
                        <button id="readerNext" type="button" aria-label="Sonraki mail">↓</button>
                    </div>
                    <div class="reader-actions">
                        <button id="readerUnread" type="button">● Okunmadı</button>
                        <div class="move-control reader-move">
                            <select id="readerMoveTarget" aria-label="Maili taşıyacağınız klasör"></select>
                            <button id="readerMoveButton" type="button">Taşı</button>
                        </div>
                        <button class="danger-action" id="readerDelete" type="button">⌫ Sil</button>
                    </div>
                </header>

                <div class="reader-content">
                    <p class="reader-folder" id="readerFolder"></p>
                    <h1 id="readerSubject">(Konu yok)</h1>
                    <div class="reader-sender-row">
                        <span class="reader-avatar" id="readerAvatar">?</span>
                        <div class="reader-addresses">
                            <strong id="readerFrom"></strong>
                            <button id="recipientToggle" type="button" aria-expanded="false">Alıcı ayrıntıları⌄</button>
                            <div class="recipient-details" id="recipientDetails" hidden>
                                <p><span>Kime</span><b id="readerTo">—</b></p>
                                <p id="readerCcRow"><span>Bilgi</span><b id="readerCc">—</b></p>
                            </div>
                        </div>
                        <time id="readerDate"></time>
                    </div>

                    <div class="reader-attachments" id="readerAttachments" hidden></div>
                    <div class="reader-body" id="readerBody"></div>
                </div>
            </article>
        </div>
    </section>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
