<?php defined('ABSPATH') || exit; ?>
<section class="sn-ft" dir="auto" aria-labelledby="sn-ft-title">
  <header class="sn-ft__header"><div><p class="sn-ft__eyebrow">File 17 · Verified Users</p><h1 id="sn-ft-title">Private File Transfer</h1><p>Resumable, encrypted transfer up to 1 GB per file. Every recipient, relationship and access grant is rechecked.</p></div><nav><a href="<?php echo esc_url(SN_Smail::url()); ?>">Smail</a><a href="<?php echo esc_url(SN_Messages::messages_url()); ?>">Messages</a></nav></header>
  <?php if (SN_File_Transfer::verified_access() !== true) : ?>
    <div class="sn-ft__notice" role="status"><p>A current verified platform account is required. Verification, suspension, consent and safety state are checked again at every protected step.</p><a class="sn-ft__primary" href="<?php echo esc_url((string) apply_filters('sn_network_login_url', wp_login_url(SN_File_Transfer::url()), SN_File_Transfer::url())); ?>">Sign in</a></div>
  <?php else : ?>
  <div class="sn-ft__grid">
    <form class="sn-ft__card" data-ft-form>
      <h2>Send a private file</h2>
      <label>File<input type="file" name="file" required></label>
      <label>Recipients <span>Verified user IDs separated by commas</span><input name="recipients" inputmode="numeric" autocomplete="off" required></label>
      <label>Optional conversation ID<input name="conversation" inputmode="numeric" autocomplete="off"></label>
      <div class="sn-ft__meter" aria-label="Upload progress"><span data-ft-progress></span></div>
      <p data-ft-status role="status" aria-live="polite">Files remain private and unavailable until integrity, type, archive and approved malware checks pass.</p>
      <button class="sn-ft__primary" type="submit">Start secure transfer</button>
    </form>
    <section class="sn-ft__card"><div class="sn-ft__tabs" role="tablist"><button role="tab" aria-selected="true" data-ft-box="inbox">Received</button><button role="tab" aria-selected="false" data-ft-box="sent">Sent</button></div><div data-ft-list class="sn-ft__list" aria-live="polite"></div></section>
  </div>
  <?php endif; ?>
</section>
