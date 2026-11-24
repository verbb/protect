import { build } from 'esbuild';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Builds the browser bundle that ships inside the Composer package. The output is committed, so
// `packages/protect/tests/bundle.test.mjs` rebuilds it to a temporary folder and compares.
const root = fileURLToPath(new URL('..', import.meta.url));
const outdir = process.env.PROTECT_BUNDLE_OUT_DIR || `${root}src/web/assets/protect/dist`;
const { version } = JSON.parse(readFileSync(`${root}packages/protect/package.json`, 'utf8'));

await build({
    entryPoints: { protect: `${root}packages/protect/bundle/protect.ts` },
    outdir,
    bundle: true,
    format: 'esm',
    target: 'es2022',
    minify: true,
    legalComments: 'none',
    banner: { js: `/*! @verbb/protect ${version} | MIT */` },
    logLevel: 'warning',
});
