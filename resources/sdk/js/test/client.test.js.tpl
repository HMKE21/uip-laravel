'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { Client, ApiError } = require('../src');

function start(script) {
  const calls = [];
  const server = http.createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      calls.push({ method: req.method, url: req.url, headers: req.headers, body: Buffer.concat(chunks).toString('utf8') });
      const [status, headers, payload] = script.shift();
      res.writeHead(status, Object.assign({ 'Content-Type': 'application/json' }, headers));
      res.end(JSON.stringify(payload));
    });
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const base = 'http://127.0.0.1:' + server.address().port + '/api/dev/v1';
      resolve({ calls, base, close: () => server.close() });
    });
  });
}

test('sends the key and resolves with the data field', async () => {
  const s = await start([[200, { 'X-RateLimit-Remaining': '41' }, { success: true, data: { id: 7 } }]]);
  try {
    const client = new Client('uipk_test_abc', { baseUrl: s.base });
    assert.deepEqual(await client.getMessage('a b'), { id: 7 });
    assert.equal(s.calls[0].method, 'GET');
    assert.equal(s.calls[0].url, '/api/dev/v1/mail/messages/a%20b');
    assert.equal(s.calls[0].headers.authorization, 'Bearer uipk_test_abc');
    assert.equal(client.lastResponse.rateLimit.remaining, 41);
  } finally {
    s.close();
  }
});

test('query values are encoded', async () => {
  const s = await start([[200, {}, { data: [] }]]);
  try {
    await new Client('k', { baseUrl: s.base }).listMessages({ query: { folder: 'inbox', unread: true, skip: null } });
    assert.match(s.calls[0].url, /folder=inbox/);
    assert.match(s.calls[0].url, /unread=1/);
    assert.doesNotMatch(s.calls[0].url, /skip/);
  } finally {
    s.close();
  }
});

test('a 429 is retried after the server wait', async () => {
  const s = await start([
    [429, { 'Retry-After': '0' }, { success: false, message: 'slow down', errors: { code: 'rate_limited' } }],
    [200, {}, { data: { ok: true } }],
  ]);
  try {
    assert.deepEqual(await new Client('k', { baseUrl: s.base }).getMessage(1), { ok: true });
    assert.equal(s.calls.length, 2);
  } finally {
    s.close();
  }
});

test('errors carry status, code and message', async () => {
  const s = await start([[422, {}, { success: false, message: 'Not in sandbox', errors: { code: 'sandbox_unsupported' } }]]);
  try {
    await assert.rejects(new Client('k', { baseUrl: s.base }).getMessage(1), (err) => {
      return err instanceof ApiError && err.status === 422 && err.code === 'sandbox_unsupported' && err.message === 'Not in sandbox';
    });
  } finally {
    s.close();
  }
});

test('a POST is not retried on 503 and the body is sent as JSON', async () => {
  const s = await start([[503, {}, { success: false, message: 'down' }]]);
  try {
    await assert.rejects(new Client('k', { baseUrl: s.base }).sendMessage({ body: { subject: 'hi' } }), ApiError);
    assert.equal(s.calls.length, 1);
    assert.equal(s.calls[0].method, 'POST');
    assert.deepEqual(JSON.parse(s.calls[0].body), { subject: 'hi' });
    assert.equal(s.calls[0].headers['content-type'], 'application/json');
  } finally {
    s.close();
  }
});

test('an api key is required', () => {
  assert.throws(() => new Client(''), TypeError);
});
