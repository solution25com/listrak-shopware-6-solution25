<?php

declare(strict_types=1);

namespace Listrak\Service;

use Shopware\Storefront\Framework\Cookie\CookieProviderInterface;

// @phpstan-ignore class.implementsDeprecatedInterface (Required for Shopware 6.7.0 compatibility; the cookie event was added later.)
class ListrakCookieProvider implements CookieProviderInterface
{
    private const SINGLECOOKIE = [
        'snippet_name' => 'Listrak Cookies',
        'snippet_description' => 'Track cart, order and browse information',
        'cookie' => 'listrakTracking',
        'value' => '1',
        'expiration' => '30',
    ];

    // @phpstan-ignore parameter.deprecatedInterface (Supported by the Shopware 6.7 cookie provider bridge.)
    public function __construct(private readonly CookieProviderInterface $cookieProvider)
    {
    }

    public function getCookieGroups(): array
    {
        return array_merge(
            $this->cookieProvider->getCookieGroups(),
            [
                self::SINGLECOOKIE,
            ]
        );
    }
}
