<?php

namespace App\Http\Controllers;

use App\Enums\SocialProviderEnum;
use App\Enums\StorageProviderEnum;
use App\Services\StorageProviderService;
use App\Http\Requests\{ChangePasswordRequest,
    LoginRequest,
    PassportTokenRequest,
    RegisterRequest,
    ResetPasswordRequest,
    UpdateProfileRequest};
use App\Http\Resources\UserResource;
use App\Models\{BlockedIp, User};
use App\Services\FCMService;
use Carbon\Carbon;
use Exception;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Auth, DB, Hash, Log, Password};
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;
use Kreait\Firebase\Exception\FirebaseException;
use Laravel\Passport\Client;
use Laravel\Socialite\Facades\Socialite;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Class AuthController
 *
 * @tags Authentication
 */
class AuthController extends Controller
{
    /**
     * @var FCMService
     */
    protected FCMService $fcmService;

    /**
     * @var StorageProviderService
     */
    protected StorageProviderService $storageProviderService;

    /**
     * AuthController constructor.
     *
     * @param FCMService $fcmService
     * @param StorageProviderService $storageProviderService
     */
    public function __construct(FCMService $fcmService, StorageProviderService $storageProviderService)
    {
        $this->fcmService = $fcmService;
        $this->storageProviderService = $storageProviderService;
    }

    /**
     * Redirect the user to the provider's authentication page.
     *
     * @param SocialProviderEnum $provider
     * @return JsonResponse
     */
    public function redirectToProvider(SocialProviderEnum $provider): JsonResponse
    {
        try {
            $url = Socialite::driver($provider->value)->stateless()->redirect()->getTargetUrl();
            return response()->success(['url' => $url], "Successfully generated {$provider->value} authentication URL");
        } catch (Exception $e) {
            return $this->handleException($e, 'redirecting to the provider');
        }
    }

    /**
     * Handle the provider's callback and authenticate the user.
     *
     * @param SocialProviderEnum $provider
     * @return JsonResponse
     * @throws FirebaseException
     */
    public function handleProviderCallback(SocialProviderEnum $provider): JsonResponse
    {
        try {
            $socialUser = Socialite::driver($provider->value)->stateless()->user();
            $user = User::updateOrCreate(
                ['email' => $socialUser->getEmail()],
                [
                    'name' => $socialUser->getName(),
                    'password' => Hash::make(Str::random(16)),
                    'provider' => $provider->value,
                    'provider_id' => $socialUser->getId(),
                    'email_verified_at' => now(),
                ]
            );
            Auth::login($user);

            $token = JWTAuth::fromUser($user);
            JWTAuth::setToken($token);

            return $this->updateLoginInfo(request(), $token);
        } catch (Exception $e) {
            return $this->handleException($e, 'authenticating with the provider');
        }
    }

    /**
     * Login user and create token
     *
     * @param LoginRequest $request
     * @return JsonResponse
     * @throws FirebaseException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            if ($this->isIpBlocked($request->ip())) {
                return response()->forbidden('Your IP address is blocked.');
            }

            $credentials = $request->only('email', 'password');

            if (!Auth::attempt($credentials)) {
                return response()->unauthorized('Invalid login credentials');
            }

            $user = Auth::user();

            if (!$user->hasVerifiedEmail()) {
                Auth::logout();
                $user->sendEmailVerificationNotification();
                return response()->forbidden('Your email is not verified. A new verification link has been sent to your email address.');
            }

            $token = JWTAuth::fromUser($user);
            JWTAuth::setToken($token);

            return $this->updateLoginInfo($request, $token);
        } catch (Exception $e) {
            return $this->handleException($e, 'logging in');
        }
    }

    /**
     * Issue a new token for the user using Password Grant or Client Credentials.
     *
     * @param PassportTokenRequest $request
     * @return JsonResponse
     * @throws FirebaseException
     */
    public function issuePassportToken(PassportTokenRequest $request): JsonResponse
    {
        try {
            if ($this->isIpBlocked($request->ip())) {
                return response()->forbidden('Your IP address is blocked.');
            }
            $client = Client::where('id', $request->client_id)
                ->where('secret', $request->client_secret)
                ->first();
            if (!$client) {
                return response()->unauthorized('Invalid client credentials');
            }
            if ($request->grant_type === 'password') {
                return $this->handlePasswordGrant($request, $client);
            } elseif ($request->grant_type === 'client_credentials') {
                return $this->handleClientCredentialsGrant($client);
            }
            return response()->badRequest('Invalid grant type');
        } catch (Exception $e) {
            return $this->handleException($e, 'issuing the token');
        }
    }

