<?php

namespace App\Http\Controllers;

use App\Http\Requests\PostStoreRequest;
use App\Http\Requests\PostUpdateRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PostController extends Controller
{
    /**
     * @param Request $request
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $posts = Post::find(1);

        return PostResource::collection($posts);
    }

    /**
     * @param PostStoreRequest $request
     *
     * @return PostResource
     */
    public function store(PostStoreRequest $request): PostResource
    {
        $post = Post::create($request->validated());

        return new PostResource($post);
    }

    /**
     * @param Request $request
     * @param Post $post
     *
     * @return PostResource
     */
    public function show(Request $request, Post $post): PostResource
    {
        return new PostResource($post);
    }

    /**
     * @param PostUpdateRequest $request
     * @param Post $post
     *
     * @return PostResource
     */
    public function update(PostUpdateRequest $request, Post $post): PostResource
    {
        $post->update($request->validated());

        return new PostResource($post);
    }

    /**
     * @param Request $request
     * @param Post $post
     *
     * @return Response
     */
    public function destroy(Request $request, Post $post): Response
    {
        $post->delete();

        return response()->noContent();
    }
}
