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
            ['title' => 'Berliner Zeitung', 'url' => 'https://www.berliner-zeitung.de/feed.xml'],
            ['title' => 'Epoch Times', 'url' => 'https://www.epochtimes.de/feed'],
            ['title' => 'Tagesschau', 'url' => 'https://www.tagesschau.de/xml/rss2'],
            ['title' => 'Tagesschau Inland', 'url' => 'https://www.tagesschau.de/inland/index~rss2.xml'],
            ['title' => 'Tagesschau Ausland', 'url' => 'https://www.tagesschau.de/ausland/index~rss2.xml'],
            ['title' => 'Deutschlandfunk Nachrichten', 'url' => 'https://www.deutschlandfunk.de/nachrichten-100.rss'],
            ['title' => 'ZEIT Newsfeed', 'url' => 'https://newsfeed.zeit.de/index'],
            ['title' => 'FAZ Politik', 'url' => 'https://www.faz.net/rss/aktuell/politik/'],
            ['title' => 'Sueddeutsche Politik', 'url' => 'https://rss.sueddeutsche.de/rss/Politik'],
            ['title' => 'Spiegel Schlagzeilen', 'url' => 'https://www.spiegel.de/schlagzeilen/index.rss'],
            ['title' => 'Spiegel Politik', 'url' => 'https://www.spiegel.de/politik/index.rss'],
            ['title' => 'Spiegel Wirtschaft', 'url' => 'https://www.spiegel.de/wirtschaft/index.rss'],
            ['title' => 'Spiegel Ausland', 'url' => 'https://www.spiegel.de/ausland/index.rss'],
            ['title' => 'NachDenkSeiten', 'url' => 'https://www.nachdenkseiten.de/?feed=rss2'],
            ['title' => 'Apollo News', 'url' => 'https://apollo-news.net/feed/'],
            ['title' => 'Apolut', 'url' => 'https://apolut.net/feed/'],
            ['title' => 'NIUS', 'url' => 'https://www.nius.de/rss'],
            ['title' => 'Tichys Einblick', 'url' => 'https://www.tichyseinblick.de/feed/'],
            ['title' => 'TKP', 'url' => 'https://tkp.at/feed'],
            ['title' => 'Reitschuster', 'url' => 'https://reitschuster.de/feed/'],
            ['title' => 'Anti-Spiegel', 'url' => 'https://anti-spiegel.ru/feed/'],
        ];

        foreach ($defaultFeeds as $index => $feed) {
            Feed::updateOrCreate(
                ['url' => $feed['url']],
                [
                    'title' => $feed['title'],
                    'is_active' => true,
                    'polling_interval_minutes' => 15,
                    'position' => $index,
                ]
            );
        }
    }
}
