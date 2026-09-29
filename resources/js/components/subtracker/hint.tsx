import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn, TOUCH_TARGET_MD } from '@/lib/utils';
import { Slot } from '@radix-ui/react-slot';
import { Info } from 'lucide-react';
import { forwardRef, useSyncExternalStore, type HTMLAttributes, type ReactElement, type ReactNode, type Ref } from 'react';
import { TapInfo } from './tap-info';

const TOUCH_QUERY = '(hover: none)';

function subscribe(onChange: () => void) {
    const query = window.matchMedia(TOUCH_QUERY);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}

/** True on devices without hover (phones, tablets), where tooltips never open. */
export function useIsTouch(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => window.matchMedia(TOUCH_QUERY).matches,
        () => false,
    );
}

const CONTENT = 'max-w-72 text-pretty';

/**
 * Explanatory text for something that isn't itself an action (a status badge, a
 * column header): a hover tooltip on pointer devices, the tap-info popover on touch.
 */
export function Hint({ trigger, children }: { trigger: ReactElement; children: ReactNode }) {
    const touch = useIsTouch();

    if (touch) {
        return <TapInfo trigger={trigger}>{children}</TapInfo>;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{trigger}</TooltipTrigger>
            <TooltipContent className={CONTENT}>{children}</TooltipContent>
        </Tooltip>
    );
}

/** A table header with an explanation: dotted underline, text on hover or tap. */
export function ColumnHint({ label, children }: { label: string; children: ReactNode }) {
    return (
        <Hint
            trigger={
                <button
                    type="button"
                    className="cursor-help bg-transparent p-0 font-[inherit] text-inherit underline decoration-muted-foreground/40 decoration-dotted underline-offset-4"
                >
                    {label}
                </button>
            }
        >
            {children}
        </Hint>
    );
}

interface ActionHintProps extends HTMLAttributes<HTMLElement> {
    hint: string;
    /** The button. On touch it keeps acting on tap; the explanation moves to an ⓘ beside it. */
    children: ReactElement;
    /** For the touch wrapper, e.g. `w-full` where the button fills a grid cell. */
    className?: string;
    /**
     * Wrap the button in a focusable span on pointer devices, so the tooltip still
     * opens while the button is disabled (disabled buttons get no pointer events).
     */
    wrapDisabled?: boolean;
    /**
     * Show the ⓘ on touch. Off for buttons repeated on every row or card
     * (download, copy link), whose visible label already says enough there.
     */
    touchInfo?: boolean;
}

/**
 * What an action does. Pointer devices: a hover tooltip on the button itself.
 * Touch: tapping the button must still act, so a small ⓘ next to it opens the
 * same text instead.
 *
 * Works as the `asChild` child of a dialog trigger: the trigger's props and ref
 * are passed through to the button.
 */
export const ActionHint = forwardRef<HTMLElement, ActionHintProps>(function ActionHint(
    { hint, children, className, wrapDisabled = false, touchInfo = true, ...slotProps },
    ref,
) {
    const touch = useIsTouch();

    if (touch && !touchInfo) {
        return (
            <Slot ref={ref} {...slotProps}>
                {children}
            </Slot>
        );
    }

    if (touch) {
        return (
            <span className={cn('inline-flex items-center gap-0.5', className)}>
                <Slot ref={ref} {...slotProps}>
                    {children}
                </Slot>
                <TapInfo
                    trigger={
                        <button
                            type="button"
                            className={cn('flex size-6 shrink-0 items-center justify-center rounded-full text-muted-foreground', TOUCH_TARGET_MD)}
                            aria-label="What does this do?"
                        >
                            <Info className="size-3.5" />
                        </button>
                    }
                >
                    {hint}
                </TapInfo>
            </span>
        );
    }

    if (wrapDisabled) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span tabIndex={0} className={cn('inline-flex', className)}>
                        <Slot ref={ref} {...slotProps}>
                            {children}
                        </Slot>
                    </span>
                </TooltipTrigger>
                <TooltipContent className={CONTENT}>{hint}</TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild ref={ref as Ref<HTMLButtonElement>} {...slotProps}>
                {children}
            </TooltipTrigger>
            <TooltipContent className={CONTENT}>{hint}</TooltipContent>
        </Tooltip>
    );
});
