<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

trait HasFormActionsInHeader
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
        $actions[] = $this->getSubmitFormAction();

        return $actions;
    }
}
