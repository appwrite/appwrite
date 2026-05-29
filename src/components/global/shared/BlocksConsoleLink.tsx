import { Link } from '@tanstack/react-router'
import { ShieldAlert } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

export function BlocksConsoleLink() {
  const { account: raw } = useAuth()
  const account = raw as OperatorAccount | undefined

  // Same gate as ImpersonateConsoleUserPopover: either has the flag, or is
  // actively impersonating (operator context is preserved).
  if (!isOperatorAccount(account)) return null

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Link
          to="/blocks"
          className="hidden h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[900px]:flex [&.active]:text-foreground"
          aria-label="Blocks"
        >
          <ShieldAlert className="h-4 w-4" />
        </Link>
      </TooltipTrigger>
      <TooltipContent side="bottom">
        <p>Blocks</p>
      </TooltipContent>
    </Tooltip>
  )
}
