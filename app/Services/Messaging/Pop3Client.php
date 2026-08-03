<?php

namespace App\Services\Messaging;

use RuntimeException;

/**
 * Minimal RFC 1939 POP3S client using plain PHP streams — deliberately not
 * ext-imap, which PHP 8.4 dropped from core and isn't guaranteed to be
 * installed on the server (see PartnerEmailFetcher). Just enough to count
 * and retrieve messages; nothing here ever sends DELE.
 */
class Pop3Client
{
    /** @var resource|null */
    private $socket;

    private ?string $lastError = null;

    public function connect(string $host, int $port, bool $validateCert = true): bool
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $validateCert,
                'verify_peer_name' => $validateCert,
            ],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            $this->lastError = "{$errstr} ({$errno})";

            return false;
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, 10);

        return $this->isOk($this->readLine());
    }

    public function login(string $username, string $password): bool
    {
        if (! $this->isOk($this->command('USER '.$username))) {
            $this->lastError = 'Server rejected the username';

            return false;
        }

        if (! $this->isOk($this->command('PASS '.$password))) {
            $this->lastError = 'Server rejected the password';

            return false;
        }

        return true;
    }

    public function countMessages(): int
    {
        $response = $this->command('STAT');

        if (! $this->isOk($response)) {
            return 0;
        }

        // "+OK 3 12345" — message count, then mailbox size in bytes.
        $parts = explode(' ', trim($response));

        return isset($parts[1]) ? (int) $parts[1] : 0;
    }

    public function retrieve(int $messageNumber): ?string
    {
        if (! $this->isOk($this->command('RETR '.$messageNumber))) {
            return null;
        }

        return $this->readMultiline();
    }

    public function quit(): void
    {
        if ($this->socket === null) {
            return;
        }

        $this->command('QUIT');
        fclose($this->socket);
        $this->socket = null;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function command(string $command): string
    {
        fwrite($this->socket, $command."\r\n");

        return $this->readLine();
    }

    private function readLine(): string
    {
        $line = fgets($this->socket, 4096);

        if ($line === false) {
            throw new RuntimeException('POP3 connection closed unexpectedly');
        }

        return $line;
    }

    private function isOk(string $response): bool
    {
        return str_starts_with($response, '+OK');
    }

    private function readMultiline(): string
    {
        $lines = [];

        while (($line = fgets($this->socket, 8192)) !== false) {
            if (rtrim($line, "\r\n") === '.') {
                break;
            }

            // Dot-stuffing (RFC 1939 §3): a line starting with ".." in the
            // stream represents a literal line starting with "." in the
            // original message — undo it here.
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }

            $lines[] = $line;
        }

        return implode('', $lines);
    }
}
