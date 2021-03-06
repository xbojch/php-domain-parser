<?php

declare(strict_types=1);

namespace Pdp;

use InvalidArgumentException;

class SyntaxError extends InvalidArgumentException implements CannotProcessHost
{
    public static function dueToInvalidCharacters(string $domain): self
    {
        return new self('The host `'.$domain.'` is invalid: it contains invalid characters.');
    }

    public static function dueToIDNAError(string $domain, string $message = ''): self
    {
        if ('' === $message) {
            return new self('The host `'.$domain.'` is invalid.');
        }

        return new self('The host `'.$domain.'` is invalid : '.$message);
    }

    public static function dueToInvalidPublicSuffix(Host $publicSuffix): self
    {
        return new self('The public suffix `"'.$publicSuffix->getContent() ?? 'NULL'.'"` is invalid.');
    }

    public static function dueToUnsupportedType(string $domain): self
    {
        return new self('The domain `'.$domain.'` is invalid: this is an IPv4 host.');
    }

    public static function dueToInvalidLabelKey(Host $domain, int $key): self
    {
        return new self('the given key `'.$key.'` is invalid for the domain `"'.$domain->getContent() ?? 'NULL'.'"`.');
    }
}
