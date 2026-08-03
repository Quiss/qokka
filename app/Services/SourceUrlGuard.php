<?php

namespace App\Services;

use InvalidArgumentException;

class SourceUrlGuard
{
    public function ensurePublicHttps(string $url): string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (
            $scheme !== 'https'
            || ! is_string($host)
            || blank($host)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('URL должен быть публичным HTTPS-адресом без встроенных credentials.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolveAddresses($host);

        if ($addresses === []) {
            throw new InvalidArgumentException('Не удалось разрешить hostname источника.');
        }

        foreach ($addresses as $address) {
            if (! filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )) {
                throw new InvalidArgumentException('URL источника не может указывать на локальный или приватный адрес.');
            }
        }

        return $url;
    }

    /** @return list<string> */
    private function resolveAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }
}
