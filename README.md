> [!NOTE]
> Protect isn't a plugin for site owners to install. It's a library for your own Craft plugins and modules, and it arrives on a site as a dependency of the plugin that uses it, such as [Comments](https://github.com/verbb/comments).

<p align="center"><img src="https://assets.verbb.io/plugins/protect/protect-icon.svg" width="100" height="100" alt="Protect icon"></p>
<h1 align="center">Protect for Craft CMS</h1>

Protect is a Craft CMS library that gives your plugins and modules shared spam and abuse protection for anything the public submits, such as comments, contact messages, reviews or votes. It checks each attempt for the signs of spam and abuse and tells your code what it found, so you don't build the same checks again for every form. Your code still decides what happens next, whether that's saving, refusing, slowing someone down or holding a message for review.

## Features

- Passive checks, a honeypot, a minimum form time and a JavaScript check, which visitors never see and which turn away simple bots before anything else runs.
- Replay protection that makes each prepared form single use, with safe retries through an idempotency key.
- Rate limits per visitor, per item or site-wide, counted by IP address, user or your own identity signals.
- A challenge from your choice of provider, verified on the server against what its token was issued for.
- Spam classification of what a submission says, with mistakes reported back to the service.
- One result listing what each check found, so your code responds without parsing messages.
- A bundled browser script and the `@verbb/protect` npm package, which add a form's evidence and run its challenge widget.

## Providers

- [Akismet](https://akismet.com)
- [CleanTalk](https://cleantalk.org)
- [Cloudflare Turnstile](https://www.cloudflare.com/application-services/products/turnstile/)
- [Friendly Captcha](https://friendlycaptcha.com)
- [hCaptcha](https://www.hcaptcha.com)
- [OOPSpam](https://www.oopspam.com)
- [reCAPTCHA v3](https://developers.google.com/recaptcha/docs/v3)

## Documentation

Visit the [Protect documentation](https://github.com/verbb/protect/blob/craft-5/docs/index.md) for all documentation, guides and developer resources.

## Support

Get in touch with us by [creating a GitHub issue](https://github.com/verbb/protect/issues).

## Sponsor

Protect is licensed under the MIT license, so it will always be free and open source. If you'd like to support it anyway, you can [sponsor its development](https://github.com/sponsors/verbb).

<h2></h2>

<a href="https://verbb.io" target="_blank">
    <img width="101" height="33" src="https://verbb.io/assets/img/verbb-pill.svg" alt="Verbb">
</a>
