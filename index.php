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
    <link rel="stylesheet" href="assets/style.css?v=1">
    <script src="assets/app.js?v=1" defer></script>
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
                <p id="messageCount">Mailler yükleniyor…</p>
                <span id="pageInfo"></span>
            </div>

            <section class="message-list" id="messageList" aria-live="polite">
                <div class="loading-state">
                    <span class="spinner"></span>
                    <p>Postalar alınıyor…</p>
                </div>
            </section>
        </main>
    </section>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
