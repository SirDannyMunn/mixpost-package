<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Http\Requests\StoreProviderEntities;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Support\SocialProviderResponse;

class AccountEntitiesApiController extends Controller
{
    /**
     * Get available entities for a provider.
     */
    public function index(Request $request, string $provider): JsonResponse
    {
        if (!$request->session()->has('mixpost_callback_response')) {
            return response()->json([
                'error' => 'no_callback',
                'message' => 'No OAuth callback response found. Please restart the OAuth flow.',
            ], 400);
        }

        $providerInstance = SocialProviderManager::connect($provider);

        $accessToken = $providerInstance->requestAccessToken($request->session()->get('mixpost_callback_response'));

        if ($error = Arr::get($accessToken, 'error')) {
            return response()->json([
                'error' => 'token_error',
                'message' => $error,
            ], 400);
        }

        $providerInstance->setAccessToken($accessToken);

        /** @var SocialProviderResponse $response */
        $response = $providerInstance->getEntities();

        if ($response->hasError()) {
            return response()->json([
                'error' => 'entities_error',
                'message' => 'Something went wrong fetching entities. Please try again.',
            ], 400);
        }

        $existingAccounts = Account::select('provider', 'provider_id')->get();

        $entities = collect($response->context())->map(function ($entity) use ($provider, $existingAccounts) {
            $entity['connected'] = !!$existingAccounts
                ->where('provider', $provider)
                ->where('provider_id', $entity['id'])
                ->first();

            return $entity;
        })->sort(function ($account) {
            return $account['connected'];
        })->values();

        if ($entities->isEmpty()) {
            return response()->json([
                'error' => 'no_entities',
                'message' => 'The account has no entities.',
            ], 404);
        }

        return response()->json([
            'provider' => $provider,
            'entities' => $entities,
        ]);
    }

    /**
     * Store selected entities as accounts.
     */
    public function store(StoreProviderEntities $storeAccountEntities): JsonResponse
    {
        $storeAccountEntities->handle();

        return response()->json([
            'message' => 'Entities connected successfully',
        ]);
    }
}
