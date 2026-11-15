<?php

namespace App\Livewire\Hooks;

use Illuminate\Auth\Access\AuthorizationException;
use Livewire\ComponentHook;

class RejectNonAdminCalls extends ComponentHook
{
    private const PROTECTED_METHODS = [
        'updateJson',
        'updateOrdinary',
        'updateJsonWithInlineGate',
        'updateJsonWithAuthorize',
    ];

    public function call($method, $params, $returnEarly)
    {
        if (! in_array($method, self::PROTECTED_METHODS, true)) {
            return;
        }

        if (! auth()->user()?->is_admin) {
            throw new AuthorizationException('This action is restricted to administrators.');
        }
    }
}
