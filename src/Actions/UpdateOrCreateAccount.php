<?php

namespace Inovector\Mixpost\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inovector\Mixpost\Events\AccountAdded;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Support\MediaUploader;

class UpdateOrCreateAccount
{
    /**
     * Create or update a social account.
     *
     * @param string $providerName The provider name (e.g., 'facebook_page')
     * @param array $account Account data including: id, name, username, image, data
     * @param array $accessToken Access token data
     * @param string|int|null $organizationId Optional organization ID for multi-tenant support
     * @param string|int|null $userId Optional user ID who connected the account
     */
    public function __invoke(
        string $providerName, 
        array $account, 
        array $accessToken,
        string|int|null $organizationId = null,
        string|int|null $userId = null
    ): Account {
        // Build the unique key for finding/creating account
        $uniqueKey = [
            'provider' => $providerName,
            'provider_id' => $account['id'],
        ];
        
        // Include organization_id in unique key if provided
        if ($organizationId !== null) {
            $uniqueKey['organization_id'] = $organizationId;
        }

        // Build the update data
        $updateData = [
            'name' => $account['name'],
            'username' => $account['username'] ?? null,
            'media' => $this->media($account['image'] ?? null, $providerName),
            'data' => $account['data'] ?? null,
            'authorized' => true,
            'access_token' => $accessToken,
        ];
        
        // Add organization context if provided
        if ($organizationId !== null) {
            $updateData['organization_id'] = $organizationId;
        }
        
        // Add connected_by if provided
        if ($userId !== null) {
            $updateData['connected_by'] = $userId;
            $updateData['connected_at'] = now();
        }

        $account = Account::updateOrCreate($uniqueKey, $updateData);

        if ($account->wasRecentlyCreated) {
            AccountAdded::dispatch($account);
        }
        
        return $account;
    }

    protected function media(string|null $imageUrl, string $providerName): array|null
    {
        if (!$imageUrl || empty(trim($imageUrl))) {
            return null;
        }

        try {
            $contents = @file_get_contents($imageUrl);
            
            if ($contents === false || empty($contents)) {
                return null;
            }
            
            // Generate a unique filename - don't rely on URL parsing as Facebook URLs often have no extension
            $extension = 'jpg';
            $info = @pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?: '');
            if (!empty($info['extension']) && in_array(strtolower($info['extension']), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $extension = strtolower($info['extension']);
            }
            
            $filename = Str::random(32) . '.' . $extension;
            $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
            
            if (file_put_contents($tempFile, $contents) === false) {
                return null;
            }

            // Verify the temp file exists and has content
            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                @unlink($tempFile);
                return null;
            }

            $file = new UploadedFile($tempFile, $filename);
            $path = "mixpost/avatars/$providerName";

            $upload = MediaUploader::fromFile($file)->path($path)->upload();

            // Clean up temp file
            @unlink($tempFile);

            return [
                'disk' => $upload['disk'],
                'path' => $upload['path']
            ];
        } catch (\Throwable $e) {
            // Catch both Exception and Error (including ValueError in PHP 8+)
            // If image download/upload fails, just return null - don't block account creation
            \Illuminate\Support\Facades\Log::warning('Failed to download avatar image', [
                'url' => $imageUrl,
                'provider' => $providerName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
