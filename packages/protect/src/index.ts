import type { BrowserManifest, ChallengeDriver, ChallengeDriverFactory, ChallengeManifest, Evidence, ProtectionController } from './types.js';
import { bounded } from './scripts.js';
import { friendlyCaptcha, hcaptcha, recaptchaV3, turnstile } from './providers.js';
export type * from './types.js';

const factories = new Map<string, ChallengeDriverFactory>([['turnstile', turnstile], ['recaptcha-v3', recaptchaV3], ['hcaptcha', hcaptcha], ['friendly-captcha', friendlyCaptcha]]);
const instances = new WeakMap<HTMLFormElement, ProtectionController>();
// How far the visitor's clock is from the server's, taken when a manifest is first parsed.
// Manifests are never cached, so `issuedAt` is close enough to "now" on the server.
const clockOffsets = new WeakMap<object, number>();

export function registerChallengeProvider(handle: string, factory: ChallengeDriverFactory): void {
    if (!/^[a-z][a-z0-9-]*$/.test(handle)) throw new Error('Invalid provider handle.');
    if (factories.has(handle) && factories.get(handle) !== factory) throw new Error('Provider already registered.');
    factories.set(handle, factory);
}

export function parseManifest(value: unknown): BrowserManifest {
    if (!value || typeof value !== 'object') throw new Error('Invalid protection manifest.');
    const m = value as Record<string, unknown>;
    if (m.version !== 1 || typeof m.instanceId !== 'string' || typeof m.preparation !== 'string' || typeof m.expiresAt !== 'number' || typeof m.javascript !== 'boolean' || (m.honeypot !== null && (typeof m.honeypot !== 'string' || !/^[a-f0-9]+$/.test(m.honeypot))) || !Array.isArray(m.challenges)) throw new Error('Invalid protection manifest.');
    if (typeof m.issuedAt !== 'number' || !Number.isFinite(m.issuedAt) || (m.notBefore !== undefined && (typeof m.notBefore !== 'number' || !Number.isFinite(m.notBefore)))) throw new Error('Invalid protection timing.');
    const handles = new Set<string>();
    for (const c of m.challenges) {
        if (!c || typeof c.provider !== 'string' || typeof c.siteKey !== 'string' || !c.siteKey || typeof c.action !== 'string' || handles.has(c.provider)) throw new Error('Invalid challenge manifest.');
        handles.add(c.provider);
    }
    if (!clockOffsets.has(m)) clockOffsets.set(m, m.issuedAt * 1000 - Date.now());
    return m as BrowserManifest;
}

type Slot = { challenge: ChallengeManifest; element: HTMLElement | null; driver: ChallengeDriver | null; starting: Promise<void> | null };

