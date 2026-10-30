import type { ReactNode } from 'react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

export function OrgMemberManageMenuTooltip({
  enabled,
  label,
  children,
}: {
  enabled: boolean
  label: string
  children: ReactNode
}) {
  if (!enabled) return children
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="flex w-full">{children}</span>
      </TooltipTrigger>
      <TooltipContent className="max-w-xs text-xs">{label}</TooltipContent>
    </Tooltip>
  )
}
