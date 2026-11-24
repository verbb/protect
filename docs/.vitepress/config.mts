import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vitepress';

type SidebarEntry = string | SidebarGroup;

type SidebarGroup = {
    title: string;
    path?: string;
    collapsable?: boolean;
    children: SidebarEntry[];
};

const docsRoot = fileURLToPath(new URL('..', import.meta.url));
const sidebarConfig = JSON.parse(fs.readFileSync(path.join(docsRoot, '.sidebar.json'), 'utf8')) as SidebarGroup[];
const h1Regex = /^#\s+(.+)$/m;

const getDocLink = (entryPath: string): string => `/${entryPath.replace(/\\/g, '/').replace(/\.md$/, '')}`;
const getFallbackTitle = (entryPath: string): string => entryPath
    .split('/')
    .pop()!
    .replace(/-/g, ' ')
    .replace(/\b\w/g, char => char.toUpperCase());

const getDocTitle = (entryPath: string): string => {
    const filePath = path.join(docsRoot, `${entryPath}.md`);

    if (!fs.existsSync(filePath)) {
        return getFallbackTitle(entryPath);
    }

    const match = fs.readFileSync(filePath, 'utf8').match(h1Regex);

    return match?.[1].trim() ?? getFallbackTitle(entryPath);
};

const transformSidebarEntry = (entry: SidebarEntry): object => {
    if (typeof entry === 'string') {
        return { text: getDocTitle(entry), link: getDocLink(entry) };
    }

    return {
        text: entry.title,
        collapsed: entry.collapsable ?? false,
        items: entry.children.map(transformSidebarEntry),
        ...(entry.path ? { link: getDocLink(entry.path) } : {}),
    };
};

export default defineConfig({
    title: 'Protect',
    description: 'Shared spam and abuse protection for Craft plugins and modules.',
    cleanUrls: true,
    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/protect-icon.svg' }],
    ],
    appearance: false,
    // The README is for maintainers of this repository, not a docs page.
    srcExclude: ['README.md'],
    markdown: {
        config(md) {
            // Match the reference containers used by the public documentation renderer.
            md.block.ruler.before('fence', 'reference', (state, startLine, endLine, silent) => {
                const line = (index: number) => state.src.slice(state.bMarks[index] + state.tShift[index], state.eMarks[index]).trim();

                if (state.sCount[startLine] - state.blkIndent >= 4 || line(startLine) !== '::: reference') return false;
                if (silent) return true;

                let close = startLine + 1;
                while (close < endLine && line(close) !== ':::') close++;
                if (close === endLine) return false;

                const open = state.push('reference_open', 'div', 1);
                open.block = true;
                open.attrSet('class', 'reference');
                state.md.block.tokenize(state, startLine + 1, close);
                const end = state.push('reference_close', 'div', -1);
                end.block = true;
                state.line = close + 1;
                return true;
            });
        },
    },
    vite: {
        ssr: { noExternal: ['@verbb/vitepress-theme', 'mark.js'] },
        plugins: [tailwindcss()],
        server: { port: 5413, strictPort: true },
    },
    themeConfig: {
        logo: '/protect-logo.svg',
        siteTitle: 'Protect',
        docsTheme: {
            // The Protect brand icon's colour.
            primary: '#27856c',
        },
        sidebar: sidebarConfig.map(transformSidebarEntry),
        search: { provider: 'local' },
        outline: [2, 3],
        socialLinks: [{ icon: 'github', link: 'https://github.com/verbb/protect' }],
    },
});
