"""UIPE Developer API SDK for Python."""

from .client import DEFAULT_BASE_URL, VERSION, Client, Response
from .errors import ApiError, NetworkError, SignatureError, UipeError
from .webhooks import (
    DEFAULT_TOLERANCE,
    ReplayGuard,
    build_header,
    construct_event,
    inspect_signature,
    sign,
    verify_signature,
)

__version__ = VERSION

__all__ = [
    "ApiError",
    "Client",
    "DEFAULT_BASE_URL",
    "DEFAULT_TOLERANCE",
    "NetworkError",
    "ReplayGuard",
    "Response",
    "SignatureError",
    "UipeError",
    "VERSION",
    "build_header",
    "construct_event",
    "inspect_signature",
    "sign",
    "verify_signature",
]
