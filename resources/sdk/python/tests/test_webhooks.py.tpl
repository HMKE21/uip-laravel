import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

from uipe import ReplayGuard, SignatureError, build_header, construct_event, inspect_signature, sign, verify_signature  # noqa: E402

# Fixed vector published by the API at /docs/webhook-verification. Every UIPE SDK must accept it.
SECRET = {{V_SECRET}}
TIMESTAMP = {{V_TIMESTAMP}}
BODY = {{V_BODY}}
SIGNATURE = {{V_SIGNATURE}}
HEADER = {{V_HEADER}}


class VerifySignatureTests(unittest.TestCase):
    def test_matches_the_published_vector(self):
        self.assertEqual(SIGNATURE, sign(SECRET, TIMESTAMP, BODY))
        self.assertEqual(HEADER, build_header(SECRET, BODY, TIMESTAMP))

    def test_accepts_a_fresh_valid_delivery(self):
        self.assertTrue(verify_signature(BODY, HEADER, SECRET, now=TIMESTAMP + 10))

    def test_accepts_bytes_bodies(self):
        self.assertTrue(verify_signature(BODY.encode("utf-8"), HEADER, SECRET, now=TIMESTAMP))

    def test_rejects_a_wrong_secret_and_a_tampered_body(self):
        self.assertEqual("signature_mismatch", inspect_signature(BODY, HEADER, "whsec_other", now=TIMESTAMP)["reason"])
        self.assertEqual("signature_mismatch", inspect_signature(BODY + " ", HEADER, SECRET, now=TIMESTAMP)["reason"])

    def test_window_is_inclusive_and_symmetric(self):
        self.assertTrue(verify_signature(BODY, HEADER, SECRET, now=TIMESTAMP + 300))
        self.assertEqual("timestamp_too_old", inspect_signature(BODY, HEADER, SECRET, now=TIMESTAMP + 301)["reason"])
        self.assertTrue(verify_signature(BODY, HEADER, SECRET, now=TIMESTAMP - 300))
        self.assertEqual("timestamp_in_future", inspect_signature(BODY, HEADER, SECRET, now=TIMESTAMP - 301)["reason"])

    def test_custom_tolerance(self):
        self.assertFalse(verify_signature(BODY, HEADER, SECRET, tolerance=60, now=TIMESTAMP + 61))
        self.assertTrue(verify_signature(BODY, HEADER, SECRET, tolerance=60, now=TIMESTAMP + 60))

    def test_bad_headers(self):
        self.assertEqual("missing_header", inspect_signature(BODY, None, SECRET)["reason"])
        self.assertEqual("missing_header", inspect_signature(BODY, "  ", SECRET)["reason"])
        for bad in ("garbage", "t=abc,v1=" + SIGNATURE, "t=1", "v1=" + SIGNATURE, "t=1,v1=zz", "t=1,t=2,v1=" + SIGNATURE):
            self.assertEqual("malformed_header", inspect_signature(BODY, bad, SECRET)["reason"], bad)

    def test_missing_secret(self):
        self.assertEqual("missing_secret", inspect_signature(BODY, HEADER, [], now=TIMESTAMP)["reason"])

    def test_secret_rotation_accepts_either_secret(self):
        old_header = build_header("whsec_old", BODY, TIMESTAMP)
        self.assertTrue(verify_signature(BODY, old_header, ["whsec_new", "whsec_old"], now=TIMESTAMP))
        self.assertFalse(verify_signature(BODY, old_header, ["whsec_new"], now=TIMESTAMP))

    def test_header_with_several_v1_values(self):
        other = sign("whsec_other", TIMESTAMP, BODY)
        header = "t=%d,v1=%s,v1=%s" % (TIMESTAMP, other, SIGNATURE)
        self.assertTrue(verify_signature(BODY, header, SECRET, now=TIMESTAMP))

    def test_unknown_header_keys_are_ignored(self):
        self.assertTrue(verify_signature(BODY, "v0=whatever," + HEADER, SECRET, now=TIMESTAMP))


class ConstructEventTests(unittest.TestCase):
    def test_returns_the_parsed_event(self):
        event = construct_event(BODY, HEADER, SECRET, now=TIMESTAMP)
        self.assertEqual("evt_test", event["id"])

    def test_bad_signature_raises_with_a_reason(self):
        with self.assertRaises(SignatureError) as ctx:
            construct_event(BODY, HEADER, "whsec_other", now=TIMESTAMP)
        self.assertEqual("signature_mismatch", ctx.exception.reason)

    def test_the_same_event_id_is_rejected_the_second_time(self):
        guard = ReplayGuard()
        construct_event(BODY, HEADER, SECRET, guard=guard, now=TIMESTAMP)
        with self.assertRaises(SignatureError) as ctx:
            construct_event(BODY, HEADER, SECRET, guard=guard, now=TIMESTAMP)
        self.assertEqual("replayed_event", ctx.exception.reason)

    def test_guard_forgets_after_its_ttl(self):
        guard = ReplayGuard(ttl=10)
        self.assertTrue(guard.first_seen("evt_1", now=100))
        self.assertFalse(guard.first_seen("evt_1", now=105))
        self.assertTrue(guard.first_seen("evt_1", now=111))

    def test_guard_is_bounded(self):
        guard = ReplayGuard(ttl=1000, max_entries=3)
        for i in range(10):
            guard.first_seen("evt_%d" % i, now=100)
        self.assertLessEqual(len(guard._seen), 3)

    def test_non_json_body_with_a_valid_signature_is_rejected(self):
        body = "not json"
        header = build_header(SECRET, body, TIMESTAMP)
        with self.assertRaises(SignatureError) as ctx:
            construct_event(body, header, SECRET, now=TIMESTAMP)
        self.assertEqual("invalid_payload", ctx.exception.reason)


if __name__ == "__main__":
    unittest.main()