    /**
     * Register a new user
     *
     * @requestMediaType multipart/form-data
     * @param RegisterRequest $request
     * @return JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'username' => $request->username,
                    'phone' => $request->phone,
                    'password' => Hash::make($request->password),
                ]);

                if ($request->hasFile('profile_image')) {
                    $upload = $this->storageProviderService->uploadFile(
                        $request->file('profile_image'),
                        'profile_images',
                        StorageProviderEnum::from(config('filesystems.default_storage_provider')),
                        $user->id
                    );

                    if ($upload) {
                        $user->profile_image = $upload->path;
                        $user->save();
                    }
                }

                $this->updateDeviceInfo($user, $request);

                event(new Registered($user));

                $token = JWTAuth::fromUser($user);
                JWTAuth::setToken($token);

                Auth::login($user);

                $user->sendEmailVerificationNotification();

                return response()->created([
                    'user' => new UserResource($user),
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => JWTAuth::factory()->getTTL() * 60,
                ], 'User registered successfully. Please check your email for verification.');
            });
        } catch (Exception $e) {
            return $this->handleException($e, 'registering user');
        }
    }

    /**
     * Verify user's email
     *
     * @param Request $request
     * @param string $id
     * @param string $hash
     * @return JsonResponse
     */
    public function verify(Request $request, $id, $hash): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
                return response()->badRequest('Invalid verification link');
            }

            if ($user->hasVerifiedEmail()) {
                return response()->success(null, 'Email already verified');
            }

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }

            $token = JWTAuth::fromUser($user);
            JWTAuth::setToken($token);

            Auth::login($user);

            return response()->success([
                'user' => new UserResource($user),
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ], 'Email has been verified and user logged in successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'verifying email');
        }
    }

    /**
     * Resend verification email
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resendVerification(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if ($user->hasVerifiedEmail()) {
                return response()->success(null, 'Email already verified');
            }
            $user->sendEmailVerificationNotification();
            return response()->success(null, 'Verification link sent');
        } catch (Exception $e) {
            return $this->handleException($e, 'resending verification email');
        }
    }

    /**
     * Send a reset link to the given user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $request->validate(['email' => 'required|email']);
            $status = Password::sendResetLink($request->only('email'));
            if ($status === Password::RESET_LINK_SENT) {
                return response()->success(null, __($status));
            } else {
                return response()->badRequest(__($status));
            }
        } catch (Exception $e) {
            return $this->handleException($e, 'sending the password reset link');
        }
    }

    /**
     * Reset user's password
     *
     * @param ResetPasswordRequest $request
     * @return JsonResponse
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->forceFill(['password' => Hash::make($password)])->save();
                }
            );
            if ($status === Password::PASSWORD_RESET) {
                return response()->success(null, __($status));
            }
            return response()->badRequest(__($status));
        } catch (Exception $e) {
            return $this->handleException($e, 'resetting the password');
        }
    }

    /**
     * Change user's password
     *
     * @param ChangePasswordRequest $request
     * @return JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->badRequest('Current password is incorrect');
            }
            $user->password = Hash::make($request->new_password);
            $user->save();
            return response()->success(null, 'Password changed successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'changing the password');
        }
    }

    /**
     * Get authenticated user's profile
     *
     * @return JsonResponse
     */
    public function profile(): JsonResponse
    {
        try {
            $user = Auth::user();
            return response()->success(new UserResource($user), 'User profile retrieved successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'retrieving the user profile');
        }
    }

    /**
     * Update authenticated user's profile
     *
     * @param UpdateProfileRequest $request
     * @return JsonResponse
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $user->update($request->validated());
            return response()->success(new UserResource($user), 'User profile updated successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'updating the user profile');
        }
    }

    /**
     * Logout user (Invalidate the token)
     *
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            Auth::logout();
            return response()->success(null, 'User successfully logged out');
        } catch (Exception $e) {
            return $this->handleException($e, 'logging out');
        }
    }

    /**
     * Refresh a token.
     *
     * @return JsonResponse
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            JWTAuth::setToken($newToken);
            return response()->success(['access_token' => $newToken], 'Token successfully refreshed');
        } catch (Exception $e) {
            return $this->handleException($e, 'refreshing the token');
        }
    }

    /**
     * Update user login information and log token if necessary.
     *
     * @param Request $request
     * @param string $token
     * @return JsonResponse
     * @throws FirebaseException
     */
    private function updateLoginInfo(Request $request, string $token): JsonResponse
    {
        try {
            $user = Auth::user();
            $user->last_login_at = $user->current_login_at;
            $user->current_login_at = Carbon::now();
            $user->last_login_ip = $user->current_login_ip;
            $user->current_login_ip = $request->ip();
            $user->login_count = ($user->login_count ?? 0) + 1;
            $user->save();

            $this->updateDeviceInfo($user, $request);

            $this->sendLoginNotification($user);

            return response()->success([
                'user' => new UserResource($user),
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ], 'User logged in successfully');
        } catch (Exception $e) {
            return $this->handleException($e, 'updating login information');
        }
    }

    /**
     * Update or add device information for the user.
     *
     * @param User $user
     * @param Request $request
     */
    private function updateDeviceInfo(User $user, Request $request): void
    {
        $agent = new Agent();
        $device = $agent->device();
        $browser = $agent->browser();
        $platform = $agent->platform();
        $version = $agent->version($browser);

        $user->addOrUpdateDeviceToken($request->device_token, [
            'device_type' => $request->header('User-Agent'),
            'device_name' => $request->device_name ?? $device,
            'app_version' => $request->app_version ?? $version,
        ]);
    }

    /**
     * Send login notification to all user devices.
     *
     * @param User $user
     * @throws FirebaseException
     */
    private function  sendLoginNotification(User $user): void
    {
        $deviceTokens = $user->deviceTokens()->pluck('token')->toArray();
        if (!empty($deviceTokens)) {
            $this->fcmService->sendToDevices(
                $deviceTokens,
                [
                    'title' => 'New Login',
                    'body' => 'Your account was just logged into.',
                ],
                ['type' => 'login_notification']
            );
        }
    }

    /**
     * Check if the given IP is blocked.
     *
     * @param string $ip
     * @return bool
     */
    private function isIpBlocked(string $ip): bool
    {
        return BlockedIp::where('ip_address', $ip)->exists();
    }

    /**
     * Handle exceptions and return appropriate JSON response.
     *
     * @param Exception $e
     * @param string $action
     * @return JsonResponse
     */
    private function handleException(Exception $e, string $action): JsonResponse
    {
        Log::error("Error in AuthController while {$action}: " . $e->getMessage());
        return response()->internalServerError("An error occurred while {$action}.");
    }

    /**
     * Handle a password grant type.
     *
     * @param PassportTokenRequest $request
     * @param Client $client
     * @return JsonResponse
     * @throws FirebaseException
     */
    private function handlePasswordGrant(PassportTokenRequest $request, Client $client): JsonResponse
    {
        if (!$client->password_client) {
            return response()->unauthorized('This client is not authorized for password grant');
        }
        if (!$token = auth()->setTTL(10080)->attempt(['email' => $request->email, 'password' => $request->password])) {
            return response()->unauthorized('Invalid user credentials');
        }
        $request['scopes'] = '[]';
        return $this->updateLoginInfo($request, $token);
    }

    /**
     * Handle client credentials grant type.
     *
     * @param Client $client
     * @return JsonResponse
     */
    private function handleClientCredentialsGrant(Client $client): JsonResponse
    {
        if (!$client->personal_access_client) {
            return response()->unauthorized('This client is not authorized for client credentials grant');
        }
        $token = $client->createToken('Client Access Token');
        $token->token->expires_at = Carbon::now()->addDays(30);
        $token->token->save();
        return response()->success([
            'access_token' => $token->accessToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->token->expires_at->toDateTimeString(),
        ], 'Client credentials token issued successfully');
    }

    /**
     * Unlock the screen using a PIN.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unlock(Request $request): JsonResponse
    {
        try {
            $request->validate([
                /**
                 * The PIN used to unlock the screen.
                 * @var string $pin
                 * @example 123456
                 */
                'pin' => 'required|string|max:6',
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->unauthorized(null, 'User not authenticated');
            }
            if (Hash::check($request->pin, $user->pin)) {
                return response()->success(null, 'Screen unlocked successfully');
            } else {
                return response()->badRequest(null, 'Invalid PIN');
            }
        } catch (Exception $e) {
            return $this->handleException($e, 'unlocking the screen');
        }
    }
}
