#!/usr/bin/env python3
"""Browser checks for bin/e2e.sh in headless Chrome, driven over the DevTools protocol (standard library only).

  browser-e2e.py setup  <site> <bmlt root url>
      An administrator saves the root server URL on Settings → BMLT Localities, then ticks five service bodies and
      saves again; the selection is shown checked after a reload.
  browser-e2e.py visit  <site> <page url> <label>
      A visitor opens the page with [bmlt_localities]: the rows, the merged state spellings, the links, the escaped
      markup, the stylesheet (bands and dividers), and no console errors.
  browser-e2e.py outage <site>
      With BMLT down and nothing cached, the administrator sees the error and that the selection is kept, and saves.
"""
import base64, json, os, socket, struct, subprocess, sys, tempfile, time, urllib.request
from urllib.parse import urlparse

PORT = int(os.environ.get('CHROME_DEBUG_PORT', '9336'))

class WS:
    def __init__(self, url):
        u = urlparse(url)
        self.s = socket.create_connection((u.hostname, u.port))
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall((f'GET {u.path} HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nUpgrade: websocket\r\n'
                        f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        head = b''
        while b'\r\n\r\n' not in head:
            head += self.s.recv(1)
        assert b' 101 ' in head.split(b'\r\n')[0], head

    def _read(self, n):
        out = b''
        while len(out) < n:
            chunk = self.s.recv(n - len(out))
            if not chunk:
                raise ConnectionError('closed')
            out += chunk
        return out

    def send(self, text):
        data = text.encode()
        mask = os.urandom(4)
        header = bytes([0x81])
        n = len(data)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', n)
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', n)
        self.s.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))

    def recv(self):
        message = b''
        while True:
            b1, b2 = self._read(2)
            n = b2 & 0x7f
            if n == 126:
                n = struct.unpack('>H', self._read(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self._read(8))[0]
            payload = self._read(n)
            if (b1 & 0x0f) == 9:
                continue
            message += payload
            if b1 & 0x80:
                return message.decode()


class Browser:
    """Headless Chrome with a fresh profile; collects console errors, exceptions and every request URL."""

    def __init__(self):
        self.profile = tempfile.mkdtemp(prefix='loc-chrome-')
        self.proc = subprocess.Popen([os.environ.get('CHROME', 'google-chrome'), '--headless=new', f'--remote-debugging-port={PORT}',
                                      f'--user-data-dir={self.profile}', '--no-first-run', '--no-default-browser-check', '--disable-gpu',
                                      '--lang=en-US', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        pages = []
        for i in range(180):
            try:
                pages = [t for t in json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/list', timeout=5)) if t['type'] == 'page']
                if pages:
                    break
                if i >= 8:
                    urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{PORT}/json/new?about:blank', method='PUT'), timeout=5).read()
            except Exception:
                pass
            time.sleep(0.25)
        if not pages:
            self.close()
            sys.exit('Chrome did not start')
        self.ws = WS(pages[0]['webSocketDebuggerUrl'])
        self.n = 0
        self.errors, self.requests = [], []
        for domain in ('Page', 'Runtime', 'Network', 'Log'):
            self.call(domain + '.enable')

    def _event(self, msg):
        m, p = msg.get('method'), msg.get('params', {})
        if m == 'Runtime.exceptionThrown':
            self.errors.append('exception: ' + p['exceptionDetails'].get('text', '') + ' ' + json.dumps(p['exceptionDetails'].get('exception', {}).get('description', ''))[:300])
        elif m == 'Runtime.consoleAPICalled' and p.get('type') in ('error', 'assert'):
            self.errors.append('console: ' + ' '.join(str(a.get('value', a.get('description', ''))) for a in p.get('args', [])))
        elif m == 'Log.entryAdded' and p['entry'].get('level') == 'error' and 'favicon' not in p['entry'].get('url', ''):
            self.errors.append('log: ' + p['entry'].get('text', '') + ' ' + p['entry'].get('url', ''))
        elif m == 'Network.requestWillBeSent':
            self.requests.append(p['request']['url'])

    def call(self, method, **params):
        self.n += 1
        self.ws.send(json.dumps({'id': self.n, 'method': method, 'params': params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get('id') == self.n:
                if 'error' in msg:
                    raise RuntimeError(f'{method}: {msg["error"]}')
                return msg.get('result', {})
            self._event(msg)

    def js(self, expression):
        r = self.call('Runtime.evaluate', expression=expression, returnByValue=True, awaitPromise=True)
        return r.get('result', {}).get('value')

    def wait(self, check, timeout=30):
        end = time.time() + timeout
        while time.time() < end:
            try:
                if self.js(check):
                    return
            except Exception:
                pass
            time.sleep(0.25)
        raise TimeoutError(check + '\n' + (self.js('document.body ? document.body.innerText.slice(0, 1500) : ""') or ''))

    def go(self, url):
        self.call('Page.navigate', url=url)
        time.sleep(0.3)
        self.wait('document.readyState === "complete"')

    def login(self, site, user, password):
        self.call('Network.clearBrowserCookies')
        self.go(site + '/wp-login.php')
        self.wait('!!document.getElementById("wp-submit")')
        self.js(f'document.getElementById("user_login").value={json.dumps(user)};'
                f'document.getElementById("user_pass").value={json.dumps(password)};'
                'document.getElementById("wp-submit").click(); true')
        time.sleep(0.5)
        self.wait('document.readyState === "complete" && !location.href.includes("wp-login.php")')

    def close(self):
        try:
            self.proc.terminate()
            self.proc.wait(10)
        except Exception:
            pass
        subprocess.run(['rm', '-rf', self.profile], stderr=subprocess.DEVNULL)



def ok(text):
    print('  ok   ' + text)


def fail(text):
    print('  FAIL ' + text)
    sys.exit(1)


def critical(b, where):
    text = b.js('document.body ? document.body.innerText : ""') or ''
    for bad in ('There has been a critical error', 'Fatal error', 'Warning:', 'Notice:', 'Deprecated:'):
        if bad in text:
            fail(f'{where}: "{bad}" on the page: ' + text[:300])


SELECTED = ['101', '102', '103', '104', '106']

EXPECTED = [
    ['Hillcrest Area - Call 555-0102 any time', 'Ohio', 'Hillcrest, Ridge'],
    ['Lakeside Area', 'Virginia', 'Laketown'],
    ['Lakeside North - 555-0104', 'Virginia', 'Northshore'],
    ['Markup <script>alert("x")</script> & Co Area - +1 555 010 6"><b>x</b>', 'North Carolina', '<b>Bold</b> Town & Sons'],
    ['Riverside Area - (555) 010-1001', 'Maryland', 'Eastbank'],
    ['Riverside Area - (555) 010-1001', 'Virginia', 'Château Ville, Millbrook, Riverton, Riverton 2, Riverton 10'],
]

EXPECTED_LINKS = [
    ['Hillcrest Area', 'https://hillcrest.example.org/'],
    ['Call 555-0102 any time', 'tel:5550102'],
    ['Lakeside North', 'https://north.example.org/'],
    ['555-0104', 'tel:5550104'],
    ['Markup <script>alert("x")</script> & Co Area', 'https://markup.example.org/?a=1&b=2'],
    ['+1 555 010 6"><b>x</b>', 'tel:+15550106'],
    ['Riverside Area', 'https://riverside.example.org/'],
    ['(555) 010-1001', 'tel:5550101001'],
]

SETTINGS = '/wp-admin/options-general.php?page=bmlt-localities'


def setup(site, bmlt_root):
    b = Browser()
    try:
        b.login(site, 'admin', 'Admin-pass-1')
        b.go(site + SETTINGS)
        critical(b, 'settings page')
        if b.js('document.querySelectorAll(\'input[name="bodies[]"]\').length') != 0:
            fail('service bodies listed before a server was set')
        b.js('const s = document.getElementById("bmlt-server"); s.value = ' + json.dumps(bmlt_root + '/client_interface/json/')
             + '; s.form.querySelector("[type=submit]").click(); true')
        b.wait('document.body.innerText.includes("Root server saved. Select the service bodies below and save again.")')
        critical(b, 'after saving the server')
        server = b.js('document.getElementById("bmlt-server").value')
        if server != bmlt_root:
            fail(f'server saved as {server!r}, expected {bmlt_root!r}')
        count = b.js('document.querySelectorAll(\'input[name="bodies[]"]\').length')
        if count != 10:
            fail(f'{count} service bodies listed, expected 10')
        ok(f'root server saved over HTTPS (…/client_interface/json/ trimmed); its 10 service bodies listed')
        b.js(json.dumps(SELECTED) + '.forEach(v => { document.querySelector(`input[name="bodies[]"][value="${v}"]`).checked = true; });'
             ' document.getElementById("bmlt-bodies").form.querySelector("[type=submit]").click(); true')
        b.wait('document.body.innerText.includes("Settings saved. BMLT data has been refreshed.")')
        b.go(site + SETTINGS)
        checked = b.js('[...document.querySelectorAll(\'input[name="bodies[]"]:checked\')].map(i => i.value).sort()')
        if checked != SELECTED:
            fail(f'checked after reload: {checked}')
        if b.js('[...document.querySelectorAll("#bmlt-bodies label")].some(l => l.innerHTML.includes("<script"))'):
            fail('markup from BMLT rendered on the settings page')
        if b.errors:
            fail('browser errors: ' + '; '.join(b.errors[:5]))
        ok('five service bodies ticked and saved; still ticked after a reload; names with markup shown as text')
    finally:
        b.close()


def visit(site, url, label):
    b = Browser()
    try:
        b.go(url)
        critical(b, label)
        b.wait('!!document.querySelector(".bmlt-localities-table tbody tr")', 20)
        # In block content WordPress's texturize turns the " - " between Area and phone into an en dash.
        rows = b.js('''(() => { let area = ""; return [...document.querySelectorAll(".bmlt-localities-table tbody tr")].map(tr => {
            const th = tr.querySelector("th"); if (th) area = th.innerText.trim().replace(" \u2013 ", " - ");
            const td = tr.querySelectorAll("td"); return [area, td[0].innerText.trim(), td[1].innerText.trim()]; }); })()''')
        if rows != EXPECTED:
            fail(f'{label}: rows\n    got      {json.dumps(rows, ensure_ascii=False)}\n    expected {json.dumps(EXPECTED, ensure_ascii=False)}')
        ok(f'{label}: 6 rows for 5 service bodies; VA/Va/va./Virginia/virginia one state; Riverton spellings once; '
           'unpublished and virtual meetings left out; sub-Area listed on its own')
        links = b.js('[...document.querySelectorAll(".bmlt-localities-table tbody th a")].map(a => [a.innerText.trim(), a.href])')
        if links != EXPECTED_LINKS:
            fail(f'{label}: links {json.dumps(links)}')
        if b.js('[...document.querySelectorAll(".bmlt-localities-table th")].some(th => th.querySelector("script, b"))'):
            fail(f'{label}: markup from BMLT became elements')
        ok(f'{label}: Area websites (hillcrest.example.org → https), tel: links, javascript: website left as text, markup shown as text')
        style = b.js('''(() => { const rows = document.querySelectorAll(".bmlt-localities-table tbody tr");
            const cs = el => getComputedStyle(el); return [cs(rows[0].firstElementChild).backgroundColor, cs(rows[1].firstElementChild).backgroundColor,
            cs(rows[0].firstElementChild).borderTopWidth, cs(rows[5].firstElementChild).borderTopWidth,
            document.querySelectorAll("style#bmlt-localities-inline-css").length,
            rows[4].querySelector("th").getAttribute("rowspan"), cs(rows[0].lastElementChild).wordBreak]; })()''')
        if style != ['rgb(232, 244, 252)', 'rgb(255, 255, 255)', '4px', '0px', 1, '2', 'normal']:
            fail(f'{label}: layout {style}')
        ok(f'{label}: stylesheet printed once; blue/white Area bands, 4px divider before each Area, none between its state rows; whole words wrap')
        if b.errors:
            fail(f'{label}: browser errors: ' + '; '.join(b.errors[:5]))
        if any(':' + os.environ.get('BMLT_PORT', '-') + '/' in r for r in b.requests):
            fail(f'{label}: the browser contacted BMLT')
        ok(f'{label}: no console errors; the page itself never contacts BMLT')
    finally:
        b.close()


def outage(site):
    b = Browser()
    try:
        b.login(site, 'admin', 'Admin-pass-1')
        b.go(site + SETTINGS)
        critical(b, 'settings page during an outage')
        b.wait('document.body.innerText.includes("BMLT returned HTTP 503.")')
        b.wait('document.body.innerText.includes("saving keeps the current selection")')
        if b.js('document.querySelectorAll(\'input[name="bodies[]"]\').length') != 0:
            fail('checkboxes shown without a list')
        b.js('document.getElementById("bmlt-server").form.querySelector("[type=submit]").click(); true')
        b.wait('document.body.innerText.includes("Settings saved.")')
        ok('BMLT down: the settings page shows "BMLT returned HTTP 503." and saving goes through')
    finally:
        b.close()


if __name__ == '__main__':
    mode = sys.argv[1] if len(sys.argv) > 1 else ''
    if mode == 'setup' and len(sys.argv) == 4:
        setup(sys.argv[2], sys.argv[3])
    elif mode == 'visit' and len(sys.argv) == 5:
        visit(sys.argv[2], sys.argv[3], sys.argv[4])
    elif mode == 'outage' and len(sys.argv) == 3:
        outage(sys.argv[2])
    else:
        sys.exit(__doc__)
