<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Feed;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Builder;

class FetchFeedsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'feeds:fetch {--feed_id= : Fetch only one feed by id} {--limit=100 : Max number of feeds to process} {--stale_for_minutes= : Fetch only feeds not updated within the given number of minutes}';

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
        $limitOption = $this->option('limit');
        $limit = ($limitOption === null || $limitOption === '')
            ? null
            : max(1, (int) $limitOption);

        $query = Feed::query()->where('is_active', true)->orderBy('id');

        if ($this->option('feed_id')) {
            $query->where('id', (int) $this->option('feed_id'));
        }

        $staleForMinutes = $this->resolveStaleForMinutes();

        if ($staleForMinutes !== null && ! $this->option('feed_id')) {
            $cutoff = Carbon::now()->subMinutes($staleForMinutes);

            $query->where(function (Builder $builder) use ($cutoff) {
                $builder
                    ->whereNull('last_fetched_at')
                    ->orWhere('last_fetched_at', '<', $cutoff);
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $feeds = $query->get();

        if ($feeds->isEmpty()) {
            $message = $staleForMinutes !== null
                ? 'No eligible feeds found for refresh.'
                : 'No active feeds found.';

            $this->info($message);

            return self::SUCCESS;
        }

        foreach ($feeds as $feed) {
            /** @var Feed $feed */
            $this->fetchFeed($feed);
        }

        $this->info('Feed import completed.');

        return self::SUCCESS;
    }

    private function resolveStaleForMinutes(): ?int
    {
        $value = $this->option('stale_for_minutes');

        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
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

                $existingArticle = Article::query()
                    ->where('feed_id', $feed->id)
                    ->where(function (Builder $query) use ($articleData) {
                        $query->where('url', $articleData['url']);

                        if (! empty($articleData['guid'])) {
                            $query->orWhere('guid', $articleData['guid']);
                        }
                    })
                    ->first();

                if ($existingArticle) {
                    $readerFields = [];

                    if ($existingArticle->content_hash !== $articleData['content_hash']) {
                        $readerFields = [
                            'reader_html' => null,
                            'reader_text' => null,
                            'reader_extracted_at' => null,
                            'reader_error' => null,
                        ];
                    }

                    $existingArticle->fill([
                        'url' => $articleData['url'],
                        'title' => $articleData['title'],
                        'guid' => $articleData['guid'],
                        'summary' => $articleData['summary'],
                        'published_at' => $articleData['published_at'],
                        'author' => $articleData['author'],
                        'image_url' => $articleData['image_url'],
                        'categories' => $articleData['categories'],
                        'content_hash' => $articleData['content_hash'],
                        ...$readerFields,
                    ]);
                    $existingArticle->save();
                } else {
                    Article::create([
                        'feed_id' => $feed->id,
                        'url' => $articleData['url'],
                        'title' => $articleData['title'],
                        'guid' => $articleData['guid'],
                        'summary' => $articleData['summary'],
                        'published_at' => $articleData['published_at'],
                        'author' => $articleData['author'],
                        'image_url' => $articleData['image_url'],
                        'categories' => $articleData['categories'],
                        'content_hash' => $articleData['content_hash'],
                    ]);
                }

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
            $items = [];

            foreach ($xml->channel->item as $item) {
                $items[] = $item;
            }

            return $items;
        }

        if (isset($xml->entry)) {
            $items = [];

            foreach ($xml->entry as $item) {
                $items[] = $item;
            }

            return $items;
        }

        return [];
    }

    private function normalizeItem(\SimpleXMLElement $item): ?array
    {
        $title = $this->sanitizeText((string) ($item->title ?? 'Untitled'), 300);
        if ($title === '') {
            $title = 'Untitled';
        }

        $url = trim((string) ($item->link ?? ''));

        if ($url === '' && isset($item->link)) {
            $attributes = $item->link->attributes();
            $url = trim((string) ($attributes['href'] ?? ''));
        }

        if ($url === '') {
            return null;
        }

        $guid = trim((string) ($item->guid ?? $item->id ?? $url));

        $rawSummary = trim((string) ($item->description ?? $item->summary ?? ''));
        if ($rawSummary === '') {
            $content = $item->children('content', true);
            $rawSummary = trim((string) ($content->encoded ?? ''));
        }
        $summary = $this->sanitizeText($rawSummary, 4000);
        $imageUrl = $this->extractImageUrl($item, $rawSummary);
        $categories = $this->extractCategories($item);

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
            'image_url' => $imageUrl,
            'categories' => $categories,
            'content_hash' => hash('sha256', mb_strtolower($guid . '|' . $url . '|' . $title)),
        ];
    }

    private function extractImageUrl(\SimpleXMLElement $item, string $rawSummary): ?string
    {
        if (isset($item->enclosure)) {
            foreach ($item->enclosure as $enclosure) {
                $attributes = $enclosure->attributes();
                $url = trim((string) ($attributes['url'] ?? ''));
                $type = trim((string) ($attributes['type'] ?? ''));
                if ($url !== '' && ($type === '' || str_starts_with(mb_strtolower($type), 'image/'))) {
                    return mb_substr($url, 0, 2048);
                }
            }
        }

        $media = $item->children('media', true);
        if (isset($media->content)) {
            foreach ($media->content as $content) {
                $attributes = $content->attributes();
                $url = trim((string) ($attributes['url'] ?? ''));
                $type = trim((string) ($attributes['type'] ?? ''));
                if ($url !== '' && ($type === '' || str_starts_with(mb_strtolower($type), 'image/'))) {
                    return mb_substr($url, 0, 2048);
                }
            }
        }

        if (isset($media->thumbnail)) {
            foreach ($media->thumbnail as $thumbnail) {
                $attributes = $thumbnail->attributes();
                $url = trim((string) ($attributes['url'] ?? ''));
                if ($url !== '') {
                    return mb_substr($url, 0, 2048);
                }
            }
        }

        if (isset($item->link)) {
            foreach ($item->link as $link) {
                $attributes = $link->attributes();
                $href = trim((string) ($attributes['href'] ?? ''));
                $rel = mb_strtolower(trim((string) ($attributes['rel'] ?? '')));
                $type = mb_strtolower(trim((string) ($attributes['type'] ?? '')));
                if ($href !== '' && ($rel === 'enclosure' || str_starts_with($type, 'image/'))) {
                    return mb_substr($href, 0, 2048);
                }
            }
        }

        if ($rawSummary !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/iu', $rawSummary, $matches) === 1) {
            return mb_substr(trim($matches[1]), 0, 2048);
        }

        return null;
    }

    private function extractCategories(\SimpleXMLElement $item): ?array
    {
        $categories = [];

        if (isset($item->category)) {
            foreach ($item->category as $category) {
                $value = trim((string) $category);

                if ($value === '') {
                    $attributes = $category->attributes();
                    $value = trim((string) ($attributes['term'] ?? ''));
                }

                $value = $this->sanitizeText($value, 80);
                if ($value !== '') {
                    $categories[] = $value;
                }
            }
        }

        $dc = $item->children('dc', true);
        if (isset($dc->subject)) {
            foreach ($dc->subject as $subject) {
                $value = $this->sanitizeText((string) $subject, 80);
                if ($value !== '') {
                    $categories[] = $value;
                }
            }
        }

        $categories = array_values(array_unique($categories));

        return $categories === [] ? null : $categories;
    }

    private function sanitizeText(string $value, int $maxLength = 4000): string
    {
        if ($value === '') {
            return '';
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainText = strip_tags($decoded);
        $normalized = preg_replace('/\s+/u', ' ', trim($plainText));

        if (! is_string($normalized)) {
            $normalized = trim($plainText);
        }

        return mb_substr($normalized, 0, $maxLength);
    }
}
