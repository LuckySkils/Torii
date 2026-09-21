import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { Copy } from 'lucide-react';
import { toast } from 'sonner';

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
        <Tooltip>
            <TooltipTrigger asChild>
                <Button variant="ghost" size="icon" className="size-8" aria-label={label} onClick={handleClick}>
                    <Copy className="size-4" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
