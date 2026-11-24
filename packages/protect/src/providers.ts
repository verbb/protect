import type { ChallengeDriverFactory } from './types.js';
import { bounded, FRIENDLY_CAPTCHA_SCRIPT, HCAPTCHA_SCRIPT, loadScript, RECAPTCHA_SCRIPT, TURNSTILE_SCRIPT } from './scripts.js';

type TurnstileApi = {
    render(element: HTMLElement, options: Record<string, unknown>): string;
    execute(id: string): void;
    reset(id: string): void;
    remove(id: string): void;
};
type RecaptchaApi = {
    ready(callback: () => void): void;
    render(element: HTMLElement, options: Record<string, unknown>): number;
    execute(id: number, options: { action: string }): Promise<string>;
    reset(id: number): void;
};
type HcaptchaApi = {
    render(element: HTMLElement, options: Record<string, unknown>): string;
    execute(id: string, options: { async: true }): Promise<{ response: string }>;
    reset(id: string): void;
    remove(id: string): void;
};
type FriendlyCaptchaWidget = {
    start(): void;
    reset(): void;
    destroy(): void;
    getState(): string;
    addEventListener(type: string, listener: (event: Event) => void): void;
};
type FriendlyCaptchaApi = { createWidget(options: Record<string, unknown>): FriendlyCaptchaWidget };
declare global {
    interface Window { turnstile?: TurnstileApi; grecaptcha?: RecaptchaApi; hcaptcha?: HcaptchaApi; frcaptcha?: FriendlyCaptchaApi; }
}

// A provider script can define its global a moment after its load event, so look for it briefly.
function whenDefined<T>(read: () => T | undefined, signal: AbortSignal, ms = 5000): Promise<T> {
    const deadline = Date.now() + ms;
    return bounded(new Promise<T>((resolve, reject) => {
        const poll = () => {
            const value = read();
            if (value) resolve(value);
            else if (signal.aborted || Date.now() > deadline) reject(new Error('Challenge unavailable.'));
            else setTimeout(poll, 25);
        };
        poll();
    }), signal, ms + 1000);
}

// Cloudflare marks these families as not worth retrying: a bad site key, domain, action or browser.
const isTerminalTurnstileError = (code: unknown): boolean => /^(100|110[1-5]|200|400)/.test(String(code ?? ''));

export const turnstile: ChallengeDriverFactory = async (context) => {
    await bounded(loadScript(TURNSTILE_SCRIPT, context.nonce), context.signal);
    const api = window.turnstile;
    if (!api || context.signal.aborted) throw new Error('Challenge unavailable.');
    let pending: { resolve: (value: string) => void; reject: () => void } | null = null;
    let executed = false;
    let terminal = false;
    // Render without running: a challenge starts at submit, so forms nobody posts cost nothing
    // and the token can't age out on the page. Each attempt then asks for its own token.
    const id = api.render(context.element, {
        sitekey: context.manifest.siteKey,
        action: context.manifest.action,
        execution: 'execute',
        appearance: 'interaction-only',
        'refresh-expired': 'never',
        retry: 'never',
        'response-field': false,
        callback: (value: string) => {
            context.onToken(value);
            pending?.resolve(value);
            pending = null;
        },
        'expired-callback': () => { context.onToken(''); },
        'error-callback': (code?: unknown) => {
            terminal = isTerminalTurnstileError(code);
            context.onToken('');
            context.onError(code === undefined ? undefined : String(code));
            pending?.reject();
            pending = null;
            return true;
        },
    });
    return {
        execute() {
            if (terminal) return Promise.reject(new Error('Challenge unavailable.'));
            return bounded(new Promise<string>((resolve, reject) => {
                pending = { resolve, reject: () => reject(new Error('Challenge unavailable.')) };
                if (executed) api.reset(id);
                executed = true;
                api.execute(id);
            }), context.signal, 120000);
        },
        reset() {
            terminal = false;
            context.onToken('');
            if (executed) api.reset(id);
            executed = false;
        },
        destroy() { pending?.reject(); pending = null; api.remove(id); },
    };
};

export const recaptchaV3: ChallengeDriverFactory = async (context) => {
    await bounded(loadScript(RECAPTCHA_SCRIPT, context.nonce), context.signal);
    const api = window.grecaptcha;
    if (!api || context.signal.aborted) throw new Error('Challenge unavailable.');
    await bounded(new Promise<void>((resolve) => api.ready(resolve)), context.signal);
    const id = api.render(context.element, { sitekey: context.manifest.siteKey, size: 'invisible', badge: 'inline' });
    // Google injects its own textarea; only the reserved Protect evidence is submitted.
    context.element.querySelectorAll('[name="g-recaptcha-response"]').forEach((el) => el.removeAttribute('name'));
    return {
        async execute() {
            try {
                const token = await bounded(api.execute(id, { action: context.manifest.action }), context.signal);
                context.onToken(token);
                return token;
            } catch (error) {
                context.onError();
                throw error;
            }
        },
        reset() { context.onToken(''); api.reset(id); },
        destroy() { context.onToken(''); api.reset(id); context.element.replaceChildren(); },
    };
};

