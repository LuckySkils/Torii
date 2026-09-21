import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

/**
 * Every Inertia visit carries a fresh `flash` object (HandleInertiaRequests
 * reads it from the success/error session keys), including redirects whose
 * HTTP status is a plain 200 — e.g. deleteRule catching a qBit failure still
 * responds `back()->with('error', ...)`, which Inertia treats as a normal
 * successful visit. So `flash` is the only reliable signal for those
 * business-level outcomes; per-request onSuccess/onError toasts can't see them.
 */
export function useFlashToasts() {
    const { flash } = usePage<SharedData>().props;

    useEffect(() => {
        if (flash.success) {
            toast.success(flash.success);
        }

        if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash]);
}
