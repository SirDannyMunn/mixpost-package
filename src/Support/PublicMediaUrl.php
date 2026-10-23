<?php

namespace Inovector\Mixpost\Support;

use Illuminate\Validation\ValidationException;

/** HTTPS URLs TikTok or this app may fetch. Rejects private, link-local, and loopback targets. */
class PublicMediaUrl
{
    public function __construct(private readonly ?\Closure $resolve = null) {}

    public function assertFetchable(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw ValidationException::withMessages(['url' => 'Provide a public https URL with no credentials or custom port.']);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) || $this->blockedHost($host) || !Util::isPublicDomainUrl($url)) {
            throw ValidationException::withMessages(['url' => 'The media URL must use a public hostname.']);
        }
        $addresses = $this->addresses($host);
        if ($addresses === []) {
            throw ValidationException::withMessages(['url' => 'The media URL host could not be resolved.']);
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ValidationException::withMessages(['url' => 'The media URL host is not a public address.']);
            }
        }
        return $host;
    }

    /** @return list<string> */
    private function addresses(string $host): array
    {
        if ($this->resolve) return array_values(array_filter(($this->resolve)($host), 'strval'));
        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        $addresses = array_values(array_filter(array_merge(array_column($records, 'ip'), array_column($records, 'ipv6'))));
        if ($addresses !== []) return $addresses;
        return array_values(array_filter(gethostbynamel($host) ?: []));
    }

    private function blockedHost(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_contains($host, 'metadata.google');
    }
}
