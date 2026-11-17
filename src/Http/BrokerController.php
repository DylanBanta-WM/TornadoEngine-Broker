<?php

namespace TornadoEngine\Broker\Http;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use TornadoEngine\Access\Models\Role;
use TornadoEngine\Broker\Directory;
use TornadoEngine\Broker\Models\LogEntry;
use TornadoEngine\Cron\Cron;

class BrokerController extends Controller
{
    /** The roles whose people are given pseudonyms. */
    public function roles(Request $request): RedirectResponse
    {
        $ids = array_map('intval', (array) $request->input('roles', []));
        Setting::put('broker.pseudonym_roles', json_encode(array_values(array_intersect(Role::pluck('id')->all(), $ids))));

        return back()->with('broker_status', __('Saved. Click Refresh now to apply it.'));
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
