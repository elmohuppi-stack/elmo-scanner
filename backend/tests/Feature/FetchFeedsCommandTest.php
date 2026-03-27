<?php

namespace Tests\Feature;

use App\Models\Feed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchFeedsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_all_endpoint_only_refreshes_feeds_older_than_five_minutes(): void
    {
        Carbon::setTestNow('2026-03-27 12:00:00');

        $staleFeed = Feed::query()->create([
            'title' => 'Stale Feed',
            'url' => 'https://stale.example/feed.xml',
            'position' => 0,
            'polling_interval_minutes' => 15,
            'is_active' => true,
            'last_fetched_at' => now()->subMinutes(6),
        ]);

        $recentFeed = Feed::query()->create([
            'title' => 'Recent Feed',
            'url' => 'https://recent.example/feed.xml',
            'position' => 1,
            'polling_interval_minutes' => 15,
            'is_active' => true,
            'last_fetched_at' => now()->subMinutes(2),
        ]);

        Http::fake([
            'https://stale.example/feed.xml' => Http::response(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <rss version="2.0">
                  <channel>
                    <title>Stale Feed</title>
                    <item>
                      <title>Fresh Item</title>
                      <link>https://stale.example/item-1</link>
                      <guid>item-1</guid>
                      <description>Example summary</description>
                    </item>
                  </channel>
                </rss>
                XML),
            '*' => Http::response('', 500),
        ]);

        $response = $this->postJson('/api/admin/feeds/fetch-all');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Eligible feeds fetched successfully.')
            ->assertJsonPath('stale_for_minutes', 5)
            ->assertJsonPath('eligible_count', 1)
            ->assertJsonPath('refreshed_count', 1)
            ->assertJsonPath('refreshed_feed_titles.0', 'Stale Feed')
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('failed_count', 0);

        Http::assertSentCount(1);
        Http::assertSent(fn($request) => $request->url() === 'https://stale.example/feed.xml');

        $this->assertTrue($staleFeed->fresh()->last_fetched_at->equalTo(now()));
        $this->assertTrue($recentFeed->fresh()->last_fetched_at->equalTo(now()->subMinutes(2)));

        Carbon::setTestNow();
    }

    public function test_fetch_command_skips_recent_feeds_when_stale_filter_is_used(): void
    {
        Carbon::setTestNow('2026-03-27 12:00:00');

        Feed::query()->create([
            'title' => 'Too Recent',
            'url' => 'https://recent.example/feed.xml',
            'position' => 0,
            'polling_interval_minutes' => 15,
            'is_active' => true,
            'last_fetched_at' => now()->subMinutes(4),
        ]);

        Http::fake();

        Artisan::call('feeds:fetch', [
            '--stale_for_minutes' => 5,
            '--limit' => 100,
        ]);

        Http::assertNothingSent();

        Carbon::setTestNow();
    }
}
