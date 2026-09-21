import { Switch } from '@/components/ui/switch';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface TrackSwitchProps {
    showId: number;
    showName: string;
    tracked: boolean;
}

export function TrackSwitch({ showId, showName, tracked }: TrackSwitchProps) {
    const [checked, setChecked] = useState(tracked);
    const [pending, setPending] = useState(false);

    useEffect(() => {
        setChecked(tracked);
    }, [tracked]);

    function handleChange(next: boolean) {
        setChecked(next);
        setPending(true);

        router.patch(
            `/shows/${showId}/track`,
            { tracked: next },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    setChecked(!next);
                    toast.error(`Couldn't update tracking for "${showName}".`);
                },
                onFinish: () => setPending(false),
            },
        );
    }

    return <Switch checked={checked} disabled={pending} onCheckedChange={handleChange} aria-label={`Track ${showName}`} />;
}
