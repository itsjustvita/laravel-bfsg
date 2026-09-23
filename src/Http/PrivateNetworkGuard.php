<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Closure;

/**
 * Refuses URLs whose host is, or resolves to, a loopback, private, link-local or unspecified address: the SSRF
 * guard of the MCP URL tools when no allow-list is configured. IP literals are classified directly; hostnames are
 * resolved (A records via gethostbynamel(), which also covers /etc/hosts and numeric forms like 2130706433, and
 * AAAA records via dns_get_record()) and refused when any address is blocked. A host that does not resolve is let
 * through: the request itself then fails to connect. Resolution happens before the request, so a DNS answer that
 * changes between the check and the connection (rebinding) is not covered.
 */
class PrivateNetworkGuard
{
    /** IPv4 ranges: unspecified/this network, private, loopback, link-local (incl. 169.254.169.254), private. */
    private const BLOCKED_V4 = ['0.0.0.0/8', '10.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16'];

    /** IPv6 ranges: unspecified, loopback, unique local, link-local. IPv4-mapped addresses are checked as IPv4. */
    private const BLOCKED_V6 = ['::/128', '::1/128', 'fc00::/7', 'fe80::/10'];

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host => its IP addresses (default: DNS)
     * @param  list<string>  $exemptHosts  hosts that are never refused (the host of app.url)
     */
    public function __construct(?Closure $resolver = null, private array $exemptHosts = [])
    {
        $this->resolver = $resolver ?? self::resolve(...);
    }

    /** @param  list<string>  $hosts */
    public function exempting(array $hosts): static
    {
        return new static($this->resolver, array_values(array_unique([...$this->exemptHosts, ...$hosts])));
    }

    /** @throws FetchFailed when the URL's host is (or resolves to) a blocked address */
    public function check(string $url): void
    {
        $host = self::normalize((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || in_array($host, array_map(self::normalize(...), $this->exemptHosts), true)) {
            return;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                throw FetchFailed::privateAddress($url, $host, $address);
            }
        }
    }

    public static function isBlocked(string $ip): bool
    {
        $ip = trim($ip, '[]');
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12); // ::ffff:a.b.c.d is a.b.c.d
        }

        foreach (strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6 as $cidr) {
            [$network, $bits] = explode('/', $cidr);

            if (self::inRange($packed, (string) inet_pton($network), (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /** @return list<string> the A and AAAA addresses of $host (empty when it does not resolve) */
    private static function resolve(string $host): array
    {
        $addresses = @gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values($addresses);
    }

    private static function normalize(string $host): string
    {
        return rtrim(trim(strtolower(trim($host)), '[]'), '.');
    }
}
