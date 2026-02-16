<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use function Pest\Faker\fake;
use function Pest\Laravel\assertModelMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

test('index behaves as expected', function () {
    $posts = Post::factory()->count(3)->create();

    $response = get(route('posts.index'));

    $response->assertOk();
    $response->assertJsonStructure([]);
});


test('store uses form request validation')
    ->assertActionUsesFormRequest(
        \App\Http\Controllers\PostController::class,
        'store',
        \App\Http\Requests\PostStoreRequest::class
    );

test('store saves', function () {
    $name = fake()->name();
    $description = fake()->text();
    $user = User::factory()->create();

    $response = post(route('posts.store'), [
        'name' => $name,
        'description' => $description,
        'user_id' => $user->id,
    ]);

    $posts = Post::query()
        ->where('name', $name)
        ->where('description', $description)
        ->where('user_id', $user->id)
        ->get();
    expect($posts)->toHaveCount(1);
    $post = $posts->first();

    $response->assertCreated();
    $response->assertJsonStructure([]);
});


test('show behaves as expected', function () {
    $post = Post::factory()->create();

    $response = get(route('posts.show', $post));

    $response->assertOk();
    $response->assertJsonStructure([]);
});


test('update uses form request validation')
    ->assertActionUsesFormRequest(
        \App\Http\Controllers\PostController::class,
        'update',
        \App\Http\Requests\PostUpdateRequest::class
    );

test('update behaves as expected', function () {
    $post = Post::factory()->create();
    $name = fake()->name();
    $description = fake()->text();
    $user = User::factory()->create();

    $response = put(route('posts.update', $post), [
        'name' => $name,
        'description' => $description,
        'user_id' => $user->id,
    ]);

    $post->refresh();

    $response->assertOk();
    $response->assertJsonStructure([]);

    expect($name)->toEqual($post->name);
    expect($description)->toEqual($post->description);
    expect($user->id)->toEqual($post->user_id);
});


test('destroy deletes and responds with', function () {
    $post = Post::factory()->create();

    $response = delete(route('posts.destroy', $post));

    $response->assertNoContent();

    assertModelMissing($post);
});
