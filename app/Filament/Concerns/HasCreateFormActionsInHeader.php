<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

trait HasCreateFormActionsInHeader
{
    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * @param  array<Action>  $actions
     * @return array<Action>
     */
    protected function withFormActions(array $actions = []): array
    {
        $actions[] = $this->getCancelFormAction();

        if (static::canCreateAnother()) {
            $actions[] = $this->linkToMainForm($this->getCreateAnotherFormAction());
        }

        $actions[] = $this->linkToMainForm($this->getSubmitFormAction());

        return $actions;
    }

    /**
     * Filament's own submit actions render as <button type="submit"> with no
     * explicit `form` attribute, because they're normally placed *inside* the
     * <form> by Filament's default layout — native submit-button-to-form
     * association then just comes from being a descendant of it. Header
     * actions render in the page header, outside the <form> entirely, so
     * without pointing the button at the form's id explicitly it's a submit
     * button attached to nothing: clicking it (or pressing Enter in a field)
     * does nothing, silently — no request, no JS error. Filament's own
     * create-record form partial hardcodes id="form" (see vendor
     * .../resources/pages/create-record.blade.php).
     */
    protected function linkToMainForm(Action $action): Action
    {
        if ($action->canSubmitForm() && $action->getFormId() === null) {
            $action->formId('form');
        }

        return $action;
    }
}
