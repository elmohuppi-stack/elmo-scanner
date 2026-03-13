<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Feed;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchFeedsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'feeds:fetch {--feed_id= : Fetch only one feed by id} {--limit=100 : Max number of feeds to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch RSS/Atom feeds and store new articles';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $query = Feed::query()->where('is_active', true)->orderBy('id');

        if ($this->option('feed_id')) {
            $query->where('id', (int) $this->option('feed_id'));
        }

        $feeds = $query->limit($limit)->get();

        if ($feeds->isEmpty()) {
            $this->info('No active feeds found.');

            return self::SUCCESS;
        }

        foreach ($feeds as $feed) {
            /** @var Feed $feed */
            $this->fetchFeed($feed);
        }

        $this->info('Feed import completed.');

        return self::SUCCESS;
    }

    private function fetchFeed(Feed $feed): void
    {
        try {
            $response = Http::timeout(12)
                ->retry(2, 500)
                ->withHeaders([
                    'User-Agent' => 'elmo-scanner-rss-reader/1.0',
                    'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9,*/*;q=0.8',
                ])
                ->get($feed->url);

            if (! $response->successful()) {
                throw new \RuntimeException('Feed request failed with status ' . $response->status());
            }

            $xml = $this->parseXml($response->body());
            if (! $xml) {
                throw new \RuntimeException('Feed XML could not be parsed.');
            }

            $feedTitle = $this->extractFeedTitle($xml);
            if (! $feed->title && $feedTitle) {
                $feed->title = $feedTitle;
            }

            $items = $this->extractItems($xml);
            $saved = 0;

            foreach ($items as $item) {
                $articleData = $this->normalizeItem($item);
                if (! $articleData) {
                    continue;
                }

                Article::updateOrCreate(
                    [
                        'feed_id' => $feed->id,
                        'url' => $articleData['url'],
                    ],
                    [
                        'title' => $articleData['title'],
                        'guid' => $articleData['guid'],
                        'summary' => $articleData['summary'],
                        'published_at' => $articleData['published_at'],
                        'author' => $articleData['author'],
                        'image_url' => $articleData['image_url'],
                        'content_hash' => $articleData['content_hash'],
                    ]
                );

                $saved++;
            }

            $feed->last_fetched_at = Carbon::now();
            $feed->last_error = null;
            $feed->save();

            $this->info("Feed {$feed->id} processed ({$saved} items).");
        } catch (\Throwable $e) {
            $feed->last_error = mb_substr($e->getMessage(), 0, 4000);
            $feed->save();

            $this->warn("Feed {$feed->id} failed: {$e->getMessage()}");
        }
    }

    private function parseXml(string $content): ?\SimpleXMLElement
    {
        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($content);

        libxml_clear_errors();

        return $xml ?: null;
    }

    private function extractFeedTitle(\SimpleXMLElement $xml): ?string
    {
        if (isset($xml->channel->title)) {
            return trim((string) $xml->channel->title);
        }

        if (isset($xml->title)) {
            return trim((string) $xml->title);
        }

        return null;
    }

    private function extractItems(\SimpleXMLElement $xml): array
    {
        if (isset($xml->channel->item)) {
            return iterator_to_array($xml->channel->item);
        }

        if (isset($xml->entry)) {
            return iterator_to_array($xml->entry);
        }

        return [];
    }

    private function normalizeItem(\SimpleXMLElement $item): ?array
    {
        $title = trim((string) ($item->title ?? 'Untitled'));
        $url = trim((string) ($item->link ?? ''));

        if ($url === '' && isset($item->link)) {
            $attributes = $item->link->attributes();
            $url = trim((string) ($attributes['href'] ?? ''));
        }

        if ($url === '') {
            return null;
        }

        $guid = trim((string) ($item->guid ?? $item->id ?? $url));
        $summary = trim((string) ($item->description ?? $item->summary ?? ''));

        $publishedRaw = trim((string) ($item->pubDate ?? $item->published ?? $item->updated ?? ''));
        $publishedAt = null;
        if ($publishedRaw !== '') {
            try {
                $publishedAt = Carbon::parse($publishedRaw);
            } catch (\Throwable $e) {
                $publishedAt = null;
            }
        }

        $author = trim((string) ($item->author->name ?? $item->author ?? ''));

        return [
            'title' => $title,
            'url' => $url,
            'guid' => $guid,
            'summary' => $summary,
            'published_at' => $publishedAt,
            'author' => $author !== '' ? $author : null,
            'image_url' => null,
            'content_hash' => hash('sha256', mb_strtolower($guid . '|' . $url . '|' . $title)),
        ];
    }
}
