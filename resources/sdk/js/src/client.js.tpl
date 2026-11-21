'use strict';

const { ApiError, NetworkError } = require('./errors');
const { Operations } = require('./operations');

const DEFAULT_BASE_URL = '{{BASE_URL}}';
const VERSION = '{{SDK_VERSION}}';
const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE'];
const MAX_WAIT_MS = 30000;

/** A successful answer. `data` is the unwrapped `data` field of the envelope. */
class Response {
  constructor(status, headers, data, meta, message) {
    this.status = status;
    this.headers = headers;
    this.data = data;
    this.meta = meta;
    this.message = message;
  }

  get rateLimit() {
    const num = (name) => {
      const n = parseInt(this.headers[name], 10);
      return Number.isFinite(n) ? n : null;
    };
    return { limit: num('x-ratelimit-limit'), remaining: num('x-ratelimit-remaining') };
  }

  /** 'live' or 'sandbox': the mode of the credential that made the request. */
  get mode() {
    return this.headers['x-uipe-mode'] || null;
  }
}

/**
 * Client for the UIPE Developer API (Node 18+, uses the global fetch).
 *
 *   const client = new Client('uipk_test_...');
 *   await client.listMessages({ query: { folder: 'inbox' } });
 *
 * Every operation resolves with the `data` of the response and rejects with ApiError.
 * 429 answers (and 502/503/504 for idempotent calls) are retried after the wait the server asks for.
 */
class Client extends Operations {
  constructor(apiKey, { baseUrl = DEFAULT_BASE_URL, timeout = 30000, maxRetries = 2, userAgent, fetch: fetchImpl } = {}) {
    super();
    if (!apiKey) throw new TypeError('apiKey is required');
    this.apiKey = apiKey;
    this.baseUrl = String(baseUrl).replace(/\/+$/, '');
    this.timeout = timeout;
    this.maxRetries = Math.max(0, maxRetries | 0);
    this.userAgent = userAgent || 'uipe-js/' + VERSION;
    this.fetch = fetchImpl || ((...a) => globalThis.fetch(...a));
    this.lastResponse = null;
  }

  // -- helpers used by the generated operations ---------------------------------

  _expand(template, values) {
    let out = template;
    for (const [key, value] of Object.entries(values)) {
      out = out.split('{' + key + '}').join(encodeURIComponent(String(value)));
    }
    return out;
  }

  _url(path, query) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query || {})) {
      if (value === undefined || value === null) continue;
      if (Array.isArray(value)) value.forEach((v) => params.append(key, String(v)));
      else params.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    }
    const qs = params.toString();
    return this.baseUrl + path + (qs ? '?' + qs : '');
  }

  // -- requests -----------------------------------------------------------------

  /** Send a request and resolve with the `data` of the response. */
  async request(method, path, { query, body } = {}) {
    return (await this.requestRaw(method, path, { query, body })).data;
  }

  /** Send a request and resolve with a Response (status, headers, data, meta, message). */
  async requestRaw(method, path, { query, body } = {}) {
    method = String(method).toUpperCase();
    const url = this._url(path, query);
    const headers = { Authorization: 'Bearer ' + this.apiKey, Accept: 'application/json', 'User-Agent': this.userAgent };
    let payload;
    if (body !== undefined && body !== null) {
      payload = JSON.stringify(body);
      headers['Content-Type'] = 'application/json';
    }

    let attempt = 0;
    for (;;) {
      let res;
      try {
        res = await this._once(method, url, payload, headers);
      } catch (err) {
        if (err instanceof NetworkError && IDEMPOTENT.includes(method) && attempt < this.maxRetries) {
          await this._sleep(attempt, null);
          attempt += 1;
          continue;
        }
        throw err;
      }
      const retryable = res.status === 429 || ([502, 503, 504].includes(res.status) && IDEMPOTENT.includes(method));
      if (retryable && attempt < this.maxRetries) {
        await this._sleep(attempt, res.headers['retry-after']);
        attempt += 1;
        continue;
      }
      return this._parse(res.status, res.headers, res.text);
    }
  }

  async _once(method, url, payload, headers) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeout);
    try {
      const res = await this.fetch(url, { method, headers, body: payload, signal: controller.signal });
      const text = await res.text();
      const out = {};
      res.headers.forEach((value, key) => {
        out[key.toLowerCase()] = value;
      });
      return { status: res.status, headers: out, text };
    } catch (err) {
      throw new NetworkError(err && err.name === 'AbortError' ? 'Request timed out' : String((err && err.message) || err));
    } finally {
      clearTimeout(timer);
    }
  }

  _sleep(attempt, retryAfter) {
    let ms = null;
    if (retryAfter !== null && retryAfter !== undefined && Number.isFinite(parseFloat(retryAfter))) {
      ms = Math.min(parseFloat(retryAfter) * 1000, MAX_WAIT_MS);
    }
    if (ms === null) ms = Math.min(500 * 2 ** attempt, 8000) + Math.random() * 250;
    return new Promise((resolve) => setTimeout(resolve, Math.max(0, ms)));
  }

  _parse(status, headers, text) {
    let payload = null;
    if (text) {
      try {
        payload = JSON.parse(text);
      } catch (e) {
        payload = null;
      }
    }
    const envelope = payload && typeof payload === 'object' && !Array.isArray(payload) ? payload : {};
    if (status >= 200 && status < 300) {
      if (text && payload === null) {
        throw new ApiError(status, 'The server did not return JSON.', { code: 'invalid_response', headers, body: text });
      }
      this.lastResponse = new Response(status, headers, envelope.data === undefined ? null : envelope.data, envelope.meta === undefined ? null : envelope.meta, envelope.message === undefined ? null : envelope.message);
      return this.lastResponse;
    }
    const errors = envelope.errors;
    const code = errors && typeof errors === 'object' && typeof errors.code === 'string' ? errors.code : null;
    throw new ApiError(status, envelope.message || 'HTTP ' + status, { code, errors: errors === undefined ? null : errors, headers, body: text });
  }
}

module.exports = { Client, Response, DEFAULT_BASE_URL, VERSION };
