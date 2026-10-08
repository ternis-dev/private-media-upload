<?php

declare(strict_types=1);

namespace PrivateWf\Api;

/**
 * ClamAV TCP client (INSTREAM). Unconfigured host → skipped (NullScanner).
 * M2a: synchronous scan on complete(); async worker with Laravel M2b.
 */
final class ClamAv
{
    public function __construct(
        private readonly string $host = '',
        private readonly int $port = 3310,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(getenv('CLAMAV_HOST') ?: '', (int) (getenv('CLAMAV_PORT') ?: 3310));
    }

    public function enabled(): bool
    {
        return $this->host !== '';
    }

    /** @return null|string virus name when FOUND, null when clean/skipped */
    public function scanFile(string $path): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        $sock = fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if ($sock === false) {
            throw new \RuntimeException("clamav unreachable: {$errstr}");
        }
        try {
            fwrite($sock, "zINSTREAM\0");
            $fh = fopen($path, 'rb');
            while (!feof($fh)) {
                $chunk = fread($fh, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($sock, pack('N', strlen($chunk)) . $chunk);
            }
            fclose($fh);
            fwrite($sock, pack('N', 0));
            $response = trim(fgets($sock) ?: '');
            return self::parseResponse($response);
        } finally {
            fclose($sock);
        }
    }

    /** Pure: "stream: Eicar-Test-Signature FOUND" → name; "stream: OK" → null. */
    public static function parseResponse(string $response): ?string
    {
        if (str_ends_with($response, 'OK')) {
            return null;
        }
        if (preg_match('/^stream:\s*(.+)\s+FOUND$/', $response, $m)) {
            return $m[1];
        }
        throw new \RuntimeException('unparseable clamav response: ' . substr($response, 0, 120));
    }
}
