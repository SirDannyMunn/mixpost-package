<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Inovector\Mixpost\Builders\PostQuery;
use Inovector\Mixpost\Http\Requests\Calendar;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Http\Resources\PostResource;
use Inovector\Mixpost\Http\Resources\TagResource;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Tag;
use Inovector\Mixpost\Support\EagerLoadPostVersionsMedia;

class CalendarApiController extends Controller
{
    /**
     * Get calendar data with posts.
     */
    public function index(Calendar $request): JsonResponse
    {
        $request->handle();

        $posts = PostQuery::apply($request)->oldest('scheduled_at')->get();

        EagerLoadPostVersionsMedia::apply($posts);

        return response()->json([
            'accounts' => AccountResource::collection(Account::forCurrentOrganization()->oldest()->get())->resolve(),
            'tags' => TagResource::collection(Tag::forCurrentOrganization()->latest()->get())->resolve(),
            'posts' => PostResource::collection($posts)->additional([
                'filter' => [
                    'accounts' => Arr::map($request->get('accounts', []), 'intval'),
                ],
            ]),
            'type' => $request->type(),
            'selected_date' => $request->selectedDate(),
            'filter' => [
                'keyword' => $request->get('keyword', ''),
                'status' => $request->get('status'),
                'tags' => $request->get('tags', []),
                'accounts' => $request->get('accounts', []),
            ],
        ]);
    }
}
