import { Link } from '@tanstack/react-router'
import { DatabaseZap } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

export function CacheConsoleLink() {
  const { account: raw } = useAuth()
  const account = raw as OperatorAccount | undefined

  if (!isOperatorAccount(account)) return null

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Link
          to="/cache"
          className="hidden h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[900px]:flex [&.active]:text-foreground"
          aria-label="Cache"
        >
          <DatabaseZap className="h-4 w-4" />
        </Link>
      </TooltipTrigger>
      <TooltipContent side="bottom">
        <p>Cache</p>
      </TooltipContent>
    </Tooltip>
  )
}
