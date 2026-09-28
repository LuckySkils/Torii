import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

interface UnlinkAnimeDialogProps {
    showId: number;
    showName: string;
    animeTitle: string;
    trigger: ReactNode;
}

export function UnlinkAnimeDialog({ showId, showName, animeTitle, trigger }: UnlinkAnimeDialogProps) {
    const [open, setOpen] = useState(false);
    const [pending, setPending] = useState(false);

    function unlink() {
        setPending(true);
        router.delete(`/shows/${showId}/link`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onFinish: () => setPending(false),
        });
    }

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger asChild>{trigger}</AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle className="break-words">Unlink "{showName}"?</AlertDialogTitle>
                    <AlertDialogDescription>
                        It's no longer shown as "{animeTitle}". Torii remembers this, so it won't link these two automatically again; you can still link
                        them by hand.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={pending}>Cancel</AlertDialogCancel>
                    <AlertDialogAction asChild>
                        <Button
                            variant="destructive"
                            disabled={pending}
                            onClick={(e) => {
                                e.preventDefault();
                                unlink();
                            }}
                        >
                            {pending ? 'Unlinking…' : 'Unlink'}
                        </Button>
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
