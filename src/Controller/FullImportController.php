<?php

namespace Listrak\Controller;

use Listrak\Message\ExportCustomersMessage;
use Listrak\Message\ImportCustomersMessage;
use Listrak\Message\ImportOrdersMessage;
use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpFoundation\Request;

#[Route(defaults: ['_routeScope' => ['api']])]
class FullImportController
{
    private ListrakConfigService $listrakConfigService;
    private ListrakApiService $listrakApiService;
    public function __construct(
        ListrakConfigService $listrakConfig,
        ListrakApiService $listrakApi,
        EntityRepository $failedRequestRepository,
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $orderRepository,
        private readonly MessageBusInterface $messageBus,
        LoggerInterface $logger
    ) {
        $this->listrakConfigService = $listrakConfig;
        $this->listrakApiService = $listrakApi;
        $this->failedRequestRepository = $failedRequestRepository;
        $this->logger = $logger;
    }


//    #[Route(path: '/api/_action/listrak-customer-sync', name: 'api.action.listrak.customer-sync', methods: ['POST'])]
//    public function importAllCustomers(RequestDataBag $dataBag, Context $context): JsonResponse
//    {
//        try {
//            $criteria = new Criteria();
//            $criteria->setLimit(500);
//            $criteria->addSorting(new FieldSorting('id'));
//            $iterator = new RepositoryIterator($this->customerRepository, $context, $criteria);
//            while (($result = $iterator->fetch()) !== null) {
//                $customers = $result->getEntities();
//                $items = [];
//                foreach ($customers as $customer) {
//                    $address = $customer->getDefaultBillingAddress();
//                    $addressItem = [];
//                    if ($address) {
//                        $addressItem = [
//                            'street' => $address->getStreet() ?? '',
//                            'city' => $address->getCity() ?? '',
//                            'state' => $address->getCountryState() ? $address->getCountryState()->getName() : '',
//                            'postalCode' => $address->getZipcode() ?? '',
//                            'country' => $address->getCountry() ? $address->getCountry()->getName() : '',
//                        ];
//                    }
//
//                    $data = [
//                        'customerNumber' => $customer->getCustomerNumber(),
//                        'firstName' => $customer->getFirstName(),
//                        'lastName' => $customer->getLastName(),
//                        'email' => $customer->getEmail(),
//                    ];
//                    $data['address'] = $addressItem;
//
//                    $items[] = $data;
//                }
//                $this->listrakApiService->importCustomer($items, $context);
//            }
//        } catch (\Exception $e) {
//            $success = ['success' => false];
//            return new JsonResponse($success);
//        }
//        $success = ['success' => true];
//        return new JsonResponse($success);
//    }

    #[Route(path: '/api/_action/listrak-customer-sync', name: 'api.action.listrak.customer-sync', methods: ['POST'])]
    public function importCustomers(Request $request, Context $context): JsonResponse
    {
        try {
                $message = new ImportCustomersMessage($context);
                $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $success = ['success' => false];
            return new JsonResponse($success);
        }
        $success = ['success' => true];
        return new JsonResponse($success);
    }

