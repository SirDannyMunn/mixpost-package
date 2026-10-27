<?php

namespace Inovector\Mixpost\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostFormRequest extends FormRequest
{
    public function rules(): array
    {
        return \Inovector\Mixpost\Support\TikTokPostOptions::rules() + [
            'accounts' => ['array'],
            'accounts.*' => ['nullable', 'uuid'],
            'tags' => ['array'],
            'tags.*' => ['uuid'],
            'date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'generation_snapshot_id' => ['nullable', 'uuid'],
            'expected_body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'expected_status' => ['sometimes', 'in:DRAFT,SCHEDULED'],
            'versions' => ['required', 'array', 'min:1'],
            'versions.*.content.*.body' => ['nullable', 'string', 'max:5000'],
            'versions.*.content.*.media' => ['array'],
            'versions.*.content.*.media.*' => ['uuid']
        ];
    }

    protected function scheduledAt(): ?string
    {
        return $this->input('date') && $this->input('time') ? "{$this->input('date')} {$this->input('time')}" : null;
    }
}
