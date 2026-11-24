# Changelog

## Unreleased

### Added
- Add typed protection plans, neutral contexts, preparation evidence, ordered checks and structured findings.
- Add signed passive checks, replay protection and configurable fixed-window rate limits, over cache and mutex state.
- Add Turnstile, reCAPTCHA v3, hCaptcha and Friendly Captcha challenge providers, and Akismet, CleanTalk and OOPSpam classifiers, with explicit classifier feedback for Akismet and OOPSpam.
- Add the framework-independent `@verbb/protect` browser package with per-form lifecycle and shared provider scripts.
- Add `RequestSignals::fromRequest()` and `helpers\Ip::clientIp()`, which resolve the client address from `REMOTE_ADDR` and Craft's `trustedHosts`, normalise it, and warn when forwarding headers arrive unconfigured.
- Add an optional `idempotencyKey` on `ProtectionContext`, so a retry-safe consumer can present the same proof again for one attempt.
- Add `ProtectionResult::retryAfter()`, typed `ProtectionContent` fields for Akismet and `services\State::peek()`.
- Add `expiresIn()`, `remainingWait()` and a `protect:waiting` event to the browser controller.
- Add a `verbb-protect` Trusted Types policy, so challenge scripts load on pages that enforce Trusted Types.
- Add a prebuilt browser script to the Composer package, loaded by `ProtectionPreparation::renderBrowserInputs()`, so a Twig form can use challenges and the JavaScript check without a front-end build.
- Add `ProviderSettingsTrait` and `Protect::renderProviderSettings()`, so a plugin can let site owners choose a challenge provider and spam classifier with shared settings, validation and control panel fields.

### Changed
- Checks and providers are now configured components, such as `new MinimumTime(['seconds' => 3])` and `new Challenge(['provider' => new Turnstile([...])])`, registered through `EVENT_REGISTER_CHECK_TYPES`, `EVENT_REGISTER_CHALLENGE_TYPES` and `EVENT_REGISTER_CLASSIFIER_TYPES`.
- Replaced the replay and counter stores with one `services\State` that can be replaced through module config.
- `ProtectionPreparation::renderHiddenInputs()` now returns safe markup, and the honeypot is hidden with the `hidden` attribute, so no stylesheet is needed.
- Rate limit rules no longer partition by subject unless `includeSubject` is set, and count IPv6 addresses per /64.
- Identity signal keys must be a built-in signal or `<plugin>.<name>`.
- Turnstile now runs when a visitor submits rather than when a form loads.
- Browser timing now uses the server's clock, and `notBefore` is present only when a plan has a minimum time.
- Provider configuration problems now produce a `ConfigurationError` finding rather than looking like an outage, and `InvalidPlanException` carries the failing component's errors.
- Protect now logs each error result once, so consumers don't need to. Its log holds codes only, without Craft's request context, so it never includes a posted form.
- Protect now requires Craft 5.10 or later.
- Build browser assets before packing and include package usage and licence files.
- `@verbb/protect` now carries the same version as `verbb/protect`, and publishing refuses a development version or a mismatch.
- Exclude local development dependencies and local state from Composer archives.

### Fixed
- Fix Akismet outages being reported as configuration errors, and revoked keys as invalid responses.
- Fix a dropped connection while loading a provider script disabling a form until reload.
- Fix visitors with a wrong device clock being unable to submit protected forms.
- Fix a rate rule being charged when a later rule in the same plan was missing a dimension.
- Fix a cache or mutex backend exception escaping the structured protection error result.
- Fix module components supplied through config being overwritten.
- Fixed a moderate-severity client IP spoofing vulnerability.

### Removed
- Remove `Protect::bootstrap()`, `Protect::$plugin`, the settings schema classes, the plan registry and the diagnostics service.
- Remove `MinimumTime`'s `maxAge` setting. The plan's `preparationTtl` is the only expiry.
