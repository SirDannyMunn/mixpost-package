<?php

namespace Inovector\Mixpost\Http\Requests;

use Illuminate\Support\Facades\DB;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Util;

class UpdatePost extends PostFormRequest
{
    public Post $post;

    public function withValidator($validator)
    {
        $this->post = Post::firstOrFailByUuid($this->route('post'));

        $validator->after(function ($validator) {
            if ($this->post->isInHistory()) {
                $validator->errors()->add('post', 'This post is in your publishing history. Duplicate it as a draft to edit or schedule it again.');
            }

            if ($this->post->isScheduleProcessing()) {
                $validator->errors()->add('post', 'This post is already being published. Wait for it to finish before trying again.');
            }
        });
    }

    public function handle()
    {
        return DB::transaction(function () {
            $this->post = Post::forCurrentOrganization()->whereKey($this->post->id)->lockForUpdate()->firstOrFail();
            if ($this->post->isInHistory() || $this->post->isScheduleProcessing()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['post' => 'This post can no longer be edited.']);
            }
            if ($this->exists('expected_status') && $this->post->status->name !== $this->input('expected_status')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['post' => 'This post changed status. Reload it before saving.']);
            }
            $body = data_get($this->post->versions()->where('is_original', true)->first()?->content, '0.body', '');
            if ($this->exists('expected_body') && $body !== $this->input('expected_body')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['post' => 'This draft changed elsewhere. Reload it before saving.']);
            }
            if ($this->post->isScheduled()) {
                app(\Inovector\Mixpost\Support\PostScheduleGuard::class)->assertAvailable(
                    $this->post,
                    $this->scheduledAt() ? Util::convertTimeToUTC($this->scheduledAt(), $this->input('timezone')) : null,
                    $this->input('accounts', []),
                );
            }
            if (empty($this->input('accounts')) || !$this->scheduledAt()) {
                $this->post->setDraft();
            }

            $this->post->accounts()->sync($this->input('accounts'));
            $this->post->tags()->sync($this->input('tags'));

            $this->post->versions()->delete();
            $this->post->versions()->createMany($this->input('versions'));


            $this->post->setScheduled(
                datetime: $this->scheduledAt() ? Util::convertTimeToUTC($this->scheduledAt(), $this->input('timezone')) : null,
                status: null,
            );
        });
    }
}
