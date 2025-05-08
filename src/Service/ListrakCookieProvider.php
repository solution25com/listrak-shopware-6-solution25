<?php

declare(strict_types=1);

namespace Listrak\Service;

use Shopware\Storefront\Framework\Cookie\CookieProviderInterface;

class ListrakCookieProvider implements CookieProviderInterface
{
    private const singleCookie = [
        'snippet_name' => 'Listrak Cookies',
        'snippet_description' => 'Track cart and order information',
        'cookie' => 'listrakCartAbandonmentTracking',
        'value' => true,
        'expiration' => '30',
    ];

    private CookieProviderInterface $cookieProvider;

    public function __construct(CookieProviderInterface $cookieProvider)
    {
        $this->cookieProvider = $cookieProvider;
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
