<?php
/**
 * Profil: zmiana hasła, włączanie/wyłączanie 2FA (TOTP).
 */

$me = current_user();
$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$me['id']]);
$user = $st->fetch();

$newSecret = $_SESSION['totp_pending_secret'] ?? null;

if (is_post()) {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'change_password') {
        $current = (string) post('current_password');
        $new = (string) post('new_password');
        $repeat = (string) post('repeat_password');
        if (!password_verify($current, $user['password_hash'])) {
            flash('err', 'Obecne hasło jest nieprawidłowe.');
        } elseif (strlen($new) < 12) {
            flash('err', 'Nowe hasło musi mieć co najmniej 12 znaków.');
        } elseif ($new !== $repeat) {
            flash('err', 'Nowe hasła nie są identyczne.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            audit('password_change', $me['username']);
            session_regenerate_id(true);
            flash('ok', 'Hasło zostało zmienione.');
        }
        redirect_to(panel_url('profile'));
    }

    if ($action === 'enable_2fa_start') {
        $_SESSION['totp_pending_secret'] = totp_new_secret();
        redirect_to(panel_url('profile'));
    }

    if ($action === 'enable_2fa_confirm') {
        $secret = $_SESSION['totp_pending_secret'] ?? '';
        $code = (string) post('code');
        if ($secret && totp_verify($secret, $code)) {
            db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?')->execute([$secret, $me['id']]);
            unset($_SESSION['totp_pending_secret']);
            audit('2fa_enabled', $me['username']);
            flash('ok', 'Dwuskładnikowe logowanie (2FA) zostało włączone.');
        } else {
            flash('err', 'Kod jest nieprawidłowy. Spróbuj ponownie.');
        }
        redirect_to(panel_url('profile'));
    }

    if ($action === 'disable_2fa') {
        $password = (string) post('password');
        if (password_verify($password, $user['password_hash'])) {
            db()->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?')->execute([$me['id']]);
            audit('2fa_disabled', $me['username']);
            flash('info', '2FA zostało wyłączone.');
        } else {
            flash('err', 'Hasło jest nieprawidłowe.');
        }
        redirect_to(panel_url('profile'));
    }
}

$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$me['id']]);
$user = $st->fetch();

layout_head('Mój profil', '');
?>

<div class="grid grid--2">
  <section class="card">
    <header class="card__head"><h2>Zmiana hasła</h2></header>
    <div class="card__body">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <label class="field"><span>Obecne hasło</span><input type="password" name="current_password" required autocomplete="current-password"></label>
        <label class="field"><span>Nowe hasło (min. 12 znaków)</span><input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label>
        <label class="field"><span>Powtórz nowe hasło</span><input type="password" name="repeat_password" required minlength="12" autocomplete="new-password"></label>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Zmień hasło</button></div>
      </form>
    </div>
  </section>

  <section class="card">
    <header class="card__head"><h2>Dwuskładnikowe logowanie (2FA)</h2></header>
    <div class="card__body">
      <?php if ((int) $user['totp_enabled'] === 1): ?>
        <p><?= status_badge('ok') ?> 2FA jest włączone. Przy logowaniu potrzebny jest kod z aplikacji (Google Authenticator, Authy, 1Password itp.).</p>
        <form method="post" class="form-grid" style="margin-top:14px;max-width:340px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="disable_2fa">
          <label class="field"><span>Potwierdź hasłem</span><input type="password" name="password" required autocomplete="current-password"></label>
          <div class="form-actions"><button class="btn btn--danger" type="submit" data-confirm="Wyłączyć 2FA?">Wyłącz 2FA</button></div>
        </form>
      <?php elseif ($newSecret): ?>
        <p class="small">Dodaj to konto w aplikacji authenticator (wpisz sekret ręcznie) i podaj kod, aby potwierdzić.</p>
        <div class="help-box" style="margin-top:10px">
          <p class="small"><strong>Sekret (Base32):</strong></p>
          <p class="mono" style="word-break:break-all;margin-top:4px"><?= e($newSecret) ?></p>
          <p class="small muted" style="margin-top:8px">URI konfiguracji:</p>
          <p class="mono small" style="word-break:break-all"><?= e(totp_uri($newSecret, $user['username'])) ?></p>
          <button class="btn btn--sm" type="button" data-copy="<?= e(totp_uri($newSecret, $user['username'])) ?>">Kopiuj URI</button>
        </div>
        <form method="post" class="form-grid" style="margin-top:14px;max-width:340px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="enable_2fa_confirm">
          <label class="field"><span>Kod z aplikacji (6 cyfr)</span><input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
          <div class="form-actions"><button class="btn btn--primary" type="submit">Włącz 2FA</button></div>
        </form>
      <?php else: ?>
        <p class="small muted">2FA nie jest włączone. Zalecamy włączenie - panel zarządza całą stroną.</p>
        <form method="post" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="enable_2fa_start">
          <button class="btn btn--primary" type="submit">Włącz 2FA</button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <header class="card__head"><h2>Informacje o koncie</h2></header>
    <div class="card__body">
      <ul class="list-plain small">
        <li><strong>Login:</strong> <?= e($user['username']) ?></li>
        <li><strong>Rola:</strong> <?= e($user['role']) ?></li>
        <li><strong>Utworzono:</strong> <?= e(format_date($user['created_at'])) ?></li>
        <li><strong>Ostatnie logowanie:</strong> <?= e(format_date((string) $user['last_login_at'])) ?></li>
        <li><strong>Sesja:</strong> automatyczne wylogowanie po <?= (int) round((int) cfg('idle_timeout') / 60) ?> min bezczynności</li>
      </ul>
    </div>
  </section>

  <section class="card">
    <header class="card__head"><h2>Bezpieczeństwo sesji</h2></header>
    <div class="card__body">
      <ul class="list-plain small">
        <li>✔ Ciasteczko sesji: HttpOnly, Secure, SameSite=Strict</li>
        <li>✔ Regeneracja ID sesji po logowaniu i co 15 minut</li>
        <li>✔ Ochrona CSRF na wszystkich formularzach</li>
        <li>✔ Limit nieudanych logowań i blokada konta</li>
        <li>✔ Hasła jako hash (bcrypt/argon)</li>
      </ul>
    </div>
  </section>
</div>

<?php layout_foot(); ?>
