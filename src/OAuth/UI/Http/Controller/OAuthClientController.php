<?php

declare(strict_types=1);

namespace App\OAuth\UI\Http\Controller;

use App\OAuth\Application\Service\RegisterOAuthClientService;
use App\OAuth\Domain\OAuthClient;
use App\OAuth\Infrastructure\Persistence\Doctrine\OAuthClientRepository;
use App\OAuth\UI\Http\Form\RegisterOAuthClientType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A registered OAuthClient is a platform-level registration of a
 * *consuming application* (see OAuthClient's own class docblock) — NOT
 * tenant-scoped. Once ANY Person from ANY company authorizes a client, it
 * can call GET /api/userinfo for that person's id/name/email/company/role.
 * That makes client registration a platform-wide capability with
 * cross-tenant blast radius: gating it on the ordinary company-scoped
 * ROLE_ADMIN (as PersonController does for its own, single-company-scoped
 * screens) would let any one company's admin register a client able to
 * harvest identity data for every OTHER company's people too, the moment
 * any of them happen to authorize it — a real privilege-escalation gap,
 * not a hypothetical one.
 *
 * Gated on ROLE_PLATFORM_ADMIN instead — a distinct, orthogonal role only
 * grantable via the console-only app:grant-platform-admin command (see
 * Person::$platformAdmin's class docblock). Class-level IsGranted, not
 * per-method, matching this portfolio's own established convention (see
 * PersonController).
 *
 * Client-creation logic itself (id/secret generation, hashing, grant/PKCE
 * policy, persistence) is NOT duplicated here — it lives in
 * RegisterOAuthClientService, shared with the console-only
 * RegisterOAuthClientCommand from Slice 2, so a UI-registered client is
 * byte-for-byte equivalent to a console-registered one.
 */
#[Route('/oauth-clients')]
#[IsGranted('ROLE_PLATFORM_ADMIN')]
final class OAuthClientController extends AbstractController
{
    #[Route('', name: 'app_oauth_client_index', methods: ['GET'])]
    public function index(OAuthClientRepository $oAuthClientRepository): Response
    {
        return $this->render('oauth_client/index.html.twig', [
            'clients' => $oAuthClientRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_oauth_client_new', methods: ['GET', 'POST'])]
    public function new(Request $request, RegisterOAuthClientService $registerOAuthClientService): Response
    {
        $form = $this->createForm(RegisterOAuthClientType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if (!is_array($data) || $data['name'] === '' || $data['redirectUri'] === '') {
                throw new \LogicException('Expected non-empty name and redirectUri after form validation.');
            }
            $name = $data['name'];
            $redirectUri = $data['redirectUri'];

            try {
                $registered = $registerOAuthClientService->register($name, $redirectUri);
            } catch (\RuntimeException $exception) {
                $form->get('redirectUri')->addError(new FormError($exception->getMessage()));

                return $this->render('oauth_client/new.html.twig', [
                    'form' => $form,
                ]);
            }

            // The plaintext secret is shown exactly once, here, on this
            // response — it is never persisted, logged, or retrievable
            // again afterwards (same one-time-reveal discipline as
            // RegisterOAuthClientCommand's console table).
            return $this->render('oauth_client/created.html.twig', [
                'client' => $registered->client,
                'plainSecret' => $registered->plainSecret,
            ]);
        }

        return $this->render('oauth_client/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{identifier}/deactivate', name: 'app_oauth_client_deactivate', methods: ['POST'])]
    public function deactivate(
        string $identifier,
        Request $request,
        OAuthClientRepository $oAuthClientRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $client = $oAuthClientRepository->find($identifier);
        if (!$client instanceof OAuthClient) {
            throw $this->createNotFoundException('No such OAuth2 client.');
        }

        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('deactivate-oauth-client-' . $identifier, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $client->setActive(false);
        $entityManager->flush();

        $this->addFlash('success', sprintf('"%s" was deactivated.', $client->getName()));

        return $this->redirectToRoute('app_oauth_client_index');
    }
}
