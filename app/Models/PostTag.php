<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\PostTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $post_id
 * @property int $tag_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Post|null $post
 * @property-read Tag|null $tag
 */
class PostTag extends Pivot
{
    /** @use HasFactory<PostTagFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'post_tags';


    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'post_id',
        'tag_id',
    ];

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
