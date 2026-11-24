# Verbb Protect

`@verbb/protect` is the browser package for the `verbb/protect` Composer library. It adds a form's protection evidence and runs its challenge widget, such as Turnstile, reCAPTCHA, hCaptcha or Friendly Captcha, without depending on any framework, while your application owns submission, validation messages and retries. Install the version that matches your `verbb/protect` release.

```ts
import { mountProtection, parseManifest } from '@verbb/protect';
import '@verbb/protect/styles.css';

// Use a form element and fresh manifest obtained from your server.
const controller = mountProtection(form, parseManifest(serverManifest));
await controller.ready;
await controller.prepare();
const body = new FormData(form);
```

Send `body` through your application's transport. After an attempt consumes its preparation, fetch a fresh manifest and remount. Call `destroy()` before removing the form. `reset()` clears challenge evidence but does not renew signed preparation. Use the controller's `expiresIn()` and `remainingWait()` rather than comparing manifest times to `Date.now()`, because a visitor's clock can be wrong.

See the [browser package guide](https://github.com/verbb/protect/blob/craft-5/docs/developers/browser-package.md) for the manifest, events, Content Security Policy and retries, and the [PHP integration guide](https://github.com/verbb/protect/blob/craft-5/docs/developers/integrating-an-action.md) for what your server prepares and verifies.
