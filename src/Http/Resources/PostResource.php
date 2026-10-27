<?php

namespace Inovector\Mixpost\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Inovector\Mixpost\Facades\Settings;
use Inovector\Mixpost\Util;

class PostResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status(),
            'has_generation_provenance' => !empty($this->generation_snapshot_id),
            'accounts' => AccountResource::collection($this->whenLoaded('accounts')),
            'versions' => PostVersionResource::collection($this->whenLoaded('versions')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'calendar_date' => ($this->scheduled_at ?? ($this->status->name === 'DRAFT' ? $this->created_at : null))?->copy()->tz(Settings::get('timezone'))->toDateString(),
            'scheduled_at' => [
                'date' => $this->scheduled_at?->tz(Settings::get('timezone'))->toDateString(),
                'time' => $this->scheduled_at?->tz(Settings::get('timezone'))->format('H:i'),
                'human' => $this->scheduled_at ? Util::dateTimeFormat($this->scheduled_at, Settings::get('timezone')) : null,
            ],
            'published_at' => [
                'iso' => $this->published_at?->toISOString(),
                'human' => $this->published_at?->tz(Settings::get('timezone'))->format("D, M j, " . Util::timeFormat())
            ],
            'publications' => $this->whenLoaded('accounts', fn () => $this->accounts->filter(fn ($account) => !empty($account->pivot->provider_post_id))->map(fn ($account) => [
                'account_id' => (string) $account->id,
                'provider' => $account->provider,
                'provider_post_id' => (string) $account->pivot->provider_post_id,
                'delivery_status' => data_get(json_decode($account->pivot->data ?? '{}', true), 'delivery_status'),
            ])->values()),
        ];
    }

    protected function status()
    {
        if ($this->isScheduleProcessing()) {
            return 'PUBLISHING';
        }

        return $this->status->name;
    }
}
