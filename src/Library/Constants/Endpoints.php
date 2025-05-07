<?php

declare(strict_types=1);

namespace Listrak\Library\Constants;

abstract class Endpoints
{
    protected const CUSTOMER_IMPORT = 'CUSTOMER_IMPORT';
    protected const ORDER_IMPORT = 'ORDER_IMPORT';
    protected const CONTACT_CREATE = 'CONTACT_CREATE';

    /**
     * @var array<string,string[]>
     */
    private static array $endpoints = [
        self::CUSTOMER_IMPORT => [
            'method' => 'POST',
            'url' => '/data/v1/Customer/',
        ],
        self::ORDER_IMPORT => [
            'method' => 'POST',
            'url' => '/data/v1/Order/',
        ],
        self::CONTACT_CREATE => [
            'method' => 'POST',
            'url' => '/email/v1/List/',
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public static function getUrl(string $endpoint): array
    {
        $endpointDetails = self::getEndpoint($endpoint);
        $baseUrl = $endpointDetails['url'];

        return [
            'method' => $endpointDetails['method'],
            'url' => 'https://api.listrak.com' . $endpointDetails['url'],
        ];
    }

    /**
     * @param array<string> $params
     * @param array<string> $queryParam
     *
     * @return array<string,array<string>|string>
     */
    public static function getUrlDynamicParam(string $endpoint, ?array $params = [], ?array $queryParam = []): array
    {
        $endpointDetails = self::getEndpoint($endpoint);
        $baseUrl = $endpointDetails['url'];

        $paramBuilder = implode('/', $params);

        $queryString = !empty($queryParam) ? '?' . http_build_query($queryParam) : '';

        return [
            'method' => $endpointDetails['method'],
            'url' => 'https://api.listrak.com' . $baseUrl . $paramBuilder . $queryString,
        ];
    }

    /**
     * @return array<string>
     */
    protected static function getEndpoint(string $endpoint): array
    {
        return self::$endpoints[$endpoint];
    }
}
