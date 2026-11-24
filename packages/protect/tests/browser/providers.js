(() => {
// Deterministic provider SDK doubles. Serve only through the browser test's network routes.
window.providerFixture ??= { widgets: new Map(), sequence: 0, executions: [], turnstileExecutions: [], hcaptchaExecutions: [], hcaptchaFailure: null, friendlyWidgets: [], resets: [], removed: [], pending: null };
const fixture = window.providerFixture;
window.turnstile = {
    render(element, options) {
        const id = `turnstile-${++fixture.sequence}`;
        fixture.widgets.set(id, { element, options });
        return id;
    },
    execute(id) { fixture.turnstileExecutions.push(id); },
    reset(id) { fixture.resets.push(id); },
    remove(id) { fixture.removed.push(id); },
};
window.grecaptcha = {
    ready(callback) { callback(); },
    render(element, options) {
        const id = ++fixture.sequence;
        fixture.widgets.set(id, { element, options });
        const response = document.createElement('textarea');
        response.name = 'g-recaptcha-response';
        element.append(response);
        return id;
    },
    async execute(id, options) {
        fixture.executions.push({ id, options });
        if (fixture.pending) return fixture.pending;
        return `recaptcha-${id}-${fixture.executions.length}`;
    },
    reset(id) { fixture.resets.push(id); },
};

window.hcaptcha = {
    render(element, options) {
        const id = `hcaptcha-${++fixture.sequence}`;
        fixture.widgets.set(id, { element, options });
        for (const name of ['h-captcha-response', 'g-recaptcha-response']) {
            const response = document.createElement('textarea');
            response.name = name;
            element.append(response);
        }
        return id;
    },
    execute(id, options) {
        fixture.hcaptchaExecutions.push({ id, options });
        if (fixture.hcaptchaFailure) return Promise.reject(fixture.hcaptchaFailure);
        return Promise.resolve({ response: `hcaptcha-${id}-${fixture.hcaptchaExecutions.length}`, key: 'fixture' });
    },
    reset(id) { fixture.resets.push(id); },
    remove(id) { fixture.removed.push(id); },
};
// Mirrors the SDK's WidgetHandle: state, start/reset/destroy, and events dispatched on the element.
class FriendlyWidget {
    constructor(options) { this.options = options; this.state = 'init'; this.starts = 0; this.resets = 0; this.destroyed = false; }
    addEventListener(type, listener) { this.options.element.addEventListener(type, listener); }
    emit(type, detail) { this.options.element.dispatchEvent(new CustomEvent(type, { detail })); }
    getState() { return this.state; }
    start() { this.starts++; this.state = 'solving'; }
    reset() { this.resets++; this.state = 'reset'; }
    destroy() { this.destroyed = true; this.state = 'destroyed'; }
    complete(response) { this.state = 'completed'; this.emit('frc:widget.complete', { name: 'frc:widget.complete', state: 'completed', response }); }
    expire() { this.state = 'expired'; this.emit('frc:widget.expire', { name: 'frc:widget.expire', state: 'expired', response: '.EXPIRED' }); }
    fail(code) { this.state = 'error'; this.emit('frc:widget.error', { name: 'frc:widget.error', state: 'error', response: '.ERROR', error: { code, detail: code } }); }
}
window.frcaptcha = {
    createWidget(options) {
        const widget = new FriendlyWidget(options);
        fixture.friendlyWidgets.push(widget);
        return widget;
    },
};

})();
