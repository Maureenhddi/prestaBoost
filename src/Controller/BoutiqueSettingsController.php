<?php

namespace App\Controller;

use App\Entity\Boutique;
use App\Repository\BoutiqueRepository;
use App\Service\BoutiqueAuthorizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

        if ($name) {
            $boutique->setName($name);
        }

        if ($domain) {
            $boutique->setDomain($domain);
        }

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
}
