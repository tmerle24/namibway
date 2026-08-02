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
            $actions[] = $this->getCreateAnotherFormAction();
        }

        $actions[] = $this->getSubmitFormAction();

        return $actions;
    }
}
