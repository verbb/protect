import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createServer } from 'node:http';
import { mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// The Composer package ships a prebuilt bundle, so it must match the source that produced it, and
// it must mount itself on a server-rendered form and hold the native submission correctly.
const root = fileURLToPath(new URL('../../../', import.meta.url));
const committed = join(root, 'src/web/assets/protect/dist');
const providers = fileURLToPath(new URL('./browser/providers.js', import.meta.url));

test('the committed bundle matches its source', () => {
    const fresh = mkdtempSync(join(tmpdir(), 'protect-bundle-'));

    try {
        const build = spawnSync(process.execPath, [join(root, 'scripts/build-bundle.mjs')], { encoding: 'utf8', env: { ...process.env, PROTECT_BUNDLE_OUT_DIR: fresh } });

        assert.equal(build.status, 0, build.stderr || build.stdout);
        assert.deepEqual(readdirSync(fresh).sort(), readdirSync(committed).sort(), 'The committed bundle has different files. Run `npm run build:bundle`.');

        for (const file of readdirSync(fresh)) {
            assert.ok(readFileSync(join(fresh, file)).equals(readFileSync(join(committed, file))), `${file} is stale. Run \`npm run build:bundle\`.`);
        }
    } finally {
        rmSync(fresh, { recursive: true, force: true });
    }
});

const escape = (value) => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

// What `renderBrowserInputs()` outputs: by default for a plan with a honeypot, a one-second
// minimum time, the JavaScript check and Turnstile.
const manifest = ({ id = 'first', challenge = true, wait = 1, expired = false } = {}) => {
    const now = Math.floor(Date.now() / 1000);

    return {
        version: 1, instanceId: id, preparation: `proof-${id}`, issuedAt: now, expiresAt: expired ? now - 1 : now + 300,
        ...(wait ? { notBefore: now + wait } : {}),
        honeypot: 'a1b2', javascript: true,
        challenges: challenge ? [{ provider: 'turnstile', siteKey: 'site-key', action: 'feedback' }] : [],
    };
};

const marker = (value) => `<div hidden data-verbb-protect="${escape(JSON.stringify(value))}"></div>`;

const page = (markers) => `<!doctype html><html lang="en"><meta charset="utf-8"><title>Bundle</title><link rel="icon" href="data:,">
<form method="post" action="/submit">
<input name="message" value="hello" required>
<input type="hidden" name="__protect[version]" value="1"><input type="hidden" name="__protect[preparation]" value="${markers[0].preparation}"><input type="hidden" name="__protect[javascript]" value="">
<div class="protect-honeypot" aria-hidden="true" hidden><input type="text" name="__protect[honeypot][a1b2]" value="" tabindex="-1" autocomplete="off" aria-label="Leave this field blank"></div>
<div id="markers">${markers.map(marker).join('')}</div>
<button name="intent" value="send">Send</button>
</form>
<script type="module" nonce="page-nonce" src="/src/web/assets/protect/dist/protect.js"></script>`;

async function withPage(markers, run) {
    const posts = [];
    const server = createServer((request, response) => {
        const path = new URL(request.url, 'http://localhost').pathname;

        if (request.method === 'POST' && path === '/submit') {
            let body = '';
            request.on('data', (chunk) => { body += chunk; });
            request.on('end', () => {
                posts.push(new URLSearchParams(body));
                response.writeHead(200, { 'Content-Type': 'text/html' });
                response.end('<p id="posted">Posted</p>');
            });
        } else if (path === '/form.html') {
            response.writeHead(200, { 'Content-Type': 'text/html' });
            response.end(page(markers));
        } else if (path === '/src/web/assets/protect/dist/protect.js') {
            response.writeHead(200, { 'Content-Type': 'text/javascript' });
            response.end(readFileSync(join(committed, 'protect.js')));
        } else {
            response.writeHead(404);
            response.end();
        }
    });

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch();

    try {
        const tab = await browser.newPage();
        const errors = [];
        tab.on('pageerror', (error) => errors.push(error.message));
        await tab.route(/^https:\/\//, (route) => route.request().url().startsWith('https://challenges.cloudflare.com/') ? route.fulfill({ path: providers, contentType: 'text/javascript' }) : route.abort());
        await tab.goto(`http://127.0.0.1:${server.address().port}/form.html`);
        await tab.waitForFunction(() => window.VerbbProtect && document.querySelector('input[name="__protect[javascript]"]').value === '1');

        if (markers.some((item) => item.challenges.length)) {
            await tab.waitForFunction(() => window.providerFixture?.widgets.size > 0);
        }

        await run(tab, posts);
        assert.deepEqual(errors, [], 'no page errors');
    } finally {
        await browser.close();
        server.close();
    }
}

// The SDK double records executions; this answers each one the way Turnstile would.
const answerWith = (tab, outcome) => tab.evaluate((outcome) => {
    const api = window.turnstile;
    const execute = api.execute;
    api.execute = (id) => {
        execute(id);
        const { options } = window.providerFixture.widgets.get(id);
        setTimeout(() => (outcome === 'token' ? options.callback(`token-${window.providerFixture.turnstileExecutions.length}`) : options['error-callback']('110200')), 50);
    };
}, outcome);

const executions = (tab) => tab.evaluate(() => window.providerFixture.turnstileExecutions.length);

test('the bundle mounts itself and holds a native submission until the evidence is ready', { timeout: 60000 }, () => withPage([manifest()], async (tab, posts) => {
    await answerWith(tab, 'token');
    assert.equal(await tab.evaluate(() => document.querySelector('script[src*="turnstile"]').nonce), 'page-nonce', 'the bundle passes its own nonce on');
    assert.equal(await executions(tab), 0, 'Turnstile waits for a submission');

    const started = Date.now();
    await tab.click('button');
    assert.equal(await tab.getAttribute('form', 'aria-busy'), 'true', 'the form says it is busy while held');
    await tab.click('button');
    await tab.waitForSelector('#posted');

    assert.ok(Date.now() - started >= 700, 'the minimum time was waited out first');
    assert.equal(posts.length, 1, 'a double click sends once');
    const body = posts[0];
    assert.equal(body.get('intent'), 'send', 'the submit button still names itself');
    assert.equal(body.get('message'), 'hello');
    assert.equal(body.get('__protect[javascript]'), '1');
    assert.equal(body.get('__protect[challenges][turnstile]'), 'token-1');
    assert.equal(body.get('__protect[honeypot][a1b2]'), '');
    assert.equal(body.getAll('__protect[preparation]').length, 1, 'server-rendered inputs are reused, not duplicated');
}));

test('a submission that is ready at once is still sent', { timeout: 60000 }, () => withPage([manifest({ challenge: false, wait: 0 })], async (tab, posts) => {
    await tab.click('button');
    await tab.waitForSelector('#posted');

    assert.equal(posts.length, 1);
    assert.equal(posts[0].get('__protect[javascript]'), '1');
}));

test('an expired form is still sent, so the server can refuse it and offer a fresh one', { timeout: 60000 }, () => withPage([manifest({ challenge: false, wait: 0, expired: true })], async (tab, posts) => {
    let incomplete = 0;
    await tab.exposeFunction('reportIncomplete', () => { incomplete++; });
    await tab.evaluate(() => document.addEventListener('protect:incomplete', (event) => window.reportIncomplete(event.detail.instanceId)));
    await tab.click('button');
    await tab.waitForSelector('#posted');

    assert.equal(incomplete, 1);
    assert.equal(posts.length, 1);
}));

test('a submission still goes when the challenge fails, so the server can refuse it', { timeout: 60000 }, () => withPage([manifest()], async (tab, posts) => {
    await answerWith(tab, 'error');
    let incomplete = 0;
    await tab.exposeFunction('reportIncomplete', () => { incomplete++; });
    await tab.evaluate(() => document.addEventListener('protect:incomplete', () => window.reportIncomplete()));
    await tab.click('button');
    await tab.waitForSelector('#posted');

    assert.equal(incomplete, 1, 'the page heard that protection didn\'t complete');
    assert.equal(posts.length, 1);
    assert.equal(posts[0].get('__protect[challenges][turnstile]'), '');
}));

test('a field that becomes invalid while waiting doesn\'t let the next submission skip protection', { timeout: 60000 }, () => withPage([manifest()], async (tab, posts) => {
    await answerWith(tab, 'token');
    await tab.click('button');
    await tab.fill('input[name="message"]', '');
    await tab.waitForFunction(() => window.providerFixture.turnstileExecutions.length === 1);
    await tab.waitForTimeout(300);
    assert.equal(posts.length, 0, 'the browser held the invalid form back');
    assert.equal(await tab.getAttribute('form', 'aria-busy'), null, 'the form is no longer busy');

    await tab.fill('input[name="message"]', 'hello again');
    await tab.click('button');
    await tab.waitForSelector('#posted');

    assert.equal(posts.length, 1);
    assert.equal(posts[0].get('__protect[challenges][turnstile]'), 'token-2', 'the second submission ran the challenge again');
}));

test('two markers in one form hold one submission with the newest protection', { timeout: 60000 }, () => withPage([manifest({ id: 'first' }), manifest({ id: 'second' })], async (tab, posts) => {
    await answerWith(tab, 'token');
    await tab.click('button');
    await tab.waitForSelector('#posted');

    assert.equal(posts.length, 1);
    assert.equal(posts[0].get('__protect[preparation]'), 'proof-second');
    assert.equal(posts[0].get('__protect[challenges][turnstile]'), 'token-1');
}));

test('a marker swapped in later mounts by itself and replaces the old protection', { timeout: 60000 }, () => withPage([manifest({ id: 'first' })], async (tab, posts) => {
    await answerWith(tab, 'token');
    const replacement = marker(manifest({ id: 'swapped', wait: 0 }));
    await tab.evaluate((html) => { document.querySelector('#markers').innerHTML = html; }, replacement);
    await tab.waitForFunction(() => document.querySelector('input[name="__protect[preparation]"]').value === 'proof-swapped');
    await tab.click('button');
    await tab.waitForSelector('#posted');

    assert.equal(posts.length, 1);
    assert.equal(posts[0].get('__protect[preparation]'), 'proof-swapped');
    assert.equal(posts[0].get('__protect[challenges][turnstile]'), 'token-1');
}));

for (const [label, change] of [['disabled', 'disable'], ['replaced', 'replace']]) {
    test(`a submit button ${label} while held still sends its name and value`, { timeout: 60000 }, () => withPage([manifest()], async (tab, posts) => {
        await answerWith(tab, 'token');
        await tab.click('button');
        await tab.evaluate((change) => {
            const button = document.querySelector('button');
            if (change === 'disable') button.disabled = true;
            else button.replaceWith(Object.assign(document.createElement('span'), { textContent: 'Sending…' }));
        }, change);
        await tab.waitForSelector('#posted');

        assert.equal(posts.length, 1);
        assert.equal(posts[0].get('intent'), 'send');
        assert.equal(posts[0].get('__protect[challenges][turnstile]'), 'token-1');
    }));
}

test('a submit listener added after the bundle sees only the released submission', { timeout: 60000 }, () => withPage([manifest()], async (tab, posts) => {
    await answerWith(tab, 'token');
    const seen = [];
    await tab.exposeFunction('reportSubmit', (token) => { seen.push(token); });
    await tab.evaluate(() => document.querySelector('form').addEventListener('submit', (event) => window.reportSubmit(new FormData(event.target).get('__protect[challenges][turnstile]'))));
    await tab.click('button');
    await tab.waitForSelector('#posted');

    assert.deepEqual(seen, ['token-1']);
    assert.equal(posts.length, 1);
}));
