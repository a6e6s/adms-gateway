<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\ManageUserAccess;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Validation\ValidationException;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    /** @param array<string, mixed> $data */
    public function saveUser(?User $record, array $data): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        try {
            return app(ManageUserAccess::class)->save($actor, $record, $data);
        } catch (ValidationException $exception) {
            $schemaName = $this->getMountedActionSchemaName();
            $statePath = $schemaName === null ? null : $this->getSchema($schemaName)?->getStatePath();
            if ($statePath === null) {
                throw $exception;
            }
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$statePath.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data): User => $this->saveUser(null, $data)),
        ];
    }
}
