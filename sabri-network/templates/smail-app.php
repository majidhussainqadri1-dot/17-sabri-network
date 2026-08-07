<?php defined('ABSPATH') || exit; ?>
<section class="sn-sm" dir="auto" aria-labelledby="sn-sm-title">
  <header class="sn-sm__header">
    <div><p class="sn-sm__eyebrow">File 17 · Internal Communication</p><h1 id="sn-sm-title">Smail</h1><p>Private platform mail using the same verified identity, conversations, blocks and safety rules as Messages.</p></div>
    <nav aria-label="Smail related services"><a href="<?php echo esc_url(SN_Messages::messages_url()); ?>">Messages</a><a href="<?php echo esc_url(SN_File_Transfer::url()); ?>">File Transfer</a></nav>
  </header>
  <?php if (SN_Policy::access() !== true) : ?>
    <div class="sn-sm__notice" role="status"><p>Sign in with an approved platform account to use Smail.</p><a class="sn-sm__button" href="<?php echo esc_url((string) apply_filters('sn_network_login_url', wp_login_url(SN_Smail::url()), SN_Smail::url())); ?>">Sign in</a></div>
  <?php else : ?>
  <div class="sn-sm__layout">
    <aside class="sn-sm__sidebar" aria-label="Smail mailboxes">
      <button class="sn-sm__compose" type="button" data-sm-compose>Compose</button>
      <div class="sn-sm__boxes" role="list">
        <?php foreach (['inbox' => 'Inbox', 'sent' => 'Sent', 'drafts' => 'Drafts', 'starred' => 'Starred', 'archive' => 'Archive', 'spam' => 'Spam', 'trash' => 'Trash'] as $key => $label) : ?>
          <button type="button" role="listitem" data-sm-box="<?php echo esc_attr($key); ?>"<?php echo $key === 'inbox' ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
      </div>
    </aside>
    <main class="sn-sm__main">
      <div class="sn-sm__toolbar"><h2 data-sm-heading>Inbox</h2><button type="button" data-sm-refresh>Refresh</button></div>
      <div class="sn-sm__status" data-sm-status role="status" aria-live="polite"></div>
      <div class="sn-sm__list" data-sm-list aria-live="polite"></div>
    </main>
  </div>
  <dialog class="sn-sm__dialog" data-sm-dialog aria-labelledby="sn-sm-compose-title">
    <form method="dialog" data-sm-form>
      <header><h2 id="sn-sm-compose-title">New Smail</h2><button type="button" data-sm-close aria-label="Close composer">×</button></header>
      <input type="hidden" name="draft_id"><input type="hidden" name="version" value="0">
      <label>Recipients <span>Verified user IDs, separated by commas</span><input name="recipients" inputmode="numeric" autocomplete="off" required></label>
      <label>Subject<input name="subject" maxlength="200" required></label>
      <label>Message<textarea name="body" maxlength="10000" rows="12" required></textarea></label>
      <p class="sn-sm__draft-state" data-sm-draft-state aria-live="polite"></p>
      <footer><button type="button" data-sm-save>Save draft</button><button class="sn-sm__button" type="submit">Send securely</button></footer>
    </form>
  </dialog>
  <?php endif; ?>
</section>
