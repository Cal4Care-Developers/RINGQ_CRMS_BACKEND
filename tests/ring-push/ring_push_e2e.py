#!/usr/bin/env python3
"""
ring_push_e2e.py -- end-to-end test of call push (mod_ringq) on one RingQ server.

Runs ON the server (inside the `ringq` container on Docker installs), as root, with only the
Python standard library. It reads the ESL and database credentials from the server itself, so no
secret is passed on the command line.

What it does:
  * plays a phone: a small SIP client that registers as the test extension, rings and answers
  * places real calls to that extension through the tenant's normal dialplan (loopback leg)
  * switches the tenant to ring_push_mode = wake for the run, and restores it afterwards
  * watches the ringq::ring / ringq::ring_end events mod_ringq fires, and the SIP the phone gets

There is no real mobile phone, so nobody can receive a push. For the "app was closed" cases the
test answers mod_ringq's push count itself (hash ringq_ring/<ring id> = "<pushed>/<iPhones>", e.g.
1/0 for one Android, 1/1 for one iPhone), standing in for node having pushed one phone, then "wakes"
the phone by registering it with the RingQ app's own User-Agent. mod_ringq reports the count it
read in ringq::ring_end (ringq-pushed), so a case where that stand-in did not take is reported as
SKIP, never as a pass or a failure.

Usage (on the server):
  python3 ring_push_e2e.py --domain example.ringq.ai --ext 1001
  python3 ring_push_e2e.py --domain example.ringq.ai --ext 1001 --cases off,registered,woken
  python3 ring_push_e2e.py --domain example.ringq.ai --ext 1001 --cleanup-only

Pick an extension that has no phone and no app signed in: during the run the tenant is in "wake"
mode (every extension of that tenant rings through mod_ringq) and the test extension is registered
by this script. Exit code: 0 all passed (skips allowed), 1 a case failed, 2 could not run.
"""
import argparse
import hashlib
import os
import random
import re
import socket
import subprocess
import sys
import threading
import time
import urllib.parse
import uuid

ESL_CONF = "/etc/freeswitch/autoload_configs/event_socket.conf.xml"
ENV_FILE = "/etc/ringq/.env"
WAKE_WAIT_MS = 15000
CASES = ["preflight", "off", "wake-dialstring", "registered", "woken", "woken-ios", "ios-waits-for-app",
         "never-wakes", "not-pushed", "caller-hangs-up", "module-unloaded"]
# mod_ringq counts only the RingQ apps' own registrations as a woken phone (SIP User-Agent
# "RingQ android - <device>" / "RingQ iOS - <device>"); anything else is "another device"
UA_ANDROID = "RingQ android - e2e-test"
UA_IOS = "RingQ iOS - e2e-test"
UA_WEB = "RingQ Webapp - e2e-test"


# ---------------------------------------------------------------------------------------------
# small helpers
# ---------------------------------------------------------------------------------------------

def now():
    return time.monotonic()


def log(msg):
    print(time.strftime("%H:%M:%S"), msg, flush=True)


def read_env(path):
    env = {}
    with open(path) as f:
        for line in f:
            m = re.match(r'^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$', line.rstrip("\n"))
            if m:
                v = m.group(2).strip()
                if len(v) >= 2 and v[0] == v[-1] and v[0] in "'\"":
                    v = v[1:-1]
                env[m.group(1)] = v
    return env


class Db:
    def __init__(self):
        e = read_env(ENV_FILE)
        self.args = ["psql", "-X", "-q", "-At", "-F", "|", "-v", "ON_ERROR_STOP=1",
                     "-h", e.get("PostgreSQL_DB_HOST", "127.0.0.1"), "-p", e.get("PostgreSQL_DB_PORT", "5432"),
                     "-U", e["PostgreSQL_DB_USER"], "-d", e["PostgreSQL_DB_NAME"]]
        self.env = dict(os.environ, PGPASSWORD=e["PostgreSQL_DB_PASSWORD"])

    def q(self, sql, *params):
        # psql variables keep the values out of the SQL text: :'v1', :'v2', ...
        args = list(self.args)
        for i, p in enumerate(params, 1):
            args += ["-v", f"v{i}={p}"]
        r = subprocess.run(args, input=sql, capture_output=True, text=True, env=self.env, timeout=30)
        if r.returncode != 0:
            raise RuntimeError(f"psql: {r.stderr.strip()}")
        return [line.split("|") for line in r.stdout.splitlines() if line != ""]


# ---------------------------------------------------------------------------------------------
# FreeSWITCH event socket
# ---------------------------------------------------------------------------------------------

