import type { SVGAttributes } from 'react';

// Torii gate mark. Uses currentColor, so colour it with text-* classes.
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" fill="currentColor" aria-hidden="true">
            <path d="M3 10.5 Q32 17.5 61 10.5 L59.2 17.6 Q32 22.6 4.8 17.6 Z" />
            <rect x="9" y="19.4" width="46" height="4" rx="0.6" />
            <rect x="29.8" y="23" width="4.4" height="6" />
            <rect x="7" y="28.6" width="50" height="4.4" rx="0.6" />
            <path d="M17.2 23 H22.6 L23.4 58 H15.4 Z" />
            <path d="M41.4 23 H46.8 L48.6 58 H40.6 Z" />
        </svg>
    );
}
