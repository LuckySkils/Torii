import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const THEME_COLOR_LIGHT = '#ffffff';
const THEME_COLOR_DARK = '#0a0a0a';

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

function setCookie(name: string, value: string, days = 365) {
    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
}

/**
 * For "system", the two theme-color tags stay media-query-driven so the browser
 * picks the right one itself. For an explicit choice, both are pinned to that
 * colour so the PWA status bar and browser chrome match regardless of the OS
 * setting.
 */
function updateThemeColorMeta(appearance: Appearance) {
    const light = document.getElementById('theme-color-light');
    const dark = document.getElementById('theme-color-dark');

    if (!(light instanceof HTMLMetaElement) || !(dark instanceof HTMLMetaElement)) {
        return;
    }

    if (appearance === 'system') {
        light.setAttribute('media', '(prefers-color-scheme: light)');
        dark.setAttribute('media', '(prefers-color-scheme: dark)');
        return;
    }

    const color = appearance === 'dark' ? THEME_COLOR_DARK : THEME_COLOR_LIGHT;
    light.setAttribute('content', color);
    dark.setAttribute('content', color);
    light.removeAttribute('media');
    dark.removeAttribute('media');
}

const applyTheme = (appearance: Appearance) => {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());

    document.documentElement.classList.toggle('dark', isDark);
    updateThemeColorMeta(appearance);
};

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance;
    applyTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    const savedAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';

    applyTheme(savedAppearance);

    // Add the event listener for system theme changes...
    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');

    const updateAppearance = (mode: Appearance) => {
        setAppearance(mode);
        localStorage.setItem('appearance', mode);
        // Also mirrored to a cookie so HandleAppearance can render the right
        // theme server-side on the next full page load, avoiding a flash.
        setCookie('appearance', mode);
        applyTheme(mode);
    };

    useEffect(() => {
        const savedAppearance = localStorage.getItem('appearance') as Appearance | null;
        updateAppearance(savedAppearance || 'system');

        return () => mediaQuery.removeEventListener('change', handleSystemThemeChange);
    }, []);

    return { appearance, updateAppearance };
}
