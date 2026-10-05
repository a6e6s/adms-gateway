"""Run with: python3 -m unittest discover -s tests -p test_adms_probe.py -v"""

import http.client
import importlib.util
from pathlib import Path
import sqlite3
import tempfile
import threading
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("adms_probe", Path(__file__).resolve().parents[1] / "adms_probe.py")
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)


class ProbeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.store = probe.CaptureStore(Path(self.directory.name) / "captures.sqlite")
        self.server = probe.ProbeServer(("127.0.0.1", 0), self.store, "A39N203960051")
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()
        self.directory.cleanup()

    def request(self, method, path, body=None, headers=None):
        connection = http.client.HTTPConnection(*self.server.server_address, timeout=3)
        connection.request(method, path, body=body, headers=headers or {})
        response = connection.getresponse()
        result = response.status, response.read()
        connection.close()
        return result

    def test_observed_handshake_and_idle_poll(self):
        status, body = self.request("GET", "/iclock/cdata?SN=A39N203960051&options=all&language=66&pushver=2.4.0&DeviceType=middle%20east&PushOptionsFlag=1")
        self.assertEqual(status, 200)
        self.assertIn(b"GET OPTION FROM: A39N203960051\n", body)
        self.assertIn(b"Realtime=1\n", body)
        self.assertEqual(self.request("GET", "/iclock/getrequest?SN=A39N203960051"), (200, b"OK"))

    def test_raw_attendance_committed_before_success_and_survives_restart(self):
        body = b"000123\t2026-10-05 08:00:01\t0\t1\t0\r\n"
        self.assertEqual(self.request("POST", "/iclock/cdata?SN=A39N203960051&table=ATTLOG&Stamp=123", body), (200, b"OK"))
        reopened = probe.CaptureStore(self.store.path)
        with reopened.connect() as connection:
            self.assertEqual(connection.execute("SELECT body FROM captures").fetchone()[0], body)

    def test_storage_failure_does_not_acknowledge(self):
        with patch.object(self.store, "capture", side_effect=sqlite3.OperationalError("disk full")):
            self.assertEqual(self.request("POST", "/iclock/cdata?SN=A39N203960051&table=ATTLOG", b"sample"), (503, b"Capture unavailable"))

    def test_success_response_is_sent_only_after_independent_reader_sees_commit(self):
        original = probe.ProbeHandler.respond
        committed = []

        def inspect_commit(handler, status, text):
            if status == 200:
                with self.store.connect() as connection:
                    committed.append(connection.execute("SELECT body FROM captures").fetchone()[0])
            original(handler, status, text)

        with patch.object(probe.ProbeHandler, "respond", inspect_commit):
            self.assertEqual(self.request("POST", "/iclock/cdata?SN=A39N203960051&table=ATTLOG", b"raw sample"), (200, b"OK"))
        self.assertEqual(committed, [b"raw sample"])

    def test_wrong_device_and_biometric_upload_are_rejected(self):
        self.assertEqual(self.request("GET", "/iclock/cdata?SN=OTHER")[0], 403)
        self.assertEqual(self.request("GET", "/iclock/cdata?SN=A39N203960051&SN=OTHER")[0], 403)
        self.assertEqual(self.request("POST", "/iclock/cdata?SN=A39N203960051&table=OPERLOG", b"FP TMP=secret")[0], 400)
        with self.store.connect() as connection:
            self.assertEqual(connection.execute("SELECT count(*) FROM captures").fetchone()[0], 0)

    def test_body_limit_and_unsupported_encoding(self):
        path = "/iclock/cdata?SN=A39N203960051&table=ATTLOG"
        self.assertEqual(self.request("POST", path, b"", {"Content-Length": str(probe.MAX_BODY + 1)})[0], 413)
        self.assertEqual(self.request("POST", path, b"x", {"Content-Encoding": "gzip"})[0], 415)

    def test_optional_query_offered_once_even_after_store_restart(self):
        command_id = self.store.queue_attendance_query("A39N203960051")
        path = "/iclock/getrequest?SN=A39N203960051"
        self.assertEqual(self.request("GET", path), (200, f"C:{command_id}:DATA QUERY ATTLOG\n".encode()))
        self.server.store = probe.CaptureStore(self.store.path)
        self.assertEqual(self.request("GET", path), (200, b"OK"))

    def test_concurrent_polls_offer_query_to_only_one_request(self):
        self.store.queue_attendance_query("A39N203960051")
        results = []
        threads = [threading.Thread(target=lambda: results.append(self.request("GET", "/iclock/getrequest?SN=A39N203960051")[1])) for _ in range(4)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        self.assertEqual(sum(body.startswith(b"C:") for body in results), 1)

    def test_command_result_without_serial_requires_known_peer(self):
        self.assertEqual(self.request("POST", "/iclock/devicecmd", b"ID=1&Return=0&CMD=DATA")[0], 403)
        self.request("GET", "/iclock/getrequest?SN=A39N203960051")
        self.assertEqual(self.request("POST", "/iclock/devicecmd", b"ID=1&Return=0&CMD=DATA"), (200, b"OK"))


if __name__ == "__main__":
    unittest.main()
