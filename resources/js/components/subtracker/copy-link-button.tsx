import { Button } from '@/components/ui/button';
import { ACTION_HINTS } from '@/lib/hints';
import { Copy } from 'lucide-react';
import { toast } from 'sonner';
import { ActionHint } from './hint';

interface CopyLinkButtonProps {
    link: string;
    label?: string;
}

export function CopyLinkButton({ link, label = 'Copy link' }: CopyLinkButtonProps) {
    async function handleClick() {
        try {
            await navigator.clipboard.writeText(link);
            toast.success('Link copied to clipboard');
        } catch {
            toast.error("Couldn't copy the link");
        }
    }

    return (
        <ActionHint hint={ACTION_HINTS.copyLink} touchInfo={false}>
            <Button variant="ghost" size="icon" className="size-8" aria-label={label} onClick={handleClick}>
                <Copy className="size-4" />
            </Button>
        </ActionHint>
    );
}
