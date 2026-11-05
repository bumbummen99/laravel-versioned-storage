<?php

declare(strict_types=1);

namespace SkyRaptor\LaravelVersionedStorage\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class FpmServer
{
    public readonly string $directory;

    private ?Process $fpm = null;

    private ?Process $nginx = null;

    private string $address;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/versioned-fpm-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    /** @param array<string, string> $environment */
    public function start(string $fpm, string $nginx, int $execution, int $termination, int $read, array $environment): void
    {
        $directory = $this->directory;
        $script = __DIR__.'/upload-timeout/http.php';
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Unable to reserve a test HTTP port.');
        }
        $this->address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        file_put_contents($directory.'/php.ini', <<<INI
            max_execution_time = {$execution}
            memory_limit = 256M
            display_errors = Off
            log_errors = On
            error_log = "{$directory}/php.log"
            INI);
        file_put_contents($directory.'/fpm.conf', <<<CONF
            [global]
            pid = {$directory}/fpm.pid
            error_log = {$directory}/fpm.log
            [www]
            chdir = {$directory}
            listen = {$directory}/fpm.sock
            pm = static
            pm.max_children = 1
            clear_env = no
            catch_workers_output = yes
            request_terminate_timeout = {$termination}s
            request_terminate_timeout_track_finished = yes
            CONF);
        file_put_contents($directory.'/nginx.conf', <<<CONF
            pid {$directory}/nginx.pid;
            error_log {$directory}/nginx.log info;
            events { worker_connections 32; }
            http {
                access_log off;
                client_body_temp_path {$directory}/body;
                fastcgi_temp_path {$directory}/fastcgi;
                proxy_temp_path {$directory}/proxy;
                uwsgi_temp_path {$directory}/uwsgi;
                scgi_temp_path {$directory}/scgi;
                server {
                    listen {$this->address};
                    location / {
                        fastcgi_pass unix:{$directory}/fpm.sock;
                        fastcgi_read_timeout {$read}s;
                        fastcgi_param SCRIPT_FILENAME "{$script}";
                        fastcgi_param REQUEST_METHOD \$request_method;
                        fastcgi_param REQUEST_URI \$request_uri;
                        fastcgi_param SERVER_PROTOCOL \$server_protocol;
                    }
                }
            }
            CONF);

        $this->fpm = new Process([$fpm, '-F', '-y', $directory.'/fpm.conf', '-c', $directory.'/php.ini'], null, $environment);
        $this->fpm->setTimeout(null);
        $this->fpm->start();
        $this->waitFor(fn (): bool => file_exists($directory.'/fpm.sock'));
        $this->nginx = new Process([$nginx, '-p', $directory, '-c', $directory.'/nginx.conf', '-g', 'daemon off;']);
        $this->nginx->setTimeout(null);
        $this->nginx->start();

        try {
            Http::retry(30, 100)->timeout(2)->get('http://'.$this->address.'/health')->throw();
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach the FPM test server: '.$this->logs(), previous: $exception);
        }
    }

    public function request(string $path): Response
    {
        return Http::timeout(15)->get('http://'.$this->address.$path);
    }

    public function waitFor(callable $condition): void
    {
        ProcessWait::until(
            $condition(...),
            array_values(array_filter([$this->fpm, $this->nginx])),
            15,
            fn (): string => $this->logs(),
        );
    }

    public function logs(): string
    {
        $logs = ($this->fpm?->getErrorOutput() ?? '').($this->nginx?->getErrorOutput() ?? '');
        foreach (['fpm.log', 'nginx.log', 'php.log'] as $name) {
            if (is_file($this->directory.'/'.$name)) {
                $logs .= file_get_contents($this->directory.'/'.$name);
            }
        }

        return $logs;
    }

    public function stop(): void
    {
        $this->nginx?->stop(1);
        $this->fpm?->stop(1);
        (new Filesystem)->deleteDirectory($this->directory);
    }
}
