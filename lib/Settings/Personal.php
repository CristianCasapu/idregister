<?php

declare(strict_types=1);

namespace OCA\IdRegister\Settings;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Controller\GoogleController;
use OCA\IdRegister\Service\Google;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/**
 * Tells a user registered with an identity card which of their details are fixed, and offers to
 * tie a Google account to this account for signing in without a password.
 */
final class Personal implements ISettings
{
    public function __construct(
        private IInitialState $initialState,
        private IUserSession $userSession,
        private IDBConnection $db,
        private \OCA\IdRegister\Service\Profile $profile,
        private Google $google,
        private IConfig $config,
    ) {}

    public function getForm(): TemplateResponse
    {
        $locked = null;
        $user = $this->userSession->getUser();
        if (null !== $user) {
            $query = $this->db->getQueryBuilder();
            $query->select('display_name', 'email', 'phone')->from('idregister_locked')
                ->where($query->expr()->eq('uid', $query->createNamedParameter($user->getUID())))
            ;
            $row = $query->executeQuery()->fetch();
            if ($row) {
                $locked = [
                    'name' => (string) $row['display_name'],
                    'email' => (string) $row['email'],
                    'phone' => (string) $row['phone'],
                ];
            }
        }
        $this->initialState->provideInitialState('google', $this->googleState());
        $this->initialState->provideInitialState('locked', $locked ?? false);
        $this->initialState->provideInitialState('profile', null !== $user && null !== $locked ? $this->profile->state($user) : false);

        return new TemplateResponse(Application::APP_ID, 'personal');
    }

    /**
     * What the personal settings page shows about Google, plus the outcome of a linking that
     * has just come back from Google (read once, then forgotten).
     *
     * @return array{enabled:bool, linked:bool, email:string, accountEmail:string, message:array{ok:bool, message:string}|null}|false
     */
    private function googleState(): array|false
    {
        $user = $this->userSession->getUser();
        if (null === $user || !$this->google->enabled()) {
            return false;
        }
        $uid = $user->getUID();
        $raw = $this->config->getUserValue($uid, Application::APP_ID, GoogleController::MESSAGE_KEY, '');
        if ('' !== $raw) {
            $this->config->deleteUserValue($uid, Application::APP_ID, GoogleController::MESSAGE_KEY);
        }
        $message = '' === $raw ? null : json_decode($raw, true);
        $row = $this->google->linkedRow($uid);

        return [
            'enabled' => true,
            'linked' => null !== $row,
            'email' => $row['email'] ?? '',
            'accountEmail' => (string) $user->getSystemEMailAddress(),
            'message' => \is_array($message) ? $message : null,
        ];
    }

    public function getSection(): string
    {
        return 'personal-info';
    }

    public function getPriority(): int
    {
        return 5;
    }
}
