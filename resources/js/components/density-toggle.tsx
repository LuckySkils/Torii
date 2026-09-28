import { Button } from '@/components/ui/button';
import { useDensity } from '@/hooks/use-density';
import { cn, TOUCH_TARGET_XS } from '@/lib/utils';
import { ALargeSmall } from 'lucide-react';

/** Compact ⇄ large layout. Hidden on phones, where large has no effect. */
export function DensityToggle({ className }: { className?: string }) {
    const [density, setDensity] = useDensity();
    const large = density === 'large';

    return (
        <Button
            variant="ghost"
            size="icon"
            className={cn('hidden md:inline-flex', large && 'bg-accent text-accent-foreground', TOUCH_TARGET_XS, className)}
            aria-pressed={large}
            aria-label="Large layout"
            title={large ? 'Large layout: on (bigger posters and text)' : 'Large layout: off'}
            onClick={() => setDensity(large ? 'compact' : 'large')}
        >
            <ALargeSmall className="size-4" />
        </Button>
    );
}
