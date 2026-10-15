import type { ComponentProps } from 'react'
import { Switch } from '@/components/ui/switch'
import { cn } from '@/lib/utils'

/** Switch forced LTR for debug menu toggles when the page is RTL. */
export function DebugMenuSwitch({
  className,
  ...props
}: ComponentProps<typeof Switch>) {
  return (
    <Switch
      dir="ltr"
      className={cn(
        '[&_[data-slot=switch-thumb]]:data-[state=unchecked]:!translate-x-0',
        '[&_[data-slot=switch-thumb]]:data-[state=checked]:!translate-x-[calc(100%-2px)]',
        className,
      )}
      {...props}
    />
  )
}
