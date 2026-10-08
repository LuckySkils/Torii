import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';
import { initializeDensity } from './hooks/use-density';
import { trackHistoryNavigation } from './lib/history-scroll';

declare global {
    const route: typeof routeFn;
}

// Before the first render, so the page never paints in the wrong density.
initializeDensity();
// Lets the layout finish Inertia's scroll restoration after Back/Forward (lib/history-scroll.ts).
trackHistoryNavigation();

const appName = JSON.parse(document.getElementById('app')?.dataset.page ?? '{}').props?.name || 'Torii';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
