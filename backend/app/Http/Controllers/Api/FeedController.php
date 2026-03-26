<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class FeedController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->integer('per_page', 50), 100);

        $feeds = Feed::query()
            ->orderBy('position', 'asc')
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);

        return response()->json($feeds);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'polling_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $maxPosition = Feed::query()->max('position') ?? 0;

        $feed = Feed::create([
            'url' => $validated['url'],
            'title' => $validated['title'] ?? null,
            'polling_interval_minutes' => $validated['polling_interval_minutes'] ?? 15,
            'is_active' => $validated['is_active'] ?? true,
            'position' => $maxPosition + 1,
        ]);

        return response()->json($feed, 201);
    }

    public function fetch(Feed $feed)
    {
        Artisan::call('feeds:fetch', [
            '--feed_id' => $feed->id,
            '--limit' => 1,
        ]);

        $freshFeed = Feed::query()->findOrFail($feed->id);

        return response()->json([
            'message' => 'Feed fetch completed.',
            'feed' => $freshFeed,
            'output' => trim(Artisan::output()),
        ]);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'feeds' => ['required', 'array'],
            'feeds.*.id' => ['required', 'integer', 'exists:feeds,id'],
            'feeds.*.position' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated['feeds'] as $feedData) {
            Feed::query()
                ->where('id', $feedData['id'])
                ->update(['position' => $feedData['position']]);
        }

        return response()->json(['message' => 'Feeds reordered successfully.']);
    }

    public function update(Request $request, Feed $feed)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'polling_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $feed->update($validated);

        return response()->json($feed);
    }

    public function destroy(Feed $feed)
    {
        $feed->articles()->delete();
        $feed->delete();

        return response()->json(['message' => 'Feed deleted successfully.'], 200);
    }

    public function fetchAll()
    {
        Artisan::call('feeds:fetch', [
            '--limit' => null,
        ]);

        return response()->json([
            'message' => 'All feeds fetched successfully.',
            'output' => trim(Artisan::output()),
        ]);
    }
}
