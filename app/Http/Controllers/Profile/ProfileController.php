<?php

namespace App\Http\Controllers\Profile;

use App\Data\Auth\UserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\DeleteAccountRequest;
use App\Http\Requests\Profile\StoreProfileImageRequest;
use App\Services\Auth\TokenService;
use App\Services\Profile\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profile,
        private readonly TokenService $tokens,
    ) {}

    public function storeImage(StoreProfileImageRequest $request): JsonResponse
    {
        $user = $this->profile->storeImage($request->user(), $request->file('image'));

        return $this->success(UserData::from($user), 'Profile image updated.');
    }

    public function removeImage(Request $request): JsonResponse
    {
        $user = $this->profile->removeImage($request->user());

        return $this->success(UserData::from($user), 'Profile image removed.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        [$accessCookie, $refreshCookie] = $this->profile->changePassword(
            $request->user(),
            $request->validated(),
        );

        return $this->success(null, 'Password updated. You remain signed in on this browser.')
            ->withCookie($accessCookie)->withCookie($refreshCookie);
    }

    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        $this->profile->deleteAccount($request->user());
        [$accessCookie, $refreshCookie] = $this->tokens->forgetCookies();

        return $this->success(null, 'Your account has been permanently deleted.')
            ->withCookie($accessCookie)->withCookie($refreshCookie);
    }
}
