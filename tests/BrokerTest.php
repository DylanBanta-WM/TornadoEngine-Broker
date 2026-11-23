<?php

namespace TornadoEngine\Broker\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use TornadoEngine\Access\Models\Role;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Broker\Directory;
use TornadoEngine\Broker\Filter;
use TornadoEngine\Broker\Mcp\BrokerServer;
use TornadoEngine\Broker\Mcp\Tools\BrokerInfoTool;
use TornadoEngine\Broker\Mcp\Tools\GamTool;
use TornadoEngine\Broker\Models\LogEntry;
use TornadoEngine\Broker\Models\Person;
use TornadoEngine\Broker\Pseudonym;
use TornadoEngine\Cron\Cron;
use TornadoEngine\Cron\Models\CronJob;
use TornadoEngine\Gam\Gam;

class BrokerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<list<string>> GAM commands that ran */
    private array $ran = [];

    private string $output = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Gam::class, new Gam('http://gam:8000', 'test-token'));

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/status')) {
                return Http::response(['configured' => true]);
            }
            $args = $request['args'] ?? [];
            $this->ran[] = $args;

            return Http::response(['exit' => 0, 'stdout' => $args === ['print', 'users', 'allfields', 'formatjson'] ? $this->directoryCsv() : $this->output, 'stderr' => '']);
        });
    }

    /** Ann and Ann Other are students, Kim is staff, Zed has no role; then the broker refreshes. */
    private function setUpBroker(): void
    {
        $student = Role::where('name', 'Student')->first();
        $staff = Role::where('name', 'Staff')->first();
        foreach ([['ann.example@school.test', 'Ann Example', $student], ['ann.other@school.test', 'Ann Other', $student], ['kim.teacher@school.test', 'Kim Teacher', $staff], ['zed.quill@school.test', 'Zed Quill', null]] as [$email, $name, $role]) {
            $user = User::factory()->create(['email' => $email, 'name' => $name]);
            $role && $user->roles()->attach($role);
        }

        $this->assertTrue($this->refresh(), (string) CronJob::find('broker.refresh')?->last_message);
    }

    private function refresh(): ?bool
    {
        return app(Cron::class)->run(app(Cron::class)->find('broker.refresh'));
    }

    private function filter(string $text): string
    {
        return app(Broker::class)->filter($text);
    }

    private function address(string $email): string
    {
        return Person::where('email', $email)->sole()->address();
    }

    public function test_the_broker_waits_for_its_first_refresh(): void
    {
        $this->assertNotNull(app(Broker::class)->notReady());
        BrokerServer::tool(GamTool::class, ['args' => ['info', 'domain']])->assertHasErrors();
        $this->assertSame([], $this->ran);
    }

    public function test_everyone_is_given_a_pseudonym_named_after_their_role(): void
    {
        $this->setUpBroker();

        $this->assertMatchesRegularExpression('/^p-[0-9a-f]{10}@student$/', $this->address('ann.example@school.test'));
        $this->assertMatchesRegularExpression('/^p-[0-9a-f]{10}@staff$/', $this->address('kim.teacher@school.test'));
        $this->assertMatchesRegularExpression('/^p-[0-9a-f]{10}@person$/', $this->address('zed.quill@school.test'), 'no role');
        $this->assertMatchesRegularExpression('/^p-[0-9a-f]{10}@person$/', $this->address('wm30lost@school.test'), 'only in Google');
        $this->assertSame('5 people given pseudonyms.', CronJob::find('broker.refresh')->last_message);
        $this->assertStringNotContainsString('Kim Teacher', $this->filter('Kim Teacher and kim.teacher@school.test'));
    }

    public function test_role_names_with_spaces_and_symbols_make_safe_pseudonyms(): void
    {
        $this->assertSame('office-staff-2', Pseudonym::slug('Office Staff #2'));
        $this->assertSame('eleve', Pseudonym::slug('Élève'));
        $this->assertSame('person', Pseudonym::slug('***'));
    }

    public function test_no_identifying_value_survives_the_filter(): void
    {
        $this->setUpBroker();
        $ann = $this->address('ann.example@school.test');
        $code = strstr($ann, '@', true);

        $text = 'Ann Example (ann.example@school.test, aexample@school.test) ID 204417, call 740-555-0142 or 614-555-0199, mother Pat Example, '
            .'parent.example@home.test, lunch 9137, homeroom B12. EXAMPLE.';
        $filtered = $this->filter($text);

        foreach (['Ann Example', 'ann.example@', 'aexample@', '204417', '740-555-0142', '614-555-0199', 'Pat Example', 'parent.example@home.test', '9137', 'B12', 'EXAMPLE'] as $value) {
            $this->assertStringNotContainsString($value, $filtered, $value);
        }
        $this->assertStringContainsString($ann, $filtered);
        $this->assertStringContainsString("[id {$code}-", $filtered);
        $this->assertMatchesRegularExpression('/\[phone v-[0-9a-f]{10}\]/', $filtered);

        // Everything taken out can be put back for people.
        $shown = app(Broker::class)->display($filtered);
        foreach (['Ann Example', 'ann.example@school.test', 'aexample@school.test', '204417', '740-555-0142', '614-555-0199', 'Pat Example', 'parent.example@home.test', '9137', 'B12'] as $value) {
            $this->assertStringContainsString($value, $shown, $value);
        }
        $this->assertStringNotContainsString('[', $shown);

        // Everyone in the directories, even without a role; addresses nobody has are left alone.
        $this->assertSame('[name '.Pseudonym::for('zed.quill@school.test').'] wrote to someone@else.test', $this->filter('Zed Quill wrote to someone@else.test'));
    }

    public function test_a_given_name_shared_by_two_people_is_replaced_without_saying_who(): void
    {
        $this->setUpBroker();

        $filtered = $this->filter('Hi Ann');
        $this->assertMatchesRegularExpression('/^Hi \[given v-[0-9a-f]{10}\]$/', $filtered);
        $this->assertSame('Hi Ann', app(Broker::class)->display($filtered));
    }

    public function test_a_value_many_people_share_isnt_treated_as_identifying(): void
    {
        $this->setUpBroker();
        foreach (range(1, Filter::SHARED_LIMIT + 1) as $i) {
            Person::create(['kind' => 'student', 'email' => "s{$i}@school.test", 'pseudonym' => sprintf('p-%010x', $i), 'values' => [['student', 'externalIds']]]);
        }
        app(Filter::class)->forget();

        $this->assertSame('All students and every student', $this->filter('All students and every student'));
    }

    public function test_values_are_only_replaced_whole(): void
    {
        $this->setUpBroker();

        $this->assertSame('Order 2044170 and 1204417', $this->filter('Order 2044170 and 1204417'));
        $this->assertStringNotContainsString('204417', $this->filter('Student ID204417'));
    }

    public function test_text_for_the_website_shows_real_people_again(): void
    {
        $this->setUpBroker();
        $broker = app(Broker::class);

        $filtered = $broker->filter('Email ann.example@school.test about Ann Example');
        $this->assertStringNotContainsString('ann.example', $filtered);
        $this->assertSame('Email ann.example@school.test about Ann Example', $broker->display($filtered));
    }

    public function test_claude_runs_any_gam_command_and_only_sees_pseudonyms(): void
    {
        $this->setUpBroker();
        $ann = $this->address('ann.example@school.test');
        $this->output = "User: ann.example@school.test\nFull Name: Ann Example\nEmployee ID: 204417\nOrg Unit Path: /Students/MS\n";

        BrokerServer::tool(GamTool::class, ['args' => ['update', 'user', $ann, 'org', '/Students/HS']])
            ->assertOk()
            ->assertSee($ann)
            ->assertSee('/Students/MS')
            ->assertDontSee(['ann.example', 'Ann Example', '204417']);

        $this->assertSame(['update', 'user', 'ann.example@school.test', 'org', '/Students/HS'], end($this->ran));
        $this->assertSame(['update', 'user', 'ann.example@school.test', 'org', '/Students/HS'], LogEntry::latest('id')->first()->command);
    }

    public function test_placeholders_cant_be_used_in_commands(): void
    {
        $this->setUpBroker();
        $code = strstr($this->address('ann.example@school.test'), '@', true);

        BrokerServer::tool(GamTool::class, ['args' => ['update', 'user', 'x', 'firstname', "[given {$code}]"]])->assertHasErrors();
    }

    public function test_broker_info_says_who_is_given_pseudonyms(): void
    {
        $this->setUpBroker();

        BrokerServer::tool(BrokerInfoTool::class)->assertSee('Ready: yes')->assertSee('2 @student')->assertSee('1 @staff')->assertSee('2 @person')->assertDontSee('ann.example');
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

    public function test_admins_see_counts_only(): void
    {
        $this->setUpBroker();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/administration/broker')
            ->assertOk()
            ->assertDontSee('Roles given a pseudonym')
            ->assertDontSee('Load now')
            ->assertSee('p-…@student · 2', false)
            ->assertSee('p-…@person · 2', false)
            ->assertSee('externalIds')
            ->assertSee(Broker::about())
            ->assertSee('What Claude ran')
            ->assertDontSee('Refresh now')
            ->assertDontSee('ann.example@school.test');

        $this->actingAs(User::factory()->create())->post('/administration/broker/roles')->assertForbidden();
    }

    public function test_before_its_first_load_admins_can_load_it_and_hear_when_it_could_not_run(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/administration/broker')->assertSee('Load now');

        $turn = Cache::lock('cron:turn', 60);
        $turn->get();
        $this->actingAs($admin)->from('/administration/broker')->post('/administration/broker/roles')
            ->assertSessionHasErrors(['broker' => 'Another scheduled job is running. Jobs run one at a time, so try again when it finishes.']);
        $turn->release();

        $this->actingAs($admin)->from('/administration/broker')->post('/administration/broker/roles')->assertSessionHas('broker_status');
        $this->assertTrue(app(Broker::class)->ready());
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

    public function test_people_added_after_the_last_refresh_are_given_pseudonyms_before_anything_is_filtered(): void
    {
        $this->setUpBroker();
        app(Directory::class)->refresh();
        $filter = app(Filter::class);

        // Added during the day, through Access, after the refresh.
        $late = User::factory()->create(['email' => 'pass.is12345678@school.test', 'name' => 'PassIs12345678 Testing']);
        $late->roles()->attach(Role::where('name', 'Student')->first());
        $text = $filter->apply("name,email\nPassIs12345678 Testing,pass.is12345678@school.test");
        $this->assertStringNotContainsString('PassIs12345678', $text);
        $this->assertStringNotContainsString('pass.is12345678@school.test', $text);
        $this->assertStringContainsString(Pseudonym::for('pass.is12345678@school.test'), $text);

        // A staff member who becomes a student (same number of role rows).
        $kim = User::where('email', 'kim.teacher@school.test')->first();
        $kim->roles()->sync([Role::where('name', 'Student')->value('id')]);
        $this->assertStringNotContainsString('Kim Teacher', $filter->apply('Kim Teacher'));

        // Renamed.
        $late->update(['name' => 'Renamed Personson']);
        $this->assertStringNotContainsString('Personson', $filter->apply('Renamed Personson'));
    }

    public function test_words_that_look_like_known_names_despite_misreads(): void
    {
        $this->setUpBroker();
        $broker = app(Broker::class);
        foreach (['EXAMPLE', 'EXAMP1E', 'Exarnple', 'Ex@mple', 'Exumple'] as $word) {
            $this->assertTrue($broker->resemblesName($word), $word);
        }
        foreach (['Sample', 'Ann', 'Quiet', 'Simple', 'Bus'] as $word) {
            $this->assertFalse($broker->resemblesName($word), $word);
        }
    }

    public function test_a_name_part_two_people_share_points_at_no_one(): void
    {
        $student = Role::where('name', 'Student')->first();
        User::factory()->create(['email' => 'dylan.monday@school.test', 'name' => 'Dylan Monday'])->roles()->attach($student);
        User::factory()->create(['email' => 'dylan.banta@school.test', 'name' => 'Dylan Banta']); // staff, no pseudonym
        User::factory()->create(['email' => 'rowan.solo@school.test', 'name' => 'Rowan Solo'])->roles()->attach($student);
        $this->setUpBroker();
        $monday = Pseudonym::for('dylan.monday@school.test');

        $banta = Pseudonym::for('dylan.banta@school.test');

        // Staff are pseudonyms too, so nothing tells Claude which Dylan is the student.
        $this->assertSame('[name '.$monday.']', $this->filter('Dylan Monday'));
        $this->assertSame("Reset [name {$banta}]'s password", $this->filter("Reset Dylan Banta's password"));
        $this->assertMatchesRegularExpression('/^\[given v-[0-9a-f]{10}\] was here$/', $this->filter('Dylan was here'), 'two Dylans');
        $this->assertSame('Reset Dylan Banta\'s password', app(Broker::class)->display($this->filter("Reset Dylan Banta's password")));
        $this->assertSame('[given '.Pseudonym::for('rowan.solo@school.test').']', $this->filter('Rowan'), 'a name only one person has');
    }

    public function test_a_first_or_last_name_alone_only_counts_when_capitalized(): void
    {
        $student = Role::where('name', 'Student')->first();
        User::factory()->create(['email' => 'will.king@school.test', 'name' => 'Will King'])->roles()->attach($student);
        $this->setUpBroker();
        $will = Pseudonym::for('will.king@school.test');

        $this->assertSame('I will check the king report', $this->filter('I will check the king report'));
        $this->assertSame("[given {$will}] was absent. [family {$will}] too. [given {$will}]", $this->filter('Will was absent. KING too. WILL'));
        $this->assertSame("Hi [name {$will}] and [name {$will}]", $this->filter('Hi will king and WILL KING'), 'full names in any case');
    }
}
