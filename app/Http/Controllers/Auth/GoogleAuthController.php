<?php

namespace App\Http\Controllers\Auth;

use App\Data\Auth\UserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StartGoogleAuthRequest;
use App\Services\Auth\GoogleAuthException;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GoogleAuthController extends Controller
{
    public function __construct(private readonly GoogleAuthService $google) {}

    public function redirect(StartGoogleAuthRequest $request): Response
    {
        try {
            return $this->google->begin($request, $request->boolean('remember_me', true));
        } catch (GoogleAuthException $exception) {
            return $exception->toResponse();
        }
    }

    public function link(StartGoogleAuthRequest $request): Response
    {
        try {
            $authorization = $this->google->begin($request, $request->boolean('remember_me', true), $request->user());

            return $this->success(['authorization_url' => $authorization->getTargetUrl()], 'Continue with Google to link your account.');
        } catch (GoogleAuthException $exception) {
            return $exception->toResponse();
        }
    }

    public function callback(Request $request): Response
    {
        try {
            [$user, $accessCookie, $refreshCookie, $isLinking] = $this->google->complete($request);
            $frontendUri = $this->google->frontendRedirectUri();
            $response = $frontendUri
                ? redirect()->away(Uri::of($frontendUri)->withQuery(['google' => $isLinking ? 'linked' : 'success'])->value())
                : $this->success(UserData::from($user), $isLinking ? 'Google account linked successfully.' : 'Login successful.');

            return $response->withCookie($accessCookie)->withCookie($refreshCookie);
        } catch (GoogleAuthException $exception) {
            return $this->callbackError($exception);
        } catch (Throwable $exception) {
            Log::error('Unable to complete Google sign-in.', ['exception' => $exception::class]);

            return $this->callbackError(GoogleAuthException::unavailable());
        }
    }

    private function callbackError(GoogleAuthException $exception): Response
    {
        $frontendUri = $this->google->frontendRedirectUri();

        return $frontendUri
            ? redirect()->away(Uri::of($frontendUri)->withQuery(['google' => 'error', 'code' => $exception->errorCode])->value())
            : $exception->toResponse();
    }
}
