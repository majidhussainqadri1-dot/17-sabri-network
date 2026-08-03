<?php defined('ABSPATH') || exit; ?>
<div id="sn-messages-root" class="sn-messages-root" data-version="<?php echo esc_attr(SN_VERSION); ?>" data-authenticated="<?php echo !empty($ready) ? '1' : '0'; ?>">
    <noscript><div class="snm-alert">JavaScript is required to use Messages.</div></noscript>

    <?php if (empty($ready)): ?>
        <section class="snm-auth-shell" aria-labelledby="snm-auth-title">
            <div class="snm-auth-card">
                <div class="snm-brand-mark" aria-hidden="true">M</div>
                <h1 id="snm-auth-title">Messages</h1>
                <p>Sign in through your Sabri Platform account. File 17 does not create accounts or bypass the platform identity authority.</p>
                <a class="snm-button snm-button-primary" href="<?php echo esc_url($login_url); ?>">Sign in to Messages</a>
            </div>
        </section>
    <?php else: ?>
        <div class="snm-app" aria-label="Messages application">
            <aside class="snm-sidebar" aria-label="Conversations">
                <header class="snm-sidebar-header">
                    <div>
                        <h1>Messages</h1>
                        <p>Private File 17 conversations</p>
                    </div>
                    <div class="snm-header-actions">
                        <button id="snm-new-conversation" class="snm-icon-button" type="button" aria-label="Start a new conversation">＋</button>
                        <a class="snm-icon-button" href="<?php echo esc_url(SN_Messages::settings_url()); ?>" aria-label="Communication settings">⚙</a>
                    </div>
                </header>
                <div class="snm-search-wrap">
                    <label class="screen-reader-text" for="snm-conversation-search">Search conversations</label>
                    <input id="snm-conversation-search" type="search" placeholder="Search conversations" autocomplete="off">
                </div>
                <div class="snm-list-filters" role="group" aria-label="Conversation filters">
                    <button type="button" class="snm-filter is-active" data-filter="active" aria-pressed="true">Active</button>
                    <button type="button" class="snm-filter" data-filter="unread" aria-pressed="false">Unread</button>
                    <button type="button" class="snm-filter" data-filter="archived" aria-pressed="false">Archived</button>
                </div>
                <div id="snm-conversation-list" class="snm-conversation-list" aria-live="polite"></div>
                <footer class="snm-sidebar-footer">
                    <a href="<?php echo esc_url(SN_Activator::network_url()); ?>">Open Network</a>
                </footer>
            </aside>

            <main class="snm-main">
                <section id="snm-empty" class="snm-empty-state">
                    <div class="snm-brand-mark snm-brand-mark-large" aria-hidden="true">M</div>
                    <h2>Select a conversation</h2>
                    <p>Messages, attachments and receipts stay inside File 17's authorization boundary.</p>
                </section>

                <section id="snm-chat" class="snm-chat" hidden>
                    <header class="snm-chat-header">
                        <button id="snm-back" class="snm-icon-button snm-mobile-only" type="button" aria-label="Back to conversations">←</button>
                        <img id="snm-chat-avatar" class="snm-chat-avatar" src="" alt="">
                        <div class="snm-chat-heading">
                            <h2 id="snm-chat-title"></h2>
                            <p id="snm-chat-subtitle"></p>
                        </div>
                        <div class="snm-chat-actions">
                            <button id="snm-mute" class="snm-icon-button" type="button" aria-label="Mute or unmute conversation">🔕</button>
                            <button id="snm-archive" class="snm-icon-button" type="button" aria-label="Archive or restore conversation">▣</button>
                        </div>
                    </header>
                    <div id="snm-message-status" class="snm-status" role="status" aria-live="polite"></div>
                    <div id="snm-message-list" class="snm-message-list" aria-live="polite" aria-relevant="additions"></div>
                    <form id="snm-composer" class="snm-composer">
                        <button id="snm-attach" class="snm-icon-button" type="button" aria-label="Attach a private file">＋</button>
                        <input id="snm-file" type="file" hidden accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg,audio/wav,audio/mp4,application/pdf,.docx,.xlsx">
                        <label class="screen-reader-text" for="snm-message-input">Message</label>
                        <textarea id="snm-message-input" rows="1" maxlength="10000" placeholder="Write a message"></textarea>
                        <button class="snm-send" type="submit" aria-label="Send message">➤</button>
                    </form>
                </section>
            </main>
        </div>

        <div id="snm-modal" class="snm-modal" hidden>
            <div class="snm-modal-backdrop" data-snm-close></div>
            <section class="snm-modal-card" role="dialog" aria-modal="true" aria-labelledby="snm-modal-title">
                <header>
                    <h2 id="snm-modal-title">New conversation</h2>
                    <button class="snm-icon-button" type="button" data-snm-close aria-label="Close">×</button>
                </header>
                <div id="snm-modal-body" class="snm-modal-body"></div>
            </section>
        </div>
        <div id="snm-toast" class="snm-toast" aria-live="assertive" hidden></div>
    <?php endif; ?>
</div>
