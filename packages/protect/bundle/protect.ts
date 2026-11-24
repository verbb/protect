// The prebuilt bundle that ships inside the Composer package, so a plugin can protect a Twig form
// without a front-end build. `ProtectionPreparation::renderBrowserInputs()` outputs a marker that
// this mounts on its form, and the global gives custom scripts the same API as the npm package.
import { mountProtection, parseManifest, registerChallengeProvider } from '../src/index.js';
import type { BrowserManifest, ProtectionController } from '../src/index.js';

const ATTRIBUTE = 'data-verbb-protect';

// Longer than the built-in providers' own limits, so it only catches a custom provider that never
// settles, which would otherwise leave the form held for good.
const HOLD_LIMIT = 150000;

type Hold = {
    controller: ProtectionController;
    instanceId: string;
    pending: boolean;
    // Carries a submit button's name and value when the button itself can't be used.
    stand: HTMLInputElement | null;
};

const holds = new WeakMap<HTMLFormElement, Hold>();
const releasing = new WeakSet<HTMLFormElement>();
const markers = new WeakSet<Element>();

// A module script has no `document.currentScript`, so find this bundle's tag by its URL. The
// browser hides a nonce attribute from the DOM, but keeps it on the element.
const nonce = Array.from(document.querySelectorAll<HTMLScriptElement>('script[src]')).find((script) => script.src === import.meta.url)?.nonce || undefined;

function release(form: HTMLFormElement, hold: Hold, submitter: HTMLElement | null, name: string | null, value: string): void {
    const button = submitter as HTMLButtonElement | null;
    const usable = button !== null && button.isConnected && button.form === form && !button.disabled;

    hold.stand?.remove();
    hold.stand = null;

    if (!usable && name) {
        // A disabled or replaced button isn't sent, so send its name and value in its place.
        hold.stand = document.createElement('input');
        hold.stand.type = 'hidden';
        hold.stand.name = name;
        hold.stand.value = value;
        form.append(hold.stand);
    }

    // The released event fires synchronously inside `requestSubmit()`, or not at all when a field
    // is invalid, so this flag can't outlive the call and let a later submission skip protection.
    releasing.add(form);

    try {
        form.requestSubmit(usable ? button : null);
    } catch {
        form.requestSubmit();
    } finally {
        releasing.delete(form);
    }
}

/**
 * Holds a native submission until the evidence is ready, then sends it with the same submit
 * button. If protection can't complete, the form still goes, so the server refuses it through the
 * consumer's own response and the visitor's input comes back to them. One listener per form, which
 * always uses the form's current controller, so mounting again replaces rather than adds.
 */
function holdSubmissions(form: HTMLFormElement): void {
    form.addEventListener('submit', (event) => {
        const hold = holds.get(form);

        // A script that already took over the submission owns it.
        if (!hold || releasing.has(form) || event.defaultPrevented) {
            return;
        }

        // Later listeners see only the released submission, so an AJAX handler sends once.
        event.preventDefault();
        event.stopImmediatePropagation();

        if (hold.pending) {
            return;
        }

        hold.pending = true;
        // Tells assistive technology the form is working, and gives sites something to style.
        form.setAttribute('aria-busy', 'true');

        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        const name = submitter?.getAttribute('name') ?? null;
        const value = (submitter as HTMLButtonElement | null)?.value ?? '';
        let timer: ReturnType<typeof setTimeout> | undefined;
        let settled = false;

        const settle = (complete: boolean) => {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(timer);

            // A new task, because a submission released while the browser is still dispatching
            // the original submit event is silently dropped.
            setTimeout(() => {
                hold.pending = false;
                form.removeAttribute('aria-busy');

                if (!complete) {
                    form.dispatchEvent(new CustomEvent('protect:incomplete', { bubbles: true, detail: { instanceId: hold.instanceId } }));
                }

                release(form, hold, submitter, name, value);
            });
        };

        timer = setTimeout(() => settle(false), HOLD_LIMIT);
        hold.controller.prepare().then(() => settle(true), () => settle(false));
    }, true);
}

function mount(form: HTMLFormElement, input: BrowserManifest, options: { nonce?: string } = {}): ProtectionController {
    const controller = mountProtection(form, input, options);
    const hold = holds.get(form);

    if (hold) {
        hold.controller = controller;
        hold.instanceId = input.instanceId;
    } else {
        holds.set(form, { controller, instanceId: input.instanceId, pending: false, stand: null });
        holdSubmissions(form);
    }

    return controller;
}

function mountElement(element: Element): ProtectionController | null {
    const form = element.closest('form');
    const value = element.getAttribute(ATTRIBUTE);

    if (markers.has(element) || !form || !value) {
        return null;
    }

    markers.add(element);
    let manifest: BrowserManifest;

    try {
        manifest = parseManifest(JSON.parse(value));
    } catch {
        console.warn('Protect couldn\'t read a form\'s protection, so it will post without it and be refused.', element);

        return null;
    }

    return mount(form, manifest, { nonce });
}

/** Mounts every marked form under `root` that isn't mounted yet. */
function mountForms(root: ParentNode = document): ProtectionController[] {
    return Array.from(root.querySelectorAll(`[${ATTRIBUTE}]`)).flatMap((element) => mountElement(element) ?? []);
}

// Only forms that hold a submission through this bundle are tracked, so a site's own controller
// for one of them takes over its submissions too.
const api = {
    mountProtection: (form: HTMLFormElement, input: BrowserManifest, options: { nonce?: string } = {}) => holds.has(form) ? mount(form, input, options) : mountProtection(form, input, options),
    parseManifest,
    registerChallengeProvider,
    mountForms,
};

declare global {
    interface Window { VerbbProtect?: typeof api }
}

// The first copy on a page owns the global, so two plugins loading the bundle share one.
if (!window.VerbbProtect) {
    window.VerbbProtect = api;
    mountForms();

    // Forms added later, such as by Sprig, htmx or a modal, mount when they arrive.
    new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node instanceof Element) {
                    if (node.hasAttribute(ATTRIBUTE)) {
                        mountElement(node);
                    }

                    mountForms(node);
                }
            }
        }
    }).observe(document.documentElement, { childList: true, subtree: true });
}
