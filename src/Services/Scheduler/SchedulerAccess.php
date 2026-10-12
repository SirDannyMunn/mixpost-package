<?php

namespace Inovector\Mixpost\Services\Scheduler;

use App\Models\OrganizationMember;
use App\Modules\OrganizationScoping\Support\CurrentOrganization;
use Illuminate\Support\Facades\Auth;

/** Shared authorization boundary for HTTP and MCP. Workers receive an explicit organization. */
class SchedulerAccess
{
    public function organization(?string $requested = null, bool $write = false): string
    {
        $org = $requested ?: CurrentOrganization::id() ?: request()->header('X-Organization-Id');
        abort_unless($org && Auth::id(), 403, 'An authenticated organization context is required.');
        $member = OrganizationMember::where('organization_id', $org)->where('user_id', Auth::id())->first();
        abort_unless($member, 403, 'Organization access denied.');
        abort_if($write && in_array($member->role, ['viewer', 'guest', 'read_only'], true), 403, 'Write access is required.');
        return (string) $org;
    }
}
