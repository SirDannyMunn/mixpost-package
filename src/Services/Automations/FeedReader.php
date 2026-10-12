<?php

namespace Inovector\Mixpost\Services\Automations;

use Illuminate\Support\Facades\Http;
use LaundryOS\DataWorkspace\Services\PublicHttpUrlGuard;
use RuntimeException;

/** Feed documents are untrusted data, never executable markup or agent instructions. */
class FeedReader
{
    private const MAX_BYTES = 2097152;

    public function __construct(private readonly PublicHttpUrlGuard $urls) {}

    public function read(string $url): array
    {
        $addresses = $this->urls->assertAllowed($url);
        if (!defined('CURLOPT_RESOLVE')) throw new RuntimeException('RSS fetching requires the cURL extension.');
        $parts = parse_url($url);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        // Pin the validated address; disable redirects and proxies to prevent DNS rebinding/SSRF.
        $response = Http::withOptions([
            'allow_redirects' => false, 'proxy' => '', 'decode_content' => false,
            'curl' => [CURLOPT_RESOLVE => [$parts['host'].':'.$port.':'.$ip]],
            'on_headers' => function ($response) {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) throw new RuntimeException('Feed exceeds 2 MiB.');
            },
            'progress' => function ($total, $downloaded) {
                if ($downloaded > self::MAX_BYTES) throw new RuntimeException('Feed exceeds 2 MiB.');
            },
        ])->withHeaders(['Accept-Encoding' => 'identity'])->connectTimeout(5)->timeout(15)->get($url);
        if (!$response->successful()) throw new RuntimeException('Feed could not be fetched. Use its final public URL (redirects are not followed).');
        return $this->parse($response->body());
    }

    public function parse(string $xml): array
    {
        if (strlen($xml) > self::MAX_BYTES || str_contains($xml, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) throw new RuntimeException('Feed is too large or contains prohibited XML entities.');
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if (!$document) throw new RuntimeException('Feed must be valid RSS or Atom XML.');
            $nodes = $document->xpath('//*[local-name()="item" or local-name()="entry"]');
            $items = [];
            foreach (array_slice($nodes ?: [], 0, 100) as $node) {
                $text = fn ($name) => trim((string) (($node->xpath('./*[local-name()="'.$name.'"]') ?: [null])[0]));
                $link = $text('link');
                foreach ($node->xpath('./*[local-name()="link"]') ?: [] as $candidate) {
                    if (isset($candidate['href']) && in_array((string) $candidate['rel'], ['', 'alternate'], true)) { $link = (string) $candidate['href']; break; }
                }
                if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true)) continue;
                $identity = $text('guid') ?: $text('id') ?: $link;
                $items[] = ['key' => hash('sha256', $identity), 'title' => mb_substr(strip_tags($text('title')), 0, 500),
                    'link' => $link, 'summary' => mb_substr(strip_tags($text('description') ?: $text('summary')), 0, 1000)];
            }
            if (!$nodes && !in_array($document->getName(), ['rss', 'feed', 'RDF'], true)) throw new RuntimeException('This document is not an RSS or Atom feed.');
            return array_reverse($items);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