/** Owns evidence only. The host awaits prepare() immediately before its own submission. */
export function mountProtection(form: HTMLFormElement, input: BrowserManifest, options: { nonce?: string } = {}): ProtectionController {
    instances.get(form)?.destroy();
    const manifest = parseManifest(input);
    const offset = clockOffsets.get(manifest) ?? 0;
    const now = () => Date.now() + offset;
    const abort = new AbortController();
    const owned: HTMLElement[] = [];
    let destroyed = false;
    let executing: Promise<Evidence> | null = null;
    const emit = (name: string, detail: Record<string, unknown> = {}) => {
        if (!destroyed) form.dispatchEvent(new CustomEvent(`protect:${name}`, { bubbles: true, detail: { instanceId: manifest.instanceId, ...detail } }));
    };
    const field = (name: string, value: string, text = false): HTMLInputElement => {
        let el = Array.from(form.querySelectorAll<HTMLInputElement>('input[name]')).find((item) => item.name === name);
        const created = !el;
        if (!el) {
            el = document.createElement('input');
            el.type = text ? 'text' : 'hidden';
            el.name = name;
            if (text) {
                const wrapper = document.createElement('div');
                wrapper.className = 'protect-honeypot';
                wrapper.hidden = true;
                wrapper.setAttribute('aria-hidden', 'true');
                el.tabIndex = -1;
                el.autocomplete = 'off';
                el.setAttribute('aria-label', 'Leave this field blank');
                wrapper.append(el);
                form.append(wrapper);
                owned.push(wrapper);
            } else { form.append(el); owned.push(el); }
        }
        if (!text || created) el.value = value;
        return el;
    };
    field('__protect[version]', '1');
    field('__protect[preparation]', manifest.preparation);
    if (manifest.javascript) field('__protect[javascript]', '1');
    const honeypot = manifest.honeypot ? field(`__protect[honeypot][${manifest.honeypot}]`, '', true) : null;
    const tokenFields = new Map(manifest.challenges.map((c) => [c.provider, field(`__protect[challenges][${c.provider}]`, '')]));
    const slots: Slot[] = manifest.challenges.map((challenge) => ({ challenge, element: null, driver: null, starting: null }));
    const drivers = () => slots.flatMap((slot) => slot.driver ?? []);
    const destroyDriver = (driver: ChallengeDriver) => {
        // A throwing SDK must not stop field removal, or the next mount finds stale evidence.
        try { driver.destroy(); } catch { /* Nothing further can be done for this widget. */ }
    };
    const startSlot = (slot: Slot): Promise<void> => {
        if (slot.driver) return Promise.resolve();
        slot.starting ??= (async () => {
            const provider = slot.challenge.provider;
            const factory = factories.get(provider);
            if (!factory) throw new Error('Unsupported challenge provider.');
            // A failed attempt may have left a partial widget behind.
            slot.element?.remove();
            slot.element = document.createElement('div');
            form.append(slot.element);
            owned.push(slot.element);
            const driver = await factory({ element: slot.element, manifest: slot.challenge, signal: abort.signal, nonce: options.nonce,
                onToken(token) { if (!destroyed) { tokenFields.get(provider)!.value = token; emit('token', { provider }); } },
                onError(code) { emit('error', { provider, code }); },
            });
            if (destroyed) { destroyDriver(driver); return; }
            slot.driver = driver;
        })().finally(() => { slot.starting = null; });
        return slot.starting;
    };
    let started = false;
    const start = (): Promise<void> => Promise.all(slots.map(startSlot)).then(() => {
        if (!started && !destroyed) { started = true; emit('ready'); }
    }, () => { emit('error'); throw new Error('Protection could not become ready.'); });
    const ready = start();
    // Consumers may await later; avoid an unhandled rejection during initial mounting.
    void ready.catch(() => undefined);
    const controller: ProtectionController = {
        ready,
        prepare() {
            // A destroyed controller has nothing to prepare, and starting it again would add widgets.
            if (destroyed) return Promise.reject(new Error('Protection was removed.'));
            if (executing) return executing;
            executing = (async () => {
                // One dropped connection at mount shouldn't disable the form until a reload, so
                // anything that failed to start gets another attempt here.
                await start();
                if (destroyed || controller.expiresIn() === 0) throw new Error('Refresh protection before submitting.');
                let wait = controller.remainingWait();
                if (wait) emit('waiting', { wait });
                // A timer can fire a millisecond before the wall clock agrees, so wait until it does.
                while (wait > 0) {
                    const delay = wait;
                    await bounded(new Promise<void>((resolve) => {
                        const timer = setTimeout(resolve, delay);
                        abort.signal.addEventListener('abort', () => clearTimeout(timer), { once: true });
                    }), abort.signal, delay + 1000);
                    wait = controller.remainingWait();
                }
                await Promise.all(drivers().map((driver) => driver.execute()));
                if (destroyed) throw new Error('Protection was removed.');
                return { version: 1 as const, preparation: manifest.preparation, honeypot: honeypot && manifest.honeypot ? { [manifest.honeypot]: honeypot.value } : {}, javascript: manifest.javascript ? '1' : '', challenges: Object.fromEntries(Array.from(tokenFields, ([handle, el]) => [handle, el.value])) };
            })().finally(() => { executing = null; });
            return executing;
        },
        reset() {
            if (destroyed) return;
            drivers().forEach((driver) => driver.reset());
            tokenFields.forEach((el) => { el.value = ''; });
            if (manifest.javascript) field('__protect[javascript]', '1');
            emit('reset');
        },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            abort.abort();
            drivers().forEach(destroyDriver);
            tokenFields.forEach((el) => { el.value = ''; });
            owned.forEach((el) => el.remove());
            form.removeEventListener('reset', onReset);
            instances.delete(form);
        },
        expiresIn() { return Math.max(0, manifest.expiresAt * 1000 - now()); },
        remainingWait() { return Math.max(0, (manifest.notBefore ?? 0) * 1000 - now()); },
    };
    const onReset = () => queueMicrotask(() => controller.reset());
    form.addEventListener('reset', onReset);
    instances.set(form, controller);
    return controller;
}
