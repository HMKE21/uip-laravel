'use strict';

/** Base class of every error raised by this SDK. */
class UipeError extends Error {
  constructor(message) {
    super(message);
    this.name = this.constructor.name;
  }
}

/** The API answered with a non-2xx status. `code` is the machine readable `errors.code`. */
class ApiError extends UipeError {
  constructor(status, message, { code = null, errors = null, headers = {}, body = null } = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.errors = errors;
    this.headers = headers;
    this.body = body;
  }

  /** Seconds to wait before retrying (Retry-After), or null. */
  get retryAfter() {
    const n = parseInt(this.headers['retry-after'], 10);
    return Number.isFinite(n) ? n : null;
  }
}

/** The request never got an answer (DNS, connection, timeout). */
class NetworkError extends UipeError {}

/**
 * A webhook delivery was rejected. `reason` is one of: missing_header, malformed_header,
 * missing_secret, signature_mismatch, timestamp_too_old, timestamp_in_future,
 * invalid_payload, replayed_event.
 */
class SignatureError extends UipeError {
  constructor(reason, message) {
    super(message || reason);
    this.reason = reason;
  }
}

module.exports = { UipeError, ApiError, NetworkError, SignatureError };
