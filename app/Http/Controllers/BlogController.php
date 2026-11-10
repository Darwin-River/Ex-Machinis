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

    /**
     * The blog as RSS (#519), newest post first, so feed readers and free
     * sharing tools (Discord or Mastodon bots, Buffer, IFTTT) pick up new posts
     */
    public function feed()
    {
        $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $items = '';
        foreach (array_reverse(self::posts(), true) as $slug => $post) {
            $link = url('/blog/' . $slug);
            $date = strtotime($post['date']) ?: filemtime($post['file']);
            $items .= "    <item>\n"
                . '      <title>' . $x($post['title']) . "</title>\n"
                . '      <link>' . $x($link) . "</link>\n"
                . '      <guid isPermaLink="true">' . $x($link) . "</guid>\n"
                . '      <pubDate>' . gmdate(DATE_RSS, $date) . "</pubDate>\n"
                . '      <description>' . $x($post['summary']) . "</description>\n"
                . "    </item>\n";
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . "  <channel>\n"
            . '    <title>' . $x(config('app.name') . ' strategy blog') . "</title>\n"
            . '    <link>' . $x(url('/blog')) . "</link>\n"
            . '    <atom:link href="' . $x(url('/blog/feed')) . '" rel="self" type="application/rss+xml"/>' . "\n"
            . "    <description>How to write drones and companies that succeed in Ex Machinis</description>\n"
            . "    <language>en</language>\n"
            . $items
            . "  </channel>\n</rss>\n";
        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
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
