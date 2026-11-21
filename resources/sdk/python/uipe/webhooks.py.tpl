"""Webhook signature verification with replay protection.

Every delivery carries::

    X-UIPE-Signature: t=<unix seconds>,v1=<hex>
    v1 = HMAC-SHA256(secret, "<t>.<raw body>")

Verify against the RAW request body (byte for byte), reject timestamps more than
``tolerance`` seconds away from now (old or future), and ignore an event id you
already processed (``X-UIPE-Event-Id`` / the ``id`` of the event).
"""

import hashlib
import hmac
import json
import re
import threading
import time

from .errors import SignatureError

HEADER = "X-UIPE-Signature"
DEFAULT_TOLERANCE = {{TOLERANCE}}

_MAX_HEADER = 1024
_MAX_SIGNATURES = 5
_HEX64 = re.compile(r"[0-9a-fA-F]{64}")
_DIGITS = re.compile(r"[0-9]{1,12}")

MESSAGES = {
    "missing_header": "The X-UIPE-Signature header is missing or empty.",
    "malformed_header": "The signature header is not in the form t=<unix seconds>,v1=<64 hex characters>.",
    "missing_secret": "No signing secret was given.",
    "signature_mismatch": "The signature does not match. Use the raw request body (byte for byte) and the secret of this webhook.",
    "timestamp_too_old": "The signature is valid but older than the allowed window (replay protection).",
    "timestamp_in_future": "The signature is valid but its timestamp is too far in the future.",
    "invalid_payload": "The body is not valid JSON.",
    "replayed_event": "This event id was already processed.",
}


def _bytes(value):
    if isinstance(value, (bytes, bytearray)):
        return bytes(value)
    return str(value).encode("utf-8")


def sign(secret, timestamp, payload):
    """Hex HMAC-SHA256 of "<timestamp>.<payload>" (what UIPE puts in v1)."""
    msg = str(int(timestamp)).encode("ascii") + b"." + _bytes(payload)
    return hmac.new(_bytes(secret), msg, hashlib.sha256).hexdigest()


def build_header(secret, payload, timestamp=None):
    """A full X-UIPE-Signature value. Useful to test your own receiver."""
    t = int(time.time()) if timestamp is None else int(timestamp)
    return "t=%d,v1=%s" % (t, sign(secret, t, payload))


def parse_header(header):
    """Returns (timestamp, [v1 signatures]) or None when the header is malformed."""
    header = (header or "").strip()
    if not header or len(header) > _MAX_HEADER:
        return None
    timestamp = None
    signatures = []
    for part in header.split(","):
        part = part.strip()
        if not part:
            continue
        eq = part.find("=")
        if eq <= 0:
            return None
        key, value = part[:eq], part[eq + 1:]
        if key == "t":
            if timestamp is not None or not _DIGITS.fullmatch(value):
                return None
            timestamp = int(value)
        elif key == "v1":
            if not _HEX64.fullmatch(value) or len(signatures) >= _MAX_SIGNATURES:
                return None
            signatures.append(value.lower())
        # unknown keys (v0 ...) are ignored so new versions can be added later
    if timestamp is None or not signatures:
        return None
    return timestamp, signatures


def inspect_signature(payload, header, secrets, tolerance=DEFAULT_TOLERANCE, now=None):
    """Full verification with the reason.

    ``secrets`` is one secret or a list (use two while you rotate a webhook secret).
    Returns a dict: valid, reason, timestamp, age_seconds, tolerance_seconds.
    The signature is checked before the time, so a timestamp_* reason is only given for a genuine signature.
    """
    tolerance = tolerance if tolerance and tolerance > 0 else DEFAULT_TOLERANCE
    now = int(time.time()) if now is None else int(now)
    result = {"valid": False, "reason": None, "timestamp": None, "age_seconds": None, "tolerance_seconds": tolerance}

    if not (header or "").strip():
        result["reason"] = "missing_header"
        return result
    parsed = parse_header(header)
    if parsed is None:
        result["reason"] = "malformed_header"
        return result
    timestamp, signatures = parsed
    age = now - timestamp
    result["timestamp"] = timestamp
    result["age_seconds"] = age

    if isinstance(secrets, (str, bytes, bytearray)):
        secrets = [secrets]
    secrets = [s for s in (secrets or []) if s]
    if not secrets:
        result["reason"] = "missing_secret"
        return result

    matched = False
    for secret in secrets:
        expected = sign(secret, timestamp, payload)
        for given in signatures:
            if hmac.compare_digest(expected, given):  # no break: roughly constant time
                matched = True
    if not matched:
        result["reason"] = "signature_mismatch"
        return result
    if age > tolerance:
        result["reason"] = "timestamp_too_old"
        return result
    if -age > tolerance:
        result["reason"] = "timestamp_in_future"
        return result
    result["valid"] = True
    return result


def verify_signature(payload, header, secrets, tolerance=DEFAULT_TOLERANCE, now=None):
    """True when the signature is genuine and the timestamp is inside the window."""
    return inspect_signature(payload, header, secrets, tolerance, now)["valid"]


class ReplayGuard(object):
    """In-memory, thread-safe "have I seen this event id?" store.

    Good for a single process. With several workers keep the ids in a shared store
    (Redis ``SET key 1 NX EX ttl``, a database unique index) and pass any object
    that has ``first_seen(event_id) -> bool``.
    """

    def __init__(self, ttl=2 * DEFAULT_TOLERANCE, max_entries=10000):
        self._ttl = ttl
        self._max = max_entries
        self._seen = {}
        self._lock = threading.Lock()

    def first_seen(self, event_id, now=None):
        now = time.time() if now is None else now
        with self._lock:
            if len(self._seen) >= self._max:
                for key in [k for k, exp in self._seen.items() if exp <= now]:
                    del self._seen[key]
                while len(self._seen) >= self._max:
                    self._seen.pop(next(iter(self._seen)))
            expires = self._seen.get(event_id)
            if expires is not None and expires > now:
                return False
            self._seen[event_id] = now + self._ttl
            return True


def construct_event(payload, header, secrets, tolerance=DEFAULT_TOLERANCE, guard=None, now=None):
    """Verify a delivery and return the parsed event (a dict).

    Raises ``SignatureError`` (see ``.reason``) when the signature is bad, the
    timestamp is outside the window, the body is not JSON, or ``guard`` has already
    seen the event id.
    """
    res = inspect_signature(payload, header, secrets, tolerance, now)
    if not res["valid"]:
        raise SignatureError(res["reason"], MESSAGES.get(res["reason"]))
    try:
        event = json.loads(_bytes(payload).decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        raise SignatureError("invalid_payload", MESSAGES["invalid_payload"])
    if guard is not None and isinstance(event, dict):
        event_id = event.get("id")
        if isinstance(event_id, str) and event_id and not guard.first_seen(event_id):
            raise SignatureError("replayed_event", MESSAGES["replayed_event"])
    return event
