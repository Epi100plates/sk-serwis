<?php
/**
 * Tworzenie konta administratora panelu.
 *
 * Użycie (na serwerze, jako root lub skcms):
 *   php /srv/studio-komp/panel/bin/create-admin.php --username=admin
 *   php .../create-admin.php --username=admin --password='...' --role=admin
 *
 * Domyślnie generuje silne hasło i zapisuje je w pliku 0600:
 *   /srv/studio-komp-cms/data/initial-credentials.txt
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

require __DIR__ . '/../bootstrap.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z0-9-]+)$/', $arg, $m)) {
        $args[$m[1]] = true;
    }
}

$username = strtolower(trim((string) ($args['username'] ?? 'admin')));
$role = in_array($args['role'] ?? 'admin', ['admin', 'editor'], true) ? ($args['role'] ?? 'admin') : 'admin';
$password = (string) ($args['password'] ?? '');

if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
    fwrite(STDERR, "Nieprawidlowy login (3-40 znakow: male litery, cyfry, . _ -).\n");
    exit(1);
}

if ($password === '') {
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 20; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Haslo musi miec co najmniej 12 znakow.\n");
    exit(1);
}

$st = db()->prepare('SELECT id FROM users WHERE username = ?');
$st->execute([$username]);
$existing = $st->fetch();

if ($existing) {
    db()->prepare('UPDATE users SET password_hash = ?, role = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $role, $existing['id']]);
    echo "Zaktualizowano haslo istniejacego uzytkownika: $username\n";
} else {
    db()->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?,?,?,?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, now()]);
    echo "Utworzono konto: $username ($role)\n";
}

$count = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$credentialsFile = (string) cfg('data_dir') . '/initial-credentials.txt';
$content = "Panel Studio-Komp - dane dostepowe\n"
    . "Adres: " . cfg('site_url') . "/panel/\n"
    . "Login: $username\n"
    . "Haslo: $password\n"
    . "Rola: $role\n"
    . "Utworzono: " . now() . "\n\n"
    . "Po pierwszym logowaniu zmien haslo (Moj profil) i wlacz 2FA.\n"
    . "Usun ten plik po zapisaniu hasla w menedzerze hasel.\n";
file_put_contents($credentialsFile, $content);
@chmod($credentialsFile, 0600);

echo "Liczba administratorow w bazie: $count\n";
echo "Dane dostepowe zapisano w: $credentialsFile\n";
echo "Haslo: $password\n";
