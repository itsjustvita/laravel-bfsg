<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Http;

use ItsJustVita\LaravelBfsg\Http\PrivateNetworkGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrivateNetworkGuardTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function addresses(): array
    {
        return [
            'ipv4 multicast' => ['224.0.0.1', true],
            'ipv4 multicast top' => ['239.255.255.250', true],
            'ipv6 multicast' => ['ff02::1', true],
            'ipv6 multicast expanded' => ['ff02:0:0:0:0:0:0:1', true],
            'translated loopback' => ['::ffff:0:127.0.0.1', true],
            'translated private' => ['::ffff:0:10.0.0.1', true],
            'translated loopback expanded' => ['0:0:0:0:ffff:0:7f00:1', true],
            'translated loopback hex' => ['::ffff:0:7f00:1', true],
            'translated public' => ['::ffff:0:8.8.8.8', false],
            'translated public expanded' => ['0:0:0:0:ffff:0:808:808', false],
            'public v4' => ['8.8.8.8', false],
            'mapped loopback' => ['::ffff:127.0.0.1', true],
            'mapped public' => ['::ffff:8.8.8.8', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_address_classes(string $ip, bool $blocked): void
    {
        $this->assertSame($blocked, PrivateNetworkGuard::isBlocked($ip), $ip);
    }
}
