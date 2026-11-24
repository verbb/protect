import test from 'node:test';
import assert from 'node:assert/strict';
import { mountProtection, registerChallengeProvider, parseManifest } from '../dist/index.js';
import { loadScript } from '../dist/scripts.js';

// A deliberately small DOM harness; the headless browser test and real Craft acceptance complement this lifecycle suite.
class Element extends EventTarget {
    children = []; parent = null; name = ''; value = ''; attributes = {}; type = ''; isConnected = true; hidden = false;
    constructor(tag = 'div') { super(); this.tagName = tag; }
    append(child) { this.children.push(child); child.parent = this; }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter((v) => v !== this); this.isConnected = false; }
    setAttribute(key, value) { this.attributes[key] = value; }
    removeAttribute(key) { delete this.attributes[key]; }
    querySelectorAll(selector) { return this.children.flatMap((c) => [...(selector === 'input[name]' && c.tagName === 'input' && c.name ? [c] : []), ...c.querySelectorAll(selector)]); }
}
globalThis.document = { createElement: (tag) => new Element(tag), head: new Element('head') };
globalThis.CustomEvent ??= class extends Event { constructor(name, options) { super(name, options); this.detail = options.detail; } };
const seconds = () => Math.floor(Date.now() / 1000);
const manifest = (id = 'one', challenges = [], extra = {}) => ({ version: 1, instanceId: id, preparation: `signed-${id}`, issuedAt: seconds(), expiresAt: seconds() + 300, honeypot: 'abc123', javascript: true, challenges, ...extra });

test('independent forms carry namespaced evidence and preserve populated honeypots', async () => {
    const a = new Element('form'), b = new Element('form');
    const ca = mountProtection(a, manifest('a')), cb = mountProtection(b, manifest('b'));
    await Promise.all([ca.ready, cb.ready]);
    a.querySelectorAll('input[name]').find((e) => e.name.includes('honeypot')).value = '0';
    assert.equal((await ca.prepare()).honeypot.abc123, '0');
    assert.equal((await cb.prepare()).preparation, 'signed-b');
    assert.ok(a.querySelectorAll('input[name]').every((e) => e.name.startsWith('__protect[')));
    ca.destroy(); assert.equal(a.children.length, 0); assert.ok(b.children.length > 0); cb.destroy();
});

test('a generated honeypot wrapper is hidden without any stylesheet', async () => {
    const form = new Element('form');
    const c = mountProtection(form, manifest('hidden'));
    const wrapper = form.children.find((child) => child.className === 'protect-honeypot');
    assert.equal(wrapper.hidden, true);
    c.destroy();
});

test('provider execution occurs at submission and reset/destroy are isolated', async () => {
    let executions = 0, resets = 0, destroys = 0;
    registerChallengeProvider('test-provider', async (context) => ({
        async execute() { executions++; const token = `token-${executions}`; context.onToken(token); return token; },
        reset() { resets++; context.onToken(''); }, destroy() { destroys++; },
    }));
    const form = new Element('form');
    const c = mountProtection(form, manifest('test', [{ provider: 'test-provider', siteKey: 'public', action: 'post' }]));
    await c.ready; assert.equal(executions, 0);
    assert.equal((await c.prepare()).challenges['test-provider'], 'token-1');
    c.reset(); assert.equal(resets, 1);
    assert.equal((await c.prepare()).challenges['test-provider'], 'token-2');
    c.destroy(); c.destroy(); assert.equal(destroys, 1);
    await assert.rejects(c.prepare());
});

test('remount destroys previous providers and emits no tokens after removal', async () => {
    let lateToken;
    registerChallengeProvider('late-provider', async (context) => { lateToken = context.onToken; return { async execute() { return ''; }, reset() {}, destroy() {} }; });
    const form = new Element('form');
    const c = mountProtection(form, manifest('old', [{ provider: 'late-provider', siteKey: 'public', action: 'post' }]));
    let tokens = 0; form.addEventListener('protect:token', () => tokens++);
    await c.ready;
    const next = mountProtection(form, manifest('new'));
    await next.ready; lateToken('stale'); assert.equal(tokens, 0);
    assert.equal((await next.prepare()).preparation, 'signed-new'); next.destroy();
});

test('script loading is deduplicated and accepts a CSP nonce', async () => {
    const first = loadScript('https://provider.test/script.js', 'nonce');
    const second = loadScript('https://provider.test/script.js', 'nonce');
    assert.equal(first, second);
    const script = document.head.children.at(-1); assert.equal(script.nonce, 'nonce');
    script.dispatchEvent(new Event('load'));
    await Promise.all([first, second]);
});

test('the built-in provider handles are reserved', () => {
    for (const handle of ['turnstile', 'recaptcha-v3', 'hcaptcha', 'friendly-captcha']) {
        assert.throws(() => registerChallengeProvider(handle, async () => ({})), /already registered/, handle);
    }
});

test('unsupported manifests and missing providers fail visibly', async () => {
    assert.throws(() => parseManifest({ version: 2 }));
    assert.throws(() => registerChallengeProvider('test-provider', async () => ({})));
    const form = new Element('form');
    const c = mountProtection(form, manifest('invalid', [{ provider: 'unknown', siteKey: 'public', action: 'post' }]));
    await assert.rejects(c.ready); c.destroy();
});

test('expired preparation cannot be submitted', async () => {
    const form = new Element('form');
    const c = mountProtection(form, { ...manifest(), expiresAt: seconds() - 1 });
    await assert.rejects(c.prepare(), /Refresh/); c.destroy();
});

