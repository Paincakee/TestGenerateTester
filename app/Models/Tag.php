<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Collection;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Post> $posts
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsToMany<Post, $this, PostTag>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class)
            ->using(PostTag::class)
            ->as('post_tag')
            ->withPivot('id', 'post_id', 'tag_id')
            ->withTimestamps();
    }
}
