<?php

namespace App\Controller;

use App\Entity\Boutique;
use App\Entity\BoutiqueUser;
use App\Repository\BoutiqueRepository;
use App\Repository\DailyStockRepository;
use App\Service\BoutiqueAuthorizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        BoutiqueRepository $boutiqueRepository
    ): Response {
        $user = $this->getUser();

        // Get user's boutiques
        if ($user->isSuperAdmin()) {
            $boutiques = $boutiqueRepository->findAll();
        } else {
            $boutiques = [];
            foreach ($user->getBoutiqueUsers() as $boutiqueUser) {
                $boutiques[] = $boutiqueUser->getBoutique();
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'boutiques' => $boutiques,
        ]);
    }

    #[Route('/boutique/{id}', name: 'app_boutique_dashboard')]
    public function boutiqueDashboard(
        int $id,
        BoutiqueRepository $boutiqueRepository,
        DailyStockRepository $dailyStockRepository,
        BoutiqueAuthorizationService $authService
    ): Response {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        // Get latest collection date once to avoid redundant queries
        $latestDate = $dailyStockRepository->getLatestCollectionDate($boutique);

        // Get stats using optimized SQL queries (passing the date to avoid redundant queries)
        $stats = $dailyStockRepository->getLatestSnapshotStats($boutique, $latestDate);
        $lowStockProducts = $dailyStockRepository->findLowStockProducts($boutique, 10, $latestDate);
        $topSellingProducts = $dailyStockRepository->findTopSellingProducts($boutique, 7, 10);

        $response = $this->render('dashboard/boutique_dashboard.html.twig', [
            'boutique' => $boutique,
            'totalProducts' => $stats['totalProducts'],
            'outOfStock' => $stats['outOfStock'],
            'lowStock' => $stats['lowStock'],
            'lowStockProducts' => $lowStockProducts,
            'topSellingProducts' => $topSellingProducts,
        ]);

        // Cache for 30 seconds to improve navigation speed
        $response->setSharedMaxAge(30);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    #[Route('/boutiques', name: 'app_boutiques')]
    public function boutiques(): Response
    {
        // Redirect to main dashboard (this route is deprecated but kept for backward compatibility)
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/boutiques/new', name: 'app_boutique_new')]
    public function newBoutique(): Response
    {
        return $this->render('dashboard/boutique_new.html.twig');
    }

    #[Route('/boutiques/create', name: 'app_boutique_create', methods: ['POST'])]
    public function createBoutique(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();

        $boutique = new Boutique();
        $boutique->setName($request->request->get('name'));
        $boutique->setDomain($request->request->get('domain'));
        $boutique->setApiKey($request->request->get('api_key'));

        $entityManager->persist($boutique);

        // Make user admin of this boutique
        $boutiqueUser = new BoutiqueUser();
        $boutiqueUser->setBoutique($boutique);
        $boutiqueUser->setUser($user);
        $boutiqueUser->setRole('ADMIN');

        $entityManager->persist($boutiqueUser);
        $entityManager->flush();

        $this->addFlash('success', 'Boutique créée avec succès !');
        return $this->redirectToRoute('app_boutiques');
    }

    #[Route('/boutiques/{id}/stocks', name: 'app_boutique_stocks')]
    public function boutiqueStocks(
        int $id,
        Request $request,
        BoutiqueRepository $boutiqueRepository,
        DailyStockRepository $dailyStockRepository,
        BoutiqueAuthorizationService $authService
    ): Response {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 50;

        // Check if reset is requested
        $reset = $request->query->get('reset');

        // Check if form was submitted (using hidden field)
        $formSubmitted = $request->query->has('submitted');

        // Get filters from query parameters or cookies
        // If form was submitted, use query params (even if empty)
        // If reset is requested, ignore cookies and use defaults
        // Otherwise, use cookies
        if ($formSubmitted || $reset) {
            $filters = $request->query->all('filters');
        } else {
            // Load from cookies
            $filters = json_decode($request->cookies->get('stocks_filters', '[]'), true);
        }

        // Ensure filters is always an array
        if (!is_array($filters)) {
            $filters = [];
        }

        // Validate filters
        $allowedFilters = ['instock', 'low', 'outofstock'];
        $filters = array_filter($filters, fn($f) => in_array($f, $allowedFilters));

        // Get search from query or cookie
        $search = $request->query->get('search');
        if ($search === null && !$reset) {
            $search = $request->cookies->get('stocks_search', '');
        }
        $search = trim($search ?? '');

        $days = max(1, min(90, $request->query->getInt('days', $reset ? 7 : $request->cookies->getInt('stocks_days', 7))));

        // Get category from query or cookie
        $category = $request->query->get('category');
        if ($category === null && !$reset) {
            $category = $request->cookies->get('stocks_category', '');
        }
        $category = $category ?? '';
        $startDate = $request->query->get('startDate', $reset ? '' : $request->cookies->get('stocks_start_date', ''));
        $endDate = $request->query->get('endDate', $reset ? '' : $request->cookies->get('stocks_end_date', ''));
        $showSales = $request->query->getBoolean('showSales', false);
        $showRevenue = $request->query->getBoolean('showRevenue', false);

        // Get exclude out of stock days parameter
        $excludeOutOfStockDays = $request->query->get('excludeOutOfStockDays', $reset ? '' : $request->cookies->get('exclude_out_of_stock_days', ''));
        $excludeOutOfStockDays = $excludeOutOfStockDays !== '' ? max(0, min(365, (int)$excludeOutOfStockDays)) : null;

        // Get sorting parameters from query or cookies
        $allowedSortFields = ['name', 'reference', 'category', 'out_of_stock'];
        $sort = $request->query->get('sort', $reset ? 'name' : $request->cookies->get('stocks_sort', 'name'));
        $order = $request->query->get('order', $reset ? 'asc' : $request->cookies->get('stocks_order', 'asc'));

        // Validate sort field
        if (!in_array($sort, $allowedSortFields)) {
            $sort = 'name';
        }

        // Validate order
        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'asc';
        }

        // Parse and validate date range if provided
        $startDateTime = null;
        $endDateTime = null;
        if ($startDate && $endDate) {
            try {
                $startDateTime = new \DateTimeImmutable($startDate);
                $endDateTime = new \DateTimeImmutable($endDate);

                // Ensure start date is before end date
                if ($startDateTime > $endDateTime) {
                    $startDateTime = null;
                    $endDateTime = null;
                }
            } catch (\Exception $e) {
                // Invalid dates, ignore them
                $startDateTime = null;
                $endDateTime = null;
            }
        }

        // Get available categories (always from latest snapshot to avoid empty list)
        $categories = $dailyStockRepository->getDistinctCategories($boutique);

        // Get stocks with last N days history or date range
        $result = $dailyStockRepository->findStocksWithLast7Days(
            $boutique,
            $page,
            $perPage,
            $filters,
            $search,
            $days,
            $category,
            $startDateTime,
            $endDateTime,
            $showSales,
            $showRevenue,
            $sort,
            $order,
            $excludeOutOfStockDays
        );

        // Get total count from repository result
        $totalItems = $result['total_count'] ?? 0;
        $totalPages = (int) ceil($totalItems / $perPage);

        // Get global statistics using SQL aggregation (not just current page)
        $stats = $dailyStockRepository->getFilteredStockStats($boutique, $filters, $search);

        $response = $this->render('dashboard/stocks.html.twig', [
            'boutique' => $boutique,
            'stocksData' => $result,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'perPage' => $perPage,
            'filters' => $filters,
            'stats' => $stats,
            'search' => $search,
            'days' => $days,
            'categories' => $categories,
            'category' => $category,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'showSales' => $showSales,
            'showRevenue' => $showRevenue,
            'sort' => $sort,
            'order' => $order,
            'excludeOutOfStockDays' => $excludeOutOfStockDays,
        ]);

        // Handle cookies
        $cookieExpiry = time() + (365 * 24 * 60 * 60); // 1 year
        $cookiePath = '/';

        // If reset is requested, clear all filter cookies
        if ($reset) {
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('stocks_filters', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('stocks_search', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('stocks_category', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('stocks_days', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('stocks_start_date', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('stocks_end_date', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('stocks_sort', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('stocks_order', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie('exclude_out_of_stock_days', '', time() - 3600, $cookiePath, null, false, false, false, 'lax')
            );
        } else {
            // Save filters in cookies if form was submitted (even if no filters are selected)
            if ($formSubmitted) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('stocks_filters', json_encode($filters), $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            // Save search in cookie
            if ($request->query->has('search')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('stocks_search', $search, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            // Save category in cookie
            if ($request->query->has('category')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('stocks_category', $category, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('days')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('stocks_days', (string)$days, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('startDate')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('stocks_start_date', $startDate, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('endDate')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('stocks_end_date', $endDate, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('sort')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('stocks_sort', $sort, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('order')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('stocks_order', $order, $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
            if ($request->query->has('excludeOutOfStockDays')) {
                $response->headers->setCookie(
                    new \Symfony\Component\HttpFoundation\Cookie('exclude_out_of_stock_days', (string)($excludeOutOfStockDays ?? ''), $cookieExpiry, $cookiePath, null, false, false, false, 'lax')
                );
            }
        }

        // Cache for 30 seconds
        $response->setSharedMaxAge(30);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }


    private function getCompareDate(string $period): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return match($period) {
            'yesterday' => $now->modify('-1 day'),
            'lastweek' => $now->modify('-7 days'),
            'lastmonth' => $now->modify('-1 month'),
            default => null,
        };
    }
}
