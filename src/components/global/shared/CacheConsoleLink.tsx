import { Link } from '@tanstack/react-router'
import { DatabaseZap } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

type ImpersonatorAccount = Models.User & {
  impersonator?: boolean
  impersonatorUserId?: string
}

export function CacheConsoleLink() {
  const { account: raw } = useAuth()
  const account = raw as ImpersonatorAccount | undefined

  // Same gate as BlocksConsoleLink: either has the flag, or is actively
  // impersonating (operator context is preserved).
  const allowed =
    account?.impersonator === true || !!account?.impersonatorUserId

  if (!allowed) return null

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
