<?php

namespace App\Controller;

use App\Repository\BoutiqueRepository;
use App\Repository\OrderRepository;
use App\Service\BoutiqueAuthorizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class OrderController extends AbstractController
{
    #[Route('/boutique/{id}/orders', name: 'app_boutique_orders')]
    public function index(
        int $id,
        Request $request,
        BoutiqueRepository $boutiqueRepository,
        OrderRepository $orderRepository,
        BoutiqueAuthorizationService $authService
    ): Response {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        // Pagination
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Check if reset is requested
        $reset = $request->query->get('reset');

        // Get filters from query or cookies (query takes priority)
        // If reset is requested, ignore cookies and use defaults
        $period = $request->query->get('period', $reset ? '' : $request->cookies->get('orders_period', ''));
        $search = $request->query->get('search', ''); // No cookie for search
        $status = $request->query->get('status', $reset ? '' : $request->cookies->get('orders_status', ''));
        $startDate = $request->query->get('start_date', $reset ? '' : $request->cookies->get('orders_start_date', ''));
        $endDate = $request->query->get('end_date', $reset ? '' : $request->cookies->get('orders_end_date', ''));

        // Get sorting parameters from query or cookies
        $allowedSortFields = ['date', 'reference', 'amount', 'margin', 'order_id'];
        $sort = $request->query->get('sort', $reset ? 'date' : $request->cookies->get('orders_sort', 'date'));
        $order = $request->query->get('order', $reset ? 'desc' : $request->cookies->get('orders_order', 'desc'));

        // Validate sort field
        if (!in_array($sort, $allowedSortFields)) {
            $sort = 'date';
        }

        // Validate order
        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'desc';
        }

        // If period is set, calculate start and end dates
        if ($period && in_array($period, ['7', '30', '90']) && !$startDate && !$endDate) {
            $endDate = (new \DateTime())->format('Y-m-d');
            $startDate = (new \DateTime())->modify("-{$period} days")->format('Y-m-d');
        }

        // Get orders
        $orders = $orderRepository->findByBoutiqueWithFilters(
            $boutique->getId(),
            $search,
            $status,
            $startDate,
            $endDate,
            $limit,
            $offset,
            $sort,
            $order
        );

        $totalOrders = $orderRepository->countByBoutiqueWithFilters(
            $boutique->getId(),
            $search,
            $status,
            $startDate,
            $endDate
        );

        $totalPages = ceil($totalOrders / $limit);

        // Calculate total revenue with filters (optimized - one SQL query)
        $totalRevenue = $orderRepository->getTotalRevenueWithFilters(
            $boutique->getId(),
            $search,
            $status,
            $startDate,
            $endDate
        );

        $response = $this->render('orders/index.html.twig', [
            'boutique' => $boutique,
            'orders' => $orders,
            'totalOrders' => $totalOrders,
            'totalRevenue' => $totalRevenue,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'search' => $search,
            'status' => $status,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'period' => $period,
            'sort' => $sort,
            'order' => $order,
        ]);

        // Handle cookies
        $cookieExpiry = time() + (365 * 24 * 60 * 60); // 1 year
        $cookiePath = '/';

        // If reset is requested, clear all filter cookies
        if ($reset) {
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('orders_period', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('orders_status', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('orders_start_date', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('orders_end_date', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('orders_sort', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('orders_order', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
        } else {
            // Save filters in cookies if they were explicitly set in query parameters
            if ($request->query->has('period')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('orders_period', $period, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            // Search is NOT saved in cookies
            if ($request->query->has('status')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('orders_status', $status, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('start_date')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('orders_start_date', $startDate, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('end_date')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('orders_end_date', $endDate, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('sort')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('orders_sort', $sort, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('order')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('orders_order', $order, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
        }

        // Cache for 30 seconds
        $response->setSharedMaxAge(30);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    #[Route('/boutique/{id}/analytics', name: 'app_boutique_analytics')]
    public function analytics(
        int $id,
        Request $request,
        BoutiqueRepository $boutiqueRepository,
        OrderRepository $orderRepository,
        BoutiqueAuthorizationService $authService
    ): Response {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        // Check if reset is requested
        $reset = $request->query->get('reset');

        // Get period from query or cookie (default: 30 days)
        $period = $request->query->get('period', $reset ? '30' : $request->cookies->get('analytics_period', '30'));

        // Get custom dates from query or cookies
        $startDateStr = $request->query->get('startDate', $reset ? '' : $request->cookies->get('analytics_start_date', ''));
        $endDateStr = $request->query->get('endDate', $reset ? '' : $request->cookies->get('analytics_end_date', ''));

        // Get sorting parameters from query or cookies
        $allowedSortFields = ['quantity', 'orderCount', 'revenue', 'avgPrice'];
        $sort = $request->query->get('sort', $reset ? 'quantity' : $request->cookies->get('analytics_sort', 'quantity'));
        $order = $request->query->get('order', $reset ? 'desc' : $request->cookies->get('analytics_order', 'desc'));

        // Validate sort field
        if (!in_array($sort, $allowedSortFields)) {
            $sort = 'quantity';
        }

        // Validate order
        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'desc';
        }

        // Calculate start and end dates
        if ($startDateStr && $endDateStr) {
            // Use custom dates if provided
            $startDate = new \DateTime($startDateStr);
            $endDate = new \DateTime($endDateStr);
            $period = 'custom';
        } else {
            // Use period
            $endDate = new \DateTime();
            $startDate = (clone $endDate)->modify("-{$period} days");
        }

        // Get top selling products
        $topProducts = $orderRepository->getTopSellingProducts(
            $boutique->getId(),
            $startDate,
            $endDate,
            100, // Top 100
            $sort,
            $order
        );

        $response = $this->render('analytics/index.html.twig', [
            'boutique' => $boutique,
            'topProducts' => $topProducts,
            'period' => $period,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startDateStr' => $startDateStr,
            'endDateStr' => $endDateStr,
            'sort' => $sort,
            'order' => $order,
        ]);

        // Handle cookies
        $cookieExpiry = time() + (365 * 24 * 60 * 60); // 1 year
        $cookiePath = '/';

        // If reset is requested, clear all cookies
        if ($reset) {
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('analytics_period', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('analytics_start_date', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('analytics_end_date', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('analytics_sort', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('analytics_order', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
        } else {
            // Save cookies if explicitly set in query parameters
            if ($request->query->has('period')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('analytics_period', $period, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('startDate')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('analytics_start_date', $startDateStr, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('endDate')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('analytics_end_date', $endDateStr, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('sort')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('analytics_sort', $sort, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('order')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('analytics_order', $order, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
        }

        // Cache for 5 minutes
        $response->setSharedMaxAge(300);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    #[Route('/boutique/{boutiqueId}/orders/{orderId}', name: 'app_order_detail')]
    public function detail(
        int $boutiqueId,
        int $orderId,
        BoutiqueRepository $boutiqueRepository,
        OrderRepository $orderRepository,
        BoutiqueAuthorizationService $authService
    ): Response {
        $boutique = $boutiqueRepository->find($boutiqueId);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        $order = $orderRepository->find($orderId);

        if (!$order || $order->getBoutique()->getId() !== $boutique->getId()) {
            throw $this->createNotFoundException('Commande non trouvée');
        }

        $response = $this->render('orders/detail.html.twig', [
            'boutique' => $boutique,
            'order' => $order,
        ]);

        // Cache for 1 minute (orders don't change often)
        $response->setSharedMaxAge(60);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }
}
