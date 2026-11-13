<?php

namespace TornadoEngine\Broker\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\View\View;
use TornadoEngine\Broker\Directory;
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

    /**
     * Makes this the primary rule, or stops it being primary. There's at most one.
     */
    public function primary(Rule $rule): RedirectResponse
    {
        $makePrimary = ! $rule->primary;

        Rule::query()->update(['primary' => false]);
        $rule->update(['primary' => $makePrimary]);

        return back()->with('broker_status', $makePrimary
            ? __('That\'s now your primary rule. Click Refresh now to check the others against it.')
            : __('You have no primary rule now, so rules aren\'t compared.'));
    }

    public function removeRule(Rule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('broker_status', __('Removed the rule. Click Refresh now to apply it.'));
    }

    public function refresh(Cron $cron, Directory $directory): RedirectResponse
    {
        $job = $cron->find('broker.refresh');
        $ok = $cron->run($job, wait: false);

        if ($ok === null) {
            return back()->withErrors(['broker' => __('Another scheduled job is running. Jobs run one at a time, so try again when it finishes.')]);
        }

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
