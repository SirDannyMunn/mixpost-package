<?php

namespace Inovector\Mixpost\Http\Controllers;

use App\Services\OAuthStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Actions\UpdateOrCreateAccount;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Models\Account;

class CallbackSocialProviderController extends Controller
{
    public function __invoke(Request $request, UpdateOrCreateAccount $updateOrCreateAccount, string $providerName): RedirectResponse
    {
        // Check for encrypted state parameter (cross-domain OAuth flow)
        // State is the canonical transport for context - no session coupling
        $stateParam = $request->get('state');
        
        if ($stateParam && $this->isEncryptedState($stateParam)) {
            return $this->handleStatefulCallback($request, $providerName, $stateParam);
        }
        
        // Otherwise, use the standard Mixpost flow (session-based, for admin panel)
        return $this->handleStandardCallback($request, $updateOrCreateAccount, $providerName);
    }
    
    /**
     * Check if the state parameter is an encrypted payload (vs csrf_token)
     */
    protected function isEncryptedState(string $state): bool
    {
        // Encrypted payloads are base64 and significantly longer than csrf tokens
        // Laravel's Crypt produces strings starting with "eyJ" (base64 of JSON)
        return strlen($state) > 100 && (
            str_starts_with($state, 'eyJ') || // base64 JSON
            preg_match('/^[A-Za-z0-9+\/=]+$/', $state) // valid base64
        );
    }
    
