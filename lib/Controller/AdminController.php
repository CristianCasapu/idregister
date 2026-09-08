<?php

declare(strict_types=1);

namespace OCA\IdRegister\Controller;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Db\PendingRegistrationMapper;
use OCA\IdRegister\Service\FaceMatch;
use OCA\IdRegister\Service\Ocr;
use OCA\IdRegister\Service\Registration;
use OCA\IdRegister\Service\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class AdminController extends Controller
{
    public function __construct(
        IRequest $request,
        private Settings $settings,
        private Registration $registration,
        private PendingRegistrationMapper $mapper,
        private IUserManager $userManager,
        private Ocr $ocr,
        private FaceMatch $faceMatch,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function config(): JSONResponse
    {
        return new JSONResponse([
            'config' => $this->settings->all(),
            'ocr' => $this->ocr->engineStatus(),
            'faces' => $this->faceMatch->status(),
        ]);
    }

    public function setConfig(array $config = []): JSONResponse
    {
        return new JSONResponse(['config' => $this->settings->set($config)]);
    }

    public function list(?string $status = null): JSONResponse
    {
        $rows = [];
        foreach ($this->mapper->findAll($status) as $pending) {
            $user = $this->userManager->get($pending->getUid());
            $rows[] = [
                'id' => (int) $pending->getId(),
                'uid' => $pending->getUid(),
                'name' => $pending->getFullName(),
                'email' => $pending->getEmail(),
                'phone' => $pending->getPhone(),
                'status' => $pending->getStatus(),
                'created_at' => $pending->getCreatedAt(),
                'expires_at' => $pending->getExpiresAt(),
                'enabled' => $user?->isEnabled() ?? false,
                'exists' => null !== $user,
            ];
        }

        return new JSONResponse(['registrations' => $rows]);
    }

    public function approve(int $id): JSONResponse
    {
        try {
            $this->registration->approve($id);

            return new JSONResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['ok' => false, 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    public function delete(int $id): JSONResponse
    {
        try {
            $this->registration->remove($id);

            return new JSONResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['ok' => false, 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /** Try the card reader from the admin page without creating anything. */
    public function testScan(): JSONResponse
    {
        $file = $this->request->getUploadedFile('image');
        if (null === $file || !isset($file['tmp_name'])) {
            return new JSONResponse(['ok' => false, 'message' => 'no picture'], Http::STATUS_BAD_REQUEST);
        }
        $data = file_get_contents($file['tmp_name']);
        @unlink($file['tmp_name']);

        try {
            $card = $this->ocr->readDocument((string) $data);
            unset($card['cnp']); // never shown, not even to an administrator

            return new JSONResponse(['ok' => true, 'card' => $card]);
        } catch (\Throwable $e) {
            return new JSONResponse(['ok' => false, 'message' => $e->getMessage()], Http::STATUS_OK);
        }
    }
}