def esl_settings():
    xml = open(ESL_CONF).read()
    def p(name, default):
        m = re.search(r'<param\s+name="%s"\s+value="([^"]*)"' % name, xml)
        return m.group(1) if m else default
    host = p("listen-ip", "127.0.0.1")
    if host in ("0.0.0.0", "::", ""):
        host = "127.0.0.1"
    return host, int(p("listen-port", "8021")), p("password", "ClueCon")


class Esl:
    """One connection; api() for commands, or start_events() for an event stream."""

    def __init__(self):
        host, port, pw = esl_settings()
        self.sock = socket.create_connection((host, port), timeout=10)
        self.buf = b""
        self.lock = threading.Lock()
        self._read_frame()  # auth/request
        self.sock.sendall(f"auth {pw}\n\n".encode())
        h, _ = self._read_frame()
        if "+OK" not in h.get("Reply-Text", ""):
            raise RuntimeError("ESL auth failed")

    def _read_line(self):
        while b"\n" not in self.buf:
            d = self.sock.recv(65536)
            if not d:
                raise ConnectionError("ESL closed")
            self.buf += d
        line, self.buf = self.buf.split(b"\n", 1)
        return line.decode(errors="replace")

    def _read_exact(self, n):
        while len(self.buf) < n:
            d = self.sock.recv(65536)
            if not d:
                raise ConnectionError("ESL closed")
            self.buf += d
        data, self.buf = self.buf[:n], self.buf[n:]
        return data.decode(errors="replace")

    def _read_frame(self):
        headers = {}
        while True:
            line = self._read_line()
            if line == "":
                if headers:
                    break
                continue
            k, _, v = line.partition(":")
            headers[k.strip()] = v.strip()
        body = self._read_exact(int(headers["Content-Length"])) if "Content-Length" in headers else ""
        return headers, body

    def api(self, cmd):
        with self.lock:
            self.sock.sendall(f"api {cmd}\n\n".encode())
            while True:
                h, body = self._read_frame()
                if h.get("Content-Type") == "api/response":
                    return body.strip()

    def bgapi(self, cmd):
        with self.lock:
            self.sock.sendall(f"bgapi {cmd}\n\n".encode())
            while True:
                h, _ = self._read_frame()
                if h.get("Content-Type") == "command/reply":
                    return h.get("Reply-Text", "")

    def start_events(self, names, handler):
        self.sock.sendall(f"event plain {names}\n\n".encode())
        def run():
            self.sock.settimeout(None)
            while True:
                try:
                    h, body = self._read_frame()
                except Exception:
                    return
                if h.get("Content-Type") != "text/event-plain":
                    continue
                ev = {}
                for line in body.split("\n"):
                    k, sep, v = line.partition(":")
                    if sep:
                        ev[k.strip()] = urllib.parse.unquote(v.strip())
                try:
                    handler(ev)
                except Exception as e:  # never kill the reader
                    log(f"  [event handler error] {e}")
        threading.Thread(target=run, daemon=True).start()


# ---------------------------------------------------------------------------------------------
# a tiny SIP phone (UDP): REGISTER with digest auth, ring, answer, hang up
# ---------------------------------------------------------------------------------------------

