<?php

namespace Inovector\Mixpost\Support;

use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Enums\PostStatus;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;

final class PostScheduleGuard
{
    /** Call inside the caller's transaction. Account locks serialize concurrent slot claims. */
    public function assertAvailable(Post $post, mixed $instant, array $accountIds): void
    {
        if (!$instant || !$accountIds) return;
        Account::forCurrentOrganization()->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get();
        $conflict = Post::forCurrentOrganization()
            ->where('id', '!=', $post->id)
            ->where('status', PostStatus::SCHEDULED->value)
            ->where('scheduled_at', $instant)
            ->whereHas('accounts', fn ($query) => $query->whereIn('mixpost_accounts.id', $accountIds))
            ->exists();
        if ($conflict) throw ValidationException::withMessages(['date' => 'This account already has a post at that time. Choose another slot.']);
    }
}
