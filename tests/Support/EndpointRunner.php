<?php
declare(strict_types=1);

namespace Nuvis\Tests\Support;

/** Calls an api/*.php script as a given session in a separate PHP process (see endpoint_runner.php). */
final class EndpointRunner
{
    /** @return array{status:int, body:string, json:?array} */
    public static function call(string $endpoint, array $get = [], array $session = [], string $method = 'GET'): array
    {
        $cfg = sys_get_temp_dir() . '/nu_matrix_config.php';
        file_put_contents($cfg, "<?php\n\$nuConfig['dbDriver'] = 'sqlite';\n\$nuConfig['dbSqlitePath'] = " . var_export(NU_ROOT . '/tests/test.sqlite', true) . ";\n");
        $env = array_merge(getenv(), [
            'NU_TEST_ENDPOINT' => $endpoint,
            'NU_TEST_SESSION'  => json_encode($session ?: new \stdClass()),
            'NU_TEST_GET'      => json_encode($get ?: new \stdClass()),
            'NU_TEST_METHOD'   => $method,
            'NU_TEST_CONFIG'   => $cfg,
            'NU_ENV'           => 'testing',
        ]);
        $cmd = [PHP_BINARY, NU_ROOT . '/tests/Support/endpoint_runner.php'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, NU_ROOT, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($p);
        $pos = strrpos($out, "\n@@NU@@");
        $res = $pos === false ? ['status' => 0, 'body' => $out] : json_decode(substr($out, $pos + 7), true);
        if ($err !== '' && $res['status'] === 0) $res['body'] .= "\n" . $err;
        $res['json'] = json_decode((string)$res['body'], true);
        return $res;
    }
}