    /**
     * Handle OAuth callback with encrypted state parameter (cross-domain flow)
     */
    protected function handleStatefulCallback(
        Request $request, 
        string $providerName, 
        string $encryptedState
    ): RedirectResponse {
        $stateService = app(OAuthStateService::class);
        
        try {
            // Decode and validate the state
            $payload = $stateService->decode($encryptedState);
            
            $returnUrl = $payload['return_url'];
            $organizationId = $payload['org_id'];
            $userId = $payload['user_id'];
            $client = $payload['client'];
            
            Log::info('OAuth callback with state', [
                'provider' => $providerName,
                'client' => $client,
                'org_id' => $organizationId,
            ]);
            
        } catch (\InvalidArgumentException $e) {
            Log::error('OAuth state validation failed', [
                'provider' => $providerName,
                'error' => $e->getMessage(),
            ]);
            
            // Can't redirect to frontend if state is invalid - go to Mixpost admin
            return redirect()->route('mixpost.accounts.index')
                ->with('error', 'OAuth state validation failed: ' . $e->getMessage());
        }
        
        try {
            $provider = SocialProviderManager::connect($providerName);

            if (empty($provider->getCallbackResponse())) {
                return $this->redirectToFrontend($returnUrl, $client, [
                    'error' => 'no_callback_response',
                    'error_description' => 'No callback response received from provider',
                    'platform' => $providerName,
                ]);
            }

            if ($error = $request->get('error')) {
                return $this->redirectToFrontend($returnUrl, $client, [
                    'error' => $error,
                    'error_description' => $request->get('error_description', ''),
                    'platform' => $providerName,
                ]);
            }

            // For providers that need entity selection (like Facebook Pages)
            if (!$provider->isOnlyUserAccount()) {
                // For cross-domain, we need to handle entity selection differently
                // Store minimal info in cache with the state as key for entity selection flow
                $entitySelectionToken = $this->createEntitySelectionToken(
                    $providerName,
                    $provider->getCallbackResponse(),
                    $returnUrl,
                    $organizationId,
                    $userId,
                    $client
                );
                
                return $this->redirectToFrontend($returnUrl, $client, [
                    'status' => 'entity_selection_required',
                    'platform' => $providerName,
                    'entity_token' => $entitySelectionToken,
                ]);
            }

            // Get access token
            $accessToken = $provider->requestAccessToken($provider->getCallbackResponse());

            if ($error = Arr::get($accessToken, 'error')) {
                return $this->redirectToFrontend($returnUrl, $client, [
                    'error' => 'token_error',
                    'error_description' => $error,
                    'platform' => $providerName,
                ]);
            }

            $provider->setAccessToken($accessToken);

            // Get account info
            $account = $provider->getAccount();

            if ($account->hasError()) {
                return $this->redirectToFrontend($returnUrl, $client, [
                    'error' => 'account_fetch_failed',
                    'error_description' => 'Failed to retrieve account information',
                    'platform' => $providerName,
                ]);
            }

            // Save the account to the database
            $socialAccount = $this->saveAccount(
                $providerName,
                $account->context(),
                $accessToken,
                $organizationId,
                $userId
            );

            return $this->redirectToFrontend($returnUrl, $client, [
                'success' => 'true',
                'platform' => $providerName,
                'account_id' => (string) $socialAccount->id,
                'account_uuid' => $socialAccount->uuid,
                'username' => $socialAccount->username ?? $socialAccount->name ?? '',
            ]);

        } catch (\Exception $e) {
            Log::error('OAuth callback error', [
                'provider' => $providerName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->redirectToFrontend($returnUrl, $client ?? 'web', [
                'error' => 'internal_error',
                'error_description' => 'An error occurred during authentication',
                'platform' => $providerName,
            ]);
        }
    }
    
    /**
     * Create a short-lived token for entity selection flow
     */
    protected function createEntitySelectionToken(
        string $providerName,
        array $callbackResponse,
        string $returnUrl,
        string $organizationId,
        string $userId,
        string $client
    ): string {
        $token = \Illuminate\Support\Str::random(64);
        
        \Illuminate\Support\Facades\Cache::put(
            "oauth_entity_selection:{$token}",
            [
                'provider' => $providerName,
                'callback_response' => $callbackResponse,
                'return_url' => $returnUrl,
                'org_id' => $organizationId,
                'user_id' => $userId,
                'client' => $client,
            ],
            now()->addMinutes(10)
        );
        
        return $token;
    }
    
    protected function saveAccount(
        string $providerName,
        array $accountContext,
        array $accessToken,
        string|int|null $organizationId,
        string|int|null $userId
    ): Account {
        // Use Mixpost's standard account creation flow
        // The UpdateOrCreateAccount action handles image upload and proper data structure
        $updateOrCreateAccount = app(UpdateOrCreateAccount::class);
        
        $accountData = [
            'id' => $accountContext['id'] ?? $accountContext['provider_id'] ?? null,
            'name' => $accountContext['name'] ?? $accountContext['username'] ?? '',
            'username' => $accountContext['username'] ?? $accountContext['name'] ?? null,
            'image' => $accountContext['image'] ?? $accountContext['avatar'] ?? null,
            'data' => $accountContext['data'] ?? null,
        ];

        // Use the updated action that supports organization context
        return $updateOrCreateAccount(
            $providerName, 
            $accountData, 
            $accessToken,
            $organizationId,
            $userId
        );
    }
    
    /**
     * Redirect to frontend with query parameters.
     * For Chrome extension clients, uses a handoff token pattern.
     */
    protected function redirectToFrontend(string $returnUrl, string $client, array $params): RedirectResponse
    {
        // For Chrome extension, create a handoff token since it can't receive cookies
        if ($client === 'chrome_ext') {
            return $this->redirectWithHandoffToken($returnUrl, $params);
        }
        
        // For web/figma, standard query parameter redirect
        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        $queryString = http_build_query($params);
        
        return redirect()->away($returnUrl . $separator . $queryString);
    }
    
    /**
     * Create a handoff token for Chrome extension OAuth flow.
     * The extension can exchange this token via API to get the result.
     */
    protected function redirectWithHandoffToken(string $returnUrl, array $params): RedirectResponse
    {
        $handoffToken = \Illuminate\Support\Str::random(64);
        
        // Store the OAuth result in cache for the extension to retrieve
        \Illuminate\Support\Facades\Cache::put(
            "oauth_handoff:{$handoffToken}",
            $params,
            now()->addMinutes(5)
        );
        
        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        
        return redirect()->away($returnUrl . $separator . 'handoff_token=' . $handoffToken);
    }
    
    protected function handleStandardCallback(
        Request $request, 
        UpdateOrCreateAccount $updateOrCreateAccount, 
        string $providerName
    ): RedirectResponse {
        $provider = SocialProviderManager::connect($providerName);

        if (empty($provider->getCallbackResponse())) {
            return redirect()->route('mixpost.accounts.index');
        }

        if ($error = $request->get('error')) {
            // Check if we have state that we can decode for return URL
            $stateParam = $request->get('state');
            if ($stateParam && $this->isEncryptedState($stateParam)) {
                try {
                    $payload = app(OAuthStateService::class)->decode($stateParam);
                    return $this->redirectToFrontend($payload['return_url'], $payload['client'], [
                        'error' => $error,
                        'error_description' => $request->get('error_description', ''),
                        'platform' => $providerName,
                    ]);
                } catch (\Exception $e) {
                    // Fall through to standard handling
                }
            }
            return redirect()->route('mixpost.accounts.index')->with('error', $error);
        }

        if (!$provider->isOnlyUserAccount()) {
            return redirect()->route('mixpost.accounts.entities.index', ['provider' => $providerName])
                ->with('mixpost_callback_response', $provider->getCallbackResponse());
        }

        $accessToken = $provider->requestAccessToken($provider->getCallbackResponse());

        if ($error = Arr::get($accessToken, 'error')) {
            return redirect()->route('mixpost.accounts.index')
                ->with('error', $error);
        }

        $provider->setAccessToken($accessToken);

        $account = $provider->getAccount();

        if ($account->hasError()) {
            return redirect()->route('mixpost.accounts.index')
                ->with('error', "It's something wrong. Try again.");
        }

        $updateOrCreateAccount($providerName, $account->context(), $accessToken);

        return redirect()->route('mixpost.accounts.index');
    }
}
