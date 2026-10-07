import { createInertiaApp } from '@inertiajs/react';

const appName = import.meta.env.VITE_APP_NAME ?? 'Laramine';

// Application pages only. A Composer package cannot register a page yet.
// See docs/frontend.md.
createInertiaApp({
    pages: './pages',
    title: (title) => (title.length > 0 ? `${title} - ${appName}` : appName),
});
