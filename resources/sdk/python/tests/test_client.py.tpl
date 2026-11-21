import json
import os
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

from uipe import ApiError, Client  # noqa: E402


class Handler(BaseHTTPRequestHandler):
    calls = []
    script = []

    def _handle(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else b""
        Handler.calls.append((self.command, self.path, dict(self.headers), body))
        status, headers, payload = Handler.script.pop(0)
        raw = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        for key, value in headers.items():
            self.send_header(key, value)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = _handle

    def log_message(self, *args):
        pass


class ClientTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = HTTPServer(("127.0.0.1", 0), Handler)
        cls.thread = threading.Thread(target=cls.server.serve_forever)
        cls.thread.daemon = True
        cls.thread.start()
        cls.base = "http://127.0.0.1:%d/api/dev/v1" % cls.server.server_address[1]

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()

    def setUp(self):
        Handler.calls = []
        Handler.script = []
        self.client = Client("uipk_test_abc", base_url=self.base, max_retries=2)

    def test_sends_the_key_and_returns_the_data_field(self):
        Handler.script = [(200, {"X-RateLimit-Remaining": "41"}, {"success": True, "data": {"id": 7}})]
        self.assertEqual({"id": 7}, self.client.get_message("a b"))
        method, path, headers, _ = Handler.calls[0]
        self.assertEqual("GET", method)
        self.assertEqual("/api/dev/v1/mail/messages/a%20b", path)
        self.assertEqual("Bearer uipk_test_abc", headers["Authorization"])
        self.assertEqual(41, self.client.last_response.rate_limit["remaining"])

    def test_query_values_are_encoded(self):
        Handler.script = [(200, {}, {"data": []})]
        self.client.list_messages({"folder": "inbox", "unread": True, "skip": None})
        path = Handler.calls[0][1]
        self.assertIn("folder=inbox", path)
        self.assertIn("unread=1", path)
        self.assertNotIn("skip", path)

    def test_a_429_is_retried_after_the_server_wait(self):
        Handler.script = [
            (429, {"Retry-After": "0"}, {"success": False, "message": "slow down", "errors": {"code": "rate_limited"}}),
            (200, {}, {"data": {"ok": True}}),
        ]
        self.assertEqual({"ok": True}, self.client.get_message(1))
        self.assertEqual(2, len(Handler.calls))

    def test_errors_carry_status_code_and_message(self):
        Handler.script = [(422, {}, {"success": False, "message": "Not in sandbox", "errors": {"code": "sandbox_unsupported"}})]
        with self.assertRaises(ApiError) as ctx:
            self.client.get_message(1)
        self.assertEqual(422, ctx.exception.status)
        self.assertEqual("sandbox_unsupported", ctx.exception.code)
        self.assertEqual("Not in sandbox", ctx.exception.message)

    def test_a_post_is_not_retried_on_503(self):
        Handler.script = [(503, {}, {"success": False, "message": "down"})]
        with self.assertRaises(ApiError):
            self.client.send_message({"to": ["a@b.c"]})
        self.assertEqual(1, len(Handler.calls))
        self.assertEqual("POST", Handler.calls[0][0])

    def test_the_body_is_sent_as_json(self):
        Handler.script = [(200, {}, {"data": None})]
        self.client.send_message({"subject": "hi"})
        self.assertEqual({"subject": "hi"}, json.loads(Handler.calls[0][3].decode("utf-8")))
        self.assertEqual("application/json", Handler.calls[0][2]["Content-Type"])

    def test_an_api_key_is_required(self):
        with self.assertRaises(ValueError):
            Client("")


if __name__ == "__main__":
    unittest.main()
