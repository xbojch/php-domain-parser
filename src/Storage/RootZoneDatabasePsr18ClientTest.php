<?php

declare(strict_types=1);

namespace Pdp\Storage;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Pdp\TopLevelDomains;
use Pdp\UnableToLoadRootZoneDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function dirname;
use function file_get_contents;

/**
 * @coversDefaultClass \Pdp\Storage\RootZoneDatabasePsr18Client
 */
final class RootZoneDatabasePsr18ClientTest extends TestCase
{
    public function testIsCanReturnARootZoneDatabaseInstance(): void
    {
        $client = new class() implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                /** @var string $body */
                $body = file_get_contents(dirname(__DIR__, 2).'/test_data/tlds-alpha-by-domain.txt');

                return new Response(200, [], $body);
            }
        };

        $requestFactory = new class() implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new Request($method, $uri);
            }
        };

        $storage = new RootZoneDatabasePsr18Client($client, $requestFactory);
        $rzd = $storage->get('http://www.example.com');

        self::assertInstanceOf(TopLevelDomains::class, $rzd);
    }

    public function testItWillThrowIfTheClientCanNotConnectToTheRemoteURI(): void
    {
        $client = new class() implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new ConnectException('foobar', $request, null);
            }
        };

        $requestFactory = new class() implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new Request($method, $uri);
            }
        };

        self::expectException(UnableToLoadRootZoneDatabase::class);
        self::expectExceptionMessage('Could not access the Root Zone Database URI: `http://www.example.com`.');

        $storage = new RootZoneDatabasePsr18Client($client, $requestFactory);
        $storage->get('http://www.example.com');
    }

    public function testItWillThrowIfTheReturnedStatusCodeIsNotOK(): void
    {
        $client = new class() implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(404);
            }
        };

        $requestFactory = new class() implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new Request($method, $uri);
            }
        };

        self::expectException(UnableToLoadRootZoneDatabase::class);
        self::expectExceptionMessage('Invalid response from Root Zone Database URI: `http://www.example.com`.');
        self::expectExceptionCode(404);

        $storage = new RootZoneDatabasePsr18Client($client, $requestFactory);
        $storage->get('http://www.example.com');
    }
}
