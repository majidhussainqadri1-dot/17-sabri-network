<?php defined('ABSPATH') || exit; ?>
<div id="sn-network-root" class="sn-network-root" data-version="<?php echo esc_attr(SN_VERSION); ?>" data-authenticated="<?php echo !empty($network_ready) ? '1' : '0'; ?>">
    <noscript><div class="sn-alert">JavaScript is required to use Network.</div></noscript>

    <?php if (empty($network_ready)): ?>
        <section class="sn-auth-shell" aria-labelledby="sn-auth-title">
            <div class="sn-auth-card">
                <div class="sn-brand-mark" aria-hidden="true">N</div>
                <h1 id="sn-auth-title">Network and Messages</h1>
                <p class="sn-muted">Sign in through your Sabri Platform account. File 17 does not create accounts or issue verification codes.</p>
                <a class="sn-btn sn-btn-primary" href="<?php echo esc_url($login_url); ?>">Sign in to Network</a>
                <p class="sn-build-line">Version <?php echo esc_html(SN_VERSION); ?> · <a href="<?php echo esc_url(SN_Activator::safe_url()); ?>">Open Safe Route</a></p>
                <?php if (is_wp_error($access)): ?><p class="sn-form-message" role="alert"><?php echo esc_html($access->get_error_message()); ?></p><?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <div class="sn-app" aria-label="Network communication application">
            <aside class="sn-sidebar" aria-label="Network navigation">
                <header class="sn-sidebar-header">
                    <button id="sn-profile-button" type="button" class="sn-avatar-button" aria-label="Open profile">
                        <img src="<?php echo esc_url(get_avatar_url(get_current_user_id(), ['size' => 96])); ?>" alt="">
                    </button>
                    <div class="sn-sidebar-actions">
                        <button type="button" class="sn-icon-btn" id="sn-new-button" aria-label="Create conversation">＋</button>
                        <button type="button" class="sn-icon-btn" id="sn-notifications-button" aria-label="Notifications">🔔<span id="sn-notification-badge" class="sn-badge" hidden></span></button>
                        <button type="button" class="sn-icon-btn" id="sn-settings-button" aria-label="Network settings">⚙</button>
                    </div>
                </header>
                <nav class="sn-tabs" aria-label="Network sections">
                    <button type="button" class="sn-tab is-active" data-tab="chats" aria-current="page">Chats</button>
                    <button type="button" class="sn-tab" data-tab="calls">Calls</button>
                    <button type="button" class="sn-tab" data-tab="updates">Updates</button>
                    <button type="button" class="sn-tab" data-tab="communities">Communities</button>
                    <button type="button" class="sn-tab" data-tab="contacts">Contacts</button>
                </nav>
                <div class="sn-search-wrap"><label class="screen-reader-text" for="sn-global-search">Search Network</label><input id="sn-global-search" type="search" placeholder="Search Network" autocomplete="off"></div>
                <div id="sn-sidebar-content" class="sn-sidebar-content" aria-live="polite"></div>
            </aside>

            <main class="sn-main">
                <section id="sn-empty-state" class="sn-empty-state">
                    <div class="sn-brand-mark sn-brand-mark-large" aria-hidden="true">N</div>
                    <h2>Network and Messages</h2>
                    <p>Select a conversation or start an approved connection.</p>
                </section>

                <section id="sn-chat-view" class="sn-chat-view" hidden>
                    <header class="sn-chat-header">
                        <button type="button" id="sn-mobile-back" class="sn-icon-btn sn-mobile-only" aria-label="Back">←</button>
                        <img id="sn-chat-avatar" class="sn-chat-avatar" src="" alt="">
                        <div class="sn-chat-heading"><strong id="sn-chat-title"></strong><span id="sn-chat-subtitle"></span></div>
                        <div class="sn-chat-actions">
                            <button type="button" id="sn-audio-call" class="sn-icon-btn" aria-label="Start audio call">☎</button>
                            <button type="button" id="sn-video-call" class="sn-icon-btn" aria-label="Start video call">▣</button>
                            <button type="button" id="sn-chat-info" class="sn-icon-btn" aria-label="Conversation information">⋮</button>
                        </div>
                    </header>
                    <div id="sn-message-list" class="sn-message-list" aria-live="polite" aria-relevant="additions"></div>
                    <div id="sn-reply-preview" class="sn-reply-preview" hidden></div>
                    <form id="sn-composer" class="sn-composer">
                        <button type="button" id="sn-attach-button" class="sn-icon-btn" aria-label="Attach private file">＋</button>
                        <input id="sn-file-input" type="file" hidden accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg,audio/wav,audio/mp4,application/pdf,.docx,.xlsx">
                        <label class="screen-reader-text" for="sn-message-input">Message</label>
                        <textarea id="sn-message-input" rows="1" maxlength="10000" placeholder="Write a message"></textarea>
                        <button type="submit" class="sn-send-btn" aria-label="Send message">➤</button>
                    </form>
                </section>
            </main>
        </div>
    <?php endif; ?>

    <div id="sn-modal" class="sn-modal" hidden>
        <div class="sn-modal-backdrop" data-close-modal></div>
        <section class="sn-modal-card" role="dialog" aria-modal="true" aria-labelledby="sn-modal-title">
            <header><h2 id="sn-modal-title">Network</h2><button type="button" class="sn-icon-btn" data-close-modal aria-label="Close">×</button></header>
            <div id="sn-modal-body" class="sn-modal-body"></div>
        </section>
    </div>

    <div id="sn-call-overlay" class="sn-call-overlay" hidden>
        <div class="sn-call-stage" role="dialog" aria-modal="true" aria-labelledby="sn-call-name">
            <video id="sn-remote-video" autoplay playsinline></video>
            <video id="sn-local-video" autoplay playsinline muted></video>
            <div class="sn-call-details"><strong id="sn-call-name">Network call</strong><span id="sn-call-status">Connecting…</span></div>
            <div class="sn-call-controls">
                <button type="button" id="sn-call-mute" class="sn-call-btn">Mute</button>
                <button type="button" id="sn-call-camera" class="sn-call-btn">Camera</button>
                <button type="button" id="sn-call-end" class="sn-call-btn sn-call-end">End</button>
            </div>
        </div>
    </div>

    <div id="sn-toast-container" class="sn-toast-container" aria-live="assertive"></div>
</div>
