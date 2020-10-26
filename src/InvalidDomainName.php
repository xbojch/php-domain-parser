<?php

declare(strict_types=1);

namespace Pdp;

class InvalidDomainName extends InvalidHost
{
    public static function dueToInvalidLabelKey(int $key): self
    {
        return new self('the given key `'.$key.'` is invalid.');
    }

    public static function dueToInvalidCharacters(string $domain): self
    {
        return new self('The domain `'.$domain.'` is invalid: it contains invalid characters.');
    }

    public static function dueToUnsupportedType(string $domain): self
    {
        return new self('The domain `'.$domain.'` is invalid: this is an IPv4 host.');
    }

    public static function dueToInvalidPublicSuffix(Host $publicSuffix): self
    {
        return new self('The public suffix `"'.$publicSuffix->getContent() ?? 'NULL'.'"` is invalid.');
    }
}