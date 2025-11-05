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
        BoutiqueRepository $boutiqueRepository,
        DailyStockRepository $dailyStockRepository
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

        // Calculate global stats and alerts
        $totalProducts = 0;
        $outOfStock = 0;
        $lowStock = 0;
        $lowStockProducts = [];
        $topSellingProducts = [];

        // Get date for comparison (7 days ago)
        $weekAgo = new \DateTimeImmutable('-7 days');

        foreach ($boutiques as $boutique) {
            $currentStocks = $dailyStockRepository->findLatestSnapshot($boutique);
            $weekAgoStocks = $dailyStockRepository->findSnapshotByDate($boutique, $weekAgo);

            // Build comparison map for week ago
            $weekAgoMap = [];
            foreach ($weekAgoStocks as $stock) {
                $weekAgoMap[$stock->getProductId()] = $stock->getQuantity();
            }

            foreach ($currentStocks as $stock) {
                $totalProducts++;
                $currentQty = $stock->getQuantity();

                // Stats
                if ($currentQty == 0) {
                    $outOfStock++;
                } elseif ($currentQty < 10) {
                    $lowStock++;
                    $lowStockProducts[] = [
                        'boutique' => $boutique,
                        'stock' => $stock,
                    ];
                }

                // Calculate sales (negative variation = products sold)
                $previousQty = $weekAgoMap[$stock->getProductId()] ?? null;
                if ($previousQty !== null && $previousQty > $currentQty) {
                    $sold = $previousQty - $currentQty;
                    if ($sold > 0) {
                        $topSellingProducts[] = [
                            'boutique' => $boutique,
                            'stock' => $stock,
                            'sold' => $sold,
                            'previousQty' => $previousQty,
                        ];
                    }
                }
            }
        }

        // Sort by quantity (most critical first)
        usort($lowStockProducts, fn($a, $b) => $a['stock']->getQuantity() <=> $b['stock']->getQuantity());

        // Keep only top 10 most critical
        $lowStockProducts = array_slice($lowStockProducts, 0, 10);

        // Sort top selling by quantity sold (descending)
        usort($topSellingProducts, fn($a, $b) => $b['sold'] <=> $a['sold']);

        // Keep only top 10 best sellers
        $topSellingProducts = array_slice($topSellingProducts, 0, 10);

        return $this->render('dashboard/index.html.twig', [
            'boutiques' => $boutiques,
            'totalBoutiques' => count($boutiques),
            'totalProducts' => $totalProducts,
            'outOfStock' => $outOfStock,
            'lowStock' => $lowStock,
            'lowStockProducts' => $lowStockProducts,
            'topSellingProducts' => $topSellingProducts,
        ]);
    }

    #[Route('/boutiques', name: 'app_boutiques')]
    public function boutiques(BoutiqueRepository $boutiqueRepository): Response
    {
        $user = $this->getUser();

        if ($user->isSuperAdmin()) {
            $boutiques = $boutiqueRepository->findAll();
        } else {
            $boutiques = [];
            foreach ($user->getBoutiqueUsers() as $boutiqueUser) {
                $boutiques[] = $boutiqueUser->getBoutique();
            }
        }

        return $this->render('dashboard/boutiques.html.twig', [
            'boutiques' => $boutiques,
        ]);
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
        $comparePeriod = $request->query->get('compare', 'yesterday');
        $filter = $request->query->get('filter', 'all');

        // Get ALL stocks (not paginated yet)
        $allStocks = $dailyStockRepository->findLatestSnapshot($boutique);

        // Get comparison data
        $compareDate = $this->getCompareDate($comparePeriod);
        $compareStocks = $compareDate ? $dailyStockRepository->findSnapshotByDate($boutique, $compareDate) : [];

        // Build comparison map
        $compareMap = [];
        foreach ($compareStocks as $stock) {
            $compareMap[$stock->getProductId()] = $stock->getQuantity();
        }

        // Add variations to current stocks and calculate stats
        $allStocksWithVariation = [];
        $stats = [
            'total' => 0,
            'outOfStock' => 0,
            'lowStock' => 0,
            'withChanges' => 0,
        ];

        foreach ($allStocks as $stock) {
            $currentQty = $stock->getQuantity();
            $previousQty = $compareMap[$stock->getProductId()] ?? null;
            $variation = $previousQty !== null ? ($currentQty - $previousQty) : null;

            $item = [
                'stock' => $stock,
                'variation' => $variation,
                'variationPercent' => $previousQty !== null && $previousQty > 0
                    ? round((($currentQty - $previousQty) / $previousQty) * 100, 1)
                    : null
            ];

            // Calculate stats (always on ALL products)
            $stats['total']++;
            if ($currentQty == 0) $stats['outOfStock']++;
            if ($currentQty > 0 && $currentQty < 10) $stats['lowStock']++;
            if ($variation !== null && $variation != 0) $stats['withChanges']++;

            // Apply filter
            $shouldInclude = match($filter) {
                'outofstock' => $currentQty == 0,
                'low' => $currentQty > 0 && $currentQty < 10,
                'changes' => $variation !== null && $variation != 0,
                default => true, // 'all'
            };

            if ($shouldInclude) {
                $allStocksWithVariation[] = $item;
            }
        }

        // Calculate pagination on filtered results
        $filteredCount = count($allStocksWithVariation);
        $totalPages = (int) ceil($filteredCount / $perPage);

        // Apply pagination to filtered results
        $offset = ($page - 1) * $perPage;
        $stocksWithVariation = array_slice($allStocksWithVariation, $offset, $perPage);

        return $this->render('dashboard/stocks.html.twig', [
            'boutique' => $boutique,
            'stocks' => $stocksWithVariation,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $filteredCount,
            'perPage' => $perPage,
            'comparePeriod' => $comparePeriod,
            'filter' => $filter,
            'stats' => $stats,
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
