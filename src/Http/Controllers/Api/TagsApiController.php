<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inovector\Mixpost\Http\Requests\StoreTag;
use Inovector\Mixpost\Http\Requests\UpdateTag;
use Inovector\Mixpost\Http\Resources\TagResource;
use Inovector\Mixpost\Models\Tag;

class TagsApiController extends Controller
{
    /**
     * List all tags.
     */
    public function index(): JsonResponse
    {
        $tags = Tag::forCurrentOrganization()->latest()->get();

        return response()->json([
            'data' => TagResource::collection($tags)->resolve(),
        ]);
    }

    /**
     * Store a new tag.
     */
    public function store(StoreTag $storeTag): JsonResponse
    {
        $tag = $storeTag->handle();

        return response()->json([
            'message' => 'Tag created successfully',
            'data' => new TagResource($tag),
        ], 201);
    }

    /**
     * Update a tag.
     */
    public function update(UpdateTag $updateTag): JsonResponse
    {
        $tag = $updateTag->handle();

        return response()->json([
            'message' => 'Tag updated successfully',
            'data' => new TagResource($tag),
        ]);
    }

    /**
     * Delete a tag.
     */
    public function destroy(Request $request): JsonResponse
    {
        $tag = Tag::forCurrentOrganization()
            ->where('uuid', $request->route('tag'))
            ->first();

        if (!$tag) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Tag not found',
            ], 404);
        }

        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully',
        ]);
    }
}
