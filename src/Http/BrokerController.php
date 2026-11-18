<?php

namespace TornadoEngine\Broker\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Broker\Models\LogEntry;

class BrokerController extends Controller
{
    /** The roles whose people are given pseudonyms; saving refreshes the directory. */
    public function roles(Request $request, Broker $broker): RedirectResponse
    {
        Broker::saveRoles((array) $request->input('roles', []));
        [$ok, $message] = $broker->refresh();

        return $ok
            ? back()->with('broker_status', __('Saved. :summary', ['summary' => $message]))
            : back()->withErrors(['broker' => __('Saved. :reason', ['reason' => $message])]);
    }

    public function log(): View
    {
        return view('broker::log', ['entries' => LogEntry::latest('id')->limit(200)->get()]);
    }
}
