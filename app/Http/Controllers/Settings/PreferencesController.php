<?php

namespace App\Http\Controllers\Settings;

use App\Data\Settings\UserPreferencesData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePreferencesRequest;
use App\Services\Settings\PreferencesService;
use Illuminate\Http\JsonResponse;

class PreferencesController extends Controller
{
    public function __construct(private readonly PreferencesService $preferences) {}

    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $this->preferences->updateFont($request->user(), $request->validated('font_family'));

        return $this->success(new UserPreferencesData($user->font_family), 'Font preference saved.');
    }
}
