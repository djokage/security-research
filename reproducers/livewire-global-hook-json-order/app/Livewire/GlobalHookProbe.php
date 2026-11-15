<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Json;
use Livewire\Component;

class GlobalHookProbe extends Component
{
    #[Json]
    public function updateJson(int $documentId): void
    {
        $this->writeDocument($documentId, 'modified-by-json');
    }

    public function updateOrdinary(int $documentId): void
    {
        $this->writeDocument($documentId, 'modified-by-ordinary');
    }

    #[Json]
    public function updateJsonWithInlineGate(int $documentId): void
    {
        Gate::authorize('update-document', $documentId);

        $this->writeDocument($documentId, 'modified-after-inline-gate');
    }

    #[Json]
    #[Authorize('update-document', 'documentId')]
    public function updateJsonWithAuthorize(int $documentId): void
    {
        $this->writeDocument($documentId, 'modified-after-attribute-authorize');
    }

    private function writeDocument(int $documentId, string $value): void
    {
        DB::table('documents')
            ->where('id', $documentId)
            ->update([
                'value' => $value,
                'changed_by_user_id' => auth()->id(),
            ]);
    }

    public function render()
    {
        return view('livewire.global-hook-probe');
    }
}
