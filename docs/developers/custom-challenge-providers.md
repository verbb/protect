# Custom Challenge Providers

A challenge provider connects Protect to a verification service that shows visitors a widget, such as Turnstile, reCAPTCHA, hCaptcha or Friendly Captcha, which are built in. A custom one lets you use a service Protect doesn't include. It has two halves. The PHP class verifies tokens on the server, and a browser driver renders the widget and gets each token. Both use the same handle.

## Create the Challenge Provider Class

Most verification services follow the same "siteverify" pattern as Turnstile and reCAPTCHA, where your server posts the token and secret key to an endpoint and gets back JSON. For one of those, extend `verbb\protect\base\ChallengeProvider` and supply the handle and the endpoint:

```php
namespace example\protection;

use verbb\protect\base\ChallengeProvider;

class ExampleWidget extends ChallengeProvider
{
    public static function handle(): string
    {
        return 'example-widget';
    }

    public static function displayName(): string
    {
        return 'Example Widget';
    }

    protected function siteVerifyUrl(): string
    {
        return 'https://widget.example.com/siteverify';
    }
}
```

The base class gives you the same settings as the built-in providers (`siteKey`, `secretKey`, `expectedAction`, `hostnames` and `sendIp`), validates them, and resolves environment expressions. When the form is prepared, it puts only the handle, the public site key and the action in the browser manifest. When the attempt is checked, it posts the token to your endpoint with short timeouts and no redirects, and turns the answer into a result:

- An empty or oversized token is a `challenge-token-missing` failure.
- A missing secret key, or a response reporting `missing-input-secret`, `invalid-input-secret` or `bad-request`, is a `provider-configuration` error.
- A response reporting `missing-input-response`, `invalid-input-response` or `timeout-or-duplicate` is a `challenge-rejected` failure, and one reporting `internal-error` is `provider-unavailable`. Any other error code is `invalid-provider-response`.
- A 5xx response or a connection failure is `provider-unavailable`, and any other status apart from 200 is `provider-http-error`.
- A successful response must include `action`, `hostname` and `challenge_ts` as strings, or it's `invalid-provider-response`.
- An action that isn't `expectedAction`, or a hostname that isn't in `hostnames`, is a `challenge-context-mismatch` failure.

Override `interpret()` to check fields of your own after calling the parent, as `RecaptchaV3` does for its score, or override `verify()` entirely for a service that doesn't use this pattern.

A service that differs from the pattern in smaller ways can override a hook instead. When it has no actions, return `false` from `requiresAction()`, so `expectedAction` isn't required, and replace `interpret()` with your own, because the parent's requires an action. When it wants JSON or a credential in a header rather than a form body, override `requestOptions()`. When it answers some errors with a status other than 200, override `interpretStatus()`. `FriendlyCaptcha` uses all three. Return `Failed` when the visitor's token is missing or invalid, and `Error` when the service is unavailable or its answer can't be read.

If your provider has another credential besides `secretKey`, add it to `secretAttributes()` and keep the parent's list, so the secret key stays out of fingerprints and manifests too:

```php
public string $apiToken = '';

public function secretAttributes(): array
{
    return [...parent::secretAttributes(), 'apiToken'];
}
```

## Register the Challenge Provider

Protect finds challenge providers through the `registerChallengeTypes` event. Register your class from your module or plugin’s `init()` method.

```php
use example\protection\ExampleWidget;
use craft\events\RegisterComponentTypesEvent;
use verbb\protect\services\Challenges;
use yii\base\Event;

Event::on(
    Challenges::class,
    Challenges::EVENT_REGISTER_CHALLENGE_TYPES,
    static function(RegisterComponentTypesEvent $event): void {
        $event->types[] = ExampleWidget::class;
    },
);
```

Then add it to a plan with a `Challenge` check, the same way as Turnstile. In this partial example, `$plan` is the plan from your protection service:

```php
use example\protection\ExampleWidget;
use verbb\protect\checks\Challenge;

$plan->add(new Challenge([
    'provider' => new ExampleWidget([
        'siteKey' => '$EXAMPLE_WIDGET_SITE_KEY',
        'secretKey' => '$EXAMPLE_WIDGET_SECRET_KEY',
        'expectedAction' => 'feedback',
        'hostnames' => ['example.com'],
    ]),
]));
```

## Add the Browser Driver

The browser needs a driver registered under the same handle. The driver below adapts a provider's SDK to Protect. `WidgetSdk` describes the methods it expects from that SDK, and isn't something Protect supplies, so back it with your provider's real SDK, loaded from the provider's own origin with your page's CSP nonce before you register the driver:

