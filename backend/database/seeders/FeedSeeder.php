<?php

namespace Database\Seeders;

use App\Models\Feed;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FeedSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultFeeds = [
            ['title' => 'Laravel News', 'url' => 'https://feeds.feedburner.com/laravel'],
            ['title' => 'Hacker News Frontpage', 'url' => 'https://hnrss.org/frontpage'],
            ['title' => 'GitHub Blog', 'url' => 'https://github.blog/feed/'],
            ['title' => 'Smashing Magazine', 'url' => 'https://www.smashingmagazine.com/feed/'],
            ['title' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml'],
        ];

        foreach ($defaultFeeds as $feed) {
            Feed::updateOrCreate(
                ['url' => $feed['url']],
                [
                    'title' => $feed['title'],
                    'is_active' => true,
                    'polling_interval_minutes' => 15,
                ]
            );
        }
    }
}
