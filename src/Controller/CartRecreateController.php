<?php
declare(strict_types=1);

namespace Listrak\Controller;

use Listrak\Service\TokenCodec;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class CartRecreateController extends StorefrontController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly TokenCodec $codec,
        private readonly LineItemFactoryRegistry $lineItemFactoryRegistry
    ) {
    }

    #[Route(
        path: '/listrak/cart/recreate/{code}',
        name: 'frontend.listrak.cart.recreate',
        requirements: ['code' => '.+'],
        methods: ['GET']
    )]
    public function recreate(string $code, SalesChannelContext $context, Request $request): RedirectResponse
    {
        try {
            $payload = $this->codec->decode($code);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'The cart link is invalid or expired.');

            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        if (!empty($payload['sc']) && $payload['sc'] !== $context->getSalesChannelId()) {
            $this->addFlash('warning', 'This cart link is for a different sales channel.');

            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        if (!empty($payload['replace'])) {
            $cart->getLineItems()->clear();
        }

        foreach ($payload['items'] ?? [] as $it) {
            $referencedId = (string) ($it['id'] ?? '');
            if ($referencedId === '') {
                continue;
            }

            $quantity = (int) max(1, (int) ($it['qty'] ?? 1));

            $lineItem = $this->lineItemFactoryRegistry->create([
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $referencedId,         // variant concrete id
                'quantity' => $quantity,
                'stackable' => true,
                'removable' => true,
            ], $context);

            $this->cartService->add($cart, $lineItem, $context);
        }

        foreach ($payload['promotions'] ?? [] as $promoCode) {
            $code = trim((string) $promoCode);
            if ($code === '') {
                continue;
            }

            $promo = new LineItem(Uuid::randomHex(), LineItem::PROMOTION_LINE_ITEM_TYPE);
            $promo->setReferencedId($code);
            $promo->setRemovable(true);

            $this->cartService->add($cart, $promo, $context);
        }

        return $this->redirectToRoute('frontend.checkout.cart.page');
    }
}
