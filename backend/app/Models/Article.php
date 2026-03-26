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
        'reader_html',
        'reader_text',
        'reader_extracted_at',
        'reader_error',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'categories' => 'array',
            'reader_extracted_at' => 'datetime',
        ];
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }
}
