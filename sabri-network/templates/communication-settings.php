<?php defined('ABSPATH') || exit; ?>
<div id="sn-communication-settings-root" class="snm-settings-root" data-version="<?php echo esc_attr(SN_VERSION); ?>" data-authenticated="<?php echo !empty($ready) ? '1' : '0'; ?>">
    <noscript><div class="snm-alert">JavaScript is required to manage communication settings.</div></noscript>

    <?php if (empty($ready)): ?>
        <section class="snm-auth-shell" aria-labelledby="snm-settings-auth-title">
            <div class="snm-auth-card">
                <div class="snm-brand-mark" aria-hidden="true">⚙</div>
                <h1 id="snm-settings-auth-title">Communication Settings</h1>
                <p>Sign in through your Sabri Platform account to manage privacy and communication preferences.</p>
                <a class="snm-button snm-button-primary" href="<?php echo esc_url($login_url); ?>">Sign in</a>
            </div>
        </section>
    <?php else: ?>
        <main class="snm-settings-shell" aria-labelledby="snm-settings-title">
            <header class="snm-settings-header">
                <div>
                    <p class="snm-eyebrow">File 17</p>
                    <h1 id="snm-settings-title">Communication Settings</h1>
                    <p>Choose who may reach you. Server-side policy, verified age, guardian consent, blocks and account restrictions remain authoritative.</p>
                </div>
                <a class="snm-button" href="<?php echo esc_url(SN_Messages::messages_url()); ?>">Back to Messages</a>
            </header>

            <form id="snm-settings-form" class="snm-settings-form">
                <section class="snm-settings-card" aria-labelledby="snm-contact-settings-title">
                    <h2 id="snm-contact-settings-title">Contact permissions</h2>
                    <div class="snm-settings-grid">
                        <label>Messages
                            <select name="messages">
                                <option value="everyone">Everyone</option>
                                <option value="contacts">Contacts</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </label>
                        <label>Calls
                            <select name="calls">
                                <option value="everyone">Everyone</option>
                                <option value="contacts">Contacts</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </label>
                        <label>Group invitations
                            <select name="groups">
                                <option value="everyone">Everyone</option>
                                <option value="contacts">Contacts</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </label>
                        <label>Updates
                            <select name="updates">
                                <option value="everyone">Everyone</option>
                                <option value="contacts">Contacts</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </label>
                        <label>Followers
                            <select name="follows">
                                <option value="everyone">Everyone</option>
                                <option value="contacts">Contacts</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </label>
                        <label>Last seen
                            <select name="last_seen">
                                <option value="everyone">Everyone</option>
                                <option value="contacts">Contacts</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </label>
                    </div>
                </section>

                <section class="snm-settings-card" aria-labelledby="snm-profile-settings-title">
                    <h2 id="snm-profile-settings-title">Profile visibility used by communication</h2>
                    <div class="snm-settings-grid">
                        <label>Phone visibility
                            <select name="phone_visibility">
                                <option value="everyone">Everyone</option>
                                <option value="contacts">Contacts</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </label>
                        <label>Profile photo
                            <select name="profile_photo">
                                <option value="everyone">Everyone</option>
                                <option value="contacts">Contacts</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </label>
                    </div>
                </section>

                <div id="snm-settings-message" class="snm-status" role="status" aria-live="polite"></div>
                <button class="snm-button snm-button-primary" type="submit">Save communication settings</button>
            </form>
        </main>
    <?php endif; ?>
</div>
