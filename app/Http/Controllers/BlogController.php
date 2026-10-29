<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;

/**
 * The strategy blog: posts on how to write companies that succeed, kept as
 * Markdown in documents/blog. Each file starts with a front matter block
 * (title, date, summary); its name, less ".md", is the post's address.
 */
class BlogController extends Controller
{
    const POSTS = 'documents/blog';

    /** Every post, in file-name order: slug => [title, date, summary, file] */
    public static function posts(): array
    {
        $posts = [];
        foreach (glob(base_path(self::POSTS . '/*.md')) ?: [] as $file) {
            $slug = basename($file, '.md');
            $posts[$slug] = self::parse(file_get_contents($file))['meta'] + ['file' => $file];
        }
        uksort($posts, 'strnatcmp');
        return $posts;
    }

    /** A post's front matter and its Markdown body */
    public static function parse(string $text): array
    {
        $meta = ['title' => '', 'date' => '', 'summary' => ''];
        if (preg_match('/\A---\R(.*?)\R---\R/s', $text, $m)) {
            foreach (preg_split('/\R/', $m[1]) as $line) {
                if (preg_match('/^(\w+):\s*(.*)$/', $line, $kv)) {
                    $meta[$kv[1]] = trim($kv[2]);
                }
            }
            $text = substr($text, strlen($m[0]));
        }
        return ['meta' => $meta, 'body' => $text];
    }

    public function index()
    {
        $posts = self::posts();
        return view('pages.blog', compact('posts'));
    }

    public function show(string $slug)
    {
        $posts = self::posts();
        abort_unless(isset($posts[$slug]), 404);
        $post = $posts[$slug];
        $html = Cache::rememberForever('blog:' . $slug . ':' . filemtime($post['file']),
            fn () => HomeController::renderPlayersManual(self::parse(file_get_contents($post['file']))['body']));

        // The posts before and after this one, for the links at the foot
        $slugs = array_keys($posts);
        $at = array_search($slug, $slugs, true);
        $previous = $at > 0 ? $slugs[$at - 1] : null;
        $next = $slugs[$at + 1] ?? null;

        return view('pages.blog-post', compact('post', 'html', 'posts', 'previous', 'next'));
    }
}
