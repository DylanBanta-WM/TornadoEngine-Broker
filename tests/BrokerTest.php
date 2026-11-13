<?php

namespace TornadoEngine\Broker\Tests;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Broker\BrokerException;
use TornadoEngine\Broker\Mcp\BrokerServer;
use TornadoEngine\Broker\Mcp\Tools\GamTool;
use TornadoEngine\Broker\Models\LogEntry;
use TornadoEngine\Broker\Models\Person;
use TornadoEngine\Broker\Models\Rule;
use TornadoEngine\Broker\Pseudonym;
use TornadoEngine\Cron\Cron;
use TornadoEngine\Cron\Models\CronJob;
use TornadoEngine\Gam\Gam;

/**
 * All people here are made up.
 */
class BrokerTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake GAM prints for commands other than the directory ones. */
    private string $output = '';

    /** @var list<list<string>> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Gam::class, new Gam('http://gam:8000', 'test-token'));

        Http::fake(function (Request $request) {
            $args = $request['args'] ?? [];
            $this->ran[] = $args;

            $stdout = match (true) {
                $args === ['print', 'users', 'allfields', 'formatjson'] => $this->directoryCsv(),
                ($args[0] ?? '') === 'print' && ($args[1] ?? '') === 'group-members' => "group,email\nhonor-roll@school.test,zed.quill@school.test\n",
                $args === ['print', 'groups', 'fields', 'email,name'] => "email,name\nhonor-roll@school.test,Honor Roll\nteachers@school.test,Teachers\n",
                default => $this->output,
            };

            return Http::response(['exit' => 0, 'stdout' => $stdout, 'stderr' => '']);
        });
    }

    private function directoryCsv(): string
    {
        $users = [
            [
                'primaryEmail' => 'ann.example@school.test', 'orgUnitPath' => '/Students/MS',
                'name' => ['fullName' => 'Ann Example', 'givenName' => 'Ann', 'familyName' => 'Example'],
                'aliases' => ['aexample@school.test'],
                'externalIds' => [['type' => 'organization', 'value' => '204417']],
                'phones' => [['value' => '740-555-0142']],
                'relations' => [['type' => 'mother', 'value' => 'Pat Example']],
                'recoveryEmail' => 'parent.example@home.test',
                'customSchemas' => ['StudentInfo' => ['LunchPIN' => '9137', 'Homeroom' => 'B12']],
            ],
            [
                'primaryEmail' => 'ann.other@school.test', 'orgUnitPath' => '/Students/HS',
                'name' => ['fullName' => 'Ann Other', 'givenName' => 'Ann', 'familyName' => 'Other'],
            ],
            [
                'primaryEmail' => 'zed.quill@school.test', 'orgUnitPath' => '/Guests',
                'name' => ['fullName' => 'Zed Quill', 'givenName' => 'Zed', 'familyName' => 'Quill'],
            ],
            [
                'primaryEmail' => 'wm30lost@school.test', 'orgUnitPath' => '/', 'suspended' => true,
                'name' => ['fullName' => 'Lost Student', 'givenName' => 'Lost', 'familyName' => 'Student'],
            ],
            [
                'primaryEmail' => 'kim.teacher@school.test', 'orgUnitPath' => '/Staff',
                'name' => ['fullName' => 'Kim Teacher', 'givenName' => 'Kim', 'familyName' => 'Teacher'],
                'externalIds' => [['type' => 'organization', 'value' => 'E-77']],
            ],
        ];

        $out = fopen('php://memory', 'r+');
        fputcsv($out, ['primaryEmail', 'JSON'], escape: '');
        foreach ($users as $user) {
            fputcsv($out, [$user['primaryEmail'], json_encode($user)], escape: '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    private function setUpBroker(): void
    {
        Rule::create(['type' => 'ou', 'value' => '/Students', 'sub_ous' => true]);
        Rule::create(['type' => 'group', 'value' => 'honor-roll@school.test']);
        $ok = app(Cron::class)->run(app(Cron::class)->find('broker.refresh'));
        $this->assertTrue($ok, (string) CronJob::find('broker.refresh')?->last_message);
    }

    private function filter(string $text): string
    {
        return app(Broker::class)->filter($text);
    }

    private function pseudonym(string $kind, string $email): string
    {
        return Pseudonym::for($kind, $email);
    }

    public function test_the_broker_refuses_to_run_without_rules(): void
    {
        $this->assertNotNull(app(Broker::class)->notReady());

        BrokerServer::tool(GamTool::class, ['args' => ['info', 'domain']])->assertHasErrors();
        $this->assertSame([], $this->ran);
    }

    public function test_the_refresh_sorts_students_from_staff_by_the_rules(): void
    {
        $this->setUpBroker();

        $this->assertSame('student', Person::where('email', 'ann.example@school.test')->value('kind'));
        $this->assertSame('student', Person::where('email', 'ann.other@school.test')->value('kind'));
        $this->assertSame('student', Person::where('email', 'zed.quill@school.test')->value('kind'), 'group members are students');
        $this->assertSame('staff', Person::where('email', 'kim.teacher@school.test')->value('kind'));

        $report = json_decode(Setting::get('broker.report'), true);
        $this->assertSame(1, $report['externalIds']);
        $this->assertSame(1, $report['customSchemas.StudentInfo.LunchPIN']);
        $this->assertNull(app(Broker::class)->notReady());
    }

    public function test_no_identifying_value_survives_the_filter(): void
    {
        $this->setUpBroker();
        $ann = $this->pseudonym('student', 'ann.example@school.test');
        $kim = $this->pseudonym('staff', 'kim.teacher@school.test');

        $out = $this->filter(implode("\n", [
            'primaryEmail,name.fullName,externalIds.0.value,phones.0.value,relations.0.value,recoveryEmail,LunchPIN',
            'ann.example@school.test,Ann Example,204417,740-555-0142,Pat Example,parent.example@home.test,9137',
            'Alias: aexample@school.test. ANN EXAMPLE signed in. Teacher: Kim Teacher (kim.teacher@school.test).',
            'Group honor-roll@school.test has 1 member. Annual report for Annapolis. Sent to stranger@home.test.',
        ]));

        foreach (['ann.example', 'aexample', 'Ann Example', 'ANN EXAMPLE', '204417', '740-555-0142', 'Pat Example', 'parent.example', '9137', 'Kim Teacher', 'kim.teacher', 'stranger@home.test'] as $real) {
            $this->assertStringNotContainsStringIgnoringCase($real, $out);
        }

        $this->assertStringContainsString("{$ann}@student", $out);
        $this->assertStringContainsString("[name {$ann}]", $out);
        $this->assertStringContainsString("[id {$ann}]", $out);
        $this->assertStringContainsString("{$kim}@staff", $out);
        $this->assertMatchesRegularExpression('/c-[0-9a-f]{10}@contact/', $out, 'unknown addresses become contacts');
        $this->assertStringContainsString('honor-roll@school.test', $out, 'groups are not people');
        $this->assertStringContainsString('Annual report for Annapolis', $out, 'names only match whole words');
    }

    public function test_a_given_name_shared_by_two_students_is_hidden_without_saying_who(): void
    {
        $this->setUpBroker();

        $this->assertSame('Hello [given]!', $this->filter('Hello Ann!'));
    }

    public function test_students_caught_by_a_pattern_but_outside_the_student_ous_are_listed_for_admins(): void
    {
        Rule::create(['type' => 'ou', 'value' => '/Students', 'sub_ous' => true]);
        Rule::create(['type' => 'email', 'value' => '^wm\\d{2}[a-z]+@school\\.test$']);
        app(Cron::class)->run(app(Cron::class)->find('broker.refresh'));

        $this->assertSame('student', Person::where('email', 'wm30lost@school.test')->value('kind'));

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/administration/broker')
            ->assertSee('Students outside your student OUs (1)')
            ->assertSee('caught by your email pattern rule (or a Google Group rule) but not by your OU rule')
            ->assertSee('Lost Student')
            ->assertSee('wm30lost@school.test')
            ->assertSee('In OU /, caught by your email pattern rule.')
            ->assertSee('suspended');
    }

    public function test_the_safety_net_hides_phone_numbers(): void
    {
        $this->setUpBroker();

        $this->assertSame('call [phone] or [phone] or [phone]', $this->filter('call (614) 555-0199 or 614.555.0199 or +44 20 7946 0958'));
    }

    public function test_pseudonyms_become_real_addresses_on_the_way_in(): void
    {
        $this->setUpBroker();
        $ann = $this->pseudonym('student', 'ann.example@school.test');

        $this->assertSame(['info', 'user', 'ann.example@school.test'], app(Broker::class)->reveal(['info', 'user', "{$ann}@student"]));

        $this->expectException(BrokerException::class);
        app(Broker::class)->reveal(['info', 'user', 's-0000000000@student']);
    }

    public function test_placeholders_cant_be_used_in_commands(): void
    {
        $this->setUpBroker();

        $this->expectException(BrokerException::class);
        app(Broker::class)->reveal(['update', 'user', 'x', 'firstname', '[given s-0123456789]']);
    }

    public function test_claude_runs_any_gam_command_and_only_sees_pseudonyms(): void
    {
        $this->setUpBroker();
        $ann = $this->pseudonym('student', 'ann.example@school.test');
        $this->output = "User: ann.example@school.test\nFull Name: Ann Example\nEmployee ID: 204417\nOrg Unit Path: /Students/MS\n";

        BrokerServer::tool(GamTool::class, ['args' => ['update', 'user', "{$ann}@student", 'org', '/Students/HS']])
            ->assertOk()
            ->assertSee("{$ann}@student")
            ->assertSee('/Students/MS')
            ->assertDontSee(['ann.example', 'Ann Example', '204417']);

        $this->assertSame(['update', 'user', 'ann.example@school.test', 'org', '/Students/HS'], end($this->ran));
        $this->assertSame(['update', 'user', 'ann.example@school.test', 'org', '/Students/HS'], LogEntry::latest('id')->first()->command);
    }

    public function test_pages_claude_opens_show_pseudonyms(): void
    {
        $this->setUpBroker();
        Route::middleware(['web', 'auth'])->get('/test-roster', fn () => '<td>Ann Example</td><td>ann.example@school.test</td>');

        $this->withServerVariables(['TORNADO_VIEW' => 'broker'])->get('/test-roster')
            ->assertOk()
            ->assertDontSee('Ann Example')
            ->assertDontSee('ann.example@school.test');

        $this->withServerVariables([])->actingAs(User::factory()->create())->get('/test-roster')->assertSee('Ann Example');
    }

    public function test_the_mcp_server_only_answers_the_harness(): void
    {
        $this->postJson('/mcp/broker', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertNotFound();
        $this->withServerVariables(['TORNADO_VIEW' => 'broker'])->postJson('/mcp/broker', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_admins_manage_rules_and_see_counts_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->from('/administration')->post('/administration/broker/rules', ['type' => 'ou', 'value' => 'Students'])->assertSessionHasErrors('value');
        $this->actingAs($admin)->from('/administration')->post('/administration/broker/rules', ['type' => 'email', 'value' => '([a-z'])->assertSessionHasErrors('value');
        $this->actingAs($admin)->from('/administration')->post('/administration/broker/rules', ['type' => 'ou', 'value' => '/Students', 'sub_ous' => '1'])->assertRedirect('/administration');

        $this->actingAs($admin)->from('/administration')->post('/administration/broker/refresh')->assertRedirect('/administration');

        $this->actingAs($admin)->get('/administration/broker')
            ->assertSee('Ready: 2 students and 3 staff')
            ->assertSee('externalIds')
            ->assertDontSee('204417');

        $this->actingAs(User::factory()->create())->post('/administration/broker/refresh')->assertForbidden();
    }
}
