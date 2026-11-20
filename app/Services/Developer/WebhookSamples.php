<?php

namespace App\Services\Developer;

/** كود جاهز للمستقبِل (verifySignature) بأربع لغات. بيتحط في x-codeSamples بتاعة OpenAPI وفي GET /api/dev/v1/docs/webhook-verification. */
final class WebhookSamples
{
    public static function php(): string
    {
        $code = <<<'CODE'
<?php
// Verify a UIPE webhook delivery. Always use the RAW body, never a re-encoded one.
function verifySignature(string $rawBody, ?string $header, string $secret, int $tolerance = 300): bool
{
    if (!$header) {
        return false;
    }
    $t = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) {
            continue;
        }
        if ($kv[0] === 't') {
            $t = $kv[1];
        } elseif ($kv[0] === 'v1') {
            $sigs[] = strtolower($kv[1]);
        }
    }
    if ($t === null || !ctype_digit($t) || !$sigs) {
        return false;
    }
    $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
    $match = false;
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) {
            $match = true;
        }
    }
    return $match && abs(time() - (int) $t) <= $tolerance; // whole seconds, 5 minutes, both directions
}

$raw = file_get_contents('php://input');
if (!verifySignature($raw, $_SERVER['HTTP_X_UIPE_SIGNATURE'] ?? null, (string) getenv('UIPE_WEBHOOK_SECRET'))) {
    http_response_code(400);
    exit('invalid signature');
}
$event = json_decode($raw, true);
// The same event id can arrive twice (retry or redelivery): keep ids for 10+ minutes and skip repeats.
// if (alreadyProcessed($event['id'])) { http_response_code(200); exit; }
http_response_code(200);
CODE;
        return $code;
    }

    public static function node(): string
    {
        $code = <<<'CODE'
const crypto = require('crypto');
const express = require('express');

// rawBody: Buffer or string exactly as received.
function verifySignature(rawBody, header, secret, tolerance = 300) {
  if (!header) return false;
  let t = null;
  const sigs = [];
  for (const part of String(header).split(',')) {
    const i = part.indexOf('=');
    if (i < 1) continue;
    const key = part.slice(0, i).trim();
    const val = part.slice(i + 1).trim();
    if (key === 't') t = val;
    else if (key === 'v1') sigs.push(val.toLowerCase());
  }
  if (!t || !/^\d+$/.test(t) || sigs.length === 0) return false;
  const expected = crypto.createHmac('sha256', secret).update(t + '.').update(rawBody).digest('hex');
  const match = sigs.some((s) => s.length === expected.length && crypto.timingSafeEqual(Buffer.from(s), Buffer.from(expected)));
  return match && Math.abs(Math.floor(Date.now() / 1000) - Number(t)) <= tolerance; // whole seconds, 5 minutes, both directions
}

const app = express();
const seen = new Map(); // event id -> expiry (use Redis or a database in production)

app.post('/webhooks/uipe', express.raw({ type: 'application/json' }), (req, res) => {
  if (!verifySignature(req.body, req.get('X-UIPE-Signature'), process.env.UIPE_WEBHOOK_SECRET)) {
    return res.status(400).send('invalid signature');
  }
  const event = JSON.parse(req.body.toString('utf8'));
  const now = Date.now();
  for (const [id, exp] of seen) if (exp < now) seen.delete(id);
  if (seen.has(event.id)) return res.sendStatus(200); // already processed
  seen.set(event.id, now + 10 * 60 * 1000);
  res.sendStatus(200);
  // handle(event) after answering
});

app.listen(3000);
CODE;
        return $code;
    }

    public static function python(): string
    {
        $code = <<<'CODE'
import hashlib
import hmac
import os
import time

from flask import Flask, request

app = Flask(__name__)
SEEN = {}  # event id -> expiry (use Redis or a database in production)


def verify_signature(raw_body: bytes, header: str, secret: str, tolerance: int = 300) -> bool:
    if not header:
        return False
    t, sigs = None, []
    for part in header.split(","):
        key, _, val = part.strip().partition("=")
        if key == "t":
            t = val
        elif key == "v1":
            sigs.append(val.lower())
    if not t or not (t.isascii() and t.isdigit()) or not sigs:
        return False
    expected = hmac.new(secret.encode(), t.encode() + b"." + raw_body, hashlib.sha256).hexdigest()
    match = any(hmac.compare_digest(expected, s) for s in sigs)
    return match and abs(int(time.time()) - int(t)) <= tolerance  # whole seconds, 5 minutes, both directions


@app.post("/webhooks/uipe")
def uipe_webhook():
    raw = request.get_data()  # raw bytes, before any JSON parsing
    if not verify_signature(raw, request.headers.get("X-UIPE-Signature", ""), os.environ["UIPE_WEBHOOK_SECRET"]):
        return "invalid signature", 400
    event = request.get_json(force=True)
    now = time.time()
    for k in [k for k, exp in SEEN.items() if exp < now]:
        del SEEN[k]
    if event["id"] in SEEN:
        return "", 200  # already processed
    SEEN[event["id"]] = now + 600
    return "", 200
CODE;
        return $code;
    }

    public static function go(): string
    {
        $code = <<<'CODE'
package uipe

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"math"
	"strconv"
	"strings"
	"time"
)

// VerifySignature checks X-UIPE-Signature against the raw request body.
// Pass tolerance = 5 * time.Minute (both directions).
func VerifySignature(rawBody []byte, header, secret string, tolerance time.Duration) bool {
	var ts string
	var sigs []string
	for _, part := range strings.Split(header, ",") {
		kv := strings.SplitN(strings.TrimSpace(part), "=", 2)
		if len(kv) != 2 {
			continue
		}
		switch kv[0] {
		case "t":
			ts = kv[1]
		case "v1":
			sigs = append(sigs, strings.ToLower(kv[1]))
		}
	}
	if ts == "" || strings.Trim(ts, "0123456789") != "" || len(sigs) == 0 {
		return false
	}
	sec, err := strconv.ParseInt(ts, 10, 64)
	if err != nil {
		return false
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(ts + "."))
	mac.Write(rawBody)
	expected := mac.Sum(nil)
	match := false
	for _, s := range sigs {
		if got, err := hex.DecodeString(s); err == nil && hmac.Equal(got, expected) {
			match = true
		}
	}
	age := time.Now().Unix() - sec // whole seconds
	return match && math.Abs(float64(age)) <= tolerance.Seconds()
}
CODE;
        return $code;
    }

    /** @return array<string,string> lang => source */
    public static function all(): array
    {
        return ['php' => self::php(), 'node' => self::node(), 'python' => self::python(), 'go' => self::go()];
    }

    /** بصيغة x-codeSamples (Redoc / Scalar / Swagger UI). */
    public static function codeSamples(): array
    {
        return [
            ['lang' => 'PHP', 'label' => 'PHP', 'source' => self::php()],
            ['lang' => 'JavaScript', 'label' => 'Node.js (Express)', 'source' => self::node()],
            ['lang' => 'Python', 'label' => 'Python (Flask)', 'source' => self::python()],
            ['lang' => 'Go', 'label' => 'Go', 'source' => self::go()],
        ];
    }
}
