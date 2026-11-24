<?php

namespace Tests\Feature;

use App\Mail\Notice;
use App\Services\Notices;
use App\Services\Players;
use App\Services\Startups;
use App\Services\World;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * New on the exchange (#996): companies that offered their shares for the
 * first time and loan requests, on the stock exchange's front page and in
 * an opt-in notice that leaves out the player's own companies.
 */
class NewListingsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
        Cache::flush();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $u = Players::create($name, 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        return User::where('user_id', $u->user_id)->first();
    }

    private function goesPublic(User $owner, string $name): int
    {
        $id = Startups::start($owner, $name, 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 1000 + Startups::grant()]);
        $this->assertTrue(Startups::mature($owner, $id)['ok']);
        DB::table('companies')->where('company_id', $id)->update(['code_public_t' => World::gameNow()]);
        return $id;
    }

    private function asks(int $company, int $amount): void
    {
        DB::table('loan_requests')->insert(['company_id' => $company, 'drone' => (int) DB::table('agents')->where('company_id', $company)->value('agent_id'),
            'amount' => $amount, 'rate_bp' => 750, 'requested_t' => World::gameNow(), 'due_t' => World::gameNow() + 15 * 86400]);
    }

    public function testTheStockExchangeShowsWhatIsNew()
    {
        $this->get('/market/shares')->assertOk()->assertSee('id="new-on-exchange"', false)
            ->assertSee('No company has offered its shares for the first time');
        $founder = $this->player('Listing Founder');
        $id = $this->goesPublic($founder, 'Fresh Listing Co');
        $this->asks($id, 2500);
        $this->get('/market/shares')->assertOk()->assertSee('Fresh Listing Co')
            ->assertSee('offered its shares for the first time')->assertSee('asks to borrow 2,500 credits at 7.5%')
            ->assertSee('companies/' . $id . '#investor-sheet', false);
        // A week on, it isn't new
        DB::table('companies')->where('company_id', $id)->update(['code_public_t' => World::gameNow() - 8 * 86400]);
        DB::table('loan_requests')->where('company_id', $id)->update(['requested_t' => World::gameNow() - 8 * 86400]);
        $this->get('/market/shares')->assertOk()->assertDontSee('class="new-ipo"', false)->assertDontSee('class="new-loan-request"', false)
            ->assertSee('No company has offered its shares for the first time');
    }

    public function testTheNoticeTellsWhatIsNewSinceItLastToldAndNotYourOwn()
    {
        $reader = $this->player('Listing Reader');
        $founder = $this->player('Listing Other');
        $this->assertFalse(Notices::settings($reader)['listings'], 'off until turned on');
        Notices::save($reader, ['listings']);
        // Before it was turned on: not told
        DB::table('notice_settings')->where('user_id', $reader->user_id)->update(['listings_since' => World::gameNow() - 10]);
        $mine = $this->goesPublic($reader, 'My Own Listing');
        $theirs = $this->goesPublic($founder, 'Their Listing');
        $this->asks($theirs, 1200);
        $this->assertSame(1, Notices::runIfDue(true)['daily']);
        $texts = [];
        Mail::assertSent(Notice::class, function ($m) use (&$texts) {
            $texts = array_merge($texts, array_column($m->items, 'text'));
            return true;
        });
        $this->assertCount(2, $texts);
        $this->assertStringContainsString('Their Listing offered its shares for the first time', implode(' ', $texts));
        $this->assertStringContainsString('Their Listing asks to borrow 1,200 credits', implode(' ', $texts));
        $this->assertStringNotContainsString('My Own Listing', implode(' ', $texts));
        // Told once: the next day there is nothing new
        DB::table('notice_settings')->where('user_id', $reader->user_id)->update(['last_daily_at' => now()->subDays(2)]);
        Cache::flush();
        $this->assertSame(0, Notices::runIfDue(true)['daily']);
    }
}
