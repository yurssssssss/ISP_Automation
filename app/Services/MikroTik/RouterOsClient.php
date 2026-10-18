<?php

namespace App\Services\MikroTik;

/**
 * Minimal RouterOS API client (https://help.mikrotik.com/docs/display/ROS/API)
 * over PHP streams, so it needs no ext-sockets and can use API-SSL (tls://).
 *
 * A command is a "sentence" of length-prefixed words:
 *   /ppp/secret/print  ?name=juan        (query)
 *   /ppp/secret/set    =.id=*1A =disabled=yes   (attributes)
 * The router answers with !re (one row each), !trap (error), then !done.
 */
class RouterOsClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port = 8728,
        private bool $ssl = false,
        private bool $sslVerify = false,
        private int $timeout = 10,
    ) {}

    public function connect(string $username, string $password): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->sslVerify,
            'verify_peer_name' => $this->sslVerify,
            'allow_self_signed' => ! $this->sslVerify,
        ]]);

        $uri = ($this->ssl ? 'tls' : 'tcp')."://{$this->host}:{$this->port}";
        $socket = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if (! $socket) {
            throw new RouterOsException("Cannot reach the router at {$this->host}:{$this->port} ({$errstr}). Check the IP, that /ip service \"api".($this->ssl ? '-ssl' : '').'" is enabled, and that the firewall allows this server.');
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        // Plain-text login, used by RouterOS 6.43 and newer (and all of v7).
        try {
            $this->talk('/login', ['name' => $username, 'password' => $password]);
        } catch (RouterOsException $e) {
            $this->disconnect();
            throw new RouterOsException('Router login failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Runs one command and returns its !re rows as arrays.
     *
     * @param  array<string, scalar>  $attributes  sent as =key=value
     * @param  array<string, scalar>  $where  sent as ?key=value (all must match)
     * @return array<int, array<string, string>>
     *
     * @throws RouterOsException on !trap / !fatal or a broken connection
     */
    public function talk(string $command, array $attributes = [], array $where = []): array
    {
        if (! $this->socket) {
            throw new RouterOsException('Not connected to the router.');
        }

        $words = [$command];
        foreach ($attributes as $key => $value) {
            $words[] = '='.$key.'='.self::scalar($value);
        }
        foreach ($where as $key => $value) {
            $words[] = '?'.$key.'='.self::scalar($value);
        }
        $this->writeSentence($words);

        $rows = [];
        $trap = null;

        while (true) {
            $sentence = $this->readSentence();
            $reply = array_shift($sentence);
            $data = self::parseWords($sentence);

            switch ($reply) {
                case '!re':
                    $rows[] = $data;
                    break;
                case '!trap':
                    $trap ??= $data['message'] ?? 'unknown error';
                    break;
                case '!fatal':
                    $this->disconnect();
                    throw new RouterOsException('Router closed the session: '.($sentence[0] ?? 'fatal error'));
                case '!done':
                    if ($trap !== null) {
                        throw new RouterOsException($trap);
                    }

                    return $rows;
            }
        }
    }

    private static function scalar(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
    }

    /** ['=name=juan', '=.id=*1'] → ['name' => 'juan', '.id' => '*1'] */
    private static function parseWords(array $words): array
    {
        $data = [];
        foreach ($words as $word) {
            if (str_starts_with($word, '=')) {
                [$key, $value] = array_pad(explode('=', substr($word, 1), 2), 2, '');
                $data[$key] = $value;
            }
        }

        return $data;
    }

    private function writeSentence(array $words): void
    {
        $payload = '';
        foreach ($words as $word) {
            $payload .= self::encodeLength(strlen($word)).$word;
        }
        $payload .= "\0"; // zero-length word ends the sentence

        for ($written = 0; $written < strlen($payload); $written += $n) {
            $n = @fwrite($this->socket, substr($payload, $written));
            if ($n === false || $n === 0) {
                throw new RouterOsException('Lost the connection to the router while sending.');
            }
        }
    }

    private function readSentence(): array
    {
        $words = [];
        while (($length = $this->readLength()) > 0) {
            $words[] = $this->read($length);
        }

        return $words;
    }

    public static function encodeLength(int $length): string
    {
        return match (true) {
            $length < 0x80 => chr($length),
            $length < 0x4000 => pack('n', $length | 0x8000),
            $length < 0x200000 => substr(pack('N', $length | 0xC00000), 1),
            $length < 0x10000000 => pack('N', $length | 0xE0000000),
            default => "\xF0".pack('N', $length),
        };
    }

    private function readLength(): int
    {
        $first = ord($this->read(1));

        if (($first & 0x80) === 0x00) {
            return $first;
        }
        if (($first & 0xC0) === 0x80) {
            return (($first & 0x3F) << 8) | ord($this->read(1));
        }
        if (($first & 0xE0) === 0xC0) {
            return (($first & 0x1F) << 16) | unpack('n', $this->read(2))[1];
        }
        if (($first & 0xF0) === 0xE0) {
            return (($first & 0x0F) << 24) | unpack('N', "\0".$this->read(3))[1];
        }

        return unpack('N', $this->read(4))[1];
    }

    private function read(int $bytes): string
    {
        $buffer = '';
        while (strlen($buffer) < $bytes) {
            $chunk = @fread($this->socket, $bytes - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $timedOut = stream_get_meta_data($this->socket)['timed_out'] ?? false;
                $this->disconnect();
                throw new RouterOsException($timedOut ? 'The router did not answer in time.' : 'The router closed the connection.');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
