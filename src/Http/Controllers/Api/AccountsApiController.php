<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inovector\Mixpost\Actions\UpdateOrCreateAccount;
use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Enums\ServiceGroup;
use Inovector\Mixpost\Facades\ServiceManager;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Models\Account;

class AccountsApiController extends Controller
{
    use UsesSocialProviderManager;

    /**
     * List all connected accounts.
     */
    public function index(): JsonResponse
    {
        $socialServices = ServiceManager::services()->group(ServiceGroup::SOCIAL)->getNames();

        return response()->json([
            'accounts' => AccountResource::collection(Account::latest()->get())->resolve(),
            'is_configured_service' => ServiceManager::isConfigured($socialServices),
            'is_service_active' => ServiceManager::isActive($socialServices),
        ]);
    }

    /**
     * Update/refresh account from provider.
     */
    public function update(Request $request, string $account): JsonResponse
    {
        $accountModel = Account::firstOrFailByUuid($account);

        $connection = $this->connectProvider($accountModel);

        $response = $connection->getAccount();

        if ($response->hasError()) {
            if ($response->isUnauthorized()) {
                return response()->json([
                    'error' => 'unauthorized',
                    'message' => 'The account cannot be updated. Re-authenticate your account.',
                ], 401);
            }

            return response()->json([
                'error' => 'update_failed',
                'message' => 'The account cannot be updated.',
            ], 400);
        }

        (new UpdateOrCreateAccount())($accountModel->provider, $response->context(), $accountModel->access_token->toArray());

        return response()->json([
            'message' => 'Account updated successfully',
            'account' => new AccountResource($accountModel->fresh()),
        ]);
    }

    /**
     * Delete an account.
     */
    public function destroy(Request $request, string $account): JsonResponse
    {
        $accountModel = Account::firstOrFailByUuid($account);

        $connection = $this->connectProvider($accountModel);

        if (method_exists($connection, 'revokeToken')) {
            $connection->revokeToken();
        }

        $accountModel->delete();

        return response()->json([
            'message' => 'Account deleted successfully',
        ]);
    }
}
