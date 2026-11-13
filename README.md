# Tornado Engine Broker

A [Tornado Engine](https://github.com/DylanBanta-WM/TornadoEngineCore) module: lets Claude work with your Google Workspace without any student information reaching it.

- Claude gets MCP tools in the harness: `gam` runs any GAM command, `broker_info` says whether the broker is ready.
- People in everything Claude sees become pseudonyms: `s-<10 hex>@student`, `t-…@staff`, `c-…@contact`. Names, ID numbers, phones, addresses and other identifying values become placeholders such as `[name s-…]` and `[id s-…]`. Claude can use a pseudonym in a command, and the broker swaps the real address back in before GAM runs.
- Website pages Claude opens through the harness (broker view) are filtered the same way.
- It refuses to run until an admin has added student rules (OUs, Google Groups or email patterns) and the directory has been loaded.

Needs the GAM and Cron modules. Set it up on the Administration page: add student rules, then click Refresh now. Every command Claude runs is listed under "What Claude ran".
