#!/usr/bin/env node
/**
 * Keeps `@verbb/protect` on the same version as the `verbb/protect` Composer package, because the
 * browser and PHP halves of one release must agree.
 *
 *   node scripts/release-version.mjs              Check the versions match.
 *   node scripts/release-version.mjs --publishable  Also refuse a development version, before publishing.
 *   node scripts/release-version.mjs 1.0.0         Set every version for review, then check them.
 *
 * It never publishes, commits, tags or pushes.
 */
import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const files = {
    composer: `${root}composer.json`,
    package: `${root}packages/protect/package.json`,
    lockfile: `${root}package-lock.json`,
    bundle: `${root}src/web/assets/protect/dist/protect.js`,
};
const args = process.argv.slice(2);
const publishable = args.includes('--publishable');
const target = args.find((arg) => !arg.startsWith('--'));

const read = (path) => JSON.parse(readFileSync(path, 'utf8'));
// Each file keeps its own indentation, so the diff shows only the version.
const write = (path, data) => {
    const indent = readFileSync(path, 'utf8').match(/^\{\r?\n([ \t]+)"/)?.[1] ?? '    ';

    writeFileSync(path, `${JSON.stringify(data, null, indent)}\n`);
};

if (target !== undefined) {
    if (!/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(target)) {
        throw new Error(`"${target}" isn't a version such as 1.0.0 or 1.1.0-beta.1.`);
    }

    const composer = read(files.composer);
    const pkg = read(files.package);
    const lockfile = read(files.lockfile);

    composer.version = target;
    pkg.version = target;
    lockfile.packages['packages/protect'].version = target;

    write(files.composer, composer);
    write(files.package, pkg);
    write(files.lockfile, lockfile);

    // The bundle's banner names the version, so rebuild it with the new one.
    const bundle = spawnSync(process.execPath, [`${root}scripts/build-bundle.mjs`], { stdio: 'inherit' });

    if (bundle.status !== 0) {
        throw new Error('The browser bundle failed to build.');
    }

    console.log(`Set verbb/protect, @verbb/protect and the bundle to ${target}. Review the diff; nothing was published.`);
}

const lockfile = read(files.lockfile);
const versions = {
    'composer.json': read(files.composer).version,
    'packages/protect/package.json': read(files.package).version,
    'package-lock.json (packages/protect)': lockfile.packages['packages/protect']?.version,
    'src/web/assets/protect/dist/protect.js': readFileSync(files.bundle, 'utf8').match(/^\/\*! @verbb\/protect (\S+) \|/)?.[1],
};
const expected = versions['composer.json'];
const mismatched = Object.entries(versions).filter(([, version]) => version !== expected);

if (!expected) {
    throw new Error('composer.json has no version, so there is nothing for @verbb/protect to follow.');
}

if (mismatched.length) {
    throw new Error(`Versions aren't in lockstep with composer.json (${expected}):\n${mismatched.map(([file, version]) => `  ${file}: ${version}`).join('\n')}`);
}

if (publishable && /-dev$/.test(expected)) {
    throw new Error(`${expected} is a development version. Set the release version first: node scripts/release-version.mjs <version>`);
}

console.log(`PASS: verbb/protect and @verbb/protect are both ${expected}.`);
