<?php
defined('ABSPATH') || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('sn-meet-page'); ?>>
<?php wp_body_open(); ?>
<main id="sn-meet-root" class="sn-meet" aria-label="Sabri Meet">
    <a class="sn-meet-skip" href="#sn-meet-main">Skip to meeting controls</a>
    <header class="sn-meet-header">
        <a class="sn-meet-brand" href="<?php echo esc_url(home_url('/calls/')); ?>" aria-label="Sabri Meet dashboard">
            <span class="sn-meet-mark" aria-hidden="true">SM</span>
            <span>Sabri Meet</span>
        </a>
        <nav aria-label="Meeting navigation">
            <a href="<?php echo esc_url(SN_Activator::network_url()); ?>">Network &amp; Messages</a>
        </nav>
    </header>

    <div id="sn-meet-main" class="sn-meet-main" tabindex="-1">
        <div id="sn-meet-status" class="sn-meet-status" role="status" aria-live="polite">Loading Sabri Meet…</div>
        <div id="sn-meet-alert" class="sn-meet-alert" role="alert" hidden></div>

        <?php if (!is_user_logged_in()) : ?>
            <section class="sn-meet-card sn-meet-auth">
                <h1 id="sn-meet-title">Sabri Meet</h1>
                <p>Sign in through the platform account system to open or join a meeting.</p>
                <a class="sn-meet-primary" href="<?php echo esc_url(wp_login_url(home_url(add_query_arg([], $GLOBALS['wp']->request ?? 'calls/')))); ?>">Sign in</a>
            </section>
        <?php else : ?>
            <section id="sn-meet-dashboard" class="sn-meet-dashboard" hidden>
                <div class="sn-meet-dashboard-head">
                    <div>
                        <h1 id="sn-meet-title">Sabri Meet</h1>
                        <p>Private, identity-bound meetings with host admission and moderated participation.</p>
                    </div>
                    <button id="sn-meet-new" class="sn-meet-primary" type="button">New meeting</button>
                </div>

                <form id="sn-meet-create-form" class="sn-meet-card sn-meet-form" hidden novalidate>
                    <h2>Create a meeting</h2>
                    <label>Title
                        <input id="sn-meet-create-title" name="title" maxlength="191" required autocomplete="off">
                    </label>
                    <label>Description
                        <textarea id="sn-meet-create-description" name="description" maxlength="2000" rows="3"></textarea>
                    </label>
                    <div class="sn-meet-form-grid">
                        <label>Start time
                            <input id="sn-meet-create-start" name="scheduled_start" type="datetime-local">
                        </label>
                        <label>Participant limit
                            <input id="sn-meet-create-limit" name="participant_limit" type="number" min="2" max="100" value="100">
                        </label>
                    </div>
                    <label class="sn-meet-checkbox">
                        <input id="sn-meet-create-lobby" name="lobby_enabled" type="checkbox" checked>
                        Use waiting room and host admission
                    </label>
                    <div class="sn-meet-form-actions">
                        <button class="sn-meet-primary" type="submit">Create Sabri Meet</button>
                        <button id="sn-meet-create-cancel" type="button">Cancel</button>
                    </div>
                </form>

                <section aria-labelledby="sn-meet-list-title">
                    <h2 id="sn-meet-list-title">Your meetings</h2>
                    <div id="sn-meet-list" class="sn-meet-list"></div>
                </section>
            </section>

            <section id="sn-meet-room" class="sn-meet-room" hidden>
                <header class="sn-meet-room-head">
                    <div>
                        <p class="sn-meet-eyebrow">Sabri Meet</p>
                        <h1 id="sn-meet-title-room">Meeting</h1>
                        <p id="sn-meet-room-meta"></p>
                    </div>
                    <div class="sn-meet-room-actions">
                        <a id="sn-meet-chat" href="#" hidden>Meeting chat</a>
                        <button id="sn-meet-copy-link" type="button">Copy link</button>
                        <button id="sn-meet-lock" type="button" hidden>Lock meeting</button>
                        <button id="sn-meet-end" class="sn-meet-danger" type="button" hidden>End meeting</button>
                    </div>
                </header>

                <div class="sn-meet-layout">
                    <section class="sn-meet-stage" aria-label="Meeting stage">
                        <div id="sn-meet-local-tile" class="sn-meet-tile">
                            <video id="sn-meet-local-video" autoplay muted playsinline></video>
                            <div class="sn-meet-tile-label">You <span id="sn-meet-local-state"></span></div>
                        </div>
                        <div id="sn-meet-provider-stage" class="sn-meet-provider-stage" aria-live="polite"></div>
                    </section>

                    <aside id="sn-meet-participant-panel" class="sn-meet-panel" aria-labelledby="sn-meet-participants-title">
                        <h2 id="sn-meet-participants-title">Participants</h2>
                        <form id="sn-meet-invite-form" class="sn-meet-invite" hidden>
                            <label for="sn-meet-invite-user">Invite member by user ID</label>
                            <div>
                                <input id="sn-meet-invite-user" type="number" min="1" inputmode="numeric" required>
                                <button type="submit">Invite</button>
                            </div>
                        </form>
                        <ul id="sn-meet-participants" class="sn-meet-participants"></ul>
                    </aside>
                </div>

                <div id="sn-meet-waiting" class="sn-meet-card sn-meet-waiting" hidden>
                    <h2>Waiting room</h2>
                    <p>The host must admit you before conference media becomes available.</p>
                </div>

                <div id="sn-meet-prejoin" class="sn-meet-card sn-meet-prejoin">
                    <h2>Ready to join?</h2>
                    <p>Camera and microphone access begins only after you choose a control.</p>
                    <button id="sn-meet-join" class="sn-meet-primary" type="button">Ask to join</button>
                </div>

                <footer class="sn-meet-controls" aria-label="Meeting controls">
                    <button id="sn-meet-mic" type="button" aria-pressed="false" disabled>Microphone</button>
                    <button id="sn-meet-camera" type="button" aria-pressed="false" disabled>Camera</button>
                    <button id="sn-meet-share" type="button" aria-pressed="false" disabled>Share screen</button>
                    <button id="sn-meet-captions" type="button" aria-pressed="false" disabled>Captions</button>
                    <button id="sn-meet-hand" type="button" aria-pressed="false" disabled>Raise hand</button>
                    <button id="sn-meet-leave" class="sn-meet-danger" type="button" disabled>Leave</button>
                </footer>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php wp_footer(); ?>
</body>
</html>
