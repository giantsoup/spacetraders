<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProxyHttpsTest extends TestCase
{
    #[DataProvider('proxyRequests')]
    public function test_asset_and_route_urls_follow_only_the_trusted_proxy_scheme(string $remoteAddress, ?string $forwardedProto, string $expectedScheme): void
    {
        $server = [
            'REMOTE_ADDR' => $remoteAddress,
            'HTTP_HOST' => 'spacetraders.test',
            'SERVER_PORT' => 80,
            'HTTP_X_FORWARDED_HOST' => 'untrusted.example',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.8',
            'HTTP_X_FORWARDED_PORT' => '8443',
        ];
        if ($forwardedProto !== null) {
            $server['HTTP_X_FORWARDED_PROTO'] = $forwardedProto;
        }

        $response = $this->call('GET', 'http://spacetraders.test/', server: $server)->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('src="'.$expectedScheme.'://spacetraders.test/build/assets/', $html);
        $this->assertStringContainsString('href="'.$expectedScheme.'://spacetraders.test/build/assets/', $html);
        $this->assertStringNotContainsString('untrusted.example', $html);
        $this->assertStringNotContainsString(':8443', $html);
        $this->assertSame($expectedScheme.'://spacetraders.test/login', route('login'));
    }

    public static function proxyRequests(): array
    {
        return [
            'IPv4 local HTTPS tunnel' => ['127.0.0.1', 'https', 'https'],
            'IPv6 local HTTPS tunnel' => ['::1', 'https', 'https'],
            'direct local HTTP' => ['127.0.0.1', null, 'http'],
            'external spoofed forwarding' => ['198.51.100.7', 'https', 'http'],
        ];
    }
}
