<?php

namespace App\Service;

use App\Entity\Boutique;
use App\Entity\DailyStock;
use App\Entity\Order;
use App\Entity\OrderItem;
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

            // Fetch categories
            $categories = $this->fetchCategories($boutique);
            $this->logger->info('Categories fetched', ['count' => count($categories)]);

            // Merge products and stocks
            $mergedData = $this->mergeProductsAndStocks($products, $stocks, $categories);
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
                'display' => '[id,reference,name,id_category_default]',
                'output_format' => 'JSON'
            ]
        ]);

        $data = $response->toArray();

        // Handle PrestaShop API response format
        if (isset($data['products'])) {
            $products = is_array($data['products']) ? $data['products'] : [$data['products']];

            return $products;
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
     * Fetch categories from PrestaShop API
     */
    private function fetchCategories(Boutique $boutique): array
    {
        try {
            $url = rtrim($boutique->getDomain(), '/') . '/api/categories';

            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'display' => '[id,name]',
                    'output_format' => 'JSON'
                ]
            ]);

            $data = $response->toArray();

            // Handle PrestaShop API response format
            if (isset($data['categories'])) {
                $categories = is_array($data['categories']) ? $data['categories'] : [$data['categories']];

                // Convert to id => name array for easy lookup
                $categoriesById = [];
                foreach ($categories as $category) {
                    $categoryId = $category['id'] ?? null;
                    if (!$categoryId) {
                        continue;
                    }

                    // Handle category name (can be array for multilang or string)
                    $name = $category['name'] ?? 'Uncategorized';
                    if (is_array($name)) {
                        $nameValues = array_values($name);
                        $name = !empty($nameValues[0]) ? $nameValues[0] : 'Uncategorized';
                        if (is_array($name) && isset($name['value'])) {
                            $name = $name['value'];
                        }
                    }

                    $categoriesById[$categoryId] = (string) $name;
                }

                return $categoriesById;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch categories', [
                'boutique_id' => $boutique->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Merge products and stocks by id_product
     */
    private function mergeProductsAndStocks(array $products, array $stocks, array $categories = []): array
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

            // Lookup category
            $categoryId = $product['id_category_default'] ?? null;
            $category = null;
            if ($categoryId) {
                // Convert to integer for lookup (PrestaShop API returns it as string)
                $categoryIdInt = (int) $categoryId;
                if (isset($categories[$categoryIdInt])) {
                    $category = $categories[$categoryIdInt];
                }
            }

            $merged[] = [
                'id' => $productId,
                'reference' => $reference,
                'name' => (string) $name,
                'quantity' => (int) $quantity,
                'category' => $category
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
            $dailyStock->setCategory($item['category'] ?? null);
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
            'days' => $days === 0 ? 'all' : $days
        ]);

        try {
            // Calculate date range
            $endDate = new \DateTimeImmutable();
            // If days is 0, collect all orders (start from year 2000)
            $startDate = $days === 0
                ? new \DateTimeImmutable('2000-01-01')
                : $endDate->modify("-{$days} days");

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
     * Fetch orders from PrestaShop API (optimized version)
     */
    private function fetchOrders(Boutique $boutique, \DateTimeImmutable $startDate): array
    {
        $url = rtrim($boutique->getDomain(), '/') . '/api/orders';

        $this->logger->info('Fetching all order IDs with date filter');

        try {
            // Step 1: Get all order IDs with date filter (fast!)
            $dateFilter = $startDate->format('Y-m-d');
            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON',
                    'display' => '[id]',
                    'filter[date_add]' => '[' . $dateFilter . ',]',  // From startDate to now
                    'sort' => '[id_DESC]'
                ],
                'timeout' => 30
            ]);

            $data = $response->toArray();

            if (!isset($data['orders']) || empty($data['orders'])) {
                $this->logger->info('No orders found with date filter');
                return [];
            }

            $orderIds = array_column($data['orders'], 'id');
            $this->logger->info('Found order IDs', ['count' => count($orderIds)]);

            // Step 2: Fetch full order data in batches of 50
            $orders = [];
            $batchSize = 50;
            $batches = array_chunk($orderIds, $batchSize);

            foreach ($batches as $batchIndex => $batchIds) {
                $this->logger->info('Fetching batch', [
                    'batch' => $batchIndex + 1,
                    'total_batches' => count($batches),
                    'ids_in_batch' => count($batchIds)
                ]);

                foreach ($batchIds as $orderId) {
                    try {
                        $orderUrl = rtrim($boutique->getDomain(), '/') . "/api/orders/{$orderId}";
                        $orderResponse = $this->httpClient->request('GET', $orderUrl, [
                            'auth_basic' => [$boutique->getApiKey(), ''],
                            'query' => [
                                'output_format' => 'JSON'
                            ],
                            'timeout' => 10
                        ]);

                        $orderData = $orderResponse->toArray();

                        if (isset($orderData['order'])) {
                            $orders[] = $orderData['order'];
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to fetch order', [
                            'order_id' => $orderId,
                            'error' => $e->getMessage()
                        ]);
                        continue;
                    }
                }

                // Small delay between batches to avoid overwhelming the API
                usleep(100000); // 100ms
            }

            return $orders;

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch orders with optimized method, falling back', [
                'error' => $e->getMessage()
            ]);

            // Fallback to old method
            return $this->fetchOrdersLegacy($boutique, $startDate);
        }
    }

    /**
     * Legacy method: fetch orders by scanning IDs (slow but reliable)
     */
    private function fetchOrdersLegacy(Boutique $boutique, \DateTimeImmutable $startDate): array
    {
        $maxOrderId = $this->findMaxOrderId($boutique);

        if (!$maxOrderId) {
            $this->logger->warning('Could not determine max order ID');
            return [];
        }

        $this->logger->info('Using legacy method - found max order ID', ['max_id' => $maxOrderId]);

        $daysToFetch = $startDate->diff(new \DateTimeImmutable())->days;

        if ($daysToFetch > 3650) {
            // Collecting all orders - scan from ID 1
            $minOrderId = 1;
            $this->logger->warning('Collecting all orders - this will take time!');
        } elseif ($daysToFetch > 365) {
            // For more than 1 year, estimate range
            $estimatedOrders = $daysToFetch * 100;
            $minOrderId = max(1, $maxOrderId - $estimatedOrders);
            $this->logger->info('Estimated orders to scan', ['estimated' => $estimatedOrders]);
        } else {
            $estimatedOrders = max(1000, $daysToFetch * 100);
            $minOrderId = max(1, $maxOrderId - $estimatedOrders);
        }

        $this->logger->info('Fetching orders in range', [
            'min' => $minOrderId,
            'max' => $maxOrderId
        ]);

        $orders = [];
        $batchSize = 50; // Process 50 orders concurrently
        $shouldStop = false;

        for ($orderId = $maxOrderId; $orderId >= $minOrderId && !$shouldStop; $orderId -= $batchSize) {
            $batchStart = max($minOrderId, $orderId - $batchSize + 1);
            $batchEnd = $orderId;

            $this->logger->info('Processing batch', [
                'batch_start' => $batchStart,
                'batch_end' => $batchEnd
            ]);

            // Create concurrent requests for this batch
            $responses = [];
            for ($id = $batchEnd; $id >= $batchStart; $id--) {
                $orderUrl = rtrim($boutique->getDomain(), '/') . "/api/orders/{$id}";
                try {
                    $responses[$id] = $this->httpClient->request('GET', $orderUrl, [
                        'auth_basic' => [$boutique->getApiKey(), ''],
                        'query' => ['output_format' => 'JSON'],
                        'timeout' => 10
                    ]);
                } catch (\Exception $e) {
                    // Skip this order ID
                    continue;
                }
            }

            // Process responses
            foreach ($responses as $id => $response) {
                try {
                    $orderData = $response->toArray();

                    if (isset($orderData['order'])) {
                        $order = $orderData['order'];

                        if (isset($order['date_add'])) {
                            $orderDate = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $order['date_add']);

                            if ($orderDate && $orderDate >= $startDate) {
                                $orders[] = $order;
                            }
                            // Note: We don't stop even if we find older orders,
                            // because IDs might not be chronological
                        }
                    }
                } catch (\Exception $e) {
                    // Skip this order
                    continue;
                }
            }

            $this->logger->info('Batch complete', [
                'orders_found' => count($orders)
            ]);

            // Save progress every 50 orders to avoid memory issues
            if (count($orders) > 0 && count($orders) % 50 === 0) {
                $this->logger->info('Saving batch of orders', ['count' => count($orders)]);
                $this->saveOrdersData($boutique, $orders);
                $orders = []; // Clear to free memory
                gc_collect_cycles(); // Force garbage collection
            }
        }

        return $orders;
    }

    /**
     * Find the maximum order ID by binary search
     */
    private function findMaxOrderId(Boutique $boutique): ?int
    {
        $url = rtrim($boutique->getDomain(), '/') . '/api/orders';

        // Try to get rough count first
        try {
            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON',
                    'limit' => 1
                ],
                'timeout' => 10
            ]);

            // Try descending sort to get max ID
            $sortResponse = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON',
                    'limit' => 1,
                    'sort' => '[id_DESC]'
                ],
                'timeout' => 10
            ]);

            $data = $sortResponse->toArray();
            if (isset($data['orders'][0]['id'])) {
                return (int) $data['orders'][0]['id'];
            }
        } catch (\Exception $e) {
            // Sort might not work, use alternative method
        }

        // Fallback: try high numbers
        $testIds = [200000, 150000, 100000, 50000, 10000, 5000, 1000];

        foreach ($testIds as $testId) {
            try {
                $testUrl = rtrim($boutique->getDomain(), '/') . "/api/orders/{$testId}";
                $this->httpClient->request('GET', $testUrl, [
                    'auth_basic' => [$boutique->getApiKey(), ''],
                    'query' => ['output_format' => 'JSON'],
                    'timeout' => 5
                ]);

                // If this ID exists, search forward to find the max
                return $this->searchForwardForMax($boutique, $testId);
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Search forward from a known ID to find the maximum
     */
    private function searchForwardForMax(Boutique $boutique, int $startId): int
    {
        $currentMax = $startId;
        $step = 10000;

        // Quick search forward with large steps
        while ($step >= 100) {
            $testId = $currentMax + $step;

            try {
                $testUrl = rtrim($boutique->getDomain(), '/') . "/api/orders/{$testId}";
                $this->httpClient->request('GET', $testUrl, [
                    'auth_basic' => [$boutique->getApiKey(), ''],
                    'query' => ['output_format' => 'JSON'],
                    'timeout' => 5
                ]);

                $currentMax = $testId;
            } catch (\Exception $e) {
                // This ID doesn't exist, reduce step
                $step = (int) ($step / 2);
            }
        }

        return $currentMax;
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

            // Skip existing orders that already have complete data
            if ($existingOrder && !$order->getItems()->isEmpty() && $order->getCustomerName()) {
                // Order already has items and customer data, skip it to save time
                continue;
            }

            // Fetch order details to get products and customer info
            if (!$existingOrder || $order->getItems()->isEmpty()) {
                $this->fetchAndSaveOrderItems($boutique, $order, $orderData['id']);
            } else {
                // For existing orders with items, just update customer info
                $this->fetchAndSaveCustomerData($boutique, $order, $orderData['id']);
            }

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

    /**
     * Fetch order details and save order items (products)
     */
    private function fetchAndSaveOrderItems(Boutique $boutique, Order $order, int $orderId): void
    {
        try {
            $url = rtrim($boutique->getDomain(), '/') . "/api/orders/{$orderId}";

            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON'
                ],
                'timeout' => 10
            ]);

            $data = $response->toArray();
            $orderData = $data['order'] ?? [];

            // Fetch customer information
            if (isset($orderData['id_customer'])) {
                $this->fetchAndSaveCustomerInfo($boutique, $order, (int) $orderData['id_customer']);
            }

            // Fetch delivery address
            if (isset($orderData['id_address_delivery'])) {
                $this->fetchAndSaveDeliveryAddress($boutique, $order, (int) $orderData['id_address_delivery']);
            }

            if (isset($orderData['associations']['order_rows'])) {
                $orderRows = $orderData['associations']['order_rows'];

                // Handle single product case (not an array of arrays)
                if (isset($orderRows['id']) && !isset($orderRows[0])) {
                    $orderRows = [$orderRows];
                }

                foreach ($orderRows as $row) {
                    $orderItem = new OrderItem();
                    $orderItem->setOrder($order);
                    $orderItem->setProductId((int) ($row['product_id'] ?? 0));
                    $orderItem->setProductName($row['product_name'] ?? 'Produit inconnu');
                    $orderItem->setProductReference($row['product_reference'] ?? null);
                    $orderItem->setQuantity((int) ($row['product_quantity'] ?? 1));
                    $orderItem->setUnitPrice($row['product_price'] ?? '0');
                    $orderItem->setTotalPrice($row['total_price_tax_incl'] ?? $row['product_price'] ?? '0');

                    // Fetch and set wholesale price for margin calculation
                    $productId = (int) ($row['product_id'] ?? 0);
                    if ($productId > 0) {
                        $wholesalePrice = $this->fetchProductWholesalePrice($boutique, $productId);
                        if ($wholesalePrice !== null) {
                            $orderItem->setWholesalePrice($wholesalePrice);
                        }
                    }

                    $order->addItem($orderItem);
                    $this->entityManager->persist($orderItem);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch order items', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Fetch product wholesale price from PrestaShop API
     */
    private function fetchProductWholesalePrice(Boutique $boutique, int $productId): ?string
    {
        try {
            $url = rtrim($boutique->getDomain(), '/') . "/api/products/{$productId}";

            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON',
                    'display' => '[wholesale_price]'
                ],
                'timeout' => 5
            ]);

            $data = $response->toArray();
            $product = $data['product'] ?? null;

            if ($product && isset($product['wholesale_price'])) {
                $wholesalePrice = $product['wholesale_price'];
                // Handle both string and numeric values
                if (is_numeric($wholesalePrice) && (float) $wholesalePrice > 0) {
                    return (string) $wholesalePrice;
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->debug('Could not fetch wholesale price for product', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function fetchAndSaveCustomerData(Boutique $boutique, Order $order, int $orderId): void
    {
        // Note: This method currently only attempts to fetch customer data if the PrestaShop API
        // key has permissions for customers and addresses resources. Many PrestaShop configurations
        // don't grant these permissions by default. Customer data will be populated if available.
        try {
            $url = rtrim($boutique->getDomain(), '/') . "/api/orders/{$orderId}";

            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON'
                ],
                'timeout' => 10
            ]);

            $data = $response->toArray();
            $orderData = $data['order'] ?? [];

            // Try to fetch customer information if we have customer ID
            if (isset($orderData['id_customer']) && !empty($orderData['id_customer'])) {
                $this->fetchAndSaveCustomerInfo($boutique, $order, (int) $orderData['id_customer']);
            }

            // Try to fetch delivery address if we have address ID
            if (isset($orderData['id_address_delivery']) && !empty($orderData['id_address_delivery'])) {
                $this->fetchAndSaveDeliveryAddress($boutique, $order, (int) $orderData['id_address_delivery']);
            }
        } catch (\Exception $e) {
            // Silently fail - customer data is optional
            $this->logger->debug('Could not fetch customer data', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function fetchAndSaveCustomerInfo(Boutique $boutique, Order $order, int $customerId): void
    {
        try {
            $url = rtrim($boutique->getDomain(), '/') . "/api/customers/{$customerId}";

            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON'
                ],
                'timeout' => 10
            ]);

            $data = $response->toArray();
            $customer = $data['customer'] ?? [];

            if ($customer) {
                $firstName = $customer['firstname'] ?? '';
                $lastName = $customer['lastname'] ?? '';
                $order->setCustomerName(trim($firstName . ' ' . $lastName));
                $order->setCustomerEmail($customer['email'] ?? null);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not fetch customer info', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function fetchAndSaveDeliveryAddress(Boutique $boutique, Order $order, int $addressId): void
    {
        try {
            $url = rtrim($boutique->getDomain(), '/') . "/api/addresses/{$addressId}";

            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON'
                ],
                'timeout' => 10
            ]);

            $data = $response->toArray();
            $address = $data['address'] ?? [];

            if ($address) {
                $addressParts = [];
                if (!empty($address['address1'])) {
                    $addressParts[] = $address['address1'];
                }
                if (!empty($address['address2'])) {
                    $addressParts[] = $address['address2'];
                }

                $order->setDeliveryAddress(implode(', ', $addressParts) ?: null);
                $order->setDeliveryPostcode($address['postcode'] ?? null);
                $order->setDeliveryCity($address['city'] ?? null);
                $order->setDeliveryCountry($address['country'] ?? null);
                $order->setCustomerPhone($address['phone'] ?? $address['phone_mobile'] ?? null);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not fetch delivery address', [
                'address_id' => $addressId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Collect orders for a specific ID range (chunked collection)
     * This method is memory-safe and processes only a limited range of IDs
     */
    public function collectOrdersChunk(Boutique $boutique, int $startId, int $endId): array
    {
        $this->logger->info('Starting chunked orders collection', [
            'boutique_id' => $boutique->getId(),
            'start_id' => $startId,
            'end_id' => $endId,
            'range_size' => $endId - $startId + 1
        ]);

        try {
            $orders = [];
            $batchSize = 50; // Process 50 orders concurrently

            for ($orderId = $endId; $orderId >= $startId; $orderId -= $batchSize) {
                $batchStart = max($startId, $orderId - $batchSize + 1);
                $batchEnd = $orderId;

                $this->logger->debug('Processing ID batch', [
                    'batch_start' => $batchStart,
                    'batch_end' => $batchEnd
                ]);

                // Create concurrent requests for this batch
                $responses = [];
                for ($id = $batchEnd; $id >= $batchStart; $id--) {
                    $orderUrl = rtrim($boutique->getDomain(), '/') . "/api/orders/{$id}";
                    try {
                        $responses[$id] = $this->httpClient->request('GET', $orderUrl, [
                            'auth_basic' => [$boutique->getApiKey(), ''],
                            'query' => ['output_format' => 'JSON'],
                            'timeout' => 10
                        ]);
                    } catch (\Exception $e) {
                        continue;
                    }
                }

                // Process responses
                foreach ($responses as $id => $response) {
                    try {
                        $orderData = $response->toArray();
                        if (isset($orderData['order'])) {
                            $orders[] = $orderData['order'];
                        }
                    } catch (\Exception $e) {
                        // Order ID doesn't exist or error, skip it
                        continue;
                    }
                }

                // Save every 50 orders to free memory
                if (count($orders) >= 50) {
                    $this->logger->debug('Saving intermediate batch', ['count' => count($orders)]);
                    $this->saveOrdersData($boutique, $orders);
                    $orders = [];
                    gc_collect_cycles(); // Force garbage collection
                }
            }

            // Save remaining orders
            $savedCount = 0;
            if (count($orders) > 0) {
                $savedCount = $this->saveOrdersData($boutique, $orders);
            }

            $this->logger->info('Chunk collection completed', [
                'boutique_id' => $boutique->getId(),
                'start_id' => $startId,
                'end_id' => $endId,
                'saved_count' => $savedCount
            ]);

            return [
                'success' => true,
                'orders_found' => count($orders),
                'saved_count' => $savedCount
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error collecting orders chunk', [
                'boutique_id' => $boutique->getId(),
                'start_id' => $startId,
                'end_id' => $endId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch stock movements from PrestaShop API
     * Returns stock movements with date, quantity, sign, and reason
     */
    public function fetchStockMovements(Boutique $boutique, ?\DateTimeImmutable $startDate = null): array
    {
        $this->logger->info('Fetching stock movements for boutique', [
            'boutique_id' => $boutique->getId(),
            'start_date' => $startDate?->format('Y-m-d')
        ]);

        try {
            $url = rtrim($boutique->getDomain(), '/') . '/api/stock_movements';

            $queryParams = [
                'display' => '[id,id_product,id_product_attribute,physical_quantity,sign,date_add,id_stock_mvt_reason,product_name,reference]',
                'output_format' => 'JSON',
                'sort' => '[date_add_DESC]'
            ];

            // Add date filter if provided
            if ($startDate) {
                $queryParams['filter[date_add]'] = '[' . $startDate->format('Y-m-d') . ',]';
            }

            $response = $this->httpClient->request('GET', $url, [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => $queryParams,
                'timeout' => 30
            ]);

            $data = $response->toArray();

            if (isset($data['stock_movements'])) {
                $movements = is_array($data['stock_movements']) ? $data['stock_movements'] : [$data['stock_movements']];
                $this->logger->info('Stock movements fetched successfully', ['count' => count($movements)]);
                return $movements;
            }

            $this->logger->warning('No stock_movements key in API response');
            return [];

        } catch (\Exception $e) {
            $this->logger->error('Error fetching stock movements', [
                'boutique_id' => $boutique->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return empty array instead of throwing to allow graceful degradation
            return [];
        }
    }
}
