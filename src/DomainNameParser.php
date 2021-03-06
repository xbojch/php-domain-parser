<?php

declare(strict_types=1);

namespace Pdp;

use TypeError;
use UnexpectedValueException;
use function array_reverse;
use function explode;
use function filter_var;
use function gettype;
use function idn_to_ascii;
use function idn_to_utf8;
use function implode;
use function method_exists;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function strpos;
use function strtolower;
use const FILTER_FLAG_IPV4;
use const FILTER_VALIDATE_IP;
use const IDNA_DEFAULT;
use const IDNA_ERROR_BIDI;
use const IDNA_ERROR_CONTEXTJ;
use const IDNA_ERROR_DISALLOWED;
use const IDNA_ERROR_DOMAIN_NAME_TOO_LONG;
use const IDNA_ERROR_EMPTY_LABEL;
use const IDNA_ERROR_HYPHEN_3_4;
use const IDNA_ERROR_INVALID_ACE_LABEL;
use const IDNA_ERROR_LABEL_HAS_DOT;
use const IDNA_ERROR_LABEL_TOO_LONG;
use const IDNA_ERROR_LEADING_COMBINING_MARK;
use const IDNA_ERROR_LEADING_HYPHEN;
use const IDNA_ERROR_PUNYCODE;
use const IDNA_ERROR_TRAILING_HYPHEN;
use const INTL_IDNA_VARIANT_UTS46;

abstract class DomainNameParser
{
    /**
     * Get and format IDN conversion error message.
     */
    private static function getIdnErrors(int $errorByte): string
    {
        /**
         * IDNA errors.
         *
         * @see http://icu-project.org/apiref/icu4j/com/ibm/icu/text/IDNA.Error.html
         */
        static $idnErrors = [
            IDNA_ERROR_EMPTY_LABEL => 'a non-final domain name label (or the whole domain name) is empty',
            IDNA_ERROR_LABEL_TOO_LONG => 'a domain name label is longer than 63 bytes',
            IDNA_ERROR_DOMAIN_NAME_TOO_LONG => 'a domain name is longer than 255 bytes in its storage form',
            IDNA_ERROR_LEADING_HYPHEN => 'a label starts with a hyphen-minus ("-")',
            IDNA_ERROR_TRAILING_HYPHEN => 'a label ends with a hyphen-minus ("-")',
            IDNA_ERROR_HYPHEN_3_4 => 'a label contains hyphen-minus ("-") in the third and fourth positions',
            IDNA_ERROR_LEADING_COMBINING_MARK => 'a label starts with a combining mark',
            IDNA_ERROR_DISALLOWED => 'a label or domain name contains disallowed characters',
            IDNA_ERROR_PUNYCODE => 'a label starts with "xn--" but does not contain valid Punycode',
            IDNA_ERROR_LABEL_HAS_DOT => 'a label contains a dot=full stop',
            IDNA_ERROR_INVALID_ACE_LABEL => 'An ACE label does not contain a valid label string',
            IDNA_ERROR_BIDI => 'a label does not meet the IDNA BiDi requirements (for right-to-left characters)',
            IDNA_ERROR_CONTEXTJ => 'a label does not meet the IDNA CONTEXTJ requirements',
        ];

        $res = [];
        foreach ($idnErrors as $error => $reason) {
            if ($error === ($errorByte & $error)) {
                $res[] = $reason;
            }
        }

        return [] === $res ? 'Unknown IDNA conversion error.' : implode(', ', $res).'.';
    }

    /**
     * Converts the input to its IDNA ASCII form.
     *
     * This method returns the string converted to IDN ASCII form
     *
     * @throws SyntaxError if the string can not be converted to ASCII using IDN UTS46 algorithm
     */
    final protected function idnToAscii(string $domain, int $option = IDNA_DEFAULT): string
    {
        $domain = rawurldecode($domain);

        static $pattern = '/[^\x20-\x7f]/';

        if (1 !== preg_match($pattern, $domain)) {
            return strtolower($domain);
        }

        $output = idn_to_ascii($domain, $option, INTL_IDNA_VARIANT_UTS46, $infos);
        if ([] === $infos) {
            throw SyntaxError::dueToIDNAError($domain);
        }

        if (0 !== $infos['errors']) {
            throw SyntaxError::dueToIDNAError($domain, self::getIdnErrors($infos['errors']));
        }

        // @codeCoverageIgnoreStart
        if (false === $output) {
            throw new UnexpectedValueException(sprintf('The Intl extension is misconfigured for %s, please correct this issue before proceeding.', PHP_OS));
        }
        // @codeCoverageIgnoreEnd

        if (false === strpos($output, '%')) {
            return $output;
        }

        throw SyntaxError::dueToInvalidCharacters($domain);
    }

