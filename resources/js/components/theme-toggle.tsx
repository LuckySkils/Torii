import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { type Appearance, useAppearance } from '@/hooks/use-appearance';
import { TOUCH_TARGET_XS } from '@/lib/utils';
import { Check, Monitor, Moon, Sun } from 'lucide-react';

const OPTIONS: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

export function ThemeToggle() {
    const { appearance, updateAppearance } = useAppearance();
    const ActiveIcon = OPTIONS.find((option) => option.value === appearance)?.icon ?? Monitor;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className={TOUCH_TARGET_XS} aria-label="Change theme">
                    <ActiveIcon className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {OPTIONS.map((option) => (
                    <DropdownMenuItem key={option.value} onSelect={() => updateAppearance(option.value)}>
                        <option.icon className="size-4" />
                        {option.label}
                        {appearance === option.value && <Check className="ml-auto size-4" />}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
