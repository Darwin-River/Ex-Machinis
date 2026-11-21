<?php

namespace Tests\Feature;

use App\Mail\ResetPassword;
use App\Services\Players;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** A forgotten password (#910): a link to the verified address, good for an hour, once */
class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['forge.new_rules' => true, 'forge.trial_auto_verify' => false]);
        Mail::fake();
    }

    private function player(string $name, bool $verified = true): \App\User
    {
        $email = strtolower($name) . '@example.com';
        $user = Players::create($name, 'old-password', $email);
        if ($verified) {
            Players::verify($user->user_id, sha1($email));
        }
        RateLimiter::clear('reset-mail|' . $user->user_id);
        return $user->fresh();
    }

    private function linkSentTo(string $email): string
    {
        $url = null;
        Mail::assertSent(ResetPassword::class, function ($mail) use ($email, &$url) {
            if ($mail->hasTo($email)) {
                $url = $mail->url;
                return true;
            }
            return false;
        });
        return $url;
    }

    private function path(string $url): string
    {
        return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    }

    public function testTheLoginPageLinksToIt()
    {
        $this->get('/login')->assertOk()->assertSee('Forgot your password?')->assertSee(url('password/forgot'));
        $this->get('/password/forgot')->assertOk()->assertSee('Name or e-mail address');
    }

    public function testItIsHiddenWhileTheNewRulesAreOff()
    {
        config(['forge.new_rules' => false]);
        $this->get('/password/forgot')->assertNotFound();
    }

    public function testTheReplyIsTheSameForAnyNameAndOnlyAVerifiedAddressGetsALink()
    {
        $this->player('Resetter');
        $this->player('Unverified', false);
        $replies = [];
        foreach (['Resetter', 'Unverified', 'Nobody Here', 'nobody@example.com'] as $given) {
            $replies[] = $this->post('/password/forgot', ['account' => $given])->assertRedirect('/password/forgot')
                ->getSession()->get('success');
        }
        $this->assertCount(1, array_unique($replies));
        Mail::assertSent(ResetPassword::class, 1);
        $this->linkSentTo('resetter@example.com');
    }

    public function testTheAddressFindsTheAccountToo()
    {
        $this->player('ByMail');
        $this->post('/password/forgot', ['account' => '  BYMAIL@example.com '])->assertRedirect();
        $this->linkSentTo('bymail@example.com');
    }

    public function testALinkSetsANewPasswordOnceAndLogsIn()
    {
        $user = $this->player('Forgetful');
        DB::table('users')->where('user_id', $user->user_id)->update(['api_token' => hash('sha256', 'x')]);
        $this->post('/password/forgot', ['account' => 'Forgetful']);
        $link = $this->path($this->linkSentTo('forgetful@example.com'));

        $this->get($link)->assertOk()->assertSee('Forgetful')->assertSee('Choose a new password');
        // Too short, or not the same twice: refused, and the link still works
        $this->post($link, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($link, ['password' => 'new-password', 'password_confirmation' => 'other-one'])->assertSessionHasErrors('password');
        $this->post($link, ['password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertRedirect('/account');
        $this->assertAuthenticatedAs($user->fresh());

        $row = DB::table('users')->where('user_id', $user->user_id)->first();
        $this->assertTrue(Hash::check('new-password', $row->password));
        $this->assertNull($row->api_token);
        $this->assertNotNull(Players::authenticate('Forgetful', 'new-password'));
        $this->assertNull(Players::authenticate('Forgetful', 'old-password'));

        // Used: the same link no longer works
        $this->post('/logout');
        $this->get($link)->assertRedirect('/password/forgot');
        $this->post($link, ['password' => 'third-password', 'password_confirmation' => 'third-password'])
            ->assertRedirect('/password/forgot');
        $this->assertNotNull(Players::authenticate('Forgetful', 'new-password'));
    }

    public function testATamperedOrExpiredLinkIsRefused()
    {
        $user = $this->player('Tamper');
        $this->post('/password/forgot', ['account' => 'Tamper']);
        $link = $this->path($this->linkSentTo('tamper@example.com'));
        $this->get(str_replace('/reset/' . $user->user_id . '/', '/reset/' . ($user->user_id + 1) . '/', $link))->assertForbidden();
        $this->get($link . 'x')->assertForbidden();
        $this->travel(Players::RESET_MINUTES + 1)->minutes();
        $this->get($link)->assertForbidden();
    }

    public function testAPasswordChangeEndsTheAccountsOtherSessions()
    {
        $user = $this->player('Elsewhere');
        $this->post('/login', ['name' => 'Elsewhere', 'password' => 'old-password'])->assertRedirect('/account');
        $this->get('/account')->assertOk();
        Players::resetPassword($user, 'changed-elsewhere');
        $this->app['auth']->forgetGuards();   // the next request reads the account afresh, as a real one does
        $this->get('/account')->assertRedirect('/login');
    }

    public function testAtMostThreeLinksAnHour()
    {
        $this->player('Spammed');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/password/forgot', ['account' => 'Spammed'])->assertRedirect('/password/forgot');
        }
        Mail::assertSent(ResetPassword::class, 3);
    }

    public function testAPupilGetsNoLink()
    {
        $teacher = $this->player('Teacher');
        config(['forge.class_group_max' => 40]);
        $group = Players::createClassGroup($teacher, 'Resetclass', 1);
        $this->post('/password/forgot', ['account' => $group['accounts'][0]['name']]);
        Mail::assertNothingSent();
    }
}
