# UIPE Developer API — JavaScript SDK

Generated from the OpenAPI spec of **{{API_TITLE}} {{API_VERSION}}** (`php artisan developer:sdk`). Do not edit by hand. No dependencies, Node 18+ (global `fetch`).

```js
const { Client } = require('uipe');

const client = new Client('uipk_test_...');      // an API key or an OAuth access token
const me = await client.getMe();
const messages = await client.listMessages({ query: { folder: 'inbox' } });
await client.sendMessage({ body: { to: ['a@example.com'], subject: 'Hi', body: 'Hello' } });
```

Path parameters are positional (`client.getMessage(id)`); `query` and `body` go in the options object. Every operation resolves with the `data` of the response and rejects with `ApiError` (`status`, `code`, `message`, `retryAfter`). `429` answers (and `502/503/504` for GET/PUT/DELETE) are retried after the wait the server asks for. `client.requestRaw(...)` and `client.lastResponse` expose headers (`rateLimit`, `mode`).

## Verify a webhook (Express)

```js
const express = require('express');
const { constructEvent, ReplayGuard, SignatureError } = require('uipe');

const guard = new ReplayGuard();                  // one per process; use Redis/DB for several workers
const app = express();

app.post('/uipe/webhook', express.raw({ type: 'application/json' }), (req, res) => {
  let event;
  try {
    event = constructEvent(req.body, req.get('X-UIPE-Signature'), process.env.UIPE_WEBHOOK_SECRET, { guard });
  } catch (err) {
    if (err instanceof SignatureError) return res.status(400).send(err.reason); // signature_mismatch, timestamp_too_old, replayed_event ...
    throw err;
  }
  res.sendStatus(200);                            // answer first, work after
  // event.type, event.data
});
```

`req.body` must be the raw body (a Buffer), before any JSON parsing. The window is {{TOLERANCE}} seconds in both directions. Pass an array of secrets while rotating a webhook secret.

## Operations

{{OPERATIONS_TABLE}}

## Tests

```
npm test
```
