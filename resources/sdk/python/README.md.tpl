# UIPE Developer API — Python SDK

Generated from the OpenAPI spec of **{{API_TITLE}} {{API_VERSION}}** (`php artisan developer:sdk`). Do not edit by hand. Standard library only, Python 3.8+.

```python
from uipe import Client

client = Client("uipk_test_...")          # an API key or an OAuth access token
me = client.get_me()
messages = client.list_messages({"folder": "inbox"})
```

Every operation returns the `data` of the response and raises `ApiError` (`status`, `code`, `message`, `retry_after`) on failure. `429` answers (and `502/503/504` for GET/PUT/DELETE) are retried after the wait the server asks for. Use `client.request_raw(...)` or `client.last_response` for headers (`rate_limit`, `mode`).

## Verify a webhook

```python
from uipe import construct_event, ReplayGuard, SignatureError

guard = ReplayGuard()                        # one per process; use Redis/DB for several workers

def handle(raw_body, headers):
    try:
        event = construct_event(raw_body, headers.get("X-UIPE-Signature"), WEBHOOK_SECRET, guard=guard)
    except SignatureError as err:
        return 400, err.reason               # signature_mismatch, timestamp_too_old, replayed_event ...
    ...                                      # event["type"], event["data"]
    return 200, "ok"
```

`raw_body` must be the body byte for byte, before any JSON parsing. The window is {{TOLERANCE}} seconds in both directions. Pass a list of secrets while rotating a webhook secret.

## Operations

{{OPERATIONS_TABLE}}

## Tests

```
python -m unittest discover -s tests -v
```
