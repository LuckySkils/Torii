import { createContext, useContext } from 'react';

/**
 * Lets something inside a hover card (the Track switch's confirm dialog) keep the
 * card open while the pointer is over a dialog outside it. Null outside a card.
 */
export const CardPinContext = createContext<((pinned: boolean) => void) | null>(null);

export function usePinCard() {
    return useContext(CardPinContext);
}
