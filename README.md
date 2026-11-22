# Restate PHP SDK — Test harness

`Restate\Testing\RestateTestEnvironment` runs integration tests of your [Restate](https://restate.dev) services against a real Restate server: it starts Restate in a container, serves your endpoint script with `php -S` and the Restate PHP extension loaded (or runs your own endpoint image), registers it, and hands out an ingress client ([`restate/ingress`](https://github.com/restate-php/ingress)) connected to it. With PHPUnit, the `InteractsWithRestate` trait shares one environment per process across test classes and prints the Restate and endpoint logs when a test fails. It also restarts, pauses or kills either side for tests of failure handling.

## Installation

```bash
composer require --dev restate/testing guzzlehttp/guzzle
```

Guzzle is the default HTTP client of `ingressClient()`; pass your own PSR-18 client and PSR-17 factory otherwise.

## Usage

```php
use PHPUnit\Framework\TestCase;
use Restate\Testing\PHPUnit\InteractsWithRestate;
use Restate\Testing\PhpCliServer;

final class GreeterTest extends TestCase
{
    use InteractsWithRestate;

    protected static function restateEndpoint(): PhpCliServer
    {
        return PhpCliServer::forScript(__DIR__ . '/../public/restate.php'); // the script that calls Endpoint::serve()
    }

    public function testGreets(): void
    {
        $greeter = self::restate()->ingressClient()->serviceClient('Greeter');

        self::assertSame('Hello Mary', $greeter->request('greet', 'Mary')->call());
    }
}
```

Without PHPUnit, `RestateTestEnvironment::start(PhpCliServer::forScript(...))` returns the same environment as a plain object.

## Requirements

- PHP 8.4, 8.5 or 8.6
- Docker or podman (rootless works), or `restate-server` on `PATH` with `RestateProcess::default()` instead of the default container, which needs no container runtime with `php -S`
- The Restate PHP extension where the endpoint runs (the `php -S` child or your image), not in the test process; the harness does not require [`restate/sdk`](https://github.com/restate-php/sdk), your project does
- PHPUnit 10 or newer for the trait only

## Documentation

See [Testing Your Services](https://github.com/restate-php/restate#testing-your-services) in the main repository for every option, and the [main repository](https://github.com/restate-php/restate) for the rest of the SDK.

This repository is a read-only subtree split of [restate-php/restate](https://github.com/restate-php/restate). Report issues and send pull requests there.

## License

MIT; see [LICENSE](LICENSE).
