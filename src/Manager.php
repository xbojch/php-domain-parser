<?php

/**
 * PHP Domain Parser: Public Suffix List based URL parsing.
 *
 * @see http://github.com/jeremykendall/php-domain-parser for the canonical source repository
 *
 * @copyright Copyright (c) 2017 Jeremy Kendall (http://jeremykendall.net)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Pdp;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Pdp\Exception\CouldNotLoadRules;
use Pdp\Exception\CouldNotLoadTLDs;
use Psr\SimpleCache\CacheInterface;
use TypeError;
use const DATE_ATOM;
use const FILTER_VALIDATE_INT;
use const JSON_ERROR_NONE;
use function filter_var;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function md5;
use function sprintf;
use function strtolower;

/**
 * Public Suffix List Manager.
 *
 * This class obtains, writes, caches, and returns PHP representations
 * of the Public Suffix List ICANN section
 *
 * @author Jeremy Kendall <jeremy@jeremykendall.net>
 * @author Ignace Nyamagana Butera <nyamsprod@gmail.com>
 */
final class Manager
{
    const PSL_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';
    const RZD_URL = 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt';

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var HttpClient
     */
    private $http;

    /**
     * @var DateInterval|null
     */
    private $ttl;

    /**
     * @var Converter;
     */
    private $converter;

    /**
     * new instance.
     *
     * @param null|mixed $ttl
     */
    public function __construct(CacheInterface $cache, HttpClient $http, $ttl = null)
    {
        $this->cache = $cache;
        $this->http = $http;
        $this->ttl = $this->filterTtl($ttl);
        $this->converter = new Converter();
    }

    /**
     * Gets the Public Suffix List Rules.
     *
     * @param null|mixed $ttl
     *
     * @throws CouldNotLoadRules If the PSL rules can not be loaded
     */
    public function getRules(string $url = self::PSL_URL, $ttl = null): Rules
    {
        $key = $this->getCacheKey('PSL', $url);
        $data = $this->cache->get($key);

        if (null === $data && !$this->refreshRules($url, $ttl)) {
            throw new CouldNotLoadRules(sprintf('Unable to load the public suffix list rules for %s', $url));
        }

        $data = json_decode($data ?? $this->cache->get($key), true);
        if (JSON_ERROR_NONE === json_last_error()) {
            return new Rules($data);
        }

        throw new CouldNotLoadRules('The public suffix list cache is corrupted: '.json_last_error_msg(), json_last_error());
    }

    /**
     * Downloads, converts and cache the Public Suffix.
     *
     * If a local cache already exists, it will be overwritten.
     *
     * Returns true if the refresh was successful
     *
     * @param null|mixed $ttl
     */
    public function refreshRules(string $url = self::PSL_URL, $ttl = null): bool
    {
        $data = $this->converter->convert($this->http->getContent($url));
        $key = $this->getCacheKey('PSL', $url);
        $ttl = $this->filterTtl($ttl) ?? $this->ttl;

        return $this->cache->set($key, json_encode($data), $ttl);
    }

    /**
     * Gets the Public Suffix List Rules.
     *
     * @param null|mixed $ttl
     *
     * @throws Exception If the Top Level Domains can not be returned
     */
    public function getTLDs(string $url = self::RZD_URL, $ttl = null): TopLevelDomains
    {
        $key = $this->getCacheKey('RZD', $url);
        $data = $this->cache->get($key);

        if (null === $data && !$this->refreshTLDs($url, $ttl)) {
            throw new CouldNotLoadTLDs(sprintf('Unable to load the root zone database from %s', $url));
        }

        $data = json_decode($data ?? $this->cache->get($key), true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new CouldNotLoadTLDs('The root zone database cache is corrupted: '.json_last_error_msg(), json_last_error());
        }

        if (!isset($data['records'], $data['version'], $data['update'])) {
            throw new CouldNotLoadTLDs(sprintf('The root zone database cache content is corrupted'));
        }

        return new TopLevelDomains(
            $data['records'],
            $data['version'],
            DateTimeImmutable::createFromFormat(DATE_ATOM, $data['update'])
        );
    }

    /**
     * Downloads, converts and cache the IANA Root Zone TLD.
     *
     * If a local cache already exists, it will be overwritten.
     *
     * Returns true if the refresh was successful
     *
     * @param null|mixed $ttl
     *
     * @throws Exception if the source is not validated
     */
    public function refreshTLDs(string $url = self::RZD_URL, $ttl = null): bool
    {
        $data = $this->converter->convertRootZoneDatabase($this->http->getContent($url));
        $key = $this->getCacheKey('RZD', $url);
        $ttl = $this->filterTtl($ttl) ?? $this->ttl;

        return $this->cache->set($key, json_encode($data), $ttl);
    }

    /**
     * set the cache TTL.
     *
     * @return DateInterval|null
     */
    private function filterTtl($ttl)
    {
        if ($ttl instanceof DateInterval || null === $ttl) {
            return $ttl;
        }

        if ($ttl instanceof DateTimeInterface) {
            return (new DateTimeImmutable('now', $ttl->getTimezone()))->diff($ttl);
        }

        if (false !== ($res = filter_var($ttl, FILTER_VALIDATE_INT))) {
            return new DateInterval('PT'.$res.'S');
        }

        if (is_string($ttl)) {
            return DateInterval::createFromDateString($ttl);
        }

        throw new TypeError(sprintf(
            'The ttl must an integer, a string or a DateInterval object %s given',
            is_object($ttl) ? get_class($ttl) : gettype($ttl)
        ));
    }

    /**
     * Returns the cache key according to the source URL.
     */
    private function getCacheKey(string $prefix, string $str): string
    {
        return sprintf('%s_FULL_%s', $prefix, md5(strtolower($str)));
    }
}
