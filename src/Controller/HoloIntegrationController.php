<?php

namespace CoregenionShop\Controller;

use CoregenionShop\Entity\Article;
use CoregenionShop\Entity\ArticleCombine;
use CoregenionShop\Entity\ArticleCombineLanguage;
use CoregenionShop\Entity\ArticleLanguage;
use CoregenionShop\Entity\ArticleMedia;
use CoregenionShop\Entity\ArticlePrice;
use CoregenionShop\Entity\Ordering;
use CoregenionShop\Entity\OrderingArticle;
use CoregenionShop\Entity\OrderingShipping;
use CoregenionShop\Entity\User;
use CoregenionShop\Repository\ArticleCombineLanguageRepository;
use CoregenionShop\Repository\ArticleCombineRepository;
use CoregenionShop\Repository\ArticleMediaRepository;
use CoregenionShop\Repository\ArticlePriceRepository;
use CoregenionShop\Repository\ArticleRepository;
use CoregenionShop\Repository\OrderingArticleRepository;
use CoregenionShop\Repository\OrderingRepository;
use CoregenionShop\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class HoloIntegrationController extends AbstractController
{
    private string $assetsUrl = 'https://myaroma.de';

    // coregenionShop_api_product:
    //     path:  /{_locale}/api/holo/product
    //     defaults:
    //         _controller: CoregenionShop\Controller\HoloIntegrationController:getProduct
    //         _locale: '%locale%'
    //     requirements:
    //         _locale: '%app_locales%'
    public function getProduct(
        Request $request,
        ArticleRepository $articleRepository,
        ArticleCombineRepository $articleCombineRepository,
        ArticlePriceRepository $articlePriceRepository,
        ArticleMediaRepository $articleMediaRepository,
        ArticleCombineLanguageRepository $articleCombineLanguageRepository
    ): JsonResponse {
        if (!$this->checkForHoloAuth()) {
            return $this->json(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $locale = $request->getLocale();

        $articles = [];

        /** @var ?string */
        $articleId = $request->get('id');
        if (!is_null($articleId)) {
            /** @var ?Article */
            $article = $articleRepository->findOneBy(['id' => $articleId, 'isActive' => true]);
            if (!is_null($article)) {
                $articles[] = $article;
            }
        } else {
            /** @var Article[] */
            $articles = $articleRepository->findBy(['isActive' => true]);
        }

        // increase execution time
        ini_set('max_execution_time', 600);

        $articlesData = [];
        foreach ($articles as $article) {
            $articlesData[] = $this->articleData(
                $article,
                $locale,
                $articleCombineRepository,
                $articlePriceRepository,
                $articleCombineLanguageRepository,
                $articleMediaRepository
            );
        }

        // reset execution time
        ini_set('max_execution_time', 30);

        return $this->json($articlesData);
    }

    // coregenionShop_api_order:
    //     path:  /{_locale}/api/holo/order
    //     defaults:
    //         _controller: CoregenionShop\Controller\HoloIntegrationController:getOrder
    //         _locale: '%locale%'
    //     requirements:
    //         _locale: '%app_locales%'
    public function getOrder(
        Request $request,
        OrderingRepository $orderingRepository,
        OrderingArticleRepository $orderingArticleRepository,
        UserRepository $userRepository
    ): JsonResponse {
        if (!$this->checkForHoloAuth()) {
            return $this->json(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $orders = [];

        /** @var ?string */
        $orderId = $request->get('id');
        if (!is_null($orderId)) {
            /** @var ?Ordering */
            $order = $orderingRepository->findOneBy(['id' => $orderId, 'isPaid' => true]);
            if (!is_null($order)) {
                $orders[] = $order;
            }
        } else {
            /** @var Ordering[] */
            $orders = $orderingRepository->findBy(['isPaid' => true], ['id' => 'DESC'], 50); // limit to 50 orders for performance
        }

        $ordersData = [];
        foreach ($orders as $order) {
            $ordersData[] = $this->orderData($order, $orderingArticleRepository, $userRepository);
        }

        return $this->json($ordersData);
    }

    // coregenionShop_api_order_fulfill:
    //     path:  /{_locale}/api/holo/order-fulfill/{id}
    //     defaults:
    //         _controller: CoregenionShop\Controller\HoloIntegrationController:fulfillOrder
    //         _locale: '%locale%'
    //     requirements:
    //         _locale: '%app_locales%'
    //     methods: [POST]
    public function fulfillOrder(
        Ordering $order,
        Request $request,
        OrderingArticleRepository $orderingArticleRepository,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository
    ): JsonResponse {
        if (!$this->checkForHoloAuth()) {
            return $this->json(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $trackingNumber = $data['trackingNumber'] ?? '';
        if (empty($trackingNumber)) {
            return $this->json(['error' => 'Tracking number is required'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $shippingLabelUrl = $data['shippingLabelUrl'] ?? '';
        if (empty($trackingNumber)) {
            return $this->json(['error' => 'No Label URL'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $order->setStatusDate(new \DateTime());
        $order->setOrderStatus(5); // Set order status to fulfilled

        $fileName = $order->getId().'.pdf';
        $content = '';

        try {
            $content = file_get_contents($shippingLabelUrl);
        } catch (\Throwable) {
            // throw $th;
        }
        $file = new Filesystem();
        $file->dumpFile('pdf/shippingLabel/'.strtolower($order->getShop()).'/dhl/'.$fileName, $content);

        if (!is_null($order->getOrderingShipping())) {
            $orderShip = $order->getOrderingShipping();
        } else {
            $orderShip = new OrderingShipping();
            $order->setOrderingShipping($orderShip);
        }

        $orderShip->setFileName($fileName);
        $orderShip->setShipmentCode(0);
        $orderShip->setCodeText('ok');
        $orderShip->setCodeMessage('Der Webservice wurde ohne Fehler ausgeführt.');
        $orderShip->setShipmentNumber($trackingNumber);
        $orderShip->setIsDelete(false);
        $orderShip->setStatusDate(new \DateTime());
        $entityManager->persist($orderShip);
        $entityManager->flush();

        return $this->json([$this->orderData($order, $orderingArticleRepository, $userRepository)]);
    }

    private function checkForHoloAuth(): bool
    {
        /** @var string */
        $bearerToken = $_ENV['HOLO_API_KEY'];

        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            return false;
        }

        $authorization = $headers['Authorization'];

        return $authorization === 'Bearer '.$bearerToken;
    }

    /**
     * @return mixed[]
     */
    private function articleData(
        Article $article,
        string $locale,
        ArticleCombineRepository $articleCombineRepository,
        ArticlePriceRepository $articlePriceRepository,
        ArticleCombineLanguageRepository $articleCombineLanguageRepository,
        ArticleMediaRepository $articleMediaRepository
    ): array {
        $data = [];
        $data['id'] = $article->getId();
        $data['sku'] = $article->getId();

        /** @var ?ArticleLanguage */
        $articleLanguage = $article->getArticleLanguageByKey($locale);
        $data['title'] = $articleLanguage ? $articleLanguage->getTitle() : 'Product '.$article->getId();
        $data['description'] = $articleLanguage ? $articleLanguage->getShortDescription() : null;

        /** @var ?ArticleMedia */
        $media = $articleMediaRepository->findOneBy(['articleId' => $article->getId()]);
        $data['image'] = $media ? $this->assetsUrl.'/img/article/'.$media->getFileName().'.'.$media->getType() : null;

        /** @var ArticleCombine[] */
        $articleCombines = $articleCombineRepository->findBy(['articleId' => $article->getId()]);
        $data['variants'] = [];
        foreach ($articleCombines as $articleCombine) {
            $data['variants'][] = $this->articleCombineData(
                $articleCombine,
                $articleCombineLanguageRepository,
                $articlePriceRepository,
                $articleMediaRepository,
                $data,
                $articleLanguage
            );
        }

        return $data;
    }

    /**
     * @return mixed[]
     */
    private function articleCombineData(
        ArticleCombine $articleCombine,
        ArticleCombineLanguageRepository $articleCombineLanguageRepository,
        ArticlePriceRepository $articlePriceRepository,
        ArticleMediaRepository $articleMediaRepository,
        array $parentData,
        ?ArticleLanguage $articleLanguage = null
    ): array {
        /** @var ?ArticleCombineLanguage */
        $articleCombineLanguage = $articleCombineLanguageRepository->findOneBy(['articleCombine' => $articleCombine, 'articleLanguage' => $articleLanguage]);

        /** @var ?ArticlePrice */
        $articlePrice = $articlePriceRepository->findOneBy(['articleCombine' => $articleCombine, 'type' => 'c', 'qty' => 1]);

        $variantTitle = $articleCombineLanguage ? $articleCombineLanguage->getTitle() : '';
        $variantDescription = $articleCombineLanguage ? $articleCombineLanguage->getShortDescription() : null;

        /** @var ?ArticleMedia */
        $media = $articleMediaRepository->findOneBy(['articleCombineId' => $articleCombine->getId()]);
        $variantImage = $media ? $this->assetsUrl.'/img/article/'.$media->getFileName().'.'.$media->getType() : null;

        return [
            'id' => $articleCombine->getId(),
            'title' => empty($variantTitle) ? $parentData['title'].' - '.$articleCombine->getArticleNumber() : $variantTitle,
            'description' => is_null($variantDescription) ? $parentData['description'] : $variantDescription,
            'image' => $variantImage,
            'sku' => $articleCombine->getArticleNumber(),
            'weight' => $articleCombine->getWeight(),
            'price' => $articlePrice ? $articlePrice->getPrice() / 100 : 0,
        ];
    }

    /**
     * @return mixed[]
     */
    private function orderData(
        Ordering $order,
        OrderingArticleRepository $orderingArticleRepository,
        UserRepository $userRepository
    ): array {
        $data = [];
        $data['id'] = $order->getId();
        $data['orderName'] = $order->getOrderNr();
        $data['fulfilled'] = 5 == $order->getOrderStatus() ? true : false;
        $data['cancelled'] = -1 == $order->getOrderStatus() ? true : false;
        $data['totalPrice'] = $order->getTotal() / 100;
        $data['createdAt'] = $order->getOrderDate()->format('Y-m-d H:i:s');

        $delivery = $order->getDelivery();
        if (is_null($delivery)) {
            $delivery = $order->getBill();
        }

        /** @var ?User */
        $user = $userRepository->find($order->getUserId());

        $shippingData = [];
        if (!is_null($delivery)) {
            $shippingData['email'] = $user->getEmail();
            $shippingData['firstName'] = $delivery->getFirstName();
            $shippingData['lastName'] = $delivery->getLastName();
            $shippingData['companyName'] = $delivery->getFirm();
            $shippingData['street'] = $delivery->getStreet();
            $shippingData['zipCode'] = $delivery->getZipCode();
            $shippingData['city'] = $delivery->getCity();
            $shippingData['countryCode'] = $delivery->getCountry()->getIsoCode();
        }

        $data['shipping'] = $shippingData;

        $orderingArticlesData = [];

        /** @var OrderingArticle[] */
        $orderingArticles = $orderingArticleRepository->findBy(['ordering' => $order]);
        foreach ($orderingArticles as $orderingArticle) {
            $orderingArticlesData[] = [
                'quantity' => $orderingArticle->getQty(),
                'variant' => $orderingArticle->getArticleCombineId(),
            ];
        }

        $data['products'] = $orderingArticlesData;

        return $data;
    }
}
