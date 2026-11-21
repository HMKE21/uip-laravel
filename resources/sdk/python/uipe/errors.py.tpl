"""Errors raised by the UIPE SDK."""


class UipeError(Exception):
    """Base class of every error raised by this SDK."""


class ApiError(UipeError):
    """The API answered with a non-2xx status.

    ``code`` is the machine readable ``errors.code`` of the response (for example
    ``rate_limited``, ``insufficient_scope``, ``sandbox_unsupported``).
    """

    def __init__(self, status, message, code=None, errors=None, headers=None, body=None):
        super().__init__(message)
        self.status = status
        self.message = message
        self.code = code
        self.errors = errors
        self.headers = headers or {}
        self.body = body

    @property
    def retry_after(self):
        """Seconds to wait before retrying (from the Retry-After header), or None."""
        value = self.headers.get("retry-after")
        try:
            return int(value) if value is not None else None
        except (TypeError, ValueError):
            return None

    def __str__(self):
        return "%s %s: %s" % (self.status, self.code or "error", self.message)


class NetworkError(UipeError):
    """The request never got an answer (DNS, connection, timeout)."""


class SignatureError(UipeError):
    """A webhook delivery was rejected.

    ``reason`` is one of: missing_header, malformed_header, missing_secret,
    signature_mismatch, timestamp_too_old, timestamp_in_future, invalid_payload,
    replayed_event.
    """

    def __init__(self, reason, message=None):
        super().__init__(message or reason)
        self.reason = reason
