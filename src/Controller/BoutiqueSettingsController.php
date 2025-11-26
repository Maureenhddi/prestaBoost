<?php

namespace App\Controller;

use App\Entity\Boutique;
use App\Message\CollectBoutiqueDataMessage;
use App\Message\CollectOrdersChunkMessage;
use App\Repository\BoutiqueRepository;
use App\Service\BoutiqueAuthorizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_USER')]
#[Route('/boutique/{id}/settings')]
class BoutiqueSettingsController extends AbstractController
{
    #[Route('', name: 'app_boutique_settings')]
    public function index(
        int $id,
        BoutiqueRepository $boutiqueRepository,
        BoutiqueAuthorizationService $authService
    ): Response {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        // Check if user is admin of this boutique
        $isAdmin = $user->isSuperAdmin() || $user->isAdminOfBoutique($boutique);

        if (!$isAdmin) {
            throw $this->createAccessDeniedException('Vous devez être administrateur de cette boutique pour accéder aux paramètres.');
        }

        return $this->render('boutique/settings.html.twig', [
            'boutique' => $boutique,
        ]);
    }

    #[Route('/update', name: 'app_boutique_settings_update', methods: ['POST'])]
    public function update(
        int $id,
        Request $request,
        BoutiqueRepository $boutiqueRepository,
        EntityManagerInterface $entityManager,
        BoutiqueAuthorizationService $authService,
        SluggerInterface $slugger
    ): Response {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        // Check if user is admin of this boutique
        $isAdmin = $user->isSuperAdmin() || $user->isAdminOfBoutique($boutique);

        if (!$isAdmin) {
            throw $this->createAccessDeniedException('Vous devez être administrateur de cette boutique.');
        }

        // Update boutique settings
        $name = $request->request->get('name');
        $domain = $request->request->get('domain');
        $primaryColor = $request->request->get('primaryColor');
        $lowStockThreshold = $request->request->getInt('lowStockThreshold', 10);

        if ($name) {
            $boutique->setName($name);
        }

        if ($domain) {
            $boutique->setDomain($domain);
        }

        // Update low stock threshold (validated between 1 and 100)
        $boutique->setLowStockThreshold($lowStockThreshold);

        // Handle logo upload
        $logoFile = $request->files->get('logo');
        if ($logoFile) {
            // Validate file size (2MB max)
            if ($logoFile->getSize() > 2 * 1024 * 1024) {
                $this->addFlash('error', 'Le fichier est trop volumineux. Taille maximale: 2MB');
            } else {
                // Get extension from original filename
                $extension = strtolower($logoFile->getClientOriginalExtension());

                // Validate file extension
                $allowedExtensions = ['png', 'jpg', 'jpeg', 'svg'];
                if (!in_array($extension, $allowedExtensions)) {
                    $this->addFlash('error', 'Format de fichier non autorisé. Utilisez PNG, JPG ou SVG');
                } else {
                    $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    $newFilename = $safeFilename.'-'.uniqid().'.'.$extension;

                    try {
                        $logoFile->move(
                            $this->getParameter('logos_directory'),
                            $newFilename
                        );
                        $boutique->setLogoUrl('/uploads/logos/'.$newFilename);
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Erreur lors de l\'upload du logo');
                    }
                }
            }
        }

        if ($primaryColor) {
            $boutique->setPrimaryColor($primaryColor);
        }