test('a manifest with an empty site key or no issuedAt is rejected', () => {
    assert.throws(() => parseManifest(manifest('empty', [{ provider: 'turnstile', siteKey: '', action: 'post' }])), /challenge manifest/);
    const { issuedAt, ...untimed } = manifest();
    assert.throws(() => parseManifest(untimed), /timing/);
});

// The visitor's clock is simulated by moving the server's timestamps the other way.
for (const [label, skew] of [['ten minutes behind', 600], ['two hours ahead', -7200], ['a day ahead', -86400], ['a year behind', 31536000]]) {
    test(`a visitor clock ${label} can still submit`, async () => {
        const issuedAt = seconds() + skew;
        const form = new Element('form');
        const c = mountProtection(form, manifest('skew', [], { issuedAt, expiresAt: issuedAt + 3600 }));
        assert.ok(Math.abs(c.expiresIn() - 3600000) < 2000, 'expiry follows the server clock');
        assert.equal(c.remainingWait(), 0);
        assert.equal((await c.prepare()).preparation, 'signed-skew');
        c.destroy();
    });

    test(`a visitor clock ${label} still waits the real minimum time`, async () => {
        const issuedAt = seconds() + skew;
        const form = new Element('form');
        const c = mountProtection(form, manifest('wait', [], { issuedAt, expiresAt: issuedAt + 3600, notBefore: issuedAt + 1 }));
        const events = [];
        form.addEventListener('protect:waiting', (event) => events.push(event.detail.wait));
        assert.ok(c.remainingWait() > 0 && c.remainingWait() <= 1000);
        const started = Date.now();
        await c.prepare();
        assert.ok(Date.now() - started >= 800, 'waited for the minimum time');
        assert.equal(events.length, 1);
        assert.equal(c.remainingWait(), 0);
        c.destroy();
    });
}

test('a manifest with no minimum time never waits', async () => {
    const form = new Element('form');
    const c = mountProtection(form, manifest('no-wait'));
    let waited = false;
    form.addEventListener('protect:waiting', () => { waited = true; });
    await c.prepare();
    assert.equal(waited, false);
    c.destroy();
});

test('server-rendered fields are adopted rather than duplicated', async () => {
    const form = new Element('form');
    const rendered = ['__protect[version]', '__protect[preparation]', '__protect[javascript]', '__protect[honeypot][abc123]'].map((name) => {
        const input = new Element('input'); input.name = name; form.append(input); return input;
    });
    rendered[3].value = 'typed-by-a-bot';
    const c = mountProtection(form, manifest('adopted'));
    assert.equal(form.children.length, rendered.length);
    assert.equal(rendered[1].value, 'signed-adopted');
    assert.equal(rendered[2].value, '1');
    assert.equal((await c.prepare()).honeypot.abc123, 'typed-by-a-bot');
    c.destroy();
    assert.equal(form.children.length, rendered.length, 'fields Protect did not create stay in place');
});

test('a failed provider start is retried by the next prepare', async () => {
    let attempts = 0;
    registerChallengeProvider('flaky-start', async (context) => {
        attempts++;
        if (attempts === 1) throw new Error('Script failed.');
        return { async execute() { context.onToken('recovered'); return 'recovered'; }, reset() {}, destroy() {} };
    });
    const form = new Element('form');
    const events = [];
    for (const name of ['error', 'ready']) form.addEventListener(`protect:${name}`, () => events.push(name));
    const c = mountProtection(form, manifest('flaky', [{ provider: 'flaky-start', siteKey: 'public', action: 'post' }]));
    await assert.rejects(c.ready);
    assert.deepEqual(events, ['error']);
    assert.equal((await c.prepare()).challenges['flaky-start'], 'recovered');
    assert.equal(attempts, 2);
    assert.deepEqual(events, ['error', 'ready']);
    await c.prepare();
    assert.equal(attempts, 2, 'a started provider is not started again');
    c.destroy();
});

test('a provider that keeps failing rejects each attempt and destroy stays clean', async () => {
    registerChallengeProvider('always-failing', async () => { throw new Error('Script failed.'); });
    const form = new Element('form');
    const c = mountProtection(form, manifest('down', [{ provider: 'always-failing', siteKey: 'public', action: 'post' }]));
    await assert.rejects(c.prepare(), /could not become ready/);
    await assert.rejects(c.prepare(), /could not become ready/);
    c.destroy();
    assert.equal(form.children.length, 0);
});

test('a throwing driver destroy still removes fields and allows a remount', async () => {
    registerChallengeProvider('bad-destroy', async () => ({ async execute() { return 'x'; }, reset() {}, destroy() { throw new Error('SDK failure.'); } }));
    const form = new Element('form');
    const c = mountProtection(form, manifest('bad', [{ provider: 'bad-destroy', siteKey: 'public', action: 'post' }]));
    await c.ready;
    c.destroy();
    assert.equal(form.children.length, 0);
    const next = mountProtection(form, manifest('next'));
    assert.equal((await next.prepare()).preparation, 'signed-next');
    next.destroy();
});

test('driver error codes reach protect:error', async () => {
    let raise;
    registerChallengeProvider('coded-error', async (context) => { raise = context.onError; return { async execute() { return 'x'; }, reset() {}, destroy() {} }; });
    const form = new Element('form');
    const c = mountProtection(form, manifest('coded', [{ provider: 'coded-error', siteKey: 'public', action: 'post' }]));
    await c.ready;
    const details = [];
    form.addEventListener('protect:error', (event) => details.push(event.detail));
    raise('110100');
    assert.equal(details[0].code, '110100');
    assert.equal(details[0].provider, 'coded-error');
    c.destroy();
});
