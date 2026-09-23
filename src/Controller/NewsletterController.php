<?php declare(strict_types=1);

namespace Listrak\Controller;

use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Pagelet\Newsletter\Account\NewsletterAccountPageletLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class NewsletterController extends StorefrontController
{
    public function __construct(private readonly NewsletterAccountPageletLoader $loader)
    {
    }

    #[Route(path: '/listrak/newsletter', name: 'frontend.listrak.newsletter', methods: ['POST'], defaults: [
        'XmlHttpRequest' => true, '_loginRequired' => true, '_loginRequiredAllowGuest' => true,
    ])]
    public function update(Request $request, RequestDataBag $data, SalesChannelContext $context): JsonResponse
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            throw CartException::customerNotLoggedIn();
        }
        // Shopware hydrates identity from the authenticated checkout customer,
        // preserving double opt-in without using the public CAPTCHA form.
        $pagelet = $this->loader->action($request, $data, $context, $customer);
        $messages = [];
        foreach ($pagelet->getMessages() ?? [] as $message) {
            $messages[] = [
                'type' => $message['type'],
                'alert' => $this->renderView('@Storefront/storefront/utilities/alert.html.twig', [
                    'type' => $message['type'], 'content' => $message['text'],
                ]),
            ];
        }

        return new JsonResponse($messages);
    }
}
