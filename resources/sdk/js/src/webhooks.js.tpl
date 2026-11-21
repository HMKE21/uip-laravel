'use strict';

/*
 * Webhook signature verification with replay protection.
 *
 *   X-UIPE-Signature: t=<unix seconds>,v1=<hex>
 *   v1 = HMAC-SHA256(secret, "<t>.<raw body>")
 *
 * Verify against the RAW request body (byte for byte), reject timestamps more than
 * `tolerance` seconds away from now (old or future), and ignore an event id you already processed.
 */

const crypto = require('crypto');
const { SignatureError } = require('./errors');

const HEADER = 'X-UIPE-Signature';
const DEFAULT_TOLERANCE = {{TOLERANCE}};
const MAX_HEADER = 1024;
const MAX_SIGNATURES = 5;

const MESSAGES = {
  missing_header: 'The X-UIPE-Signature header is missing or empty.',
  malformed_header: 'The signature header is not in the form t=<unix seconds>,v1=<64 hex characters>.',
  missing_secret: 'No signing secret was given.',
  signature_mismatch: 'The signature does not match. Use the raw request body (byte for byte) and the secret of this webhook.',
  timestamp_too_old: 'The signature is valid but older than the allowed window (replay protection).',
  timestamp_in_future: 'The signature is valid but its timestamp is too far in the future.',
  invalid_payload: 'The body is not valid JSON.',
  replayed_event: 'This event id was already processed.',
};

function toBuffer(value) {
  if (Buffer.isBuffer(value)) return value;
  if (value instanceof Uint8Array) return Buffer.from(value);
  return Buffer.from(String(value), 'utf8');
}

/** Hex HMAC-SHA256 of "<timestamp>.<payload>" (what UIPE puts in v1). */
function sign(secret, timestamp, payload) {
  return crypto
    .createHmac('sha256', toBuffer(secret))
    .update(String(Math.trunc(Number(timestamp))) + '.')
    .update(toBuffer(payload))
    .digest('hex');
}

/** A full X-UIPE-Signature value. Useful to test your own receiver. */
function buildHeader(secret, payload, timestamp) {
  const t = timestamp === undefined || timestamp === null ? Math.floor(Date.now() / 1000) : Math.trunc(Number(timestamp));
  return 't=' + t + ',v1=' + sign(secret, t, payload);
}

/** Returns { timestamp, signatures } or null when the header is malformed. */
function parseHeader(header) {
  header = String(header === undefined || header === null ? '' : header).trim();
  if (!header || header.length > MAX_HEADER) return null;
  let timestamp = null;
  const signatures = [];
  for (let part of header.split(',')) {
    part = part.trim();
    if (!part) continue;
    const eq = part.indexOf('=');
    if (eq <= 0) return null;
    const key = part.slice(0, eq);
    const value = part.slice(eq + 1);
    if (key === 't') {
      if (timestamp !== null || !/^[0-9]{1,12}$/.test(value)) return null;
      timestamp = parseInt(value, 10);
    } else if (key === 'v1') {
      if (!/^[0-9a-fA-F]{64}$/.test(value) || signatures.length >= MAX_SIGNATURES) return null;
      signatures.push(value.toLowerCase());
    } // unknown keys (v0 ...) are ignored so new versions can be added later
  }
  if (timestamp === null || signatures.length === 0) return null;
  return { timestamp, signatures };
}

/**
 * Full verification with the reason. `secrets` is one secret or an array (two while you rotate).
 * Returns { valid, reason, timestamp, ageSeconds, toleranceSeconds }.
 * The signature is checked before the time, so a timestamp_* reason is only given for a genuine signature.
 */
function inspectSignature(payload, header, secrets, { tolerance = DEFAULT_TOLERANCE, now } = {}) {
  tolerance = tolerance > 0 ? tolerance : DEFAULT_TOLERANCE;
  now = now === undefined || now === null ? Math.floor(Date.now() / 1000) : Math.trunc(Number(now));
  const result = { valid: false, reason: null, timestamp: null, ageSeconds: null, toleranceSeconds: tolerance };

  if (!String(header === undefined || header === null ? '' : header).trim()) {
    result.reason = 'missing_header';
    return result;
  }
  const parsed = parseHeader(header);
  if (parsed === null) {
    result.reason = 'malformed_header';
    return result;
  }
  const age = now - parsed.timestamp;
  result.timestamp = parsed.timestamp;
  result.ageSeconds = age;

  const list = (Array.isArray(secrets) ? secrets : [secrets]).filter((s) => s !== undefined && s !== null && s !== '');
  if (list.length === 0) {
    result.reason = 'missing_secret';
    return result;
  }

  let matched = false;
  for (const secret of list) {
    const expected = Buffer.from(sign(secret, parsed.timestamp, payload), 'utf8');
    for (const given of parsed.signatures) {
      const g = Buffer.from(given, 'utf8');
      if (g.length === expected.length && crypto.timingSafeEqual(g, expected)) matched = true; // no break
    }
  }
  if (!matched) {
    result.reason = 'signature_mismatch';
    return result;
  }
  if (age > tolerance) {
    result.reason = 'timestamp_too_old';
    return result;
  }
  if (-age > tolerance) {
    result.reason = 'timestamp_in_future';
    return result;
  }
  result.valid = true;
  return result;
}

/** true when the signature is genuine and the timestamp is inside the window. */
function verifySignature(payload, header, secrets, options) {
  return inspectSignature(payload, header, secrets, options).valid;
}

/**
 * In-memory "have I seen this event id?" store. Good for a single process. With several workers keep the
 * ids in a shared store (Redis SET key 1 NX EX ttl) and pass any object with `firstSeen(id) -> boolean`.
 */
class ReplayGuard {
  constructor({ ttl = 2 * DEFAULT_TOLERANCE, maxEntries = 10000 } = {}) {
    this.ttl = ttl;
    this.maxEntries = maxEntries;
    this.seen = new Map();
  }

  firstSeen(eventId, now = Date.now() / 1000) {
    if (this.seen.size >= this.maxEntries) {
      for (const [key, exp] of this.seen) if (exp <= now) this.seen.delete(key);
      while (this.seen.size >= this.maxEntries) this.seen.delete(this.seen.keys().next().value);
    }
    const expires = this.seen.get(eventId);
    if (expires !== undefined && expires > now) return false;
    this.seen.delete(eventId);
    this.seen.set(eventId, now + this.ttl);
    return true;
  }
}

/**
 * Verify a delivery and return the parsed event. Throws SignatureError (see `.reason`) when the signature
 * is bad, the timestamp is outside the window, the body is not JSON, or `guard` already saw the event id.
 */
function constructEvent(payload, header, secrets, { tolerance, guard, now } = {}) {
  const res = inspectSignature(payload, header, secrets, { tolerance, now });
  if (!res.valid) throw new SignatureError(res.reason, MESSAGES[res.reason]);
  let event;
  try {
    event = JSON.parse(toBuffer(payload).toString('utf8'));
  } catch (e) {
    throw new SignatureError('invalid_payload', MESSAGES.invalid_payload);
  }
  if (guard && event && typeof event === 'object' && typeof event.id === 'string' && event.id && !guard.firstSeen(event.id)) {
    throw new SignatureError('replayed_event', MESSAGES.replayed_event);
  }
  return event;
}

module.exports = { HEADER, DEFAULT_TOLERANCE, sign, buildHeader, parseHeader, inspectSignature, verifySignature, ReplayGuard, constructEvent };
