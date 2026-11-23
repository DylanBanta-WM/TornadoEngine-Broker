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
    /** Loads the directory now (normally overnight, and as people are added). */
    public function roles(Request $request, Broker $broker): RedirectResponse
    {
        [$ok, $message] = $broker->refresh();

        return $ok ? back()->with('broker_status', $message) : back()->withErrors(['broker' => $message]);
    }

    public function log(): View
    {
        return view('broker::log', ['entries' => LogEntry::latest('id')->limit(200)->get()]);
    }
}