// hCaptcha adds its own response field. Only the reserved Protect evidence is submitted.
const stripHcaptchaFields = (element: HTMLElement) => {
    element.querySelectorAll('[name="h-captcha-response"], [name="g-recaptcha-response"]').forEach((el) => el.removeAttribute('name'));
};

export const hcaptcha: ChallengeDriverFactory = async (context) => {
    await bounded(loadScript(HCAPTCHA_SCRIPT, context.nonce), context.signal);
    const api = await whenDefined(() => window.hcaptcha, context.signal);
    if (context.signal.aborted) throw new Error('Challenge unavailable.');
    let executed = false;
    // Invisible, so nothing shows until a visitor submits and hCaptcha decides it needs to ask them.
    const id = api.render(context.element, {
        sitekey: context.manifest.siteKey,
        size: 'invisible',
        'expired-callback': () => { context.onToken(''); },
        'chalexpired-callback': () => { context.onToken(''); },
    });
    stripHcaptchaFields(context.element);
    return {
        async execute() {
            // Each token is single use, so a widget that already produced one is reset first.
            if (executed) api.reset(id);
            executed = true;
            let code: string | undefined;
            try {
                const result = api.execute(id, { async: true }).catch((error: unknown) => {
                    code = typeof error === 'string' ? error : undefined;
                    throw error;
                });
                const { response } = await bounded(result, context.signal, 120000);
                if (typeof response !== 'string' || response === '') throw new Error('Challenge unavailable.');
                stripHcaptchaFields(context.element);
                context.onToken(response);
                return response;
            } catch {
                context.onToken('');
                context.onError(code);
                throw new Error('Challenge unavailable.');
            }
        },
        reset() {
            context.onToken('');
            if (executed) api.reset(id);
            executed = false;
        },
        destroy() { context.onToken(''); api.remove(id); },
    };
};

// Widget states in which Friendly Captcha is already solving a puzzle that will complete by itself.
const FRIENDLY_CAPTCHA_SOLVING = ['activating', 'activated', 'requesting', 'solving', 'verifying'];
const FRIENDLY_CAPTCHA_IDLE = ['init', 'reset', 'unactivated'];

export const friendlyCaptcha: ChallengeDriverFactory = async (context) => {
    await bounded(loadScript(FRIENDLY_CAPTCHA_SCRIPT, context.nonce), context.signal);
    const sdk = await whenDefined(() => window.frcaptcha, context.signal);
    if (context.signal.aborted) throw new Error('Challenge unavailable.');
    let pending: { resolve: () => void; reject: () => void } | null = null;
    // A solved response no submission has used yet. Each one verifies only once.
    let fresh = '';
    let terminal = false;
    const widget = sdk.createWidget({
        element: context.element,
        sitekey: context.manifest.siteKey,
        // Protect submits the response as its own evidence, so the SDK adds no field.
        formFieldName: null,
        // The proof of work starts when the visitor first focuses the form, so it's usually done
        // by the time they submit, and a form nobody touches costs nothing.
        startMode: 'focus',
        apiEndpoint: context.manifest.endpoint === 'eu' ? 'eu' : 'global',
    });
    widget.addEventListener('frc:widget.complete', (event) => {
        const response = (event as CustomEvent<{ response?: unknown }>).detail?.response;
        if (typeof response !== 'string' || response === '') return;
        fresh = response;
        context.onToken(response);
        pending?.resolve();
        pending = null;
    });
    widget.addEventListener('frc:widget.expire', () => {
        fresh = '';
        context.onToken('');
    });
    widget.addEventListener('frc:widget.error', (event) => {
        const code = (event as CustomEvent<{ error?: { code?: unknown } }>).detail?.error?.code;
        // A wrong site key won't fix itself, so later attempts fail at once until a reset.
        terminal = code === 'sitekey_invalid' || code === 'sitekey_missing';
        fresh = '';
        context.onToken('');
        context.onError(typeof code === 'string' ? code : undefined);
        pending?.reject();
        pending = null;
    });
    const take = () => {
        const response = fresh;
        fresh = '';
        return response;
    };
    return {
        execute() {
            if (terminal) return Promise.reject(new Error('Challenge unavailable.'));
            if (fresh) return Promise.resolve(take());
            return bounded(new Promise<string>((resolve, reject) => {
                pending = { resolve: () => resolve(take()), reject: () => reject(new Error('Challenge unavailable.')) };
                const state = widget.getState();
                if (!FRIENDLY_CAPTCHA_SOLVING.includes(state)) {
                    // A completed, expired or failed widget has nothing left to give, so it starts over.
                    if (!FRIENDLY_CAPTCHA_IDLE.includes(state)) widget.reset();
                    widget.start();
                }
            }), context.signal, 120000);
        },
        reset() {
            terminal = false;
            fresh = '';
            context.onToken('');
            widget.reset();
        },
        destroy() {
            pending?.reject();
            pending = null;
            fresh = '';
            widget.destroy();
        },
    };
};
