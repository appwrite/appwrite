import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

interface TransferOptionProps {
  icon: LucideIcon
  title: string
  description: string
  children: ReactNode
}

/** One way to transfer a resource, side by side with the others in a transfer card. */
export function TransferOption({
  icon: Icon,
  title,
  description,
  children,
}: TransferOptionProps) {
  return (
    <section className="flex flex-col gap-4 rounded-lg border border-border bg-card p-4">
      <div className="flex gap-3">
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
          <Icon className="h-4 w-4 text-muted-foreground" />
        </div>
        <div className="min-w-0">
          <h4 className="text-[13px] font-medium text-foreground">{title}</h4>
          <p className="text-[12px] text-muted-foreground mt-0.5">
            {description}
          </p>
        </div>
      </div>
      <div className="mt-auto">{children}</div>
    </section>
  )
}

interface TransferActionButtonProps {
  disabledReason: string | null
  onClick: () => void
  children: ReactNode
}

/** Disabled with its reason in a tooltip, so the user learns why they can't transfer. */
export function TransferActionButton({
  disabledReason,
  onClick,
  children,
}: TransferActionButtonProps) {
  if (disabledReason === null) {
    return (
      <Button size="sm" className="h-9 text-[13px] shrink-0" onClick={onClick}>
        {children}
      </Button>
    )
  }

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-block shrink-0">
            <Button size="sm" className="h-9 text-[13px]" disabled>
              {children}
            </Button>
          </span>
        </TooltipTrigger>
        <TooltipContent>{disabledReason}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

interface MoveConsiderationProps {
  icon: LucideIcon
  title: string
  description: string
}

/** One thing to weigh before moving a resource, listed in the move confirmation. */
export function MoveConsideration({
  icon: Icon,
  title,
  description,
}: MoveConsiderationProps) {
  return (
    <div className="flex gap-3">
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
        <Icon className="h-4 w-4 text-muted-foreground" />
      </div>
      <div>
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <p className="text-[12px] text-muted-foreground mt-0.5">
          {description}
        </p>
      </div>
    </div>
  )
}
