import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { mountProtection, parseManifest } from '../dist/index.js';

// One half of the PHP and browser round trip. A real PHP prepare() wrote preparation.json, and this
// mounts it the way a server-rendered form would and writes down what would be submitted. The PHP
// suite then checks that evidence through a real check(), so a renamed key on either side fails.
const fixture = (name) => new URL(`./fixtures/${name}`, import.meta.url);

class Element extends EventTarget {
    children = []; parent = null; name = ''; value = ''; attributes = {}; type = ''; hidden = false;
    constructor(tag = 'div') { super(); this.tagName = tag; }
    append(child) {
        this.children.push(child); child.parent = this;
        if (child.tagName === 'script') queueMicrotask(() => child.dispatchEvent(new Event('load')));
    }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter((v) => v !== this); }
    setAttribute(key, value) { this.attributes[key] = value; }
    removeAttribute(key) { delete this.attributes[key]; }
    querySelectorAll(selector) { return this.children.flatMap((c) => [...(selector === 'input[name]' && c.tagName === 'input' && c.name ? [c] : []), ...c.querySelectorAll(selector)]); }
}

test('the package mounts a real PHP preparation and produces evidence PHP accepts', async () => {
    globalThis.document = { createElement: (tag) => new Element(tag), head: new Element('head') };
    globalThis.CustomEvent ??= class extends Event { constructor(name, options) { super(name, options); this.detail = options.detail; } };
    const widgets = [];
    globalThis.window = { turnstile: {
        render(element, options) { widgets.push(options); return 'widget'; },
        execute() { widgets[0].callback('fixture-token'); },
        reset() {},
        remove() {},
    } };

    const preparation = JSON.parse(readFileSync(fixture('preparation.json'), 'utf8'));
    const manifest = parseManifest(preparation.manifest);
    const form = new Element('form');

    for (const [name, value] of Object.entries(preparation.hiddenInputs)) {
        const input = new Element('input');
        input.name = name;
        input.value = value;
        form.append(input);
    }

    const controller = mountProtection(form, manifest);
    await controller.ready;
    assert.equal(widgets[0].sitekey, 'fixture-site-key');
    assert.equal(widgets[0].action, 'round-trip');
    const evidence = await controller.prepare();
    const fields = Object.fromEntries(form.querySelectorAll('input[name]').map((input) => [input.name, input.value]));
    const actual = { evidence, fields };
    controller.destroy();

    if (process.env.UPDATE_ROUND_TRIP_FIXTURES) {
        writeFileSync(fixture('evidence.json'), JSON.stringify(actual, null, 4) + '\n');
    }

    assert.deepEqual(actual, JSON.parse(readFileSync(fixture('evidence.json'), 'utf8')));
});
