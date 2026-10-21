<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Smoke tests for the public website pages.
 *
 * These only read from the database. They expect the schema and data
 * loaded by the GitHub Actions workflow (see .github/workflows/tests.yml):
 * the game-engine SQL files plus tests/database/fixtures.sql.
 */
class PagesTest extends TestCase
{
    /**
     * @dataProvider staticPages
     */
    public function testStaticPagesLoad($url, $title)
    {
        $this->get($url)
            ->assertOk()
            ->assertSee("<title>{$title} - Ex-Machinis</title>", false);
    }

    public function staticPages()
    {
        return [
            'home'             => ['/', 'Home'],
            'home alias'       => ['/home', 'Home'],
            'forth dictionary' => ['/forth-dictionary', 'FORTH Dictionary'],
            'getting started'  => ['/getting-started', 'Getting Started'],
            'about us'         => ['/about-us', 'About Us'],
            'queries'          => ['/queries', 'Queries'],
            'message'          => ['/message', 'Notification'],
            'register'         => ['/register', 'Join the Game'],
            'resources'        => ['/resources', 'Resources'],
            'buy orders'       => ['/buys', 'Buy Orders'],
            'sell orders'      => ['/sells', 'Sell Orders'],
            'locations'        => ['/locations', 'Locations'],
            'spacecraft'       => ['/spacecraft', 'Spacecraft Search'],
            'protocols'        => ['/protocols', 'Protocols'],
            'companies'        => ['/companies', 'Companies'],
            'events'           => ['/events', 'In-Game Events'],
        ];
    }

    public function testAuthPagesLoad()
    {
        $this->get('/login')->assertOk();
        $this->get('/password/reset')->assertOk();
    }

    /**
     * @dataProvider detailPages
     */
    public function testDetailPagesShowRecord($url, $name)
    {
        $this->get($url)
            ->assertOk()
            ->assertSee("<title>{$name} - Ex-Machinis</title>", false);
    }

    public function detailPages()
    {
        return [
            'resource'   => ['/resources/1', 'Ore, Iron'],
            'location'   => ['/locations/3', 'Earth'],
            'spacecraft' => ['/spacecraft/1', 'Test Drone'],
            'company'    => ['/companies/1', 'Test Company'],
        ];
    }

    public function testMissingSpacecraftReturns404()
    {
        $this->get('/spacecraft/999999')->assertNotFound();
    }

    public function testBuyOrdersSearch()
    {
        $this->get('/buys/search')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.agent_name', 'Test Drone')
            ->assertJsonPath('data.0.resource_name', 'Ore, Iron')
            ->assertJsonPath('data.0.volume', 10);

        $this->get('/buys/search?resource=2')->assertOk()->assertJsonPath('total', 0);
    }

    public function testSellOrdersSearch()
    {
        $this->get('/sells/search')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.resource_name', 'Pellets, Iron')
            ->assertJsonPath('data.0.volume', 15);
    }

    public function testSpacecraftSearch()
    {
        $this->get('/spacecraft/search')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Test Drone')
            ->assertJsonPath('data.0.owner_name', 'Test Company')
            ->assertJsonPath('data.0.location_name', 'Earth');
    }

    public function testCompaniesSearch()
    {
        $this->get('/companies/search')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Test Company')
            ->assertJsonPath('data.0.agents', 1);
    }

    public function testObjectsSearch()
    {
        $this->get('/objects/search')
            ->assertOk()
            ->assertJsonPath('data.0.object_name', 'Sun');
    }

    public function testEventsSearch()
    {
        $this->get('/events/search')
            ->assertOk()
            ->assertJsonStructure(['current_page', 'data', 'total']);
    }
}
