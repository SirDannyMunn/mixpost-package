<?php

namespace Inovector\Mixpost\Http\Requests;

use Illuminate\Support\Facades\DB;
use Inovector\Mixpost\Enums\PostStatus;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Util;

class StorePost extends PostFormRequest
{
    public function handle()
    {
        return DB::transaction(function () {
            $record = Post::create([
                'organization_id' => Post::getOrganizationIdForCreate(),
                'created_by' => Post::getCurrentUserId(),
                'status' => PostStatus::DRAFT,
                'scheduled_at' => $this->scheduledAt() ? Util::convertTimeToUTC($this->scheduledAt()) : null
            ]);

            $record->accounts()->attach($this->input('accounts', []));
            $record->tags()->attach($this->input('tags'));
            
            // Ensure at least one version is marked as original
            $versions = $this->input('versions', []);
            $hasOriginal = collect($versions)->contains(fn($v) => !empty($v['is_original']));
            
            if (!$hasOriginal && count($versions) > 0) {
                $versions[0]['is_original'] = true;
            }
            
            $record->versions()->createMany($versions);

            return $record;
        });
    }
}
