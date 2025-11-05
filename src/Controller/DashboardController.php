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

        // Get total stock count
        $totalProducts = 0;
        foreach ($boutiques as $boutique) {
            $stocks = $dailyStockRepository->findLatestSnapshot($boutique);
            $totalProducts += count($stocks);
        }

        return $this->render('dashboard/index.html.twig', [
            'boutiques' => $boutiques,
            'totalBoutiques' => count($boutiques),
            'totalProducts' => $totalProducts,
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

        $paginator = $dailyStockRepository->findLatestSnapshotPaginated($boutique, $page, $perPage);
        $totalItems = count($paginator);
        $totalPages = (int) ceil($totalItems / $perPage);

        // Get comparison data
        $compareDate = $this->getCompareDate($comparePeriod);
        $compareStocks = $compareDate ? $dailyStockRepository->findSnapshotByDate($boutique, $compareDate) : [];

        // Build comparison map
        $compareMap = [];
        foreach ($compareStocks as $stock) {
            $compareMap[$stock->getProductId()] = $stock->getQuantity();
        }

        // Add variations to current stocks
        $stocksWithVariation = [];
        foreach ($paginator as $stock) {
            $currentQty = $stock->getQuantity();
            $previousQty = $compareMap[$stock->getProductId()] ?? null;

            $stocksWithVariation[] = [
                'stock' => $stock,
                'variation' => $previousQty !== null ? ($currentQty - $previousQty) : null,
                'variationPercent' => $previousQty !== null && $previousQty > 0
                    ? round((($currentQty - $previousQty) / $previousQty) * 100, 1)
                    : null
            ];
        }

        return $this->render('dashboard/stocks.html.twig', [
            'boutique' => $boutique,
            'stocks' => $stocksWithVariation,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'perPage' => $perPage,
            'comparePeriod' => $comparePeriod,
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
