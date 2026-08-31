import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { useDatabaseAdminNavCollapsed } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { ChevronDown } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'

const ADMIN_NAV_ID = 'database-admin-nav'

type DatabaseAdminNavSectionProps = {
  children: ReactNode
}

export function DatabaseAdminNavSection({
  children,
}: DatabaseAdminNavSectionProps) {
  const t = useT()
  const { account } = useAuth()
  const { collapsed, setCollapsed } = useDatabaseAdminNavCollapsed(
    account as { prefs?: Record<string, unknown> } | undefined,
  )
  const label = collapsed
    ? t('Show database tools')
    : t('Hide database tools')
  const [animate, setAnimate] = useState(false)

  useEffect(() => {
    const frame = window.requestAnimationFrame(() => setAnimate(true))
    return () => window.cancelAnimationFrame(frame)
  }, [])

  return (
    <>
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              {...analyticsAttrs('database-admin-nav-collapse')}
              aria-expanded={!collapsed}
              aria-controls={ADMIN_NAV_ID}
              aria-label={label}
              onClick={() => setCollapsed(!collapsed)}
              className="flex w-full cursor-pointer items-center justify-center rounded-md py-0.5 text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background"
            >
              <ChevronDown
                className={cn(
                  'h-3.5 w-3.5 transition-transform duration-300 ease-out',
                  collapsed && 'rotate-180',
                )}
              />
            </button>
          </TooltipTrigger>
          <TooltipContent side="top">
            <p className="text-[13px]">{label}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
      <div
        id={ADMIN_NAV_ID}
        aria-hidden={collapsed}
        inert={collapsed ? true : undefined}
        className={cn(
          'grid',
          animate &&
            'transition-[grid-template-rows,opacity] duration-300 ease-out',
          collapsed ? 'grid-rows-[0fr] opacity-0' : 'grid-rows-[1fr] opacity-100',
        )}
      >
        <div className="min-h-0 overflow-hidden">
          <div className="space-y-0.5">{children}</div>
        </div>
      </div>
    </>
  )
}
