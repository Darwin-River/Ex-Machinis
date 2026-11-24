<?php

namespace Tests\Feature;

use App\Command;
use App\Services\Release;
use Tests\TestCase;

/**
 * #1035: a drone's real limit is memory. A script must leave 64 KB of its
 * 256 KB for data once loaded; comments take none. The character limit only
 * bounds what is stored and shown, and a line may be as long as the engine
 * reads a command.
 */
class ScriptLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable(config('forge.lua_check'))) {
            $this->markTestSkipped('lua-check is built with the engine (make -C game-engine/src)');
        }
    }

    /** n functions, each building and returning a table */
    private static function code(int $functions): string
    {
        $out = '';
        for ($i = 0; $i < $functions; $i++) {
            $out .= "function step_$i(x)\n  local t = {x, x + $i, \"step $i\"}\n  if x > $i then return t end\n  return {t, t}\nend\n";
        }
        return $out . "function launched() step_0(1) end\n";
    }

    public function testTheCheckReportsMemoryAndTheStartersFit()
    {
        foreach (glob(base_path('examples/starters/*.lua')) as $file) {
            $memory = Release::luaMemory((string) file_get_contents($file));
            $this->assertNotNull($memory, basename($file));
            $this->assertSame(256 * 1024, $memory['cap']);
            $this->assertSame(64 * 1024, $memory['headroom']);
            $this->assertGreaterThan(0, $memory['script']);
            $this->assertLessThan($memory['allowance'], $memory['in_use'], basename($file));
        }
        $miner = (string) file_get_contents(base_path('examples/starters/miner.lua'));
        $this->postJson('/api/v1/check', ['script' => $miner])->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('max_length', 64000)
            ->assertJsonPath('memory.cap', 256 * 1024);
    }

    public function testCommentsTakeNoMemory()
    {
        $code = self::code(20);
        $commented = str_repeat("-- " . str_repeat('why it does this ', 4) . "\n", 300) . $code;
        $this->assertGreaterThan(20000, strlen($commented) - strlen($code));
        // Only the line numbers kept for error messages shift, by a few bytes
        $this->assertEqualsWithDelta(Release::luaMemory($code)['script'], Release::luaMemory($commented)['script'], 64);
        $this->assertNull(Release::scriptProblem($commented));
    }

    public function testAScriptThatLeavesTooLittleMemoryIsRefused()
    {
        $script = self::code(400);
        $this->assertLessThan(config('forge.max_script_length'), mb_strlen($script), 'within the character limit');
        $memory = Release::luaMemory($script);
        $this->assertGreaterThan($memory['allowance'], $memory['in_use']);
        $this->assertStringContainsString('of memory, leaving less than the 64 KB it needs for its data', Release::scriptProblem($script));
        $this->postJson('/api/v1/check', ['script' => $script])->assertOk()->assertJsonPath('ok', false);
    }

    public function testALongLineIsAcceptedUpToWhatTheEngineReads()
    {
        $long = 'names = {' . implode(', ', array_fill(0, 600, '"a name"')) . "}\nfunction launched() end\n";
        $this->assertGreaterThan(2048, strlen(explode("\n", $long)[0]));
        $this->assertNull(Release::scriptProblem($long));
        $tooLong = 'x = "' . str_repeat('a', Command::MAX_CODE_LENGTH) . "\"\nfunction launched() end\n";
        $this->assertStringContainsString('Line 1 is too long', Release::scriptProblem($tooLong));
        foreach (Release::chunks($long . $long) as $chunk) {
            $this->assertLessThanOrEqual(Command::MAX_CODE_LENGTH, strlen($chunk));
        }
    }
}
