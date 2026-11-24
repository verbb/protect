
export const TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
// Explicit mode allows different site keys to share a single script.
export const RECAPTCHA_SCRIPT = 'https://www.google.com/recaptcha/api.js?render=explicit';
// Without `recaptchacompat=off`, hCaptcha also defines `grecaptcha` and adds a field under its name.
export const HCAPTCHA_SCRIPT = 'https://js.hcaptcha.com/1/api.js?render=explicit&recaptchacompat=off';
// Friendly Captcha asks for an exact version. A classic script that sets `window.frcaptcha`, so it
// shares the loader with the other providers.
export const FRIENDLY_CAPTCHA_SCRIPT = 'https://cdn.jsdelivr.net/npm/@friendlycaptcha/sdk@1.1.5/site.min.js';
const PROVIDER_SCRIPTS = [TURNSTILE_SCRIPT, RECAPTCHA_SCRIPT, HCAPTCHA_SCRIPT, FRIENDLY_CAPTCHA_SCRIPT];

const scripts = new Map<string, Promise<void>>();

type ScriptUrlPolicy = { createScriptURL(url: string): unknown };
type TrustedTypes = { createPolicy(name: string, rules: { createScriptURL(url: string): string }): ScriptUrlPolicy };

// Shared on the page, because a second copy of this package (bundled by another consumer) can't
// create the same policy name again unless the site allows duplicates. It can only create the
// providers' constant URLs, so sharing it gives nothing away.
const POLICY_KEY = Symbol.for('verbb.protect.trustedTypesPolicy');

/**
 * Under Trusted Types (`require-trusted-types-for 'script'`), a script's `src` must come from a named
 * policy. Protect's, `verbb-protect`, allows only the challenge providers' own scripts, so a site
 * adds that name to its `trusted-types` directive. Without Trusted Types this is the plain string.
 */
function scriptUrl(src: string): string {
    const page = globalThis as { trustedTypes?: TrustedTypes; [POLICY_KEY]?: ScriptUrlPolicy | null };
    if (!page.trustedTypes) return src;
    if (page[POLICY_KEY] === undefined) {
        try {
            page[POLICY_KEY] = page.trustedTypes.createPolicy('verbb-protect', {
                createScriptURL(url) {
                    if (!PROVIDER_SCRIPTS.includes(url)) throw new TypeError('Protect only loads its challenge providers\' scripts.');
                    return url;
                },
            });
        } catch {
            // The site's policy doesn't allow the name, so the browser will refuse the string
            // instead. Remembered, so each load doesn't send another violation report.
            page[POLICY_KEY] = null;
        }
    }
    const policy = page[POLICY_KEY];
    return (policy ? policy.createScriptURL(src) : src) as string;
}

/** Script lifetime is shared; cancelling one form must not cancel other forms. */
export function loadScript(src: string, nonce?: string): Promise<void> {
    const existing = scripts.get(src);
    if (existing) return existing;
    const promise = new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        let timer: ReturnType<typeof setTimeout> | undefined;
        const fail = () => {
            clearTimeout(timer);
            script.remove();
            scripts.delete(src);
            reject(new Error('Challenge script unavailable.'));
        };
        try {
            script.src = scriptUrl(src);
        } catch {
            // Refused by the page's Trusted Types policy. Fail like any unreachable script.
            fail();
            return;
        }
        script.async = true;
        if (nonce) script.nonce = nonce;
        timer = setTimeout(() => fail(), 15000);
        script.addEventListener('load', () => { clearTimeout(timer); resolve(); }, { once: true });
        script.addEventListener('error', fail, { once: true });
        document.head.append(script);
    });
    scripts.set(src, promise);
    return promise;
}

export function bounded<T>(promise: Promise<T>, signal: AbortSignal, ms = 15000): Promise<T> {
    return new Promise((resolve, reject) => {
        const fail = () => { cleanup(); reject(new Error('Challenge unavailable.')); };
        const timer = setTimeout(fail, ms);
        const cleanup = () => { clearTimeout(timer); signal.removeEventListener('abort', fail); };
        signal.addEventListener('abort', fail, { once: true });
        if (signal.aborted) { fail(); return; }
        promise.then((value) => { cleanup(); resolve(value); }, () => fail());
    });
}