```ts
import { registerChallengeProvider } from '@verbb/protect';

interface WidgetSdk {
    render(element: HTMLElement, options: { siteKey: string; action: string }): string;
    execute(id: string, signal: AbortSignal): Promise<string>;
    reset(id: string): void;
    remove(id: string): void;
}

export function registerExampleWidget(sdk: WidgetSdk): void {
    registerChallengeProvider('example-widget', async (context) => {
        if (context.signal.aborted) throw new Error('Protection was removed.');
        const id = sdk.render(context.element, {
            siteKey: context.manifest.siteKey,
            action: context.manifest.action,
        });
        let destroyed = false;
        return {
            async execute() {
                try {
                    const token = await sdk.execute(id, context.signal);
                    if (destroyed || context.signal.aborted) {
                        throw new Error('Protection was removed.');
                    }
                    context.onToken(token);
                    return token;
                } catch {
                    if (!destroyed && !context.signal.aborted) {
                        context.onToken('');
                        context.onError();
                    }
                    throw new Error('Verification could not complete.');
                }
            },
            reset() {
                context.onToken('');
                sdk.reset(id);
            },
            destroy() {
                destroyed = true;
                sdk.remove(id);
            },
        };
    });
}
```

Call `registerExampleWidget()` once, before mounting any forms. Calling it a second time throws, because each call creates a new factory and a handle can only be registered once. Each call to the factory creates one widget for one form, and must never reuse another form's token or widget ID. Your SDK code should give up after a reasonable time, stop when the abort signal fires, and clear expired tokens by calling `onToken('')`. The driver must call `onToken()` with each token, because returning a token from `execute()` alone doesn't add it to the form.

A server-rendered form using `renderBrowserInputs()` gets its browser code from Protect's bundled script, which has its own copy of the package, so register the driver there with `window.VerbbProtect.registerChallengeProvider()` instead of the npm import. [Custom Challenge Providers](browser-package.md#custom-challenge-providers) explains when to call it.

## Methods

::: reference
### `handle()`

**Returns:** `string`

Returns the provider's handle, which the browser driver must also use. It must be lowercase, start with a letter and use only letters, digits and hyphens. This method is static.
:::

::: reference
### `displayName()`

**Returns:** `string`

Returns the name of the provider as people should see it, such as in a settings screen. This method is static.
:::

::: reference
### `siteVerifyUrl()`

**Returns:** `string`

Returns the endpoint the token and secret key are posted to. Every class that extends `ChallengeProvider` must implement it.
:::

::: reference
### `interpret()`

**Arguments:** `array $data`, the decoded JSON response from the endpoint.

**Returns:** `ProviderResult`

Returns the result for a well-formed response. Call the parent first and only add checks of your own when it passes.
:::

::: reference
### `verify()`

**Arguments:** `string $token`, the token the browser sent, and `ProtectionContext $context`, the attempt being checked.

**Returns:** `ProviderResult`

Returns the result of verifying the token. Override it only for a service that doesn't use the siteverify pattern.
:::

::: reference
### `prepare()`

**Arguments:** `ProtectionContext $context`, the attempt the form is being prepared for.

**Returns:** `array`

Returns the provider's public details for the browser manifest, which are the handle, the site key and the action by default. Never include a secret here.
:::

::: reference
### `secretAttributes()`

**Returns:** `string[]`

Returns the names of the settings that hold credentials. These are kept out of fingerprints and manifests. The default is `['secretKey']`.
:::

::: reference
### `requiresAction()`

**Returns:** `bool`

Returns whether the service binds each token to an action. The default is `true`. Return `false` for a service without actions, so `expectedAction` isn't required.
:::

::: reference
### `requestOptions()`

**Arguments:** `array $params`, the `secret`, the `response` token and, when `sendIp` is on, the `remoteip`.

**Returns:** `array`

Returns the Guzzle options that carry the parameters, which are `['form_params' => $params]` by default. Protect adds its own timeouts and turns off redirects after this.
:::

::: reference
### `interpretStatus()`

**Arguments:** `int $status`, an HTTP status other than 200.

**Returns:** `ProviderResult`

Returns the result for a response with that status. By default, a 5xx status is `provider-unavailable` and any other is `provider-http-error`.
:::

::: reference
### `maxTokenLength()`

**Returns:** `int`

Returns the longest token Protect accepts before treating it as missing. The default is 8192.
:::

::: reference
### `maxAttempts()`

**Returns:** `int`

Returns how many times Protect sends the request when the service is unavailable. The default is 1, and a retry uses shorter timeouts.
:::

::: reference
### `defineRules()`

**Returns:** `array`

Returns the validation rules for the provider's settings. Merge your rules with the parent's, which require `siteKey`, `secretKey` and `hostnames`, and `expectedAction` unless `requiresAction()` returns `false`.
:::
