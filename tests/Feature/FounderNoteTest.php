<?php

namespace Tests\Feature;

use App\Services\FounderNote;
use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\Startups;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A founder's note (#998): a short plain text its founder keeps on a
 * company's page, before and after the lock; nobody else can write it.
 */
class FounderNoteTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.startup_hull_value' => 2000, 'forge.startup_fee' => 500,
            'forge.starting_credits' => 3000, 'forge.startup_max' => 5, 'forge.startup_mature_margin' => 0]);
        Mail::fake();
    }

    private function player(string $name): User
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $u = Players::create($name, 'long-enough', $email);
        Players::verify($u->user_id, sha1($email));
        return User::where('user_id', $u->user_id)->first();
    }

    public function testTheFounderWritesANoteThatStaysAfterTheLock()
    {
        $founder = $this->player('Note Founder');
        $other = $this->player('Note Reader');
        $id = Startups::start($founder, 'Noted Co', 'function launched() wait(60) end')['company'];
        DB::table('companies')->where('company_id', $id)->update(['credits' => 1000 + Startups::grant()]);
        $this->assertTrue(Startups::mature($founder, $id)['ok']);
        $this->assertTrue(PlayerShares::sellToBank($founder, $id, 10)['ok']);   // public: its code is locked

        // Only its founder sees the form, and only its founder can save
        $this->actingAs($other)->get('/companies/' . $id)->assertOk()->assertDontSee('id="founder-note"', false);
        $this->actingAs($other)->post('/companies/' . $id . '/note', ['note' => 'Buy now!'])->assertRedirect();
        $this->assertNull(FounderNote::of($id));
        $this->actingAs($founder)->get('/companies/' . $id)->assertOk()->assertSee('Write a note');

        $this->actingAs($founder)->post('/companies/' . $id . '/note', ['note' => "  Known issue: it idles\nif fuel is over 900 <b>  "])
            ->assertRedirect('/companies/' . $id . '#founder-note');
        $this->assertSame('Known issue: it idles if fuel is over 900 <b>', FounderNote::of($id)['text']);
        // Everyone reads it, escaped
        auth()->logout();
        $this->get('/companies/' . $id)->assertOk()->assertSee('Founder\'s note', false)
            ->assertSee('Known issue: it idles if fuel is over 900 &lt;b&gt;', false)->assertDontSee('Write a note');

        // Too long is refused; empty clears it
        $this->assertFalse(FounderNote::save($founder, $id, str_repeat('x', FounderNote::MAX + 1))['ok']);
        $this->assertTrue(FounderNote::save($founder, $id, '   ')['ok']);
        $this->assertNull(FounderNote::of($id));
        // Not once the company has ended
        DB::table('companies')->where('company_id', $id)->update(['eliminated_at' => \App\Services\World::gameNow()]);
        $this->assertFalse(FounderNote::save($founder, $id, 'Too late')['ok']);
    }
}
