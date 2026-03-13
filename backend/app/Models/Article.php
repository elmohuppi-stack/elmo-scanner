<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    protected $fillable = [
        'feed_id',
        'title',
        'url',
        'guid',
        'summary',
        'published_at',
        'author',
        'image_url',
        'categories',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'categories' => 'array',
        ];
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }
}
