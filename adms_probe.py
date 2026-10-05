#!/usr/bin/env python3
"""Local iClock attendance capture probe. Uses only Python's standard library.

Run --help for usage. Raw captures live in a private SQLite database. Protocol
options are candidate defaults for the observed PUSH 2.4.0 device, not universal
firmware certification. No production authentication or HA guarantee is implied.
"""

import argparse
import json
import os
from pathlib import Path
import re
import sqlite3
import threading
from contextlib import contextmanager
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit


DEFAULT_DATABASE = Path(__file__).resolve().parent / "storage/app/private/adms-probe.sqlite"
MAX_BODY = 1024 * 1024


class CaptureStore:
    def __init__(self, path):
        self.path = str(path)
        with self.connect() as connection:
            connection.executescript("""
                CREATE TABLE IF NOT EXISTS captures (
                    id INTEGER PRIMARY KEY,
                    received_at TEXT NOT NULL,
                    remote_ip TEXT NOT NULL,
                    method TEXT NOT NULL,
                    path TEXT NOT NULL,
                    query TEXT NOT NULL,
                    serial TEXT NOT NULL,
                    table_name TEXT NOT NULL,
                    body BLOB NOT NULL
                );
                CREATE TABLE IF NOT EXISTS commands (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    serial TEXT NOT NULL,
                    payload TEXT NOT NULL,
                    offered_at TEXT
                );
            """)
        os.chmod(self.path, 0o600)

    @contextmanager
    def connect(self):
        connection = sqlite3.connect(self.path, timeout=5)
        try:
            connection.execute("PRAGMA synchronous=FULL")
            with connection:
                yield connection
        finally:
            connection.close()

    def capture(self, remote_ip, method, path, query, serial, table_name, body):
        with self.connect() as connection:
            cursor = connection.execute(
                "INSERT INTO captures (received_at, remote_ip, method, path, query, "
                "serial, table_name, body) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                (now(), remote_ip, method, path, query, serial, table_name, body),
            )
            return cursor.lastrowid

    def known_ip(self, remote_ip, serial):
        with self.connect() as connection:
            return connection.execute(
                "SELECT 1 FROM captures WHERE remote_ip = ? AND serial = ? LIMIT 1",
                (remote_ip, serial),
            ).fetchone() is not None

    def queue_attendance_query(self, serial):
        with self.connect() as connection:
            return connection.execute(
                "INSERT INTO commands (serial, payload) VALUES (?, ?)",
                (serial, "DATA QUERY ATTLOG"),
            ).lastrowid

    def offer_command(self, serial):
        with self.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            command = connection.execute(
                "SELECT id, payload FROM commands "
                "WHERE serial = ? AND offered_at IS NULL ORDER BY id LIMIT 1",
                (serial,),
            ).fetchone()
            if command is None:
                return "OK"
            connection.execute(
                "UPDATE commands SET offered_at = ? WHERE id = ?", (now(), command[0])
            )
            return f"C:{command[0]}:{command[1]}\n"


def now():
    return datetime.now(timezone.utc).isoformat()


def options_response(serial):
    # Request attendance only. Do not change the terminal timezone or clock.
    return "\n".join([
        f"GET OPTION FROM: {serial}",
        "ATTLOGStamp=None",
        "ErrorDelay=60",
        "Delay=10",
        "TransTimes=00:00;14:05",
        "TransInterval=1",
        "TransFlag=1000000000",
        "Realtime=1",
        "Encrypt=0",
        "",
    ])


class ProbeServer(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, address, store, serial, device_ip=None):
        self.store = store
        self.serial = serial
        self.device_ip = device_ip
        self.slots = threading.BoundedSemaphore(8)
        super().__init__(address, ProbeHandler)

    def process_request(self, request, client_address):
        if not self.slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except BaseException:
            self.slots.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.slots.release()


