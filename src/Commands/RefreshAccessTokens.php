<?php

namespace Inovector\Mixpost\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Models\Account;

class RefreshAccessTokens extends Command
{
    public $signature = 'mixpost:refresh-access-tokens';

    public $description = 'Automatically refresh expiring access tokens for social media accounts';

    public function handle(): int
    {
        $this->info('Checking for tokens that need refresh...');

        $refreshedCount = 0;
        $failedCount = 0;

        Account::where('authorized', true)
            ->get()
            ->each(function (Account $account) use (&$refreshedCount, &$failedCount) {
                try {
                    if ($this->shouldRefreshToken($account)) {
                        $this->info("Refreshing token for {$account->providerName()}: {$account->username}");
                        
                        if ($this->refreshToken($account)) {
                            $refreshedCount++;
                            $this->line("  ✓ Token refreshed successfully");
                        } else {
                            $failedCount++;
                            $this->error("  ✗ Token refresh failed");
                        }
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->error("  ✗ Error: " . $e->getMessage());
                    Log::error("Token refresh failed for account {$account->id}", [
                        'account_id' => $account->id,
                        'provider' => $account->provider,
                        'error' => $e->getMessage()
                    ]);
                }
            });

        $this->info("Token refresh complete. Refreshed: {$refreshedCount}, Failed: {$failedCount}");

        return self::SUCCESS;
    }

    protected function shouldRefreshToken(Account $account): bool
    {
        $accessToken = $account->access_token;

        if (!is_array($accessToken)) {
            return false;
        }

        // Skip providers without refresh capability
        if (!isset($accessToken['refresh_token']) && $account->provider !== 'instagram') {
            return false;
        }

        // Check if token has expires_in field
        if (!isset($accessToken['expires_in']) && !isset($accessToken['expires_at'])) {
            return false;
        }

        // Calculate expiry time
        if (isset($accessToken['expires_at'])) {
            $expiresAt = \Carbon\Carbon::parse($accessToken['expires_at']);
        } else {
            // Calculate from updated_at + expires_in
            $expiresAt = $account->updated_at->addSeconds($accessToken['expires_in']);
        }

        // Refresh if token expires within 7 days
        $refreshThreshold = now()->addDays(7);

        return $expiresAt->lessThan($refreshThreshold);
    }

    protected function refreshToken(Account $account): bool
    {
        try {
            $provider = SocialProviderManager::connect($account->provider, $account->values());

            // Check if provider has refreshAccessToken method
            if (!method_exists($provider, 'refreshAccessToken')) {
                return false;
            }

            $accessToken = $account->access_token;

            // Get the appropriate refresh parameter based on provider
            if ($account->provider === 'instagram') {
                // Instagram uses access_token itself to refresh
                $refreshParam = $accessToken['access_token'];
            } else {
                // Most providers use refresh_token
                $refreshParam = $accessToken['refresh_token'] ?? null;
            }

            if (!$refreshParam) {
                return false;
            }

            // Call provider's refresh method
            $newToken = $provider->refreshAccessToken($refreshParam);

            if (empty($newToken) || isset($newToken['error'])) {
                Log::error("Token refresh returned error", [
                    'account_id' => $account->id,
                    'provider' => $account->provider,
                    'error' => $newToken['error'] ?? 'Unknown error'
                ]);
                return false;
            }

            // Merge new token data with existing data
            $updatedToken = array_merge($accessToken, $newToken);

            // Add or update expires_at timestamp
            if (isset($newToken['expires_in'])) {
                $updatedToken['expires_at'] = now()->addSeconds($newToken['expires_in'])->toIso8601String();
            }

            // Save updated token
            $account->access_token = $updatedToken;
            $account->save();

            Log::info("Token refreshed successfully", [
                'account_id' => $account->id,
                'provider' => $account->provider,
                'username' => $account->username,
                'expires_at' => $updatedToken['expires_at'] ?? 'N/A'
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Exception during token refresh", [
                'account_id' => $account->id,
                'provider' => $account->provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}
