<?php
declare(strict_types=1);

namespace Listrak\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CartRecreateLinkBuilder
{
    public function __construct(
        private readonly TokenCodec $codec,
        private readonly UrlGeneratorInterface $router
    ) {
    }

    /**
     * Build a stateless share link (no DB). Keep payload tiny.
     *
     * @param bool $replace If true, link will replace the receiver's cart
     */
    public function build(Cart $cart, string $salesChannelId, bool $replace = true, int $ttlSeconds = 604800): string
    {
        $items = [];
        foreach ($cart->getLineItems() as $li) {
            if ($li->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }
            $refId = (string) $li->getReferencedId();
            if ($refId === '') {
                continue;
            }
            $items[] = ['id' => $refId, 'qty' => $li->getQuantity()];
        }

        $promos = [];
        foreach ($cart->getLineItems() as $li) {
            if ($li->getType() === LineItem::PROMOTION_LINE_ITEM_TYPE && $li->getReferencedId()) {
                $promos[] = (string) $li->getReferencedId();
            }
        }

        $payload = [
            'v' => 1,
            'sc' => $salesChannelId,
            'exp' => time() + $ttlSeconds,
            'replace' => $replace,
            'items' => $items,
            'promotions' => $promos,
        ];

        $token = $this->codec->encode($payload);

        return $this->router->generate(
            'frontend.listrak.cart.recreate',
            ['code' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }
}
