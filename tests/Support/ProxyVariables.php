<?php

namespace ItsJustVita\LaravelBfsg\Tests\Support;

/**
 * The proxy variables Guzzle and libcurl read, under test control: clearProxyEnvironment() (setUp) saves and removes
 * them from putenv(), $_ENV and $_SERVER, so a proxy set on the machine cannot change a result;
 * restoreProxyEnvironment() (tearDown) puts the saved values back in all three.
 */
trait ProxyVariables
{
    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> variable => its getenv(), _ENV and _SERVER values */
    private array $savedProxyEnvironment = [];

    /** @return list<string> */
    private static function proxyVariables(): array
    {
        return ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY'];
    }

    protected function clearProxyEnvironment(): void
    {
        foreach (self::proxyVariables() as $name) {
            $this->savedProxyEnvironment[$name] = [getenv($name, true), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function restoreProxyEnvironment(): void
    {
        foreach ($this->savedProxyEnvironment as $name => [$env, $envArray, $server]) {
            putenv($env === false ? $name : "{$name}={$env}");
            unset($_ENV[$name], $_SERVER[$name]);

            if ($envArray !== null) {
                $_ENV[$name] = $envArray;
            }

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
        }

        $this->savedProxyEnvironment = [];
    }

    /** @param  array<string, string>  $variables  set in the process environment (putenv), as a shell export would */
    protected function setProxyEnvironment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            putenv("{$name}={$value}");
        }
    }
}
