<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Feed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_sanitized_reader_content_for_an_article(): void
    {
        Http::fake([
            'https://example.com/story' => Http::response(<<<'HTML'
                <!doctype html>
                <html>
                  <body>
                    <article>
                      <h1>Headline</h1>
                      <p>Erster Absatz.</p>
                      <p>Zweiter Absatz mit <strong>mehr Inhalt</strong>.</p>
                      <script>alert('xss');</script>
                    </article>
                  </body>
                </html>
                HTML),
        ]);

        $feed = Feed::query()->create([
            'title' => 'Test Feed',
            'url' => 'https://example.com/feed.xml',
            'position' => 0,
            'polling_interval_minutes' => 15,
            'is_active' => true,
        ]);

        $article = Article::query()->create([
            'feed_id' => $feed->id,
            'title' => 'Test Story',
            'url' => 'https://example.com/story',
            'guid' => 'story-1',
            'summary' => 'Kurzfassung',
            'content_hash' => hash('sha256', 'story-1'),
        ]);

        $response = $this->getJson('/api/articles/' . $article->id);

        $response
            ->assertOk()
            ->assertJsonPath('id', $article->id)
            ->assertJsonPath('reader.source', 'reader')
            ->assertJsonPath('reader.error', null);

        $this->assertStringContainsString('Erster Absatz.', (string) data_get($response->json(), 'reader.text'));
        $this->assertStringContainsString('<p>Erster Absatz.</p>', (string) data_get($response->json(), 'reader.html'));
        $this->assertStringNotContainsString('<script>', (string) data_get($response->json(), 'reader.html'));
    }
}
