<?php
// api/whatsapp-notify.php
header('Content-Type: application/json');

// 1. Baca payload dari frontend
$input = json_decode(file_get_contents('php://input'), true);
$to    = $input['to']      ?? '';
$msg   = $input['message'] ?? '';

if (!$to || !$msg) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing to/message']);
    exit;
}

// 2. Ambil token gateway dari .env (JANGAN hardcode!)
$token = getenv('FONNTE_TOKEN');
if (!$token) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Token not configured']);
    exit;
}

// 3. Kirim ke gateway WhatsApp (contoh: Fonnte)
$ch = curl_init('https://api.fonnte.com/send');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ["Authorization: $token"],
    CURLOPT_POSTFIELDS     => [
        'target'  => $to,
        'message' => $msg,
    ],
]);

$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $err]);
    exit;
}

echo $res;