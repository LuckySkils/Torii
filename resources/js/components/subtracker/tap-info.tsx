import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { TOUCH_TARGET_MD } from '@/lib/utils';

interface TapInfoProps {
    trigger: React.ReactNode;
    children: React.ReactNode;
}

/**
 * Tooltips never open on touch. Anything with real information (error text,
 * a downloaded/queued time, an approximation note) uses this instead: a
 * Popover opens the same way on click and on tap, on every input type.
 *
 * The trigger is wrapped in an inline-flex span so the touch-only expanded
 * hit area (most of these triggers are small badges) applies uniformly
 * without every call site repeating it.
 */
export function TapInfo({ trigger, children }: TapInfoProps) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <span className={`inline-flex ${TOUCH_TARGET_MD}`}>{trigger}</span>
            </PopoverTrigger>
            <PopoverContent side="top" align="start" collisionPadding={8} className="w-auto max-w-64 p-2 text-sm">
                {children}
            </PopoverContent>
        </Popover>
    );
}
