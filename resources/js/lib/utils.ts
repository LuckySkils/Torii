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
