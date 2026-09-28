import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/**
 * Invisible expanded hit areas for small controls, touch-only (`hover: none`) — the visual
 * size never changes, only what registers as a tap. Pick the smallest one that reaches 44px
 * for the control's current size; each is tuned so neighbouring controls' expanded areas meet
 * without overlapping, given the gap they actually sit in.
 */
export const TOUCH_TARGET_XS = "relative [@media(hover:none)]:after:absolute [@media(hover:none)]:after:-inset-0.5 [@media(hover:none)]:after:content-['']";
export const TOUCH_TARGET_SM = "relative [@media(hover:none)]:after:absolute [@media(hover:none)]:after:-inset-1 [@media(hover:none)]:after:content-['']";
export const TOUCH_TARGET_MD = "relative [@media(hover:none)]:after:absolute [@media(hover:none)]:after:-inset-1.5 [@media(hover:none)]:after:content-['']";
export const TOUCH_TARGET_SWITCH =
    "relative [@media(hover:none)]:after:absolute [@media(hover:none)]:after:-inset-y-2.5 [@media(hover:none)]:after:inset-x-0 [@media(hover:none)]:after:content-['']";

/**
 * Poster grid for the shows and anime pages. Compact: the original phone-first
 * columns. Large density (md and up): wider cards, so posters stay readable on
 * a 2K screen instead of shrinking into a dozen columns.
 */
export const POSTER_GRID =
    'grid gap-3 sm:gap-4 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))] sm:[grid-template-columns:repeat(auto-fill,minmax(180px,1fr))] lg:[grid-template-columns:repeat(auto-fill,minmax(200px,1fr))] large:md:gap-5 large:md:[grid-template-columns:repeat(auto-fill,minmax(240px,1fr))] large:xl:[grid-template-columns:repeat(auto-fill,minmax(270px,1fr))] large:3xl:[grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]';