    #[Route(path: '/api/_action/listrak-order-sync', name: 'api.action.listrak.order-sync', methods: ['POST'])]
    public function importOrders(Request $request, Context $context): JsonResponse
    {
        try {
            $message = new ImportOrdersMessage($context);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $success = ['success' => false];
            return new JsonResponse($success);
        }
        $success = ['success' => true];
        return new JsonResponse($success);
    }


//    #[Route(path: '/api/_action/listrak-order-sync', name: 'api.action.listrak.order-sync', methods: ['POST'])]
//    public function importAllOrders(RequestDataBag $dataBag, Context $context): JsonResponse
//    {
//        try {
//            $criteria = new Criteria();
//            $criteria->setLimit(500);
//            $criteria->addAssociation('orderCustomer');
//            $criteria->addAssociation('lineItems');
//            $criteria->addAssociation('deliveries');
//            $criteria->addAssociation('shippingAddress');
//            $criteria->addAssociation('billingAddress');
//            $criteria->addSorting(new FieldSorting('id'));
//            $iterator = new RepositoryIterator($this->orderRepository, $context, $criteria);
//            while (($result = $iterator->fetch()) !== null) {
//                $orders = $result->getEntities();
//                $items = [];
//                foreach ($orders as $order) {
//                    $orderState = $order->getStateMachineState() ? $order->getStateMachineState()->getTechnicalName() : 'Unknown';
//                    $orderStatus = $this->listrakApiService->mapOrderStatus($orderState);
//                    $customer = $order->getOrderCustomer();
//                    $email = $customer && $customer->getEmail() ? $customer->getEmail() : '';
//                    $billingAddress = $order->getBillingAddress();
//                    $billingAddressItem = [];
//                    if ($billingAddress) {
//                        $billingAddressItem = [
//                        'firstName' => $billingAddress->getFirstName(),
//                        'lastName' => $billingAddress->getLastName(),
//                        'mobilePhone' => $billingAddress->getPhoneNumber() ?? '',
//                        'phone' => $billingAddress->getPhoneNumber() ?? '',
//                        'zipCode' => $billingAddress->getZipCode() ?? '',
//                        'city' => $billingAddress->getCity(),
//                        'country' => $billingAddress->getCountry() ? $billingAddress->getCountry()->getName() : '',
//                        'state' => $billingAddress->getCountryState() ? $billingAddress->getCountryState()->getName() : '',
//                        'address1' => $billingAddress->getStreet(),
//                        'address2' => $billingAddress->getAdditionalAddressLine1() ?? '',
//                        'address3' => $billingAddress->getAdditionalAddressLine2() ?? '',
//                        ];
//                    }
//
//                    $lineItems = [];
//                    $deliveries = [];
//                    $firstDelivery = null;
//                    if ($order->getDeliveries()) {
//                        foreach ($order->getDeliveries() as $delivery) {
//                            $deliveries[] = $delivery;
//                        }
//                        $firstDelivery = $deliveries[0];
//                    }
//
//                    $shippingAddress = $firstDelivery ? $firstDelivery->getShippingOrderAddress() : null;
//                    $shippingAddressItem = [];
//                    if ($shippingAddress) {
//                        $shippingAddressItem = [
//                        'firstName' => $shippingAddress->getFirstName(),
//                        'lastName' => $shippingAddress->getLastName(),
//                        'mobilePhone' => $shippingAddress->getPhoneNumber() ?? '',
//                        'phone' => $shippingAddress->getPhoneNumber() ?? '',
//                        'zipCode' => $shippingAddress->getZipCode() ?? '',
//                        'city' => $shippingAddress->getCity(),
//                        'country' => $shippingAddress->getCountry() ? $shippingAddress->getCountry()->getName() : '',
//                        'state' => $shippingAddress->getCountryState() ? $shippingAddress->getCountryState()->getName() : '',
//                        'address1' => $shippingAddress->getStreet(),
//                        'address2' => $shippingAddress->getAdditionalAddressLine1() ?? '',
//                        'address3' => $shippingAddress->getAdditionalAddressLine2() ?? ''
//                        ];
//                    }
//
//                    $orderItemTotal = 0;
//                    if ($order->getLineItems()) {
//                        foreach ($order->getLineItems() as $lineItem) {
//                            $calculatedPrice = $lineItem->getPrice();
//                            $sku = $this->listrakApiService->generateSku($lineItem);
//                            $listPrice = $calculatedPrice && $calculatedPrice->getListPrice() ? $calculatedPrice->getListPrice()->getPrice() : 0;
//                            $unitPrice = $lineItem->getUnitPrice();
//                            $discountedPrice = $calculatedPrice && $calculatedPrice->getListPrice() ? $calculatedPrice->getListPrice()->getDiscount() : 0;
//                            $quantity = $lineItem->getQuantity();
//                            $itemDiscountTotal = ($listPrice - $discountedPrice) * $lineItem->getQuantity();
//                            $itemTotal = ($unitPrice * $quantity) - $itemDiscountTotal;
//                            $orderItemTotal += $itemTotal;
//                            $lineItem = [
//                            'discountedPrice' => $discountedPrice,
//                            'itemTotal' => $itemTotal,
//                            'itemDiscountTotal' => $itemDiscountTotal,
//                            'orderNumber' => $order->getOrderNumber(),
//                            'price' => $unitPrice,
//                            'quantity' => $quantity,
//                            'sku' => $sku,
//                            'status' => $orderStatus,
//                            ];
//                            $lineItems[] = $lineItem;
//                        }
//                    }
//
//                    $data = [
//                    'orderNumber' => $order->getOrderNumber(),
//                    'dateEntered' => '2025-04-22T01:39:35Z',
//                    'email' => $email,
//                    'customerNumber' => $order->getOrderCustomer() ? $order->getOrderCustomer()->getCustomerNumber() : '',
//                    'billingAddress' => $billingAddressItem,
//                    'items' => $lineItems,
//                    'itemTotal' => $orderItemTotal,
//                    'orderTotal' => $order->getPrice()->getTotalPrice(),
//                    'shipDate' => $firstDelivery ? $firstDelivery->getShippingDateEarliest()->format('Y-m-d\TH:i:s\Z') : '',
//                    'shippingAddress' => $shippingAddressItem,
//                    'shippingMethod' => $firstDelivery && $firstDelivery->getShippingMethod() ? $firstDelivery->getShippingMethod()->getName() : '',
//                    'shippingTotal' => $order->getShippingTotal(),
//                    'status' => $orderStatus,
//                    'taxTotal' => $order->getPrice()->getCalculatedTaxes()->getAmount(),
//                    ];
//                    $items[] =  $data;
//                }
//                $this->listrakApiService->importOrder($items, $context);
//            }
//        } catch (\Exception $exception) {
//            $success = ['success' => false];
//            return new JsonResponse($success);
//        }
//            $success = ['success' => true];
//            return new JsonResponse($success);
//    }
}
