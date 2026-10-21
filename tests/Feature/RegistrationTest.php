<?php

namespace Tests\Feature;

use App\Agent;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Player sign-up through the home page form (POST /web-register).
 * Runs inside a transaction, so nothing is left in the database.
 */
class RegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function testNewPlayerGetsAccountAndThreeDrones()
    {
        $this->post('/web-register', [
            'name' => 'New Player',
            'email' => 'New.Player@Example.com',
        ])->assertRedirect('/message');

        $user = User::where('email', 'new.player@example.com')->first();
        $this->assertNotNull($user, 'user was not created');
        $this->assertSame('New Player', $user->name);
        $this->assertEquals(1000, $user->credits);

        $drones = Agent::where('user_id', $user->user_id)->get();
        $this->assertCount(3, $drones);
        $this->assertEquals([3, 188, 189], $drones->pluck('object_id')->sort()->values()->all());
    }

    public function testDuplicateEmailIsRejected()
    {
        $this->from('/')->post('/web-register', [
            'name' => 'Someone Else',
            'email' => 'test@example.com',
        ])->assertRedirect('/')->assertSessionHasErrors('email');

        $this->assertNull(User::where('name', 'Someone Else')->first());
    }
}
