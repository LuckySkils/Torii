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
import { useState } from 'react';
import { toast } from 'sonner';

interface DeleteRuleDialogProps {
    showId: number;
    showName: string;
    trigger: React.ReactNode;
}

export function DeleteRuleDialog({ showId, showName, trigger }: DeleteRuleDialogProps) {
    const [pending, setPending] = useState(false);

    function handleConfirm() {
        setPending(true);

        router.delete(`/shows/${showId}/rule`, {
            preserveScroll: true,
            onError: () => toast.error(`Couldn't delete the rule for "${showName}".`),
            onFinish: () => setPending(false),
        });
    }

    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>{trigger}</AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete rule for "{showName}"?</AlertDialogTitle>
                    <AlertDialogDescription>
                        This removes the qBittorrent RSS rule entirely. qBit will forget which episodes it already downloaded for this show, so
                        re-tracking later may re-download past episodes.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={pending}>Cancel</AlertDialogCancel>
                    <AlertDialogAction asChild>
                        <Button variant="destructive" disabled={pending} onClick={handleConfirm}>
                            Delete rule
                        </Button>
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
