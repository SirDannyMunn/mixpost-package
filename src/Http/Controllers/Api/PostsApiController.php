<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Inovector\Mixpost\Builders\PostQuery;
use Inovector\Mixpost\Facades\ServiceManager;
use Inovector\Mixpost\Facades\Settings;
use Inovector\Mixpost\Http\Requests\StorePost;
use Inovector\Mixpost\Http\Requests\UpdatePost;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Http\Resources\PostResource;
use Inovector\Mixpost\Http\Resources\TagResource;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Models\Tag;
use Inovector\Mixpost\Support\EagerLoadPostVersionsMedia;

class PostsApiController extends Controller
{
    /**
     * List all posts with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $posts = PostQuery::apply($request)
            ->latest()
            ->latest('id')
            ->paginate($request->input('per_page', 20))
            ->onEachSide(1)
            ->withQueryString();

        EagerLoadPostVersionsMedia::apply($posts);

        return response()->json([
            'accounts' => AccountResource::collection(Account::oldest()->get())->resolve(),
            'tags' => TagResource::collection(Tag::latest()->get())->resolve(),
            'filter' => [
                'keyword' => $request->query('keyword', ''),
                'status' => $request->query('status'),
                'tags' => $request->query('tags', []),
                'accounts' => $request->query('accounts', [])
            ],
            'posts' => PostResource::collection($posts)->additional([
                'filter' => [
                    'accounts' => Arr::map($request->query('accounts', []), 'intval')
                ]
            ]),
            'has_failed_posts' => Post::failed()->exists()
        ]);
    }

    /**
     * Get data for creating a new post.
     */
    public function create(Request $request): JsonResponse
    {
        return response()->json([
            'default_accounts' => Settings::get('default_accounts'),
            'accounts' => AccountResource::collection(Account::oldest()->get())->resolve(),
            'tags' => TagResource::collection(Tag::latest()->get())->resolve(),
            'is_configured_service' => ServiceManager::isActive(),
        ]);
    }

    /**
     * Store a new post.
     */
    public function store(StorePost $storePost): JsonResponse
    {
        $post = $storePost->handle();

        $post->load('accounts', 'versions', 'tags');
        EagerLoadPostVersionsMedia::apply($post);

        return response()->json([
            'message' => 'Post created successfully',
            'post' => new PostResource($post),
        ], 201);
    }

    /**
     * Get a single post for editing.
     */
    public function show(Request $request, string $post): JsonResponse
    {
        $postModel = Post::firstOrFailTrashedByUuid($post);

        $postModel->load('accounts', 'versions', 'tags');

        EagerLoadPostVersionsMedia::apply($postModel);

        return response()->json([
            'accounts' => AccountResource::collection(Account::oldest()->get())->resolve(),
            'tags' => TagResource::collection(Tag::latest()->get())->resolve(),
            'post' => new PostResource($postModel),
            'is_configured_service' => ServiceManager::isActive(),
            'service_configs' => ServiceManager::exposedConfiguration(),
        ]);
    }

    /**
     * Update a post.
     */
    public function update(UpdatePost $updatePost): JsonResponse
    {
        $updatePost->handle();

        return response()->json([
            'message' => 'Post updated successfully',
        ]);
    }

    /**
     * Delete a post.
     */
    public function destroy(Request $request, string $post): JsonResponse
    {
        Post::where('uuid', $post)->delete();

        return response()->json([
            'message' => 'Post deleted successfully',
        ]);
    }
}
