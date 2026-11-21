'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { ReplayGuard, SignatureError, buildHeader, constructEvent, inspectSignature, sign, verifySignature } = require('../src');

// Fixed vector published by the API at /docs/webhook-verification. Every UIPE SDK must accept it.
const SECRET = {{V_SECRET}};
const TIMESTAMP = {{V_TIMESTAMP}};
const BODY = {{V_BODY}};
const SIGNATURE = {{V_SIGNATURE}};
const HEADER = {{V_HEADER}};

test('matches the published vector', () => {
  assert.equal(sign(SECRET, TIMESTAMP, BODY), SIGNATURE);
  assert.equal(buildHeader(SECRET, BODY, TIMESTAMP), HEADER);
});

test('accepts a fresh valid delivery, strings and buffers', () => {
  assert.equal(verifySignature(BODY, HEADER, SECRET, { now: TIMESTAMP + 10 }), true);
  assert.equal(verifySignature(Buffer.from(BODY), HEADER, SECRET, { now: TIMESTAMP }), true);
});

test('rejects a wrong secret and a tampered body', () => {
  assert.equal(inspectSignature(BODY, HEADER, 'whsec_other', { now: TIMESTAMP }).reason, 'signature_mismatch');
  assert.equal(inspectSignature(BODY + ' ', HEADER, SECRET, { now: TIMESTAMP }).reason, 'signature_mismatch');
});

test('the window is inclusive and symmetric', () => {
  assert.equal(verifySignature(BODY, HEADER, SECRET, { now: TIMESTAMP + 300 }), true);
  assert.equal(inspectSignature(BODY, HEADER, SECRET, { now: TIMESTAMP + 301 }).reason, 'timestamp_too_old');
  assert.equal(verifySignature(BODY, HEADER, SECRET, { now: TIMESTAMP - 300 }), true);
  assert.equal(inspectSignature(BODY, HEADER, SECRET, { now: TIMESTAMP - 301 }).reason, 'timestamp_in_future');
});

test('custom tolerance', () => {
  assert.equal(verifySignature(BODY, HEADER, SECRET, { tolerance: 60, now: TIMESTAMP + 61 }), false);
  assert.equal(verifySignature(BODY, HEADER, SECRET, { tolerance: 60, now: TIMESTAMP + 60 }), true);
});

test('bad headers', () => {
  assert.equal(inspectSignature(BODY, null, SECRET).reason, 'missing_header');
  assert.equal(inspectSignature(BODY, '  ', SECRET).reason, 'missing_header');
  for (const bad of ['garbage', 't=abc,v1=' + SIGNATURE, 't=1', 'v1=' + SIGNATURE, 't=1,v1=zz', 't=1,t=2,v1=' + SIGNATURE]) {
    assert.equal(inspectSignature(BODY, bad, SECRET).reason, 'malformed_header', bad);
  }
});

test('missing secret', () => {
  assert.equal(inspectSignature(BODY, HEADER, [], { now: TIMESTAMP }).reason, 'missing_secret');
});

test('secret rotation accepts either secret', () => {
  const old = buildHeader('whsec_old', BODY, TIMESTAMP);
  assert.equal(verifySignature(BODY, old, ['whsec_new', 'whsec_old'], { now: TIMESTAMP }), true);
  assert.equal(verifySignature(BODY, old, ['whsec_new'], { now: TIMESTAMP }), false);
});

test('a header with several v1 values and unknown keys', () => {
  const other = sign('whsec_other', TIMESTAMP, BODY);
  assert.equal(verifySignature(BODY, 't=' + TIMESTAMP + ',v1=' + other + ',v1=' + SIGNATURE, SECRET, { now: TIMESTAMP }), true);
  assert.equal(verifySignature(BODY, 'v0=whatever,' + HEADER, SECRET, { now: TIMESTAMP }), true);
});

test('constructEvent returns the event and rejects a replay', () => {
  const guard = new ReplayGuard();
  assert.equal(constructEvent(BODY, HEADER, SECRET, { guard, now: TIMESTAMP }).id, 'evt_test');
  assert.throws(
    () => constructEvent(BODY, HEADER, SECRET, { guard, now: TIMESTAMP }),
    (err) => err instanceof SignatureError && err.reason === 'replayed_event'
  );
});

test('constructEvent explains a bad signature and a non JSON body', () => {
  assert.throws(
    () => constructEvent(BODY, HEADER, 'whsec_other', { now: TIMESTAMP }),
    (err) => err instanceof SignatureError && err.reason === 'signature_mismatch'
  );
  const body = 'not json';
  assert.throws(
    () => constructEvent(body, buildHeader(SECRET, body, TIMESTAMP), SECRET, { now: TIMESTAMP }),
    (err) => err.reason === 'invalid_payload'
  );
});

test('the guard forgets after its ttl and stays bounded', () => {
  const guard = new ReplayGuard({ ttl: 10 });
  assert.equal(guard.firstSeen('evt_1', 100), true);
  assert.equal(guard.firstSeen('evt_1', 105), false);
  assert.equal(guard.firstSeen('evt_1', 111), true);
  const small = new ReplayGuard({ ttl: 1000, maxEntries: 3 });
  for (let i = 0; i < 10; i++) small.firstSeen('evt_' + i, 100);
  assert.ok(small.seen.size <= 3);
});
