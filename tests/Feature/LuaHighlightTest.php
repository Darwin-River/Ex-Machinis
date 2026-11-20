<?php

namespace Tests\Feature;

use App\Services\LuaHighlight;
use App\Services\Release;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** #848: Lua in colour, wherever the site shows it */
class LuaHighlightTest extends TestCase
{
    use DatabaseTransactions;

    private const SCRIPT = <<<'LUA'
--[==[ a long
comment ]==]
local hold_cap = 40 -- metals it keeps
function launched()
  local t = {{2031, 1}}
  while true do
    go(moon)
    if units(fuel) < 0x1F and t.fuel ~= nil then print("low <fuel> & \"tanks\"", 'é') end
    for _, o in ipairs(sell_orders(fuel)) do x = x .. [[long
string]] .. #o end
  end
end
local s = "unterminated
LUA;

    private static function spans(string $html): array
    {
        preg_match_all('/<span class="(\w)">([^<]*)<\/span>/', $html, $m, PREG_SET_ORDER);
        return array_map(fn ($x) => [$x[1], html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5)], $m);
    }

    public function testTheTextIsTheScriptUnchanged()
    {
        $html = LuaHighlight::html(self::SCRIPT);
        $this->assertSame(self::SCRIPT, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
        $this->assertStringNotContainsString('<fuel>', $html, 'text is escaped');
        $this->assertStringContainsString('&lt;fuel&gt;', $html);
    }

    public function testEachKindOfTokenHasItsColour()
    {
        $spans = self::spans(LuaHighlight::html(self::SCRIPT));
        foreach ([
            ['c', "--[==[ a long\ncomment ]==]"], ['c', '-- metals it keeps'],
            ['k', 'local'], ['k', 'function'], ['k', 'while'], ['k', 'end'], ['k', 'and'],
            ['l', 'true'], ['l', 'nil'],
            ['n', '40'], ['n', '0x1F'], ['n', '2031'],
            ['s', '"low <fuel> & \"tanks\""'], ['s', "'é'"], ['s', "[[long\nstring]]"], ['s', '"unterminated'],
            ['o', '='], ['o', '('], ['o', '{'], ['o', '~='], ['o', '..'], ['o', '#'], ['o', ','],
            ['b', 'ipairs'],
            ['g', 'go'], ['g', 'moon'], ['g', 'units'], ['g', 'fuel'], ['g', 'print'], ['g', 'sell_orders'],
            ['f', 'launched'],
        ] as $want) {
            $this->assertContains($want, $spans, $want[1] . ' is ' . $want[0]);
        }
        // A field is the script's, whatever its name; a name of the script's own is plain
        $this->assertCount(2, array_filter($spans, fn ($s) => $s === ['g', 'fuel']), 't.fuel is not the library\'s fuel');
        $this->assertNotContains(['g', 'hold_cap'], $spans);
        $this->assertStringContainsString(' hold_cap ', LuaHighlight::html(self::SCRIPT));
    }

    public function testABlockIsVPreWithItsAttributes()
    {
        $this->assertSame('<pre id="x" class="lua home-code" v-pre><span class="k">end</span></pre>',
            LuaHighlight::block('end', ['id' => 'x', 'class' => 'home-code']));
        $this->assertSame('<pre class="lua" v-pre></pre>', LuaHighlight::block(null));
    }

    public function testTheManualAndBlogColourTheirLuaBlocksOnly()
    {
        $html = $this->get('/manual')->assertOk()->getContent();
        $this->assertStringContainsString('<pre class="lua" v-pre><code class="language-lua">', $html);
        $this->assertStringNotContainsString('<pre><code class="language-lua">', $html, 'every Lua block is coloured');
        // A drone's output (an unlabelled block) stays plain
        $this->assertMatchesRegularExpression('/<pre><code>Location: Earth/', $html);

        $this->get('/blog/1-state-machines')->assertOk()
            ->assertSee('<span class="k">function</span> <span class="f">step</span>', false);
    }

    public function testTheHomePageAndCompanyCodeAreColoured()
    {
        config(['forge.new_rules' => true]);
        $this->get('/')->assertOk()->assertSee('<pre class="lua home-code" aria-label="A sketch of the Miner starter script" v-pre>', false)
            ->assertSee('<span class="c">-- A sketch of the Miner starter</span>', false);

        $user = \App\User::create(['name' => 'Colours', 'email' => uniqid() . '@example.com', 'password' => bcrypt('secret-password')]);
        $agent = Release::release($user, 'Colour Co', "function launched()\n  go(moon)\nend\n");
        $this->get('/companies/' . $agent->company_id)->assertOk()
            ->assertSee('<span class="k">function</span> <span class="f">launched</span>', false)
            ->assertSee('<span class="g">go</span><span class="o">(</span><span class="g">moon</span>', false);
    }

    /** #852: the box players type Lua into is marked for the editor, which colours the same game names */
    public function testTheScriptBoxIsALuaEditorWithTheGameNames()
    {
        $names = LuaHighlight::gameNames();
        $this->assertContains('print', $names);
        $this->assertContains('go', $names);
        $this->assertContains('moon', $names);
        $this->assertNotContains('pairs', $names);
        $this->assertSame('<span class="g">go</span>', LuaHighlight::html('go'));

        $page = $this->get('/release')->assertOk()
            ->assertSee('id="script" name="script" rows="20" spellcheck="false" data-lua-editor', false)
            ->assertSee('window.exLuaGameNames = ', false);
        $this->assertSame(1, substr_count($page->getContent(), 'window.exLuaGameNames'));
    }
}
