<?php

declare(strict_types=1);

namespace Listrak\Controller;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Returns canonical product URLs for given product IDs.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class UrlController extends StorefrontController
{
    public function __construct()
    {
    }

    #[Route(path: '/listrak/product-url', name: 'frontend.listrak.product-url', methods: ['POST'])]
    public function __invoke(Request $request, SalesChannelContext $scContext): JsonResponse
    {
        $ids = array_values(array_filter((array) ($request->toArray()['ids'] ?? []), fn ($id) => Uuid::isValid($id)));
        if (!$ids) {
            return new JsonResponse(['urls' => []]);
        }
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = $this->generateUrl(
                'frontend.detail.page',
                ['productId' => $id],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        }

        return new JsonResponse(['urls' => $map]);
    }
}
