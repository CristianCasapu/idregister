<?php

declare(strict_types=1);

namespace OCA\IdRegister\Settings;

use OCA\IdRegister\AppInfo\Application;
use OCA\IdRegister\Service\FaceMatch;
use OCA\IdRegister\Service\Ocr;
use OCA\IdRegister\Service\Settings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

final class Admin implements ISettings
{
    public function __construct(
        private IInitialState $initialState,
        private Settings $settings,
        private IGroupManager $groupManager,
        private FaceMatch $faceMatch,
        private IURLGenerator $urlGenerator,
    ) {}

    public function getForm(): TemplateResponse
    {
        $groups = [];
        foreach ($this->groupManager->search('') as $group) {
            $groups[] = ['id' => $group->getGID(), 'name' => $group->getDisplayName()];
        }
        $this->initialState->provideInitialState('config', $this->settings->all());
        $this->initialState->provideInitialState('ocr', Ocr::status());
        $this->initialState->provideInitialState('faces', $this->faceMatch->status());
        $this->initialState->provideInitialState('groups', $groups);
        $this->initialState->provideInitialState('registerUrl', $this->urlGenerator->linkToRouteAbsolute('idregister.page.index'));

        return new TemplateResponse(Application::APP_ID, 'admin');
    }

    public function getSection(): string
    {
        return Application::APP_ID;
    }

    public function getPriority(): int
    {
        return 10;
    }
}
