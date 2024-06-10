# Scout PHP APM Agent

[![Build](https://github.com/scoutapp/scout-apm-php/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/scoutapp/scout-apm-php/actions/workflows/continuous-integration.yml) [![Latest Stable Version](https://poser.pugx.org/scoutapp/scout-apm-php/v/stable)](https://packagist.org/packages/scoutapp/scout-apm-php) [![Total Downloads](https://poser.pugx.org/scoutapp/scout-apm-php/downloads)](https://packagist.org/packages/scoutapp/scout-apm-php) [![License](https://poser.pugx.org/scoutapp/scout-apm-php/license)](https://packagist.org/packages/scoutapp/scout-apm-php)

Email us at support@ScoutAPM.com to get on the beta invite list!

Monitor the performance of PHP apps with Scout's [PHP APM Agent](https://www.scoutapm.com).

Detailed performance metrics and transaction traces are collected once the agent is installed and configured.

## Requirements

PHP Versions: 7.2+

## Quick Start

This package is the base library for the various framework-specific packages.

### Laravel, Lumen, Symfony support

To install the ScoutAPM Agent for a specific framework, use the specific package instead.

 * [Laravel](https://github.com/scoutapp/scout-apm-laravel)
 * [Lumen](https://github.com/scoutapp/scout-apm-lumen)
 * [Symfony](https://github.com/scoutapp/scout-apm-symfony-bundle/)

### Using the base library directly

```php
use Psr\Log\LoggerInterface;
use Scoutapm\Agent;
use Scoutapm\Config;
use Scoutapm\Config\ConfigKey;

// It is assumed you are using a PSR Logger
/** @var LoggerInterface $psrLoggerImplementation */

$agent = Agent::fromConfig(
    Config::fromArray([
        ConfigKey::APPLICATION_NAME => 'Your application name',
        ConfigKey::APPLICATION_KEY => 'your scout key',
        ConfigKey::MONITORING_ENABLED => true,
    ]),
    $psrLoggerImplementation
);
// If the core agent is not already running, this will download and run it (from /tmp by default)
$agent->connect();

// Use $agent to record `webTransaction`, `backgroundTransaction`, `instrument` or `tagRequest` as necessary

// Nothing is sent to Scout until you call this - so call this at the end of your request
$agent->send();
```

#### Default log level

By default, the library is *very* noisy in logging by design - this is to help us figure out what is going wrong if you
need assistance. If you are confident everything is working, and you can see data in your Scout dashboard, then you
can increase the minimum log level by adding the following configuration to set the "minimum" log level (which **only**
applies to Scout's logging):

```php
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Scoutapm\Agent;
use Scoutapm\Config;
use Scoutapm\Config\ConfigKey;

/** @var LoggerInterface $psrLoggerImplementation */

$agent = Agent::fromConfig(
    Config::fromArray([
        ConfigKey::APPLICATION_NAME => 'Your application name',
        ConfigKey::APPLICATION_KEY => 'your scout key',
        ConfigKey::MONITORING_ENABLED => true,
        ConfigKey::LOG_LEVEL => LogLevel::ERROR, // <-- add this configuration to reduce logging verbosity
    ]),
    $psrLoggerImplementation
);
```

## Monitoring of PHP internal functions

You can enable additional monitoring of internal PHP function executions to measure time taken there. To do so, you need
to install and enable the `scoutapm` PHP extension from PECL, for example:

```bash
$ sudo pecl install scoutapm
```

You may need to add `zend_extension=scoutapm.so` into your `php.ini` to enable the extension.

With the extension enabled, specific IO-bound functions in PHP are monitored, for example `file_get_contents`,
`file_put_contents`, `PDO->exec` and so on.

Alternatively, you can [install from source](https://github.com/scoutapp/scout-apm-php-ext).

## Enable caching for Scout

Due to PHP's stateless and "shared-nothing" architecture, the Scout library performs some checks (such as sending some
metadata about the running system) on every request. These can be eliminated by giving Scout a PSR-16 (Simple Cache)
implementation when creating the agent:

```php
use Doctrine\Common\Cache\RedisCache;
use Psr\Log\LoggerInterface;
use Roave\DoctrineSimpleCache\SimpleCacheAdapter;
use Scoutapm\Agent;
use Scoutapm\Config;
use Scoutapm\Config\ConfigKey;

/** @var LoggerInterface $psrLoggerImplementation */
$yourPsrSimpleCacheImplementation = new SimpleCacheAdapter(new RedisCache());

$agent = Agent::fromConfig(
    Config::fromArray([
        ConfigKey::APPLICATION_NAME => 'Your application name',
        ConfigKey::APPLICATION_KEY => 'your scout key',
        ConfigKey::MONITORING_ENABLED => true,
    ]),
    $psrLoggerImplementation,
    $yourPsrSimpleCacheImplementation
);
```

## Using the PSR-15 Middleware provided

Since `scoutapp/scout-apm-php` release version 8.1.0, a PSR-15 compatible middleware is included out the box, which may
be used in a PSR-15 middleware-compatible framework, such as Slim or Mezzio. For example, in Slim framework:

```php
// Assumes $app is defined, e.g. an instance of `\Slim\App`
$app->add(\Scoutapm\Middleware\ScoutApmMiddleware::class);
```

You will very likely need to define `\Scoutapm\Middleware\ScoutApmMiddleware::class` in your container. For example, if
your container is Laminas ServiceManager, you could define a factory like:

```php
// Assumes $serviceManager is defined, e.g. an instance of `\Laminas\ServiceManager\ServiceManager`
$serviceManager->setFactory(
    \Scoutapm\Middleware\ScoutApmMiddleware::class,
    static function (\Psr\Container\ContainerInterface $container) {
        $logger = $container->get(LoggerInterface::class);

        $agent = Agent::fromConfig(
            Config::fromArray([
                // any additional array configuration
            ]),
            $logger
        );

        return new ScoutApmMiddleware($agent, $logger);
    }
);
```

## Documentation

For full installation and troubleshooting documentation, visit our [help site](http://docs.scoutapm.com/#php-agent).

## Support

Please contact us at support@ScoutAPM.com or create an issue in this repo.
