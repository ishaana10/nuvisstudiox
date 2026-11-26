<?php
/**
 * Runs one api/*.php script in its own PHP process as a chosen user, for the endpoint-by-role matrix.
 * Input (environment): NU_TEST_ENDPOINT (e.g. api/lookup.php), NU_TEST_SESSION (JSON, empty = anonymous),
 * NU_TEST_GET (JSON), NU_TEST_METHOD, NU_DB_NAME (sqlite file). Output: one JSON line {"status":int,"body":string}.
 */
$root = dirname(__DIR__, 2);
$endpoint = (string)getenv('NU_TEST_ENDPOINT');
$session  = json_decode((string)(getenv('NU_TEST_SESSION') ?: '{}'), true) ?: [];
$_GET     = json_decode((string)(getenv('NU_TEST_GET') ?: '{}'), true) ?: [];
$_SERVER['REQUEST_METHOD'] = getenv('NU_TEST_METHOD') ?: 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/' . $endpoint;
$_SERVER['REQUEST_URI'] = '/' . $endpoint;

// Pre-create the session file so the endpoint's own session_start() picks it up.
$dir = sys_get_temp_dir() . '/nu_matrix_sessions';
@mkdir($dir, 0700, true);
session_save_path($dir);
session_name('nuvisstudioxssid');
if ($session) {
    $sid = 'matrix' . bin2hex(random_bytes(8));
    session_id($sid);
    session_start();
    $_SESSION = $session + ['nu_last_activity' => time()];
    session_write_close();
    $_COOKIE[session_name()] = $sid;
}

ob_start();
register_shutdown_function(function () {
    $body = ob_get_level() ? ob_get_clean() : '';
    fwrite(STDOUT, "\n@@NU@@" . json_encode(['status' => http_response_code() ?: 200, 'body' => $body]));
});
chdir($root . '/' . dirname($endpoint));
include $root . '/' . $endpoint;
