<?php

declare(strict_types=1);

namespace OCA\IdRegister\Controller;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Service\Profile;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/** The profile an express account completes after signing in. */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
final class ProfileController extends Controller
{
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private Profile $profile,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function state(): JSONResponse
    {
        return $this->run(fn ($user) => $this->profile->state($user));
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 6, period: 3600)]
    public function email(string $email = '', string $language = 'en'): JSONResponse
    {
        return $this->run(function ($user) use ($email, $language) {
            $this->profile->requestEmail($user, $email, $language);

            return $this->profile->state($user);
        });
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 3600)]
    public function emailConfirm(string $code = ''): JSONResponse
    {
        return $this->run(function ($user) use ($code) {
            $this->profile->confirmEmail($user, $code);

            return $this->profile->state($user);
        });
    }

    #[NoAdminRequired]
    public function phone(string $phone = ''): JSONResponse
    {
        return $this->run(function ($user) use ($phone) {
            $this->profile->setPhone($user, $phone);

            return $this->profile->state($user);
        });
    }

    #[NoAdminRequired]
    public function nickname(string $nickname = ''): JSONResponse
    {
        return $this->run(function ($user) use ($nickname) {
            $this->profile->setNickname($user, $nickname);

            return $this->profile->state($user);
        });
    }

    private function run(callable $action): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse(['ok' => false, 'message' => 'not signed in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse(['ok' => true] + $action($user), Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['ok' => false, 'message' => $e->getMessage()], Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('idregister: profile change failed', ['exception' => $e]);

            return new JSONResponse(['ok' => false, 'message' => 'Something went wrong. Please try again.'], Http::STATUS_OK);
        }
    }
}
