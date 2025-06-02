<?php

declare(strict_types=1);

namespace Listrak\Service;

use Shopware\Storefront\Framework\Cookie\CookieProviderInterface;

class ListrakCookieProvider implements CookieProviderInterface
{
    private const singleCookie = [
        'snippet_name' => 'Listrak Cookies',
        'snippet_description' => 'Track cart, order and browse information',
        'cookie' => 'listrakTracking',
        'value' => true,
        'expiration' => '30',
    ];

    public function __construct(private readonly CookieProviderInterface $cookieProvider)
    {
    }

    public function getCookieGroups(): array
    {
        return array_merge(
            $this->cookieProvider->getCookieGroups(),
            [
                self::singleCookie,
            ]
        );
    }
}
