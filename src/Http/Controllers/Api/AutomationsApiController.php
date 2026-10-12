<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inovector\Mixpost\Services\Automations\{AutomationRunner, AutomationService};
use Inovector\Mixpost\Services\Scheduler\SchedulerAccess;

class AutomationsApiController extends Controller
{
    public function __construct(private readonly AutomationService $rules, private readonly SchedulerAccess $access) {}

    public function index() { return response()->json(['automations' => $this->rules->list($this->access->organization())]); }
    public function store(Request $request) { return response()->json(['automation' => $this->rules->save($this->access->organization(write: true), $request->user()->id, $request->all())], 201); }
    public function update(Request $request, string $automation) { return response()->json(['automation' => $this->rules->save($this->access->organization(write: true), $request->user()->id, $request->all(), $automation)]); }
    public function toggle(Request $request, string $automation)
    {
        $v = $request->validate(['enabled' => ['required', 'boolean']]);
        return response()->json(['automation' => $this->rules->toggle($this->access->organization(write: true), $automation, $v['enabled'])]);
    }
    public function runs(string $automation) { return response()->json(['runs' => $this->rules->runs($this->access->organization(), $automation)]); }
    public function engagement(Request $request, AutomationRunner $runner)
    {
        $v = $request->validate([
            'post_id' => ['required', 'uuid'], 'account_id' => ['required', 'uuid'],
            'event_id' => ['required', 'string', 'max:255'], 'text' => ['sometimes', 'string', 'max:10000'],
            'metrics' => ['sometimes', 'array'], 'metrics.*' => ['integer', 'min:0'],
        ]);
        return response()->json($runner->engagement($this->access->organization(write: true), $v));
    }
}
