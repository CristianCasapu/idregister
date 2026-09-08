<?php

declare(strict_types=1);

namespace OCA\IdRegister\Settings;

use OCA\IdRegister\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/** Tells a user registered with an identity card which of their details are fixed. */
final class Personal implements ISettings
{
    public function __construct(
        private IInitialState $initialState,
        private IUserSession $userSession,
        private IDBConnection $db,
        private \OCA\IdRegister\Service\Profile $profile,
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
        $this->initialState->provideInitialState('locked', $locked ?? false);
        $this->initialState->provideInitialState('profile', null !== $user && null !== $locked ? $this->profile->state($user) : false);

        return new TemplateResponse(Application::APP_ID, 'personal');
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
