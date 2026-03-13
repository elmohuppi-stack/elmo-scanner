<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);
        $search = trim((string) $request->query('search', ''));

        $query = Article::query()
            ->with(['feed:id,title,url'])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($request->filled('feed_id')) {
            $query->where('feed_id', (int) $request->integer('feed_id'));
        }

        if ($search !== '') {
            $searchTerm = '%' . strtolower($search) . '%';
            $query->where(function ($subQuery) use ($searchTerm) {
                $subQuery
                    ->whereRaw('LOWER(title) LIKE ?', [$searchTerm])
                    ->orWhereRaw('LOWER(summary) LIKE ?', [$searchTerm]);
            });
        }

        return response()->json($query->paginate($perPage));
    }
}