    /**
     * Converts the input to its IDNA UNICODE form.
     *
     * This method returns the string converted to IDN UNICODE form
     *
     * @throws SyntaxError              if the string can not be converted to UNICODE using IDN UTS46 algorithm
     * @throws UnexpectedValueException if the intl extension is misconfigured
     */
    final protected function idnToUnicode(string $domain, int $option = IDNA_DEFAULT): string
    {
        if (false === strpos($domain, 'xn--')) {
            return $domain;
        }

        $output = idn_to_utf8($domain, $option, INTL_IDNA_VARIANT_UTS46, $info);
        if ([] === $info) {
            throw SyntaxError::dueToIDNAError($domain);
        }

        if (0 !== $info['errors']) {
            throw SyntaxError::dueToIDNAError($domain, self::getIdnErrors($info['errors']));
        }

        // @codeCoverageIgnoreStart
        if (false === $output) {
            throw new UnexpectedValueException(sprintf('The Intl extension for %s is misconfigured. Please correct this issue before proceeding.', PHP_OS));
        }
        // @codeCoverageIgnoreEnd

        return $output;
    }

    /**
     * Parse and format the domain to ensure it is valid.
     * Returns an array containing the formatted domain name labels
     * and the domain transitional information.
     *
     * For example: parse('wWw.uLb.Ac.be') should return ['be', 'ac', 'ulb', 'www'];.
     *
     * @param mixed $domain a domain
     *
     *@throws SyntaxError If the host is not a domain
     *@throws SyntaxError If the domain is not a host
     *@return array<string>
     *
     */
    final protected function parse($domain = null, int $asciiOption = IDNA_DEFAULT, int $unicodeOption = IDNA_DEFAULT): array
    {
        if ($domain instanceof Host) {
            $domain = $domain->getContent();
        }

        if (null === $domain) {
            return [];
        }

        if (is_object($domain) && method_exists($domain, '__toString')) {
            $domain = (string) $domain;
        }

        if (!is_scalar($domain)) {
            throw new TypeError(sprintf('The domain must be a string, a stringable object, a Host object or NULL; `%s` given', gettype($domain)));
        }

        $domain = (string) $domain;
        if ('' === $domain) {
            return [''];
        }

        $res = filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if (false !== $res) {
            throw SyntaxError::dueToUnsupportedType($domain);
        }

        $formattedDomain = rawurldecode($domain);

        // Note that unreserved is purposely missing . as it is used to separate labels.
        static $domainName = '/(?(DEFINE)
                (?<unreserved>[a-z0-9_~\-])
                (?<sub_delims>[!$&\'()*+,;=])
                (?<encoded>%[A-F0-9]{2})
                (?<reg_name>(?:(?&unreserved)|(?&sub_delims)|(?&encoded)){1,63})
            )
            ^(?:(?&reg_name)\.){0,126}(?&reg_name)\.?$/ix';
        if (1 === preg_match($domainName, $formattedDomain)) {
            return array_reverse(explode('.', strtolower($formattedDomain)));
        }

        // a domain name can not contains URI delimiters or space
        static $genDelimiters = '/[:\/?#\[\]@ ]/';
        if (1 === preg_match($genDelimiters, $formattedDomain)) {
            throw SyntaxError::dueToInvalidCharacters($domain);
        }

        // if the domain name does not contains UTF-8 chars then it is malformed
        static $pattern = '/[^\x20-\x7f]/';
        if (1 === preg_match($pattern, $formattedDomain)) {
            $asciiDomain = $this->idnToAscii($domain, $asciiOption);

            /** @var array $labels */
            $labels = array_reverse(explode('.', $this->idnToUnicode($asciiDomain, $unicodeOption)));

            return $labels;
        }

        throw SyntaxError::dueToInvalidCharacters($domain);
    }
}
