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
        $filter = $request->query->get('filter', 'all');
        $search = trim($request->query->get('search', ''));
        $days = max(1, min(7, $request->query->getInt('days', 7)));

        // Get stocks with last N days history
        $result = $dailyStockRepository->findStocksWithLast7Days(
            $boutique,
            $page,
            $perPage,
            $filter,
            $search,
            $days
        );

        // Get total count from repository result
        $totalItems = $result['total_count'] ?? 0;
        $totalPages = (int) ceil($totalItems / $perPage);

        // Simple stats count (for current page only)
        $stats = [
            'total' => $totalItems,
            'outOfStock' => count(array_filter($result['stocks'], fn($s) => $s['current_quantity'] == 0)),
            'lowStock' => count(array_filter($result['stocks'], fn($s) => $s['current_quantity'] > 0 && $s['current_quantity'] < 10)),
        ];

        $response = $this->render('dashboard/stocks.html.twig', [
            'boutique' => $boutique,
            'stocksData' => $result,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'perPage' => $perPage,
            'filter' => $filter,
            'stats' => $stats,
            'search' => $search,
            'days' => $days,
        ]);

        // Cache for 30 seconds
        $response->setSharedMaxAge(30);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    #[Route('/boutiques/{boutiqueId}/stocks/{productId}/history', name: 'app_product_history')]
    public function productHistory(
        int $boutiqueId,
        int $productId,
        Request $request,
        BoutiqueRepository $boutiqueRepository,
        DailyStockRepository $dailyStockRepository,
        BoutiqueAuthorizationService $authService
    ): Response {
        $boutique = $boutiqueRepository->find($boutiqueId);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        // Check if custom date range is provided
        $startDate = $request->query->get('startDate');
        $endDate = $request->query->get('endDate');
        $period = $request->query->get('period', '30'); // 7, 30, 90 days or 'custom'

        if ($startDate && $endDate && $period === 'custom') {
            // Custom date range
            try {
                $start = new \DateTimeImmutable($startDate);
                $end = new \DateTimeImmutable($endDate);
                $history = $dailyStockRepository->findProductHistoryByDateRange($boutique, $productId, $start, $end);
            } catch (\Exception $e) {
                // Invalid dates, fallback to 30 days
                $period = '30';
                $days = 30;
                $history = $dailyStockRepository->findProductHistory($boutique, $productId, $days);
                $startDate = null;
                $endDate = null;
            }
        } else {
            // Predefined period
            $days = (int) $period;
            $history = $dailyStockRepository->findProductHistory($boutique, $productId, $days);
            $startDate = null;
            $endDate = null;
        }

        // Format history for JSON serialization
        $historyData = array_map(function($stock) {
            return [
                'productId' => $stock->getProductId(),
                'name' => $stock->getName(),
                'reference' => $stock->getReference(),
                'quantity' => $stock->getQuantity(),
                'collectedAt' => $stock->getCollectedAt()->format('Y-m-d H:i:s'),
            ];
        }, $history);

        return $this->render('dashboard/product_history.html.twig', [
            'boutique' => $boutique,
            'productId' => $productId,
            'history' => $historyData,
            'period' => $period,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
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