        $boutique->setUpdatedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', 'Paramètres mis à jour avec succès !');
        return $this->redirectToRoute('app_boutique_settings', ['id' => $id]);
    }

    #[Route('/sync', name: 'app_boutique_settings_sync', methods: ['POST'])]
    public function syncNow(
        int $id,
        BoutiqueRepository $boutiqueRepository,
        BoutiqueAuthorizationService $authService,
        MessageBusInterface $messageBus,
        SessionInterface $session,
        EntityManagerInterface $entityManager
    ): Response {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        // Check if user is admin of this boutique
        $isAdmin = $user->isSuperAdmin() || $user->isAdminOfBoutique($boutique);

        if (!$isAdmin) {
            throw $this->createAccessDeniedException('Vous devez être administrateur de cette boutique.');
        }

        // Store current counts in session for progress tracking
        $ordersCount = $entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from('App\Entity\Order', 'o')
            ->where('o.boutique = :boutique')
            ->setParameter('boutique', $boutique)
            ->getQuery()
            ->getSingleScalarResult();

        $stocksCount = $entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from('App\Entity\DailyStock', 's')
            ->where('s.boutique = :boutique')
            ->setParameter('boutique', $boutique)
            ->getQuery()
            ->getSingleScalarResult();

        $session->set('boutique_' . $id . '_initial_orders', (int) $ordersCount);
        $session->set('boutique_' . $id . '_initial_stocks', (int) $stocksCount);

        // Dispatch async message for quick sync (stocks + 7 days orders)
        $messageBus->dispatch(new CollectBoutiqueDataMessage(
            $boutique->getId(),
            true,  // collect stocks
            true,  // collect orders
            7      // last 7 days
        ));

        $this->addFlash('success', 'Synchronisation lancée en arrière-plan. Les données seront mises à jour dans quelques minutes.');
        return $this->redirectToRoute('app_boutique_settings', ['id' => $id]);
    }

    #[Route('/sync-all', name: 'app_boutique_settings_sync_all', methods: ['POST'])]
    public function syncAllHistory(
        int $id,
        BoutiqueRepository $boutiqueRepository,
        BoutiqueAuthorizationService $authService,
        MessageBusInterface $messageBus,
        SessionInterface $session,
        EntityManagerInterface $entityManager,
        HttpClientInterface $httpClient
    ): Response {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        // Check if user is admin of this boutique
        $isAdmin = $user->isSuperAdmin() || $user->isAdminOfBoutique($boutique);

        if (!$isAdmin) {
            throw $this->createAccessDeniedException('Vous devez être administrateur de cette boutique.');
        }

        // Store current counts in session for progress tracking
        $ordersCount = $entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from('App\Entity\Order', 'o')
            ->where('o.boutique = :boutique')
            ->setParameter('boutique', $boutique)
            ->getQuery()
            ->getSingleScalarResult();

        $stocksCount = $entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from('App\Entity\DailyStock', 's')
            ->where('s.boutique = :boutique')
            ->setParameter('boutique', $boutique)
            ->getQuery()
            ->getSingleScalarResult();

        $session->set('boutique_' . $id . '_initial_orders', (int) $ordersCount);
        $session->set('boutique_' . $id . '_initial_stocks', (int) $stocksCount);

        // First, dispatch stock collection
        $messageBus->dispatch(new CollectBoutiqueDataMessage(
            $boutique->getId(),
            true,  // collect stocks
            false, // don't collect orders (we'll do it in chunks)
            0
        ));

        // Find max order ID from PrestaShop API
        try {
            $response = $httpClient->request('GET', rtrim($boutique->getDomain(), '/') . '/api/orders', [
                'auth_basic' => [$boutique->getApiKey(), ''],
                'query' => [
                    'output_format' => 'JSON',
                    'limit' => 1,
                    'sort' => '[id_DESC]'
                ],
                'timeout' => 10
            ]);

            $data = $response->toArray();
            $maxOrderId = 0;

            if (isset($data['orders']) && !empty($data['orders'])) {
                $maxOrderId = (int) $data['orders'][0]['id'];
            }

            if ($maxOrderId > 0) {
                // Dispatch chunked collection jobs (chunks of 5000 IDs)
                $chunkSize = 5000;
                $chunksDispatched = 0;

                for ($startId = 1; $startId <= $maxOrderId; $startId += $chunkSize) {
                    $endId = min($startId + $chunkSize - 1, $maxOrderId);

                    $messageBus->dispatch(new CollectOrdersChunkMessage(
                        $boutique->getId(),
                        $startId,
                        $endId
                    ));

                    $chunksDispatched++;
                }

                $this->addFlash('warning', "Synchronisation lancée : $chunksDispatched jobs en file d'attente pour ~$maxOrderId commandes. Progression visible en temps réel.");
            } else {
                $this->addFlash('warning', 'Impossible de déterminer le nombre de commandes à synchroniser.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la synchronisation : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_boutique_settings', ['id' => $id]);
    }

    #[Route('/sync-status', name: 'app_boutique_settings_sync_status', methods: ['GET'])]
    public function syncStatus(
        int $id,
        BoutiqueRepository $boutiqueRepository,
        BoutiqueAuthorizationService $authService,
        EntityManagerInterface $entityManager
    ): Response {
        $boutique = $boutiqueRepository->find($id);

        if (!$boutique) {
            throw $this->createNotFoundException('Boutique non trouvée');
        }

        $user = $this->getUser();
        $authService->denyAccessUnlessGranted($user, $boutique);

        // Count orders and stocks for this boutique
        $ordersCount = $entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from('App\Entity\Order', 'o')
            ->where('o.boutique = :boutique')
            ->setParameter('boutique', $boutique)
            ->getQuery()
            ->getSingleScalarResult();

        $stocksCount = $entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from('App\Entity\DailyStock', 's')
            ->where('s.boutique = :boutique')
            ->setParameter('boutique', $boutique)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'orders_count' => (int) $ordersCount,
            'stocks_count' => (int) $stocksCount
        ]);
    }
}
