<?php

namespace TornadoEngine\Broker\Http;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\View\View;
use TornadoEngine\Broker\Directory;
use TornadoEngine\Broker\Filter;
use TornadoEngine\Broker\Models\LogEntry;
use TornadoEngine\Broker\Models\Rule;
use TornadoEngine\Cron\Cron;

class BrokerController extends Controller
{
    public function addRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', ValidationRule::in(['ou', 'group', 'email'])],
            'value' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($request) {
                $problem = match ($request->input('type')) {
                    'ou' => str_starts_with($value, '/') ? null : __('An OU path starts with /, for example /Students.'),
                    'group' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : __('Use the group\'s email address.'),
                    'email' => @preg_match('/'.str_replace('/', '\/', $value).'/', '') === false ? __('That isn\'t a valid regular expression.') : null,
                    default => null,
                };
                if ($problem) {
                    $fail($problem);
                }
            }],
        ]);

        Rule::create([
            'type' => $data['type'],
            'value' => $data['type'] === 'group' ? strtolower($data['value']) : $data['value'],
            'sub_ous' => $request->boolean('sub_ous'),
        ]);

        return back()->with('broker_status', __('Added the rule. Click Refresh now to apply it.'));
    }

    public function removeRule(Rule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('broker_status', __('Removed the rule. Click Refresh now to apply it.'));
    }

    public function patterns(Request $request): RedirectResponse
    {
        $patterns = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $request->input('patterns')))));

        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '@')) {
                return back()->withErrors(['patterns' => __('This looks like an email pattern. Add it under Who is a student as an Email pattern rule.')])->withInput();
            }
            if (@preg_match('/'.str_replace('/', '\/', $pattern).'/', '') === false) {
                return back()->withErrors(['patterns' => __(':pattern isn\'t a valid regular expression.', ['pattern' => $pattern])])->withInput();
            }
        }

        Setting::put('broker.hide_long_numbers', $request->boolean('hide_long_numbers') ? '1' : '0');
        Setting::put('broker.id_patterns', json_encode(array_values(array_diff($patterns, [Filter::LONG_NUMBERS]))));
        app(Filter::class)->forget();

        return back()->with('broker_status', __('Saved what to hide.'));
    }

    public function refresh(Cron $cron, Directory $directory): RedirectResponse
    {
        $job = $cron->find('broker.refresh');
        $ok = $cron->run($job);

        $message = $cron->settings($job)->last_message;

        return $ok
            ? back()->with('broker_status', __('Refreshed: :summary.', ['summary' => $message]))
            : back()->withErrors(['broker' => __('The refresh failed: :reason', ['reason' => $message])]);
    }

    public function log(): View
    {
        return view('broker::log', ['entries' => LogEntry::latest('id')->limit(200)->get()]);
    }
}
