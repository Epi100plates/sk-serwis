<?php
/**
 * Użytkownicy panelu (tylko administrator).
 */

$isAdmin = (current_user()['role'] ?? '') === 'admin';
$me = current_user();

if (is_post()) {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'create') {
        $username = strtolower(trim((string) post('username')));
        $password = (string) post('password');
        $role = in_array(post('role'), ['admin', 'editor'], true) ? post('role') : 'editor';
        $errors = [];
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            $errors[] = 'Login: 3–40 znaków (małe litery, cyfry, . _ -).';
        }
        if (strlen($password) < 12) {
            $errors[] = 'Hasło musi mieć co najmniej 12 znaków.';
        }
        $st = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $st->execute([$username]);
        if ((int) $st->fetchColumn() > 0) {
            $errors[] = 'Taki login już istnieje.';
        }
        if ($errors) {
            flash('err', implode(' ', $errors));
        } else {
            $st = db()->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?,?,?,?)');
            $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, now()]);
            audit('user_create', $username, ['role' => $role]);
            flash('ok', 'Dodano użytkownika ' . $username . '.');
        }
        redirect_to(panel_url('users'));
    }

    if ($action === 'reset_password') {
        $id = (int) post('id', 0);
        $password = (string) post('password');
        if (strlen($password) < 12) {
            flash('err', 'Nowe hasło musi mieć co najmniej 12 znaków.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            audit('user_password_reset', 'user:' . $id);
            flash('ok', 'Zmieniono hasło użytkownika.');
        }
        redirect_to(panel_url('users'));
    }

    if ($action === 'reset_2fa') {
        $id = (int) post('id', 0);
        db()->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?')->execute([$id]);
        audit('user_2fa_reset', 'user:' . $id);
        flash('info', 'Wyłączono 2FA dla użytkownika.');
        redirect_to(panel_url('users'));
    }

    if ($action === 'delete') {
        $id = (int) post('id', 0);
        if ($id === (int) $me['id']) {
            flash('err', 'Nie możesz usunąć własnego konta.');
        } else {
            $st = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND id <> ?");
            $st->execute([$id]);
            $otherAdmins = (int) $st->fetchColumn();
            $target = db()->prepare('SELECT username, role FROM users WHERE id = ?');
            $target->execute([$id]);
            $row = $target->fetch();
            if ($row && $row['role'] === 'admin' && $otherAdmins === 0) {
                flash('err', 'Nie można usunąć ostatniego administratora.');
            } else {
                db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                audit('user_delete', (string) ($row['username'] ?? $id));
                flash('info', 'Usunięto użytkownika.');
            }
        }
        redirect_to(panel_url('users'));
    }
}

$users = db()->query('SELECT id, username, role, created_at, last_login_at, totp_enabled FROM users ORDER BY id ASC')->fetchAll();

layout_head('Użytkownicy', 'users');
?>

<div class="grid grid--sidebar">
  <section class="card">
    <header class="card__head"><h2>Konta w panelu</h2></header>
    <div class="card__body card__body--flush">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Login</th><th>Rola</th><th>2FA</th><th>Ostatnie logowanie</th><th>Akcje</th></tr></thead>
          <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <td><strong><?= e($user['username']) ?></strong><?= (int) $user['id'] === (int) $me['id'] ? ' <span class="badge badge--info">to Ty</span>' : '' ?></td>
              <td><?= $user['role'] === 'admin' ? '<span class="badge badge--ok">administrator</span>' : '<span class="badge badge--muted">redaktor</span>' ?></td>
              <td><?= (int) $user['totp_enabled'] === 1 ? status_badge('ok') : status_badge('muted') ?></td>
              <td class="small nowrap"><?= e(format_date((string) $user['last_login_at'])) ?></td>
              <td class="nowrap">
                <?php if ((int) $user['totp_enabled'] === 1): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Wyłączyć 2FA dla tego konta?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_2fa">
                    <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                    <button class="btn btn--sm btn--ghost" type="submit">Reset 2FA</button>
                  </form>
                <?php endif; ?>
                <?php if ((int) $user['id'] !== (int) $me['id']): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Usunąć konto <?= e($user['username']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                    <button class="btn btn--sm btn--danger" type="submit">Usuń</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <div class="grid">
    <section class="card">
      <header class="card__head"><h2>Dodaj użytkownika</h2></header>
      <div class="card__body">
        <form method="post" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <label class="field"><span>Login</span><input type="text" name="username" required pattern="[a-z0-9._-]{3,40}" autocomplete="off"></label>
          <label class="field"><span>Hasło (min. 12 znaków)</span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
          <label class="field"><span>Rola</span>
            <select name="role">
              <option value="admin">Administrator — pełny dostęp</option>
              <option value="editor" selected>Redaktor — treści, media, realizacje, publikacja</option>
            </select>
          </label>
          <div class="form-actions"><button class="btn btn--primary" type="submit">Dodaj konto</button></div>
        </form>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Reset hasła</h2></header>
      <div class="card__body">
        <form method="post" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_password">
          <label class="field"><span>Użytkownik</span>
            <select name="id" required>
              <?php foreach ($users as $user): ?>
                <option value="<?= (int) $user['id'] ?>"><?= e($user['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field"><span>Nowe hasło (min. 12 znaków)</span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
          <div class="form-actions"><button class="btn" type="submit">Ustaw nowe hasło</button></div>
        </form>
        <p class="small muted" style="margin-top:12px">Hasła są przechowywane wyłącznie jako hash (bcrypt). Nie znamy ich i nie da się ich odczytać.</p>
      </div>
    </section>
  </div>
</div>

<?php layout_foot(); ?>
