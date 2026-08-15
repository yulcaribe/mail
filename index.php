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
    <link rel="stylesheet" href="assets/style.css?v=7">
    <script src="assets/app.js?v=7" defer></script>
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

            <button class="settings-button" id="settingsButton" type="button">
                <span aria-hidden="true">⚙</span>
                Hesap ayarları
            </button>
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
                    <div class="heading-copy">
                        <p class="eyebrow">Posta kutusu</p>
                        <h1 id="folderTitle">Gelen Kutusu</h1>
                    </div>
                    <div class="header-actions">
                        <button class="empty-trash-button" id="emptyTrashButton" type="button" aria-label="Çöp Kutusunu tamamen boşalt" hidden>
                            <span aria-hidden="true">⌫</span>
                            <b>Çöp Kutusunu boşalt</b>
                        </button>
                        <button class="mailbox-cleanup-button" id="mailboxCleanupButton" type="button" aria-label="Mail kutusunu boşalt">
                            <span aria-hidden="true">◷</span>
                            <b>Mail kutusunu boşalt</b>
                        </button>
                        <button class="refresh-button" id="refreshButton" type="button" aria-label="Yenile">↻</button>
                    </div>
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

        <div class="settings-overlay" id="settingsOverlay" hidden>
            <div class="settings-backdrop" id="settingsBackdrop"></div>
            <section class="settings-panel" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
                <header class="settings-header">
                    <div>
                        <p>BEYAN MAIL</p>
                        <h1 id="settingsTitle">Hesap ayarları</h1>
                    </div>
                    <button id="settingsClose" type="button" aria-label="Ayarları kapat">×</button>
                </header>

                <div class="settings-content">
                    <section class="settings-account-card">
                        <span class="settings-account-avatar" id="settingsAvatar">B</span>
                        <div>
                            <strong id="settingsUsername">Hesap</strong>
                            <p>TGS Exchange · ActiveSync 14.1 · EWS</p>
                        </div>
                        <span class="connection-badge">Bağlı</span>
                    </section>

                    <section class="rules-section">
                        <div class="rules-heading">
                            <div>
                                <p class="settings-kicker">POSTA KUTUSU</p>
                                <h2>Kurallar</h2>
                                <span>Gelen mailleri koşullara göre otomatik yönetin.</span>
                            </div>
                            <button class="add-rule-button" id="addRuleButton" type="button">＋ Yeni kural</button>
                        </div>

                        <div class="rules-warning" id="rulesWarning" hidden>
                            <span aria-hidden="true">!</span>
                            <p><strong>Outlook kural verisi bulundu.</strong> İlk değişiklikte bazı kapalı veya yalnızca Outlook’ta çalışan kurallar kaybolabilir. Yazma işleminden hemen önce ayrıca onay istenecek.</p>
                        </div>

                        <div class="rules-status" id="rulesStatus">
                            <span class="spinner"></span>
                            <p>Kurallar hazırlanıyor…</p>
                        </div>
                        <div class="rules-list" id="rulesList"></div>
                    </section>
                </div>
            </section>
        </div>

        <div class="cleanup-overlay" id="cleanupOverlay" hidden>
            <div class="cleanup-backdrop" id="cleanupBackdrop"></div>
            <section class="cleanup-dialog" role="dialog" aria-modal="true" aria-labelledby="cleanupTitle">
                <header>
                    <div>
                        <p>POSTA KUTUSU TEMİZLİĞİ</p>
                        <h2 id="cleanupTitle">Eski mailleri temizle</h2>
                    </div>
                    <button id="cleanupClose" type="button" aria-label="Temizlik penceresini kapat">×</button>
                </header>

                <form id="cleanupForm">
                    <div class="cleanup-icon" aria-hidden="true">◷</div>
                    <p class="cleanup-intro">Seçtiğiniz süreden daha eski alınmış mailler, Gelen Kutusu ve bütün normal alt klasörlerden Çöp Kutusu’na taşınır.</p>

                    <label for="cleanupHours">Son kaç saat korunsun?</label>
                    <select id="cleanupHours">
                        <option value="1">Son 1 saat</option>
                        <option value="2">Son 2 saat</option>
                        <option value="4">Son 4 saat</option>
                        <option value="6">Son 6 saat</option>
                        <option value="12">Son 12 saat</option>
                        <option value="24">Son 24 saat</option>
                        <option value="48">Son 2 gün</option>
                        <option value="72">Son 3 gün</option>
                        <option value="168">Son 7 gün</option>
                    </select>

                    <div class="cleanup-summary">
                        <span aria-hidden="true">i</span>
                        <p><strong id="cleanupCutoffText"></strong> tarihinden önce gelen mailler taşınacak. Gönderilenler, Taslaklar, Giden Kutusu ve mevcut Çöp Kutusu etkilenmez.</p>
                    </div>

                    <p class="cleanup-progress" id="cleanupProgress" role="status" hidden></p>
                    <p class="form-error" id="cleanupError" role="alert" hidden></p>
                    <div class="cleanup-actions">
                        <button id="cleanupCancel" type="button">Vazgeç</button>
                        <button class="cleanup-submit" id="cleanupSubmit" type="submit">Eski mailleri Çöp Kutusu’na taşı</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="rule-editor-overlay" id="ruleEditorOverlay" hidden>
            <div class="rule-editor-backdrop" id="ruleEditorBackdrop"></div>
            <section class="rule-editor" role="dialog" aria-modal="true" aria-labelledby="ruleEditorTitle">
                <header>
                    <div>
                        <p>POSTA KUTUSU KURALI</p>
                        <h2 id="ruleEditorTitle">Yeni kural</h2>
                    </div>
                    <button id="ruleEditorClose" type="button" aria-label="Kural düzenleyiciyi kapat">×</button>
                </header>

                <form id="ruleForm">
                    <input id="ruleId" type="hidden">
                    <label for="ruleName">Kural adı</label>
                    <input id="ruleName" type="text" maxlength="128" placeholder="Ör. Raporları arşivle" required>

                    <fieldset>
                        <legend>Şu koşullardan hepsi eşleşirse</legend>
                        <label for="ruleFrom">Gönderen adresi</label>
                        <input id="ruleFrom" type="text" maxlength="320" placeholder="ornek@firma.com">

                        <label for="ruleSubject">Konu şunu içeriyor</label>
                        <input id="ruleSubject" type="text" maxlength="255" placeholder="Ör. Günlük rapor">

                        <label class="rule-check">
                            <input id="ruleHasAttachments" type="checkbox">
                            <span>Mail ek içeriyor</span>
                        </label>
                    </fieldset>

                    <fieldset>
                        <legend>Şunu yap</legend>
                        <label for="ruleAction">Ana eylem</label>
                        <select id="ruleAction" required>
                            <option value="move">Klasöre taşı</option>
                            <option value="delete">Sil</option>
                            <option value="markRead">Okundu olarak işaretle</option>
                        </select>

                        <div id="ruleMoveRow">
                            <label for="ruleMoveFolder">Hedef klasör</label>
                            <select id="ruleMoveFolder"></select>
                        </div>

                        <label class="rule-check" id="ruleMarkReadRow">
                            <input id="ruleMarkAsRead" type="checkbox">
                            <span>Ayrıca okundu olarak işaretle</span>
                        </label>
                        <label class="rule-check">
                            <input id="ruleStopProcessing" type="checkbox" checked>
                            <span>Bu kuraldan sonra diğer kuralları durdur</span>
                        </label>
                        <label class="rule-check">
                            <input id="ruleEnabled" type="checkbox" checked>
                            <span>Kural etkin</span>
                        </label>
                    </fieldset>

                    <p class="form-error" id="ruleFormError" role="alert" hidden></p>
                    <div class="rule-form-actions">
                        <button id="ruleCancelButton" type="button">Vazgeç</button>
                        <button class="rule-save-button" id="ruleSaveButton" type="submit">Kuralı kaydet</button>
                    </div>
                </form>
            </section>
        </div>
    </section>

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
