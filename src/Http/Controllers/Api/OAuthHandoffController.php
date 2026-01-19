<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Actions\UpdateOrCreateAccount;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Models\Account;

/**
 * Handles OAuth handoff token exchange for clients that can't receive cookies (Chrome extension).
 * Also handles entity selection flow for providers like Facebook Pages.
 */
class OAuthHandoffController extends Controller
{
    /**
     * Exchange a handoff token for OAuth result.
     * 
     * This endpoint is used by Chrome extensions that receive a handoff_token
     * in the OAuth redirect URL. The token can be exchanged exactly once
     * to retrieve the OAuth result.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function exchange(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string|size:64',
        ]);

        $token = $request->input('token');
        $cacheKey = "oauth_handoff:{$token}";

        // Retrieve and delete in one atomic operation
        $result = Cache::pull($cacheKey);

        if (!$result) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'Token is invalid, expired, or already used',
            ], 400);
        }

        return response()->json($result);
    }

    /**
     * Get entities for selection after OAuth callback (for Facebook Pages, etc.)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getEntities(Request $request): JsonResponse
    {
        $request->validate([
            'entity_token' => 'required|string|size:64',
        ]);

        $token = $request->input('entity_token');
        $cacheKey = "oauth_entity_selection:{$token}";

        $data = Cache::get($cacheKey);

        if (!$data) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'Entity selection token is invalid or expired',
            ], 400);
        }

        // Get the provider and fetch available entities
        $provider = SocialProviderManager::connect($data['provider']);
        
        // Check if we already have an access token (from a previous getEntities call)
        $accessToken = $data['access_token'] ?? null;
        
        if (!$accessToken) {
            // First call - exchange the auth code for an access token
            $accessToken = $provider->requestAccessToken($data['callback_response']);
            
            if (isset($accessToken['error'])) {
                return response()->json([
                    'error' => 'token_error',
                    'error_description' => $accessToken['error'],
                ], 400);
            }
            
            // Store the access token in cache for the selectEntity call
            $data['access_token'] = $accessToken;
            Cache::put($cacheKey, $data, now()->addMinutes(10));
        }

        // Use stateless method for API context (no session available)
        $provider->setAccessTokenStateless($accessToken);
        
        // Get available entities (pages, accounts, etc.)
        $entitiesResponse = $provider->getEntities(withAccessToken: true);
        
        if ($entitiesResponse->hasError()) {
            return response()->json([
                'error' => 'entities_fetch_failed',
                'error_description' => 'Failed to fetch available pages/accounts',
            ], 400);
        }

        return response()->json([
            'platform' => $data['provider'],
            'entities' => $entitiesResponse->context(),
            'entity_token' => $token, // Client needs to send this back when selecting
        ]);
    }

    /**
     * Complete entity selection and save the account.
     *
     * @param Request $request
     * @param UpdateOrCreateAccount $updateOrCreateAccount
     * @return JsonResponse
     */
    public function selectEntity(Request $request, UpdateOrCreateAccount $updateOrCreateAccount): JsonResponse
    {
        $request->validate([
            'entity_token' => 'required|string|size:64',
            'entity_id' => 'required|string',
        ]);

        $token = $request->input('entity_token');
        $entityId = $request->input('entity_id');
        $cacheKey = "oauth_entity_selection:{$token}";

        // Pull the data (one-time use)
        $data = Cache::pull($cacheKey);

        if (!$data) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'Entity selection token is invalid, expired, or already used',
            ], 400);
        }

        try {
            $provider = SocialProviderManager::connect($data['provider']);
            
            // Use the cached access token (obtained during getEntities)
            $accessToken = $data['access_token'] ?? null;
            
            if (!$accessToken) {
                return response()->json([
                    'error' => 'token_error',
                    'error_description' => 'Access token not found. Please restart the OAuth flow.',
                ], 400);
            }

            // Use stateless method for API context (no session available)
            $provider->setAccessTokenStateless($accessToken);
            
            // Fetch all entities with access tokens and find the selected one
            $entitiesResponse = $provider->getEntities(withAccessToken: true);
            
            if ($entitiesResponse->hasError()) {
                return response()->json([
                    'error' => 'entities_fetch_failed',
                    'error_description' => 'Failed to fetch entities for selection',
                ], 400);
            }
            
            $entities = $entitiesResponse->context();
            $entity = collect($entities)->firstWhere('id', $entityId);
            
            if (!$entity) {
                return response()->json([
                    'error' => 'entity_not_found',
                    'error_description' => 'The selected entity was not found',
                ], 404);
            }

            // Build the account data in Mixpost format
            $accountData = [
                'id' => $entity['id'],
                'name' => $entity['name'] ?? $entity['username'] ?? '',
                'username' => $entity['username'] ?? $entity['name'] ?? null,
                'image' => $entity['image'] ?? null,
                'data' => $entity['data'] ?? null,
            ];

            // For entities with their own access token (like Facebook Pages)
            // use the entity's access token, otherwise use the user's access token
            $entityAccessToken = $entity['access_token'] ?? null;
            
            // Check if this is a Facebook Page - needs special token handling
            $isFacebookPage = in_array($data['provider'], ['facebook', 'facebook_page']);
            
            if (is_array($entityAccessToken) && isset($entityAccessToken['access_token'])) {
                // Entity has its own structured token (e.g., Facebook Page)
                if ($isFacebookPage) {
                    // Facebook Pages need both the user token and the page token
                    $tokenToStore = array_merge($accessToken, [
                        'page_access_token' => $entityAccessToken['access_token']
                    ]);
                } else {
                    $tokenToStore = $entityAccessToken;
                }
            } elseif (is_string($entityAccessToken)) {
                // Entity has a string token - wrap it in the expected format
                if ($isFacebookPage) {
                    $tokenToStore = array_merge($accessToken, [
                        'page_access_token' => $entityAccessToken
                    ]);
                } else {
                    $tokenToStore = [
                        'access_token' => $entityAccessToken,
                        'token_type' => $accessToken['token_type'] ?? 'Bearer',
                    ];
                }
            } else {
                // Use the user's access token
                $tokenToStore = $accessToken;
            }

            // Use the Mixpost UpdateOrCreateAccount action with organization context
            $account = $updateOrCreateAccount(
                $data['provider'], 
                $accountData, 
                $tokenToStore,
                $data['org_id'] ?? null,
                $data['user_id'] ?? null
            );

            Log::info('OAuth entity selected and account saved', [
                'provider' => $data['provider'],
                'entity_id' => $entityId,
                'account_id' => $account?->id,
                'org_id' => $data['org_id'],
                'user_id' => $data['user_id'],
            ]);

            return response()->json([
                'success' => true,
                'platform' => $data['provider'],
                'account_id' => $account?->id,
                'account_uuid' => $account?->uuid,
                'username' => $account?->username ?? $account?->name,
            ]);

        } catch (\Exception $e) {
            Log::error('Entity selection error', [
                'provider' => $data['provider'] ?? 'unknown',
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'internal_error',
                'error_description' => 'An error occurred while selecting the entity',
            ], 500);
        }
    }
}
