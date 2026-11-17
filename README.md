# Tornado Engine Broker

A [Tornado Engine](https://github.com/DylanBanta-WM/TornadoEngineCore) core module: people in the roles you choose reach Claude only as pseudonyms.

- On the Broker tab in Administration, choose the roles given a pseudonym. Their people appear to Claude as `p-<10 hex>@<role>` (for example `p-1a2b3c4d5e@student`), with names as `[name p-…]` and other identifying values as `[id p-…]`. Phone numbers become `[phone]`.
- Names and emails come from Access. Modules add more of what identifies people: GAM (Google), Access-Microsoft-AD and Access-Microsoft-Entra.
- Claude gets MCP tools in the harness: `gam` (when GAM is installed) runs any GAM command, swapping pseudonyms for real addresses before it runs; `broker_info` says whether the broker is ready.
- Website pages Claude opens through the harness (broker view) are filtered the same way.
