import * as React from "react"
import * as SwitchPrimitives from "@radix-ui/react-switch"

import { cn, TOUCH_TARGET_SWITCH } from "@/lib/utils"

const Switch = React.forwardRef<
  React.ElementRef<typeof SwitchPrimitives.Root>,
  React.ComponentPropsWithoutRef<typeof SwitchPrimitives.Root>
>(({ className, ...props }, ref) => {
  // A Radix trigger wrapping the switch through `asChild` (a tooltip, as in
  // ActionHint) hands it the trigger's own `data-state` ("closed",
  // "delayed-open"). Radix spreads it over the switch's "checked"/"unchecked",
  // so neither track colour below would match and the track would turn
  // transparent. The switch's state is its own: drop the wrapper's.
  const rootProps = { ...props }
  delete (rootProps as Record<string, unknown>)["data-state"]

  return (
    <SwitchPrimitives.Root
      className={cn(
        "peer inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-primary data-[state=unchecked]:bg-input",
        TOUCH_TARGET_SWITCH,
        className
      )}
      {...rootProps}
      ref={ref}
    >
      <SwitchPrimitives.Thumb
        className={cn(
          "pointer-events-none block h-5 w-5 rounded-full bg-background shadow-lg ring-0 transition-transform data-[state=checked]:translate-x-5 data-[state=unchecked]:translate-x-0"
        )}
      />
    </SwitchPrimitives.Root>
  )
})
Switch.displayName = SwitchPrimitives.Root.displayName

export { Switch }