class ProbeHandler(BaseHTTPRequestHandler):
    server_version = "ADMSProbe/1.0"
    protocol_version = "HTTP/1.1"

    def setup(self):
        super().setup()
        self.connection.settimeout(10)

    def log_message(self, format_string, *args):
        # Avoid logging untrusted request strings or sensitive query parameters.
        pass

    def respond(self, status, text):
        body = text.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()
        self.close_connection = True
        self.wfile.write(body)

    def do_GET(self):
        self.handle_device_request()

    def do_POST(self):
        self.handle_device_request()

    def handle_device_request(self):
        try:
            self.process_device_request()
        except (sqlite3.Error, OSError, ValueError) as error:
            print(f"Capture failed: {type(error).__name__}; no success acknowledgement", flush=True)
            try:
                self.respond(503, "Capture unavailable")
            except OSError:
                pass

    def process_device_request(self):
        parsed = urlsplit(self.path)
        query = parse_qs(parsed.query, keep_blank_values=True)
        path = parsed.path
        allowed = {"/iclock/cdata", "/iclock/getrequest", "/iclock/devicecmd", "/iclock/ping"}
        if path not in allowed:
            print(f"Unsupported endpoint: {path!r}", flush=True)
            self.respond(404, "Unsupported endpoint")
            return

        remote_ip = self.client_address[0]
        if self.server.device_ip and remote_ip != self.server.device_ip:
            self.respond(403, "Unexpected device address")
            return
        serials = query.get("SN", [])
        serial = serials[0] if len(serials) == 1 else ""
        # Some firmware omits SN on command results. Only accept those from a
        # peer previously seen with the configured serial (local probe only).
        if not serials and path == "/iclock/devicecmd":
            if self.server.store.known_ip(remote_ip, self.server.serial):
                serial = self.server.serial
        if serial != self.server.serial:
            self.respond(403, "Unexpected or missing serial")
            return

        if self.headers.get("Transfer-Encoding") or self.headers.get("Content-Encoding"):
            self.respond(415, "Use an uncompressed Content-Length request")
            return
        lengths = self.headers.get_all("Content-Length", [])
        if self.command == "POST" and len(lengths) != 1:
            self.respond(411, "One Content-Length required")
            return
        if lengths and (len(lengths) != 1 or not re.fullmatch(r"[0-9]+", lengths[0])):
            self.respond(400, "Invalid Content-Length")
            return
        length = int(lengths[0]) if lengths else 0
        if length > MAX_BODY:
            self.respond(413, "Capture limit is 1 MiB")
            return
        body = self.rfile.read(length)
        if len(body) != length:
            self.respond(400, "Incomplete body")
            return

        tables = query.get("table", [])
        table = tables[0] if len(tables) == 1 else ""
        supported = (
            (path == "/iclock/cdata" and self.command == "GET")
            or (path == "/iclock/cdata" and self.command == "POST" and table.upper() in {"ATTLOG", "OPTIONS"})
            or (path in {"/iclock/getrequest", "/iclock/ping"} and self.command == "GET")
            or path == "/iclock/devicecmd"
        )
        if not supported:
            print(f"Unsupported request: {self.command} {path} table={table!r}", flush=True)
            self.respond(400, "Attendance and options only")
            return

        receipt = self.server.store.capture(
            remote_ip, self.command, path, parsed.query, serial, table, body
        )
        if path == "/iclock/cdata" and self.command == "GET":
            response = options_response(serial)
        elif path == "/iclock/getrequest":
            response = self.server.store.offer_command(serial)
        else:
            response = "OK"
        self.respond(200, response)
        print(f"[{now()}] receipt={receipt} {self.command} {path} table={table!r} bytes={length}", flush=True)
        if table.upper() == "ATTLOG":
            print(f"Attendance sample (first 4096 bytes): {body[:4096]!r}", flush=True)
        if path == "/iclock/devicecmd":
            print(f"Command result (first 1024 bytes): {body[:1024]!r}", flush=True)
        if response.startswith("C:"):
            print(f"Offered read-only command: {response.strip()!r}", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--host", default="127.0.0.1", help="Bind address; use your LAN IP for the device")
    parser.add_argument("--port", type=int, default=8000)
    parser.add_argument("--serial", required=True, help="Only accept this device serial number")
    parser.add_argument("--device-ip", help="Optional additional source-IP restriction")
    parser.add_argument("--database", type=Path, default=DEFAULT_DATABASE)
    parser.add_argument("--request-attlog", action="store_true", help="Queue one read-only DATA QUERY ATTLOG; may upload all stored attendance")
    parser.add_argument("--show-attendance", action="store_true", help="Print stored raw attendance as JSON lines, without starting the server")
    args = parser.parse_args()
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", args.serial):
        parser.error("serial must be 1–64 letters, digits, underscores or hyphens")
    if not 1 <= args.port <= 65535:
        parser.error("port must be between 1 and 65535")
    os.umask(0o077)
    args.database.parent.mkdir(parents=True, exist_ok=True)
    store = CaptureStore(args.database)
    if args.show_attendance:
        with store.connect() as connection:
            cursor = connection.execute(
                "SELECT id, received_at, query, body FROM captures "
                "WHERE serial = ? AND upper(table_name) = 'ATTLOG' ORDER BY id",
                (args.serial,),
            )
            for receipt, received_at, query, body in cursor:
                print(json.dumps({"receipt_id": receipt, "received_at": received_at,
                                  "query": query, "raw_text": body.decode("utf-8", errors="replace")}, ensure_ascii=True))
        return
    try:
        server = ProbeServer((args.host, args.port), store, args.serial, args.device_ip)
    except OSError as error:
        parser.exit(1, f"Cannot listen on {args.host}:{args.port}: {error}\nStop nc or another listener using this port.\n")
    if args.request_attlog:
        command_id = store.queue_attendance_query(args.serial)
        print(f"Queued read-only attendance query {command_id}; firmware support is unconfirmed.")
    print(f"Listening on {args.host}:{args.port}; serial={args.serial}; captures={args.database}", flush=True)
    print("Local diagnostic probe only. Make a test punch and wait for a POST with table=ATTLOG.", flush=True)
    print("ATTLOGStamp=None requests an initial cursor; firmware may replay historical records.", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nProbe stopped. Captures retained.")
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
