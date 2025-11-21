<?php

namespace App\Service;

use App\Entity\Boutique;
use App\Entity\DailyStock;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PrestaShopCollector
{
    private HttpClientInterface $httpClient;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $httpClient,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    /**
     * Collect stock data from PrestaShop API and store in database
     */
    public function collectStockData(Boutique $boutique): array
    {
        $this->logger->info('Starting stock collection for boutique', [
            'boutique_id' => $boutique->getId(),
            'boutique_name' => $boutique->getName(),
            'domain' => $boutique->getDomain()
        ]);

        try {
            // Fetch products
            $products = $this->fetchProducts($boutique);
            $this->logger->info('Products fetched', ['count' => count($products)]);

            // Fetch stocks
            $stocks = $this->fetchStocks($boutique);
            $this->logger->info('Stocks fetched', ['count' => count($stocks)]);

            // Merge products and stocks
            $mergedData = $this->mergeProductsAndStocks($products, $stocks);
            $this->logger->info('Data merged', ['count' => count($mergedData)]);

            // Save to database
            $savedCount = $this->saveStockData($boutique, $mergedData);
            $this->logger->info('Stock data saved', ['count' => $savedCount]);

            return [
                'success' => true,
                'products_count' => count($products),
                'stocks_count' => count($stocks),
                'saved_count' => $savedCount
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error collecting stock data', [
                'boutique_id' => $boutique->getId(),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch products from PrestaShop API
     */
    private function fetchProducts(Boutique $boutique): array
    {
        $url = rtrim($boutique->getDomain(), '/') . '/api/products';

        $response = $this->httpClient->request('GET', $url, [
            'auth_basic' => [$boutique->getApiKey(), ''],
            'query' => [
                'display' => '[id,reference,name]',
                'output_format' => 'JSON'
            ]
        ]);

        $data = $response->toArray();

        // Handle PrestaShop API response format
        if (isset($data['products'])) {
            return is_array($data['products']) ? $data['products'] : [$data['products']];
        }

        return [];
    }

    /**
     * Fetch stock availables from PrestaShop API
     */
    private function fetchStocks(Boutique $boutique): array
    {
        $url = rtrim($boutique->getDomain(), '/') . '/api/stock_availables';

        $response = $this->httpClient->request('GET', $url, [
            'auth_basic' => [$boutique->getApiKey(), ''],
            'query' => [
                'display' => '[id,id_product,quantity]',
                'output_format' => 'JSON'
            ]
        ]);

        $data = $response->toArray();

        // Handle PrestaShop API response format
        if (isset($data['stock_availables'])) {
            return is_array($data['stock_availables']) ? $data['stock_availables'] : [$data['stock_availables']];
        }

        return [];
    }

    /**
     * Merge products and stocks by id_product
     */
    private function mergeProductsAndStocks(array $products, array $stocks): array
    {
        $stocksByProduct = [];
        foreach ($stocks as $stock) {
            $productId = $stock['id_product'] ?? null;
            if ($productId) {
                $stocksByProduct[$productId] = $stock;
            }
        }

        $merged = [];
        foreach ($products as $product) {
            $productId = $product['id'] ?? null;
            if (!$productId) {
                continue;
            }

            $stock = $stocksByProduct[$productId] ?? null;
            $quantity = $stock['quantity'] ?? 0;

            // Handle product name (can be array for multilang or string)
            $name = $product['name'] ?? 'Unknown';
            if (is_array($name)) {
                // PrestaShop multilang format: array with language IDs as keys
                // Extract the value (not the key)
                $nameValues = array_values($name);
                $name = !empty($nameValues[0]) ? $nameValues[0] : 'Unknown';

                // If still an array (nested structure), try to get the value
                if (is_array($name) && isset($name['value'])) {
                    $name = $name['value'];
                }
            }

            // Handle product reference (can be array or string)
            $reference = $product['reference'] ?? null;
            if (is_array($reference)) {
                $referenceValues = array_values($reference);
                $reference = !empty($referenceValues[0]) ? $referenceValues[0] : null;
            }

            $merged[] = [
                'id' => $productId,
                'reference' => $reference,
                'name' => (string) $name,
                'quantity' => (int) $quantity
            ];
        }

        return $merged;
    }

    /**
     * Save stock data to database
     */
    private function saveStockData(Boutique $boutique, array $data): int
    {
        $collectedAt = new \DateTimeImmutable();
        $count = 0;

        foreach ($data as $item) {
            $dailyStock = new DailyStock();
            $dailyStock->setBoutique($boutique);
            $dailyStock->setProductId($item['id']);
            $dailyStock->setReference($item['reference']);
            $dailyStock->setName($item['name']);
            $dailyStock->setQuantity($item['quantity']);
            $dailyStock->setCollectedAt($collectedAt);

            $this->entityManager->persist($dailyStock);
            $count++;

            // Flush every 100 records to avoid memory issues
            if ($count % 100 === 0) {
                $this->entityManager->flush();
            }
        }

        // Flush remaining records
        $this->entityManager->flush();

        return $count;
    }

    /**
     * Collect visual branding data (logo, favicon, theme color)
     */
    public function collectBrandingData(Boutique $boutique): array
    {
        $this->logger->info('Starting branding data collection for boutique', [
            'boutique_id' => $boutique->getId(),
        ]);

        try {
            // Fetch shop configuration
            $shopData = $this->fetchShopConfiguration($boutique);

            if ($shopData) {
                // Extract logo and other visual elements
                if (isset($shopData['logo'])) {
                    $boutique->setLogoUrl($shopData['logo']);
                }
                if (isset($shopData['favicon'])) {
                    $boutique->setFaviconUrl($shopData['favicon']);
                }
                if (isset($shopData['theme_color'])) {
                    $boutique->setThemeColor($shopData['theme_color']);
                }

                $boutique->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();

                return [
                    'success' => true,
                    'data' => $shopData
                ];
            }

            return [
                'success' => false,
                'error' => 'No shop data found'
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error collecting branding data', [
                'boutique_id' => $boutique->getId(),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch shop configuration from PrestaShop API
     */
    private function fetchShopConfiguration(Boutique $boutique): ?array
    {
        $url = rtrim($boutique->getDomain(), '/') . '/api/shops';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON'
                ]
            ]);

            $data = $response->toArray();

            if (isset($data['shops']) && is_array($data['shops'])) {
                $shop = reset($data['shops']);
                return [
                    'logo' => $shop['logo'] ?? null,
                    'favicon' => $shop['favicon'] ?? null,
                    'theme_color' => $shop['theme_color'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch shop configuration', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Collect orders data from PrestaShop API
     */
    public function collectOrdersData(Boutique $boutique, int $days = 30): array
    {
        $this->logger->info('Starting orders collection for boutique', [
            'boutique_id' => $boutique->getId(),
            'boutique_name' => $boutique->getName(),
            'days' => $days
        ]);

        try {
            // Calculate date range
            $endDate = new \DateTimeImmutable();
            $startDate = $endDate->modify("-{$days} days");

            // Fetch orders
            $orders = $this->fetchOrders($boutique, $startDate);
            $this->logger->info('Orders fetched', ['count' => count($orders)]);

            // Save to database
            $savedCount = $this->saveOrdersData($boutique, $orders);
            $this->logger->info('Orders data saved', ['count' => $savedCount]);

            return [
                'success' => true,
                'orders_count' => count($orders),
                'saved_count' => $savedCount
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error collecting orders data', [
                'boutique_id' => $boutique->getId(),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch orders from PrestaShop API
     */
    private function fetchOrders(Boutique $boutique, \DateTimeImmutable $startDate): array
    {
        $url = rtrim($boutique->getDomain(), '/') . '/api/orders';

        $response = $this->httpClient->request('GET', $url, [
            'auth_basic' => [$boutique->getApiKey(), ''],
            'query' => [
                'display' => '[id,reference,total_paid,current_state,payment,date_add]',
                'date' => '1',
                'filter[date_add]' => '[' . $startDate->format('Y-m-d') . ',]',
                'output_format' => 'JSON'
            ]
        ]);

        $data = $response->toArray();

        // Handle PrestaShop API response format
        if (isset($data['orders'])) {
            return is_array($data['orders']) ? $data['orders'] : [$data['orders']];
        }

        return [];
    }

    /**
     * Save orders data to database
     */
    private function saveOrdersData(Boutique $boutique, array $orders): int
    {
        $collectedAt = new \DateTimeImmutable();
        $count = 0;

        foreach ($orders as $orderData) {
            // Check if order already exists
            $existingOrder = $this->entityManager->getRepository(Order::class)
                ->findOneBy([
                    'boutique' => $boutique,
                    'orderId' => $orderData['id']
                ]);

            if ($existingOrder) {
                // Update existing order
                $order = $existingOrder;
            } else {
                // Create new order
                $order = new Order();
                $order->setBoutique($boutique);
                $order->setOrderId($orderData['id']);
            }

            // Set order data
            $order->setReference($orderData['reference'] ?? null);
            $order->setTotalPaid($orderData['total_paid'] ?? '0');
            $order->setCurrentState($orderData['current_state'] ?? 'unknown');
            $order->setPayment($orderData['payment'] ?? null);

            // Parse order date
            $orderDate = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $orderData['date_add']);
            if ($orderDate === false) {
                $orderDate = new \DateTimeImmutable();
            }
            $order->setOrderDate($orderDate);
            $order->setCollectedAt($collectedAt);

            $this->entityManager->persist($order);
            $count++;

            // Flush every 50 records to avoid memory issues
            if ($count % 50 === 0) {
                $this->entityManager->flush();
            }
        }

        // Flush remaining records
        $this->entityManager->flush();

        return $count;
    }
}
