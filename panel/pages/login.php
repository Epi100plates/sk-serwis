<?php
/**
 * Logowanie do panelu.
 */

$error = '';
$next = (string) get('next', panel_url(''));
if (is_post()) {
    csrf_check();
    $result = auth_login((string) post('username'), (string) post('password'), (string) post('totp'));
    if ($result['ok']) {
        $target = $next !== '' && str_starts_with($next, '/panel') ? $next : panel_url('');
        redirect_to($target);
    }
    $error = $result['error'];
}

if (current_user()) {
    redirect_to(panel_url(''));
}
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Logowanie - Panel Studio-Komp</title>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(base_path()) ?>/assets/admin.css?v=1">
</head>
<body class="login-page">
  <main class="login-card">
    <div class="login-brand">
      <span class="login-mark">SK</span>
      <div>
        <strong>Studio-Komp</strong>
        <small>Panel zarządzania stroną</small>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert--err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="login-form" autocomplete="on">
      <?= csrf_field() ?>
      <label class="field">
        <span>Login</span>
        <input type="text" name="username" required autofocus autocomplete="username">
      </label>
      <label class="field">
        <span>Hasło</span>
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <label class="field">
        <span>Kod 2FA <em>(jeśli włączone)</em></span>
        <input type="text" name="totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000">
      </label>
      <button class="btn btn--primary btn--block" type="submit">Zaloguj się</button>
    </form>

    <p class="login-note">
      Panel jest dostępny wyłącznie dla administratorów serwisu. Po 5 nieudanych
      próbach logowanie zostaje czasowo zablokowane.
    </p>
  </main>
</body>
</html>
