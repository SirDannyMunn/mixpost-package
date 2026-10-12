<?php

namespace Inovector\Mixpost\Tests\Unit;

use Inovector\Mixpost\Services\Automations\FeedReader;
use LaundryOS\DataWorkspace\Services\PublicHttpUrlGuard;
use PHPUnit\Framework\TestCase;

class FeedReaderTest extends TestCase
{
    public function test_reads_rss_and_namespaced_atom_without_executing_markup(): void
    {
        $reader = new FeedReader(new PublicHttpUrlGuard());
        $rss = $reader->parse('<rss><channel><item><guid>stable</guid><title><![CDATA[<b>Article</b>]]></title><link>https://example.com/a</link></item></channel></rss>');
        $atom = $reader->parse('<feed xmlns="http://www.w3.org/2005/Atom"><entry><id>stable</id><title>Article</title><link href="https://example.com/a" /></entry></feed>');
        $this->assertSame($rss, $atom);
        $this->assertSame('Article', $rss[0]['title']);
    }

    public function test_rejects_external_entities(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FeedReader(new PublicHttpUrlGuard()))->parse('<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss>&x;</rss>');
    }

    public function test_rejects_private_feed_targets_before_requesting(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FeedReader(new PublicHttpUrlGuard(fn () => ['127.0.0.1'])))->read('https://feed.example.com/rss');
    }
}
