"""HTTP client of the UIPE Developer API (standard library only)."""

import json
import random
import time
import urllib.error
import urllib.parse
import urllib.request

from .errors import ApiError, NetworkError
from .operations import Operations

DEFAULT_BASE_URL = "{{BASE_URL}}"
VERSION = "{{SDK_VERSION}}"

_IDEMPOTENT = ("GET", "HEAD", "PUT", "DELETE")
_MAX_WAIT = 30.0


class Response(object):
    """A successful answer: ``data`` is the unwrapped ``data`` field of the envelope."""

    def __init__(self, status, headers, data, meta, message):
        self.status = status
        self.headers = headers
        self.data = data
        self.meta = meta
        self.message = message

    @property
    def rate_limit(self):
        def num(name):
            try:
                return int(self.headers.get(name))
            except (TypeError, ValueError):
                return None

        return {"limit": num("x-ratelimit-limit"), "remaining": num("x-ratelimit-remaining")}

    @property
    def mode(self):
        """'live' or 'sandbox': the mode of the credential that made the request."""
        return self.headers.get("x-uipe-mode")


class Client(Operations):
    """Client for the UIPE Developer API.

    >>> client = Client("uipk_test_...")
    >>> client.list_messages({"folder": "inbox"})

    Every operation returns the ``data`` of the response and raises ``ApiError`` on
    failure. 429 answers (and 502/503/504 for idempotent calls) are retried after the
    wait the server asks for, up to ``max_retries`` times.
    """

    def __init__(self, api_key, base_url=DEFAULT_BASE_URL, timeout=30.0, max_retries=2, user_agent=None):
        if not api_key:
            raise ValueError("api_key is required")
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.max_retries = max(0, int(max_retries))
        self.user_agent = user_agent or ("uipe-python/" + VERSION)
        self.last_response = None

    # -- helpers used by the generated operations ---------------------------------

    @staticmethod
    def _expand(template, values):
        out = template
        for key, value in values.items():
            out = out.replace("{" + key + "}", urllib.parse.quote(str(value), safe=""))
        return out

    def _url(self, path, query):
        url = self.base_url + path
        if query:
            pairs = []
            for key, value in query.items():
                if value is None:
                    continue
                if isinstance(value, bool):
                    value = "1" if value else "0"
                if isinstance(value, (list, tuple)):
                    pairs.extend((key, str(v)) for v in value)
                else:
                    pairs.append((key, str(value)))
            if pairs:
                url += "?" + urllib.parse.urlencode(pairs)
        return url

    # -- requests -----------------------------------------------------------------

    def request(self, method, path, query=None, body=None):
        """Send a request and return the ``data`` of the response."""
        return self.request_raw(method, path, query=query, body=body).data

    def request_raw(self, method, path, query=None, body=None):
        """Send a request and return a ``Response`` (status, headers, data, meta, message)."""
        method = method.upper()
        url = self._url(path, query)
        headers = {
            "Authorization": "Bearer " + self.api_key,
            "Accept": "application/json",
            "User-Agent": self.user_agent,
        }
        data = None
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"

        attempt = 0
        while True:
            try:
                status, resp_headers, raw = self._once(method, url, data, headers)
            except NetworkError:
                if method in _IDEMPOTENT and attempt < self.max_retries:
                    self._sleep(attempt, None)
                    attempt += 1
                    continue
                raise
            retryable = status == 429 or (status in (502, 503, 504) and method in _IDEMPOTENT)
            if retryable and attempt < self.max_retries:
                self._sleep(attempt, resp_headers.get("retry-after"))
                attempt += 1
                continue
            return self._parse(status, resp_headers, raw)

    def _once(self, method, url, data, headers):
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                return resp.status, {k.lower(): v for k, v in resp.headers.items()}, resp.read()
        except urllib.error.HTTPError as err:
            return err.code, {k.lower(): v for k, v in err.headers.items()}, err.read()
        except (urllib.error.URLError, OSError) as err:
            raise NetworkError(str(err)) from err

    @staticmethod
    def _sleep(attempt, retry_after):
        delay = None
        if retry_after is not None:
            try:
                delay = min(float(retry_after), _MAX_WAIT)
            except (TypeError, ValueError):
                delay = None
        if delay is None:
            delay = min(0.5 * (2 ** attempt), 8.0) + random.random() * 0.25
        time.sleep(max(0.0, delay))

    def _parse(self, status, headers, raw):
        text = raw.decode("utf-8", "replace") if raw else ""
        payload = None
        if text:
            try:
                payload = json.loads(text)
            except ValueError:
                payload = None
        envelope = payload if isinstance(payload, dict) else {}
        if 200 <= status < 300:
            if text and payload is None:
                raise ApiError(status, "The server did not return JSON.", code="invalid_response", headers=headers, body=text)
            res = Response(status, headers, envelope.get("data"), envelope.get("meta"), envelope.get("message"))
            self.last_response = res
            return res
        errors = envelope.get("errors")
        code = errors.get("code") if isinstance(errors, dict) and isinstance(errors.get("code"), str) else None
        raise ApiError(status, envelope.get("message") or ("HTTP %d" % status), code=code, errors=errors, headers=headers, body=text)
