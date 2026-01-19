<?php

namespace Inovector\Mixpost\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Inovector\Mixpost\Facades\Settings as SettingsFacade;
use Inovector\Mixpost\Models\Setting as SettingModel;

class SaveSettings extends FormRequest
{
    public function rules(): array
    {
        return SettingsFacade::rules();
    }

    public function handle(): void
    {
        $schema = SettingsFacade::form();
        $organizationId = SettingModel::getOrganizationIdForCreate();

        foreach ($schema as $name => $defaultPayload) {
            $payload = $this->input($name, $defaultPayload);

            // Include organization_id in the unique key for multi-tenant support
            $uniqueKey = ['name' => $name];
            if ($organizationId !== null) {
                $uniqueKey['organization_id'] = $organizationId;
            }

            SettingModel::updateOrCreate($uniqueKey, [
                'organization_id' => $organizationId,
                'payload' => $payload
            ]);

            SettingsFacade::put($name, $payload);
        }
    }
}
