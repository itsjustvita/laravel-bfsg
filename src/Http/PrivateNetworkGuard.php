<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Closure;

/**
 * Refuses URLs whose host is, or resolves to, a loopback, private, link-local or unspecified address: the SSRF
 * guard of the MCP URL tools when no allow-list is configured. IP literals are classified directly; hostnames are
 * resolved (A records via gethostbynamel(), which also covers /etc/hosts, and AAAA records via dns_get_record()) and
 * refused when any address is blocked. Numeric hosts in the forms libcurl accepts (hex, octal, decimal, short:
 * 0x7f000001, 0177.0.0.1, 2130706433, 127.1) are parsed like inet_aton() and classified; digits-and-dots hosts that
 * are no valid address are refused. A host that does not resolve at all is refused too (fail closed).
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

    /**
     * @return list<string> the vetted addresses of the URL's host (empty for an exempt host)
     *
     * @throws FetchFailed when the URL's host is (or resolves to) a blocked address, is a malformed numeric address,
     *                     or does not resolve at all
     */
    public function check(string $url): array
    {
        $host = self::normalize((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || in_array($host, array_map(self::normalize(...), $this->exemptHosts), true)) {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } elseif (self::looksNumeric($host)) {
            // 0x7f000001, 0177.1, 2130706433, 127.1: libcurl connects to these as IPv4 addresses without a lookup
            $address = self::parseNumericHost($host) ?? throw FetchFailed::malformedAddress($url, $host);
            $addresses = [$address];
        } else {
            $addresses = array_values(array_unique(($this->resolver)($host)));
        }

        if ($addresses === []) {
            throw FetchFailed::unresolvable($url, $host);
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                throw FetchFailed::privateAddress($url, $host, $address);
            }
        }

        return $addresses;
    }

    /**
     * The dotted quad of a numeric host in any form inet_aton() and libcurl accept: one to four parts, each decimal,
     * octal (leading 0) or hex (0x), the last part filling the remaining bytes (127.1 = 127.0.0.1, 2130706433 =
     * 127.0.0.1). Null when $host is not such a form.
     */
    public static function parseNumericHost(string $host): ?string
    {
        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if (preg_match('/^0x([0-9a-f]*)$/i', $part, $m) === 1) {
                $value = $m[1] === '' ? 0 : (strlen(ltrim($m[1], '0')) > 8 ? null : hexdec($m[1]));
            } elseif (preg_match('/^0[0-7]*$/', $part) === 1) {
                $value = strlen(ltrim($part, '0')) > 11 ? null : octdec($part);
            } elseif (preg_match('/^[1-9][0-9]*$/', $part) === 1) {
                $value = strlen($part) > 10 ? null : (int) $part;
            } else {
                return null;
            }

            if ($value === null) {
                return null;
            }

            $values[] = (int) $value;
        }

        $last = array_pop($values);

        foreach ($values as $value) {
            if ($value > 255) {
                return null;
            }
        }

        if ($last >= 256 ** (4 - count($values))) {
            return null;
        }

        $number = $last;

        foreach ($values as $index => $value) {
            $number += $value * 256 ** (3 - $index);
        }

        return long2ip((int) $number);
    }

    /** Made only of numeric labels (decimal digits or 0x hex), so a URL parser treats it as an IPv4 address, not a name. */
    private static function looksNumeric(string $host): bool
    {
        return preg_match('/^(?:0x[0-9a-f]*|[0-9]+)(?:\.(?:0x[0-9a-f]*|[0-9]+))*$/i', $host) === 1;
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
