import { PackageOpen } from 'lucide-react';
import { TapInfo } from './tap-info';

export function BatchHint() {
    return (
        <TapInfo
            trigger={
                <button type="button" className="inline-flex cursor-pointer bg-transparent p-0" aria-label="Batch release available">
                    <PackageOpen className="size-4 text-muted-foreground" />
                </button>
            }
        >
            A batch release is available for this show.
        </TapInfo>
    );
}
