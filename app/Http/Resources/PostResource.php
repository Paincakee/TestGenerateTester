<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'test' => $this->test,
            'jaja' => $this->jaja,
            'user_id' => $this->user_id,
            'archived_at' => $this->archived_at,
            'user' => UserResource::make($this->whenLoaded('user')),
            'tags' => TagCollection::make($this->whenLoaded('tags')),
            'likes' => LikeCollection::make($this->whenLoaded('likes')),
        ];
    }
}