class Phone:
    def __init__(self, ip, user, password, domain, server_port=5060, user_agent=UA_ANDROID):
        self.ip, self.user, self.password, self.domain = ip, user, password, domain
        self.user_agent = user_agent
        self.server = (ip, server_port)
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock.bind((ip, 0))
        self.port = self.sock.getsockname()[1]
        self.rtp = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)  # receives (and drops) audio
        self.rtp.bind((ip, 0))
        self.rtp.settimeout(0.2)
        self.cseq = random.randint(100, 1000)
        self.reg_callid = f"{uuid.uuid4()}@{ip}"
        self.tag = uuid.uuid4().hex[:10]
        self.answer_after = 1.0       # seconds between 180 and 200
        self.auto_answer = True
        self.invites = []             # {"call_id", "at", "answered_at", "bye_at", "cancel_at"}
        self.pending = {}             # transaction key -> event + response
        self.lock = threading.Lock()
        self.running = True
        threading.Thread(target=self._loop, daemon=True).start()
        threading.Thread(target=self._drain_rtp, daemon=True).start()

    # -- wire ------------------------------------------------------------------------------
    def _send(self, text, addr=None):
        self.sock.sendto(text.replace("\n", "\r\n").encode(), addr or self.server)

    def _drain_rtp(self):
        while self.running:
            try:
                self.rtp.recvfrom(4096)
            except socket.timeout:
                pass
            except OSError:
                return

    @staticmethod
    def _parse(data):
        text = data.decode(errors="replace")
        head, _, body = text.partition("\r\n\r\n")
        lines = head.split("\r\n")
        first = lines[0]
        h = {}
        vias = []
        for line in lines[1:]:
            k, _, v = line.partition(":")
            k = k.strip().lower()
            v = v.strip()
            k = {"v": "via", "f": "from", "t": "to", "i": "call-id", "m": "contact", "l": "content-length"}.get(k, k)
            if k == "via":
                vias.append(v)
            h[k] = v
        h["_vias"] = vias
        return first, h, body

    def _loop(self):
        self.sock.settimeout(0.5)
        while self.running:
            try:
                data, addr = self.sock.recvfrom(65535)
            except socket.timeout:
                continue
            except OSError:
                return
            first, h, body = self._parse(data)
            if first.startswith("SIP/2.0"):
                key = h.get("call-id", "") + "|" + h.get("cseq", "")
                code = int(first.split()[1])
                with self.lock:
                    p = self.pending.get(key)
                if p and code >= 200:
                    p["resp"] = (code, h)
                    p["event"].set()
                continue
            method = first.split()[0]
            self._on_request(method, h, addr)

    def _reply(self, h, code, reason, addr, extra="", body="", to_tag=True):
        to = h.get("to", "")
        if to_tag and ";tag=" not in to:
            to += f";tag={self.tag}"
        vias = "".join(f"Via: {v}\n" for v in h["_vias"])
        ctype = "Content-Type: application/sdp\n" if body else ""
        msg = (f"SIP/2.0 {code} {reason}\n{vias}From: {h.get('from','')}\nTo: {to}\n"
               f"Call-ID: {h.get('call-id','')}\nCSeq: {h.get('cseq','')}\n"
               f"Contact: <sip:{self.user}@{self.ip}:{self.port}>\n{extra}{ctype}"
               f"Content-Length: {len(body.encode())}\n\n{body}")
        self._send(msg, addr)

    def _sdp(self):
        sid = random.randint(1000, 9999)
        rtp_port = self.rtp.getsockname()[1]
        return (f"v=0\r\no=ringqtest {sid} {sid} IN IP4 {self.ip}\r\ns=-\r\nc=IN IP4 {self.ip}\r\nt=0 0\r\n"
                f"m=audio {rtp_port} RTP/AVP 0 101\r\na=rtpmap:0 PCMU/8000\r\n"
                f"a=rtpmap:101 telephone-event/8000\r\na=fmtp:101 0-16\r\na=sendrecv\r\n")

    def _on_request(self, method, h, addr):
        call_id = h.get("call-id", "")
        if method == "INVITE":
            with self.lock:
                known = next((c for c in self.invites if c["call_id"] == call_id), None)
                if not known:
                    known = {"call_id": call_id, "at": now(), "answered_at": None, "bye_at": None, "cancel_at": None}
                    self.invites.append(known)
            self._reply(h, 100, "Trying", addr, to_tag=False)
            self._reply(h, 180, "Ringing", addr)
            if self.auto_answer and not known["answered_at"]:
                def answer():
                    time.sleep(self.answer_after)
                    if known["cancel_at"]:
                        return
                    known["answered_at"] = now()
                    self._reply(h, 200, "OK", addr, body=self._sdp())
                threading.Thread(target=answer, daemon=True).start()
        elif method == "CANCEL":
            with self.lock:
                for c in self.invites:
                    if c["call_id"] == call_id:
                        c["cancel_at"] = now()
            self._reply(h, 200, "OK", addr)
            inv = dict(h)
            inv["cseq"] = h.get("cseq", "").replace("CANCEL", "INVITE")
            self._reply(inv, 487, "Request Terminated", addr)
        elif method == "BYE":
            with self.lock:
                for c in self.invites:
                    if c["call_id"] == call_id:
                        c["bye_at"] = now()
            self._reply(h, 200, "OK", addr)
        elif method == "ACK":
            pass
        else:  # OPTIONS keep-alive, NOTIFY, INFO, ...
            self._reply(h, 200, "OK", addr)

    # -- REGISTER --------------------------------------------------------------------------
    def _register_once(self, expires, auth=None):
        self.cseq += 1
        branch = "z9hG4bK" + uuid.uuid4().hex[:16]
        key = f"{self.reg_callid}|{self.cseq} REGISTER"
        ev = threading.Event()
        with self.lock:
            self.pending[key] = {"event": ev}
        msg = (f"REGISTER sip:{self.domain} SIP/2.0\nVia: SIP/2.0/UDP {self.ip}:{self.port};branch={branch};rport\n"
               f"Max-Forwards: 70\nFrom: <sip:{self.user}@{self.domain}>;tag={self.tag}\nTo: <sip:{self.user}@{self.domain}>\n"
               f"Call-ID: {self.reg_callid}\nCSeq: {self.cseq} REGISTER\n"
               f"Contact: <sip:{self.user}@{self.ip}:{self.port};transport=udp>\nExpires: {expires}\n"
               f"User-Agent: {self.user_agent}\n{auth or ''}Content-Length: 0\n\n")
        self._send(msg)
        ev.wait(5)
        with self.lock:
            p = self.pending.pop(key)
        return p.get("resp")

    def register(self, expires=300):
        resp = self._register_once(expires)
        if resp and resp[0] in (401, 407):
            hdr = resp[1].get("www-authenticate") or resp[1].get("proxy-authenticate") or ""
            params = dict(re.findall(r'(\w+)="?([^",]+)"?', hdr))
            realm, nonce = params.get("realm", self.domain), params.get("nonce", "")
            uri = f"sip:{self.domain}"
            ha1 = hashlib.md5(f"{self.user}:{realm}:{self.password}".encode()).hexdigest()
            ha2 = hashlib.md5(f"REGISTER:{uri}".encode()).hexdigest()
            name = "Authorization" if resp[0] == 401 else "Proxy-Authorization"
            if "auth" in params.get("qop", ""):
                cnonce, nc = uuid.uuid4().hex[:8], "00000001"
                digest = hashlib.md5(f"{ha1}:{nonce}:{nc}:{cnonce}:auth:{ha2}".encode()).hexdigest()
                auth = (f'{name}: Digest username="{self.user}", realm="{realm}", nonce="{nonce}", uri="{uri}", '
                        f'response="{digest}", algorithm=MD5, qop=auth, nc={nc}, cnonce="{cnonce}"\n')
            else:
                digest = hashlib.md5(f"{ha1}:{nonce}:{ha2}".encode()).hexdigest()
                auth = (f'{name}: Digest username="{self.user}", realm="{realm}", nonce="{nonce}", uri="{uri}", '
                        f'response="{digest}", algorithm=MD5\n')
            resp = self._register_once(expires, auth)
        return resp[0] if resp else None

    def close(self):
        self.running = False
        try:
            self.register(0)
        except Exception:
            pass
        self.sock.close()
        self.rtp.close()


