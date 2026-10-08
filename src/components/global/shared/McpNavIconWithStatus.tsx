import { useAuth } from '@/components/global/auth/RequireAuth'
import { useT } from '@/lib/i18n/translate'
import { hasAccountMcpAgentConnected } from '@/lib/mcp-adoption'
import { useAccountConsents } from '@/lib/react-query/hooks/account-applications'
import { cn } from '@/lib/utils'
import { McpIcon } from './McpIcon'

type McpNavIconWithStatusProps = {
  className?: string
  isActive?: boolean
}

/** MCP nav mark with a green/red connection dot (console sidebar parity). */
export function McpNavIconWithStatus({
  className,
  isActive,
}: McpNavIconWithStatusProps) {
  const t = useT()
  const { account, isAuthenticated } = useAuth()
  const { data: consents } = useAccountConsents({
    enabled: isAuthenticated && !!account,
  })
  const connected =
    isAuthenticated && hasAccountMcpAgentConnected(consents)
  const statusLabel = connected ? t('Connected') : t('Not connected')

  return (
    <span className="relative inline-flex shrink-0">
      <McpIcon
        variant="nav"
        className={cn(isActive && 'opacity-100', className)}
      />
      <span
        className={cn(
          'absolute -end-0.5 -bottom-0.5 h-1.5 w-1.5 rounded-full ring-2 ring-background',
          connected ? 'bg-green-500' : 'bg-red-500',
        )}
        aria-hidden
      />
      <span className="sr-only">{statusLabel}</span>
    </span>
  )
}
