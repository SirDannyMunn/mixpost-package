<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\Request;
use Inovector\Mixpost\Services\Integrations\SharedSocialConnection;
use Inovector\Mixpost\Services\Scheduler\SchedulerAccess;
use Inovector\Mixpost\Http\Resources\AccountResource;

class SharedConnectionsController
{
    public function __construct(private readonly SharedSocialConnection $connections, private readonly SchedulerAccess $access) {}

    public function index()
    {
        return response()->json($this->connections->catalog($this->access->organization()));
    }

    public function targets(Request $request)
    {
        $org = $this->access->organization(null, true);
        $v = $request->validate(['key' => 'required|string|max:255', 'connection_id' => 'required|string|max:255']);
        return response()->json(['targets' => $this->connections->targets($org, $v['key'], $v['connection_id'])]);
    }

    public function store(Request $request)
    {
        $org = $this->access->organization(null, true);
        $v = $request->validate(['key' => 'required|string|max:255', 'connection_id' => 'required|string|max:255', 'target_id' => 'required|string|max:255']);
        return response()->json(['account' => new AccountResource($this->connections->link($org, (string) $request->user()->id, $v['key'], $v['connection_id'], $v['target_id']))]);
    }
}
