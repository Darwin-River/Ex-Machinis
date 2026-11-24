<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The startups' caps stay retired (#1013): every deploy reloads
 * game_data.sql after the migrations, so its house_rules row must carry the
 * retired values itself (it took 0087's defaults, 100 and 2000, and brought
 * both caps back), and the columns' defaults agree.
 */
class StartupCapsRetiredTest extends TestCase
{
    use DatabaseTransactions;

    public function testTheGameDataWritesTheRetiredCaps()
    {
        $sql = file_get_contents(base_path('game-engine/sql/game_data.sql'));
        $this->assertSame(1, preg_match('/INSERT INTO `house_rules` \(([^)]*)\) VALUES\s*\(([^)]*)\)/', $sql, $m));
        $row = array_combine(array_map('trim', explode(',', $m[1])), array_map('trim', explode(',', $m[2])));
        $this->assertSame('1000', $row['startup_buy_permille'] ?? null, 'a startup may buy all the House has on sale');
        $this->assertSame('0', $row['startup_credit_cap_permille'] ?? null, 'no credit cap');
    }

    public function testARowInsertedWithoutThemTakesTheRetiredDefaults()
    {
        // As game_data.sql did before #1013: the row without the two columns
        DB::table('house_rules')->delete();
        DB::table('house_rules')->insert(['id' => 1, 'tax_period' => 0, 'tax_grace' => 259200, 'tax_flat' => 0, 'tax_permille' => 5,
            'plunder_share' => 50, 'start_credits' => 3000, 'start_fuel' => 100, 'sales_tax_permille' => 50,
            'compute_tax_per_million' => 15, 'dissolve_days' => 50]);
        $r = DB::table('house_rules')->where('id', 1)->first(['startup_buy_permille', 'startup_credit_cap_permille']);
        $this->assertSame([1000, 0], [(int) $r->startup_buy_permille, (int) $r->startup_credit_cap_permille]);
    }
}
