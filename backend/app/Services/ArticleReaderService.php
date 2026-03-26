<?php

namespace App\Services;

use App\Models\Article;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class ArticleReaderService
{
    public function getReaderPayload(Article $article): array
    {
        if ($this->hasCachedReaderContent($article)) {
            return [
                'html' => $article->reader_html,
                'text' => $article->reader_text,
                'extracted_at' => optional($article->reader_extracted_at)?->toIso8601String(),
                'source' => 'reader',
                'cached' => true,
                'error' => null,
            ];
        }

        try {
            $document = $this->fetchAndParseDocument($article->url);
            $contentNode = $this->extractContentNode($document['xpath']);

            if (! $contentNode instanceof DOMElement) {
                throw new \RuntimeException('Kein lesbarer Artikelinhalt gefunden.');
            }

            $html = trim($this->sanitizeNode($contentNode, $article->url));
            $text = $this->sanitizeText($contentNode->textContent ?? '', 24000);

            if ($html === '' || $text === '') {
                throw new \RuntimeException('Artikelinhalt konnte nicht bereinigt werden.');
            }

            $article->forceFill([
                'reader_html' => $html,
                'reader_text' => $text,
                'reader_extracted_at' => Carbon::now(),
                'reader_error' => null,
            ])->save();

            return [
                'html' => $article->reader_html,
                'text' => $article->reader_text,
                'extracted_at' => optional($article->reader_extracted_at)?->toIso8601String(),
                'source' => 'reader',
                'cached' => false,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $article->forceFill([
                'reader_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            return [
                'html' => $this->buildSummaryHtml($article),
                'text' => $article->summary ?: '',
                'extracted_at' => optional($article->reader_extracted_at)?->toIso8601String(),
                'source' => 'summary',
                'cached' => false,
                'error' => 'Die Originalseite blockiert oder erschwert das Auslesen. Es wird die Feed-Zusammenfassung angezeigt.',
            ];
        }
    }

    private function hasCachedReaderContent(Article $article): bool
    {
        return is_string($article->reader_html)
            && trim($article->reader_html) !== ''
            && is_string($article->reader_text)
            && trim($article->reader_text) !== '';
    }

    private function fetchAndParseDocument(string $url): array
    {
        $response = Http::timeout(12)
            ->retry(1, 400)
            ->withHeaders([
                'User-Agent' => 'elmo-scanner-reader/1.0',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Artikel request fehlgeschlagen mit Status ' . $response->status());
        }

        $html = trim($response->body());
        if ($html === '') {
            throw new \RuntimeException('Artikelantwort war leer.');
        }

        libxml_use_internal_errors(true);

        $document = new DOMDocument();
        $loaded = $document->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        if (! $loaded) {
            throw new \RuntimeException('HTML des Artikels konnte nicht geparst werden.');
        }

        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//script|//style|//noscript|//iframe|//svg|//form|//button') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        return [
            'document' => $document,
            'xpath' => $xpath,
        ];
    }

    private function extractContentNode(DOMXPath $xpath): ?DOMElement
    {
        $preferredQueries = [
            '//article',
            '//main//article',
            '//*[@role="main"]//article',
            '//*[contains(@class, "article-body")]',
            '//*[contains(@class, "article-content")]',
            '//*[contains(@class, "entry-content")]',
            '//*[contains(@class, "post-content")]',
            '//*[contains(@class, "story-content")]',
            '//*[contains(@class, "content-body")]',
            '//main',
        ];

        foreach ($preferredQueries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if ($node instanceof DOMElement && $this->isReadableNode($xpath, $node)) {
                    return $node;
                }
            }
        }

        $bestNode = null;
        $bestScore = 0;
        $candidates = $xpath->query('//article|//main|//section|//div');

        if ($candidates === false) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof DOMElement) {
                continue;
            }

            $text = $this->sanitizeText($candidate->textContent ?? '', 40000);
            $textLength = mb_strlen($text);

            if ($textLength < 180) {
                continue;
            }

            $paragraphCount = $xpath->query('.//p', $candidate)?->length ?? 0;
            $linkTextLength = 0;

            foreach ($xpath->query('.//a', $candidate) ?: [] as $link) {
                $linkTextLength += mb_strlen(trim($link->textContent ?? ''));
            }

            $linkDensity = $textLength > 0 ? $linkTextLength / $textLength : 1;
            $score = $textLength + ($paragraphCount * 220) - (int) round($linkDensity * 700);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestNode = $candidate;
            }
        }

        return $bestNode;
    }

    private function isReadableNode(DOMXPath $xpath, DOMElement $node): bool
    {
        $textLength = mb_strlen($this->sanitizeText($node->textContent ?? '', 40000));
        $paragraphCount = $xpath->query('.//p', $node)?->length ?? 0;

        return $textLength >= 40 && $paragraphCount >= 1;
    }

    private function sanitizeNode(DOMNode $node, string $baseUrl): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tagName = mb_strtolower($node->nodeName);
        $blockedTags = ['script', 'style', 'noscript', 'iframe', 'object', 'embed', 'canvas', 'svg'];
        if (in_array($tagName, $blockedTags, true)) {
            return '';
        }

        $allowedTags = [
            'p',
            'br',
            'ul',
            'ol',
            'li',
            'blockquote',
            'strong',
            'em',
            'b',
            'i',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'a',
            'img',
            'figure',
            'figcaption',
            'pre',
            'code',
            'hr',
        ];

        $childrenHtml = '';
        foreach ($node->childNodes as $childNode) {
            $childrenHtml .= $this->sanitizeNode($childNode, $baseUrl);
        }

        if (! in_array($tagName, $allowedTags, true)) {
            return $childrenHtml;
        }

        $attributes = [];

        if ($tagName === 'a' && $node instanceof DOMElement) {
            $href = $this->normalizeUrl($baseUrl, $node->getAttribute('href'));
            if ($href !== null) {
                $attributes[] = 'href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                $attributes[] = 'target="_blank"';
                $attributes[] = 'rel="noreferrer noopener"';
            }

            $title = trim($node->getAttribute('title'));
            if ($title !== '') {
                $attributes[] = 'title="' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }

        if ($tagName === 'img' && $node instanceof DOMElement) {
            $src = $this->normalizeUrl($baseUrl, $node->getAttribute('src'));
            if ($src === null) {
                return '';
            }

            $attributes[] = 'src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';

            $alt = trim($node->getAttribute('alt'));
            if ($alt !== '') {
                $attributes[] = 'alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }

        $attributeString = $attributes === [] ? '' : ' ' . implode(' ', $attributes);
        $voidTags = ['br', 'hr', 'img'];

        if (in_array($tagName, $voidTags, true)) {
            return '<' . $tagName . $attributeString . '>';
        }

        $trimmedChildren = trim($childrenHtml);
        if ($trimmedChildren === '' && in_array($tagName, ['p', 'li', 'blockquote'], true)) {
            return '';
        }

        return '<' . $tagName . $attributeString . '>' . $childrenHtml . '</' . $tagName . '>';
    }

    private function normalizeUrl(string $baseUrl, ?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(https?:|mailto:)/i', $value) === 1) {
            return $value;
        }

        if (str_starts_with($value, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme . ':' . $value;
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (str_starts_with($value, '/')) {
            return $scheme . '://' . $host . $port . $value;
        }

        $path = $parts['path'] ?? '/';
        $directory = preg_replace('~/[^/]*$~', '/', $path) ?: '/';

        return $scheme . '://' . $host . $port . $directory . $value;
    }

    private function buildSummaryHtml(Article $article): string
    {
        $summary = trim((string) $article->summary);
        if ($summary === '') {
            return '<p>Keine Vorschau verfuegbar.</p>';
        }

        $paragraphs = preg_split('/\n{2,}|(?<=\.)\s{2,}/u', $summary) ?: [$summary];
        $html = [];

        foreach ($paragraphs as $paragraph) {
            $normalized = $this->sanitizeText($paragraph, 4000);
            if ($normalized === '') {
                continue;
            }

            $html[] = '<p>' . htmlspecialchars($normalized, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return $html === [] ? '<p>Keine Vorschau verfuegbar.</p>' : implode('', $html);
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