# ---------------------------------------------------------------------------------------------
# the test
# ---------------------------------------------------------------------------------------------

class Run:
    def __init__(self, a):
        self.a = a
        self.db = Db()
        self.api = Esl()
        self.events = []
        self.ev_lock = threading.Lock()
        self.push_override = None     # value to stand in for node's push count, or None
        self.on_ring = None           # callback(ev) for the current case
        self.results = []
        self.prev_setting = None      # (uuid, value, enabled) of an override that existed before
        self.created_setting = None
        self.module_was_loaded = None

    # -- events --------------------------------------------------------------------------------
    def _handle(self, ev):
        sub = ev.get("Event-Subclass", "")
        name = ev.get("Event-Name", "")
        if sub in ("ringq::ring", "ringq::ring_end") and ev.get("ringq-ext") != self.a.ext:
            return
        ev["_at"] = now()
        with self.ev_lock:
            self.events.append(ev)
        if sub == "ringq::ring":
            if self.push_override is not None:
                rid = ev.get("ringq-ring-id")
                val = self.push_override
                # mod_ringq reads the count every 100 ms; keep writing ours so it is the one it
                # reads even though node answers (0, no app tokens) a few ms after the event
                def stand_in():
                    end = now() + 0.4
                    while now() < end:
                        try:
                            self.api.api(f"hash insert/ringq_ring/{rid}/{val}")
                        except Exception:
                            pass
                        time.sleep(0.005)
                threading.Thread(target=stand_in, daemon=True).start()
            if self.on_ring:
                threading.Thread(target=self.on_ring, args=(ev,), daemon=True).start()

    def wait_event(self, pred, since, timeout):
        end = now() + timeout
        while now() < end:
            with self.ev_lock:
                for e in self.events:
                    if e["_at"] >= since and pred(e):
                        return e
            time.sleep(0.05)
        return None

    def channels_since(self, since):
        with self.ev_lock:
            return [e for e in self.events if e["_at"] >= since and e.get("Event-Name") == "CHANNEL_CREATE"]

    # -- tenant / server state -----------------------------------------------------------------
    def flush(self):
        subprocess.run("rm -f /var/cache/ringq/directory.* /var/cache/ringq/setting.*.ringq.ring_push_mode.* "
                       "/var/cache/fusionpbx/directory.* 2>/dev/null", shell=True)
        self.api.api("memcache flush")

    def set_mode(self, mode):
        rows = self.db.q("select domain_setting_uuid, domain_setting_value, domain_setting_enabled from v_domain_settings s "
                         "join v_domains d using (domain_uuid) where d.domain_name = :'v1' and domain_setting_category = 'ringq' "
                         "and domain_setting_subcategory = 'ring_push_mode'", self.a.domain)
        if self.prev_setting is None and self.created_setting is None:
            self.prev_setting = tuple(rows[0]) if rows else ()
        if rows:
            self.db.q("update v_domain_settings set domain_setting_value = :'v1', domain_setting_enabled = 'true' "
                      "where domain_setting_uuid = :'v2'", mode, rows[0][0])
        else:
            self.created_setting = str(uuid.uuid4())
            self.db.q("insert into v_domain_settings (domain_setting_uuid, domain_uuid, domain_setting_category, "
                      "domain_setting_subcategory, domain_setting_name, domain_setting_value, domain_setting_enabled, "
                      "domain_setting_description) select :'v1', domain_uuid, 'ringq', 'ring_push_mode', 'text', :'v2', 'true', "
                      "'ring_push_e2e.py test run (removed afterwards)' from v_domains where domain_name = :'v3'",
                      self.created_setting, mode, self.a.domain)
        self.flush()

    def restore(self):
        try:
            if self.created_setting:
                self.db.q("delete from v_domain_settings where domain_setting_uuid = :'v1'", self.created_setting)
            elif self.prev_setting:
                self.db.q("update v_domain_settings set domain_setting_value = :'v1', domain_setting_enabled = :'v2' "
                          "where domain_setting_uuid = :'v3'", self.prev_setting[1], self.prev_setting[2], self.prev_setting[0])
        finally:
            if self.module_was_loaded and "true" not in self.api.api("module_exists mod_ringq"):
                self.api.api("load mod_ringq")
            self.flush()

    def dial_string(self):
        return self.api.api(f"user_data {self.a.ext}@{self.a.domain} param dial-string")

    # -- calls -----------------------------------------------------------------------------------
    def call(self, timeout=60):
        a_uuid = str(uuid.uuid4())
        vars_ = (f"origination_uuid={a_uuid},origination_caller_id_number={self.a.caller},"
                 f"origination_caller_id_name=RingPushTest,originate_timeout={timeout},ignore_early_media=true")
        self.api.bgapi(f"originate {{{vars_}}}loopback/{self.a.ext}/{self.a.context} &park()")
        return a_uuid

    def hangup(self, a_uuid):
        self.api.api(f"uuid_kill {a_uuid}")

    def record(self, case, ok, detail):
        status = "PASS" if ok is True else ("SKIP" if ok is None else "FAIL")
        self.results.append((case, status, detail))
        log(f"  => {status}: {detail}")

    # -- cases -----------------------------------------------------------------------------------
    def case_preflight(self, phone):
        checks = {
            "mod_ringq loaded": "true" in self.api.api("module_exists mod_ringq"),
            "directory hook": "ringq_ring_dial" in open(self.a.dirlua).read(),
            "dial hook is the mod_ringq one": "mod_ringq" in open("/usr/share/freeswitch/scripts/ringq_ring_dial.lua").read(),
            "loopback dialplan row gone": self.db.q("select count(*) from v_dialplans where dialplan_uuid = "
                                                    "'7b0e6b1c-2f4e-4f7a-9d8e-5a1c3f9b2e01'")[0][0] == "0",
        }
        bad = [k for k, v in checks.items() if not v]
        default = self.db.q("select default_setting_value from v_default_settings where default_setting_category = 'ringq' "
                            "and default_setting_subcategory = 'ring_push_mode'")
        log(f"  default ring_push_mode = {default[0][0] if default else '(unset)'}, auto-wake stamp "
            f"{'present' if os.path.exists('/var/lib/ringq-push/auto-wake') else 'absent'}, Firebase key "
            f"{'installed' if os.path.exists('/etc/ringq/fcm/service-account.json') else 'not installed'}")
        self.record("preflight", not bad, "all present" if not bad else "missing: " + ", ".join(bad))
        self.module_was_loaded = checks["mod_ringq loaded"]
        return not bad

    def case_off(self, phone):
        self.set_mode("off")
        ds = self.dial_string()
        self.record("off", "ringq/" not in ds and ds != "", f"dial-string stays normal: {ds[:90]}")

    def case_wake_dialstring(self, phone):
        self.set_mode("wake")
        ds = self.dial_string()
        orig = self.api.api(f"user_data {self.a.ext}@{self.a.domain} param ringq-orig-dial-string")
        want = f"ringq/{self.a.ext}@{self.a.domain}"
        self.record("wake-dialstring", ds == want and orig != "" and "ringq/" not in orig,
                    f"dial-string {ds!r}, original kept: {orig[:60]!r}")

    def run_call(self, phone, push_count=None, wake_after=None, hang_after=None, end_timeout=40):
        # The push stand-in races node's own answer (0: no app tokens on the test extension). When
        # mod_ringq reports it read something else, the attempt proves nothing: try again.
        for attempt in range(3):
            r = self._run_call_once(phone, push_count, wake_after, hang_after, end_timeout)
            end = r[1]
            if push_count is None or not end or end.get("ringq-pushed") == str(push_count).split("/")[0]:
                return r
            log(f"  (push stand-in lost the race with node, attempt {attempt + 1}; retrying)")
            phone.register(0)
            time.sleep(1)
        return r

    def _run_call_once(self, phone, push_count, wake_after, hang_after, end_timeout):
        start = now()
        self.push_override = push_count
        self.on_ring = None
        if wake_after is not None:
            def wake(ev):
                time.sleep(wake_after)
                phone.register(120)
            self.on_ring = wake
        a_uuid = self.call()
        ring = self.wait_event(lambda e: e.get("Event-Subclass") == "ringq::ring", start, 15)
        if hang_after is not None and ring:
            time.sleep(hang_after)
            self.hangup(a_uuid)
        end = self.wait_event(lambda e: e.get("Event-Subclass") == "ringq::ring_end", start, end_timeout)
        if end and end.get("ringq-answered") == "true":
            time.sleep(2)  # hold the call a moment, like a short conversation
        self.hangup(a_uuid)
        time.sleep(1.5)
        self.push_override = None
        self.on_ring = None
        chans = [e.get("Channel-Name", "") for e in self.channels_since(start)]
        invites = [c for c in phone.invites if c["at"] >= start]
        return ring, end, invites, chans, start

    def case_registered(self, phone):
        if phone.register(300) != 200:
            return self.record("registered", False, "test phone could not register")
        ring, end, invites, chans, _start = self.run_call(phone)
        if not ring or not end:
            return self.record("registered", False, f"no ringq::ring / ring_end event (ring={bool(ring)}, end={bool(end)})")
        rid = ring.get("ringq-ring-id")
        problems = []
        if ring.get("ringq-registered") != "true": problems.append("ring did not see the phone as registered")
        if end.get("ringq-answered") != "true": problems.append(f"not answered (cause {end.get('ringq-cause')})")
        if int(end.get("ringq-waited-ms", "0")) != 0: problems.append(f"waited {end.get('ringq-waited-ms')} ms")
        if not invites: problems.append("phone got no INVITE")
        elif invites[0]["call_id"] != rid: problems.append(f"INVITE Call-ID {invites[0]['call_id']} is not the ring id")
        if not chans: problems.append("saw no call legs at all (cannot check for an extra leg)")
        elif any("ringq_ring" in c for c in chans): problems.append("a loopback ringq_ring leg was created")
        self.record("registered", not problems,
                    "; ".join(problems) or f"answered, Call-ID = ring id, no extra leg (legs: {', '.join(chans)})")

    def case_woken(self, phone):
        phone.register(0)  # app closed
        ring, end, invites, chans, _ = self.run_call(phone, push_count="1/0", wake_after=3)
        if not ring or not end:
            return self.record("woken", False, f"no ringq::ring / ring_end event (ring={bool(ring)}, end={bool(end)})")
        if end.get("ringq-pushed") != "1":
            return self.record("woken", None, f"push stand-in did not take (mod_ringq read {end.get('ringq-pushed')})")
        waited = int(end.get("ringq-waited-ms", "0"))
        problems = []
        if ring.get("ringq-registered") != "false": problems.append("phone was already registered at ring time")
        if not (2500 <= waited <= 6000): problems.append(f"waited {waited} ms (expected ~3000)")
        if end.get("ringq-answered") != "true": problems.append(f"not answered (cause {end.get('ringq-cause')})")
        if not invites: problems.append("phone got no INVITE after it registered")
        elif invites[0]["call_id"] != ring.get("ringq-ring-id"): problems.append("INVITE Call-ID is not the ring id")
        self.record("woken", not problems, "; ".join(problems) or f"waited {waited} ms for the app, then it rang and was answered")

    def _ios_case(self, name, phone, other_device):
        """One iPhone pushed (1/1). other_device: a non-app device (web dialer) is registered and
        does not answer -- mod_ringq must still wait for the iOS app itself."""
        ios = Phone(phone.ip, phone.user, phone.password, phone.domain, user_agent=UA_IOS)
        web = None
        try:
            phone.register(0)
            if other_device:
                web = Phone(phone.ip, phone.user, phone.password, phone.domain, user_agent=UA_WEB)
                web.auto_answer = False
                if web.register(300) != 200:
                    return self.record(name, False, "web-dialer stand-in could not register")
            ring, end, invites, chans, start = self.run_call(ios, push_count="1/1", wake_after=3)
            if not ring or not end:
                return self.record(name, False, f"no ringq::ring / ring_end event (ring={bool(ring)}, end={bool(end)})")
            if end.get("ringq-pushed") != "1":
                return self.record(name, None, f"push stand-in did not take (mod_ringq read {end.get('ringq-pushed')})")
            waited = int(end.get("ringq-waited-ms", "0"))
            problems = []
            if other_device and ring.get("ringq-registered") != "true":
                problems.append("the web-dialer stand-in was not seen as registered")
            if not (2500 <= waited <= 6000): problems.append(f"waited {waited} ms for the iOS app (expected ~3000)")
            if end.get("ringq-answered") != "true": problems.append(f"not answered (cause {end.get('ringq-cause')})")
            if not invites: problems.append("the iOS app got no INVITE")
            elif invites[0]["call_id"] != ring.get("ringq-ring-id"): problems.append("INVITE Call-ID is not the ring id")
            detail = f"waited {waited} ms for the iOS app, then it rang and was answered"
            if other_device:
                detail += " (although another device was registered)"
            self.record(name, not problems, "; ".join(problems) or detail)
        finally:
            ios.close()
            if web:
                web.close()

    def case_woken_ios(self, phone):
        self._ios_case("woken-ios", phone, other_device=False)

    def case_ios_waits_for_app(self, phone):
        self._ios_case("ios-waits-for-app", phone, other_device=True)

    def case_never_wakes(self, phone):
        phone.register(0)
        ring, end, invites, chans, start = self.run_call(phone, push_count="1/0", end_timeout=WAKE_WAIT_MS / 1000 + 20)
        if not end:
            return self.record("never-wakes", False, "no ringq::ring_end event")
        if end.get("ringq-pushed") != "1":
            return self.record("never-wakes", None, f"push stand-in did not take (mod_ringq read {end.get('ringq-pushed')})")
        waited = int(end.get("ringq-waited-ms", "0"))
        problems = []
        if not (WAKE_WAIT_MS - 500 <= waited <= WAKE_WAIT_MS + 1500): problems.append(f"waited {waited} ms (expected ~{WAKE_WAIT_MS})")
        if end.get("ringq-answered") != "false": problems.append("reported answered")
        if invites: problems.append("phone got an INVITE although it never registered")
        self.record("never-wakes", not problems,
                    "; ".join(problems) or f"gave up after {waited} ms, cause {end.get('ringq-cause')}")

    def case_not_pushed(self, phone):
        phone.register(0)
        ring, end, invites, chans, start = self.run_call(phone)  # node answers 0: no app tokens
        if not end:
            return self.record("not-pushed", False, "no ringq::ring_end event")
        took = end["_at"] - ring["_at"] if ring else 99
        problems = []
        if end.get("ringq-pushed") != "0": problems.append(f"push count {end.get('ringq-pushed')} (node should answer 0)")
        if int(end.get("ringq-waited-ms", "0")) != 0: problems.append(f"waited {end.get('ringq-waited-ms')} ms")
        if took > 2.5: problems.append(f"took {took:.1f} s")
        if end.get("ringq-answered") != "false": problems.append("reported answered")
        self.record("not-pushed", not problems,
                    "; ".join(problems) or f"no app to wake: failed fast in {took:.1f} s, cause {end.get('ringq-cause')}")

    def case_caller_hangs_up(self, phone):
        phone.register(0)
        ring, end, invites, chans, start = self.run_call(phone, push_count="1/0", hang_after=3)
        if not end:
            return self.record("caller-hangs-up", False, "no ringq::ring_end event")
        if end.get("ringq-pushed") != "1":
            return self.record("caller-hangs-up", None, f"push stand-in did not take (mod_ringq read {end.get('ringq-pushed')})")
        waited = int(end.get("ringq-waited-ms", "0"))
        problems = []
        if waited > 5000: problems.append(f"kept waiting {waited} ms after the caller left")
        if end.get("ringq-answered") != "false": problems.append("reported answered")
        self.record("caller-hangs-up", not problems,
                    "; ".join(problems) or f"stopped waiting {waited} ms in, when the caller hung up (cause {end.get('ringq-cause')})")

    def case_module_unloaded(self, phone):
        if phone.register(300) != 200:
            return self.record("module-unloaded", False, "test phone could not register")
        self.api.api("unload mod_ringq")
        try:
            self.flush()
            ds = self.dial_string()
            start = now()
            a_uuid = self.call()
            ok_invite = None
            end = now() + 15
            while now() < end:
                ok_invite = next((c for c in phone.invites if c["at"] >= start and c["answered_at"]), None)
                if ok_invite:
                    break
                time.sleep(0.2)
            time.sleep(1)
            self.hangup(a_uuid)
            time.sleep(1)
            rang = self.wait_event(lambda e: e.get("Event-Subclass") == "ringq::ring", start, 0.1)
            problems = []
            if "ringq/" in ds: problems.append(f"dial-string still {ds!r} without the module (extension unreachable)")
            if not ok_invite: problems.append("call did not reach the phone")
            if rang: problems.append("ringq::ring fired although the module is unloaded")
            self.record("module-unloaded", not problems,
                        "; ".join(problems) or "wake tenant falls back to the normal dial-string and the call still rings")
        finally:
            self.api.api("load mod_ringq")
            self.flush()

    def journeys(self, since_epoch):
        try:
            rows = self.db.q("select coalesce(answered_by,''), coalesce(outcome,''), legs from q_call_journeys j "
                             "join v_domains d using (domain_uuid) where d.domain_name = :'v1' and start_epoch >= :'v2'::bigint "
                             "order by start_epoch", self.a.domain, str(since_epoch))
        except Exception as e:
            return f"could not read q_call_journeys: {e}"
        return rows


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--domain", required=True)
    ap.add_argument("--ext", required=True, help="test extension: no phone or app should be signed in")
    ap.add_argument("--caller", default="9001", help="caller number the test calls come from")
    ap.add_argument("--cases", default=",".join(CASES))
    ap.add_argument("--cleanup-only", action="store_true", help="restore mode/module/caches and exit")
    a = ap.parse_args()
    a.context = a.domain
    a.dirlua = "/usr/share/freeswitch/scripts/app/xml_handler/resources/scripts/directory/directory.lua"
    if os.geteuid() != 0:
        print("run as root", file=sys.stderr)
        return 2

    run = Run(a)
    rows = run.db.q("select e.number_alias, e.password, coalesce(e.extension_uuid::text,'') from v_extensions e join v_domains d "
                    "using (domain_uuid) where d.domain_name = :'v1' and e.extension = :'v2'", a.domain, a.ext)
    if not rows:
        print(f"extension {a.ext}@{a.domain} not found", file=sys.stderr)
        return 2
    sip_user, sip_pass = rows[0][0] or a.ext, rows[0][1]
    regs = run.api.api(f"sofia_contact */{sip_user}@{a.domain}")
    if regs and "error/" not in regs:
        print(f"{a.ext} already has a registered phone ({regs[:80]}); pick an unused extension", file=sys.stderr)
        return 2
    if run.db.q("select count(*) from q_devices q join v_domains d using (domain_uuid) where d.domain_name = :'v1' "
                "and q.extension = :'v2' and coalesce(q.push_token,'') <> ''", a.domain, a.ext)[0][0] != "0":
        print(f"{a.ext} has app push tokens; pick an extension with no app signed in", file=sys.stderr)
        return 2
    m = re.search(r"SIP-IP\s+(\S+)", run.api.api("sofia status profile internal"))
    sip_ip = m.group(1) if m else "127.0.0.1"

    if a.cleanup_only:
        run.module_was_loaded = True
        run.set_mode("off")
        run.restore()
        log("cleaned up")
        return 0

    events = Esl()
    events.start_events("CHANNEL_CREATE CUSTOM ringq::ring ringq::ring_end", run._handle)  # CUSTOM last: words after it are subclasses
    phone = Phone(sip_ip, sip_user, sip_pass, a.domain)
    log(f"ring-push e2e on {a.ext}@{a.domain} (SIP {sip_ip}:5060, test phone udp {phone.port})")
    epoch0 = int(time.time())
    wanted = [c.strip() for c in a.cases.split(",") if c.strip()]
    try:
        for name in CASES:
            if name not in wanted and name != "preflight":
                continue
            log(f"-- {name}")
            ok = getattr(run, "case_" + name.replace("-", "_"))(phone)
            if name == "preflight" and ok is False:
                log("preflight failed: not running the call cases")
                break
            phone.register(0)
            time.sleep(1)
    except KeyboardInterrupt:
        log("interrupted")
    finally:
        try:
            phone.close()
        finally:
            run.restore()
            log("tenant mode, module and caches restored")

    log("waiting 20 s for the call records watcher ...")
    time.sleep(20)
    j = run.journeys(epoch0)
    log("-- call journeys written during the run (answered_by should be the extension, never ringq_ring):")
    if isinstance(j, str):
        log("   " + j)
    else:
        for r in j:
            log("   " + " | ".join(r))
        if any("ringq_ring" in "|".join(r) for r in j):
            run.record("journeys", False, "a call journey names ringq_ring")

    print()
    width = max(len(r[0]) for r in run.results) if run.results else 10
    for case, status, detail in run.results:
        print(f"{status:4}  {case:{width}}  {detail}")
    fails = sum(1 for r in run.results if r[1] == "FAIL")
    print(f"\n{len(run.results)} checks: {sum(1 for r in run.results if r[1] == 'PASS')} passed, "
          f"{fails} failed, {sum(1 for r in run.results if r[1] == 'SKIP')} skipped")
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
