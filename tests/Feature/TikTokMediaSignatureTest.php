<?php

namespace Inovector\Mixpost\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inovector\Mixpost\Http\Controllers\Api\TikTokMediaController;
use PHPUnit\Framework\TestCase;

class TikTokMediaSignatureTest extends TestCase
{
    public function test_proxy_normalization_preserves_signature_but_tampering_and_expiry_fail(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $previousFacade = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $app = new \Illuminate\Container\Container;
        \Illuminate\Container\Container::setInstance($app);
        $app->instance('config', new \Illuminate\Config\Repository(['app' => ['url' => 'https://api.usetwl.com']]));
        $routes = new \Illuminate\Routing\RouteCollection;
        $routes->add((new \Illuminate\Routing\Route('GET', 'api/v1/social/tiktok-media/{media}', fn () => null))->name('social.api.tiktok-media'));
        $generator = new \Illuminate\Routing\UrlGenerator($routes, Request::create('https://api.usetwl.com'));
        $generator->setKeyResolver(fn () => 'test-only-signing-key');
        $app->instance('url', $generator);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        try {
        $controller = new class extends TikTokMediaController {
            public function valid(Request $request): bool { return $this->hasValidMediaSignature($request); }
        };
        $url = URL::temporarySignedRoute('social.api.tiktok-media', now()->addMinutes(5), ['media' => 123]);
        $this->assertTrue($controller->valid(Request::create($url)));
        $proxied = str_replace('https://api.usetwl.com', 'http://internal-proxy:8080', $url);
        $this->assertTrue($controller->valid(Request::create($proxied)));
        $this->assertFalse($controller->valid(Request::create(str_replace('/123?', '/124?', $proxied))));
        $this->assertFalse($controller->valid(Request::create($proxied.'&extra=1')));
        $this->assertFalse($controller->valid(Request::create(preg_replace('/signature=[^&]+/', 'signature=invalid', $proxied))));
        $expired = URL::temporarySignedRoute('social.api.tiktok-media', now()->subMinute(), ['media' => 123]);
        $this->assertFalse($controller->valid(Request::create($expired)));
        } finally {
            \Illuminate\Container\Container::setInstance($previous);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($previousFacade);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        }
    }
}
