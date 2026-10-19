<?php
require __DIR__ . '/../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$action = trim((string)($_GET['action'] ?? 'login'));

if ($action === 'status') {
    json_out([
        'ok' => isset($_SESSION['vip_admin']),
        'nama' => $_SESSION['vip_admin_name'] ?? null,
    ]);
}

if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '',
            $params['secure'], $params['httponly']);
    }
    session_destroy();
    json_out(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);

$d    = json_in();
$user = trim($d['user'] ?? '');
$pass = (string)($d['pass'] ?? '');
if ($user === '' || $pass === '' || strlen($user) > 8 || strlen($pass) > 8)
    json_out(['ok' => false, 'error' => 'Login salah'], 401);

$_SESSION['gagal'] = $_SESSION['gagal'] ?? 0;
$_SESSION['gagal_t'] = $_SESSION['gagal_t'] ?? time();
if (time() - $_SESSION['gagal_t'] > 600) { $_SESSION['gagal'] = 0; $_SESSION['gagal_t'] = time(); }
if ($_SESSION['gagal'] >= 5) json_out(['ok' => false, 'error' => 'Terlalu banyak percobaan. Coba lagi nanti.'], 429);

usleep(500000);

$conn = db();
$s = oci_parse($conn, "SELECT TRIM(u.USER_ID) AS USER_ID, u.PASSWORD, u.USER_NAME
                         FROM TUGU.MST_USER u
                        WHERE UPPER(TRIM(u.USER_ID)) = UPPER(:u)
                          AND (u.END_DATE IS NULL OR u.END_DATE > SYSDATE)
                          AND TRIM(u.USER_ID) IN (SELECT TRIM(USER_ID) FROM TIM_FASTRACK)");
oci_bind_by_name($s, ':u', $user);
if (!@oci_execute($s)) {
    error_log('admin_login: ' . print_r(oci_error($s), true));
    json_out(['ok' => false, 'error' => 'Terjadi kesalahan server'], 500);
}
$u = oci_fetch_assoc($s);

$ok = $u && hash_equals(rtrim((string)$u['PASSWORD']), $pass);

if (!$ok) {
    $_SESSION['gagal']++;
    json_out(['ok' => false, 'error' => 'Login salah'], 401);
}

session_regenerate_id(true);
$_SESSION['gagal'] = 0;
$_SESSION['vip_admin'] = $u['USER_ID'];
$_SESSION['vip_admin_name'] = $u['USER_NAME'];
json_out(['ok' => true, 'nama' => $u['USER_NAME']]);