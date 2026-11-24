import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Runs the built-in drivers in real Chromium against deterministic SDK doubles.
const root = fileURLToPath(new URL('..', import.meta.url));
const providers = fileURLToPath(new URL('./browser/providers.js', import.meta.url));
const types = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript' };

// Sites that enforce Trusted Types list Protect's policy by name; nothing else may set a script URL.
const trustedTypes = "require-trusted-types-for 'script'; trusted-types verbb-protect";

const drivers = (policy) => async () => {
    const server = createServer(async (request, response) => {
        const path = normalize(new URL(request.url, 'http://localhost').pathname);

        try {
            const body = await readFile(join(root, path));
            response.writeHead(200, { 'Content-Type': types[extname(path)] ?? 'text/plain', ...(policy && extname(path) === '.html' ? { 'Content-Security-Policy': policy } : {}) });
            response.end(body);
        } catch {
            response.writeHead(404);
            response.end();
        }
    });

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    let browser;

    try {
        browser = await chromium.launch();
        const page = await browser.newPage();
        const violations = [];
        page.on('console', (message) => { if (/trusted ?type/i.test(message.text())) violations.push(message.text()); });
        const external = [];
        await page.route(/^https:\/\//, async (route) => {
            const url = route.request().url();
            external.push(url);

            if (['https://challenges.cloudflare.com/', 'https://www.google.com/recaptcha/', 'https://js.hcaptcha.com/', 'https://cdn.jsdelivr.net/npm/@friendlycaptcha/'].some((origin) => url.startsWith(origin))) {
                await route.fulfill({ path: providers, contentType: 'text/javascript' });
            } else {
                await route.abort();
            }
        });
        await page.goto(`http://127.0.0.1:${server.address().port}/tests/browser/index.html`);
        await page.click('#run');
        await page.waitForFunction(() => window.testResult !== undefined, null, { timeout: 45000 });
        const result = await page.evaluate(() => window.testResult);
        assert.equal(result.passed, true, result.error);
        assert.ok(result.checks > 50, `ran ${result.checks} browser assertions`);
        assert.ok(external.length > 0, 'provider scripts were intercepted');
        assert.deepEqual(violations, []);
    } finally {
        await browser?.close();
        server.close();
    }
};

test('the built-in challenge drivers pass in a headless browser', { timeout: 60000 }, drivers(null));

test('the drivers load their scripts under an enforced Trusted Types policy', { timeout: 60000 }, drivers(trustedTypes));
