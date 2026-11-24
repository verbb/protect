import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Packs @verbb/protect as npm would publish it and checks the tarball on its own, in place of
// publint and attw, which aren't installed here. `npm test` builds first.
const packageDir = fileURLToPath(new URL('..', import.meta.url));
const root = fileURLToPath(new URL('../../..', import.meta.url));

test('the packed package is complete on its own', () => {
    const work = mkdtempSync(join(tmpdir(), 'protect-pack-'));

    try {
        const [packed] = JSON.parse(execFileSync('npm', ['pack', '--json', '--ignore-scripts', '--pack-destination', work], { cwd: packageDir, encoding: 'utf8' }));
        const files = packed.files.map((file) => file.path).sort();
        const manifest = JSON.parse(readFileSync(join(packageDir, 'package.json'), 'utf8'));
        const composer = JSON.parse(readFileSync(join(root, 'composer.json'), 'utf8'));

        assert.equal(packed.name, '@verbb/protect');
        assert.equal(packed.version, composer.version, 'The npm version must follow verbb/protect in composer.json.');
        assert.equal(manifest.license, 'MIT');
        assert.match(manifest.description, /^(?!.*\b(runtime|contract)\b)/i, 'The description describes the package itself.');
        assert.deepEqual(manifest.sideEffects, ['*.css']);

        for (const file of files) {
            assert.ok(['README.md', 'LICENSE.md', 'package.json'].includes(file) || /^(dist|styles)\//.test(file), `${file} shouldn't be published.`);
        }

        const targets = [manifest.types];

        for (const [subpath, entry] of Object.entries(manifest.exports)) {
            if (typeof entry === 'string') {
                targets.push(entry);
                continue;
            }

            assert.equal(Object.keys(entry)[0], 'types', `The "${subpath}" export must list types first.`);
            targets.push(...Object.values(entry));
        }

        for (const target of [...targets, './README.md', './LICENSE.md']) {
            assert.ok(files.includes(target.replace(/^\.\//, '')), `${target} isn't in the tarball.`);
        }

        // The Composer archive leaves `packages/` out, so the tarball must stand alone.
        const consumer = join(work, 'consumer');
        const installed = join(consumer, 'node_modules/@verbb/protect');

        mkdirSync(installed, { recursive: true });
        execFileSync('tar', ['-xzf', join(work, packed.filename), '-C', installed, '--strip-components=1']);
        assert.equal(readFileSync(join(installed, 'LICENSE.md'), 'utf8'), readFileSync(join(root, 'LICENSE.md'), 'utf8'), 'LICENSE.md must match the library licence.');
        assert.doesNotMatch(readFileSync(join(installed, 'README.md'), 'utf8'), /\b(runtime|contract)\b/i);
        writeFileSync(join(consumer, 'package.json'), JSON.stringify({ name: 'protect-pack-consumer', private: true, type: 'module' }));

        // Comments' core package imports it on the server, where there's no DOM.
        const exports = execFileSync(process.execPath, ['--input-type=module', '-e', "import('@verbb/protect').then((module) => console.log(JSON.stringify(Object.keys(module).sort())))"], { cwd: consumer, encoding: 'utf8' });

        assert.deepEqual(JSON.parse(exports), ['mountProtection', 'parseManifest', 'registerChallengeProvider']);

        const source = [
            "import { mountProtection, parseManifest, registerChallengeProvider } from '@verbb/protect';",
            "import type { BrowserManifest, ChallengeDriverFactory, Evidence, ProtectionController } from '@verbb/protect';",
            "import '@verbb/protect/styles.css';",
            'declare const form: HTMLFormElement;',
            'const manifest: BrowserManifest = parseManifest({});',
            "const controller: ProtectionController = mountProtection(form, manifest, { nonce: 'abc' });",
            'const evidence: Promise<Evidence> = controller.prepare();',
            'const factory: ChallengeDriverFactory = async () => ({ execute: async () => \'token\', reset() {}, destroy() {} });',
            "registerChallengeProvider('custom', factory);",
            'void evidence; const remaining: number = controller.expiresIn(); void remaining;',
        ].join('\n');
        const tsc = join(root, 'node_modules/typescript/bin/tsc');

        writeFileSync(join(consumer, 'styles.d.ts'), "declare module '*.css';\n");

        for (const [resolution, module, file] of [['Bundler', 'ESNext', 'consumer.ts'], ['NodeNext', 'NodeNext', 'consumer.mts']]) {
            writeFileSync(join(consumer, file), source);
            writeFileSync(join(consumer, 'tsconfig.json'), JSON.stringify({
                compilerOptions: { target: 'ES2022', module, moduleResolution: resolution, strict: true, noEmit: true, types: [], lib: ['ES2022', 'DOM'] },
                files: [file, 'styles.d.ts'],
            }));

            try {
                execFileSync(process.execPath, [tsc, '--project', join(consumer, 'tsconfig.json')], { cwd: consumer, encoding: 'utf8' });
            } catch (error) {
                assert.fail(`The packed types don't resolve under ${resolution}:\n${error.stdout}`);
            }

            rmSync(join(consumer, file));
        }
    } finally {
        rmSync(work, { recursive: true, force: true });
    }
});

test('the npm version is in lockstep with Composer', () => {
    assert.match(execFileSync(process.execPath, [join(root, 'scripts/release-version.mjs')], { encoding: 'utf8' }), /^PASS/);
});
