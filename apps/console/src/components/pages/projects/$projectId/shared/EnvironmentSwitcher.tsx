import { Check, ChevronsUpDown, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import {
  PROJECT_ENVIRONMENT_DOT_CLASS,
  type ProjectEnvironment,
  useProjectEnvironment,
} from '@/lib/project-environments'
import { useT } from '@/lib/i18n/translate'

interface EnvironmentSwitcherProps {
  projectId: string
  collapsed?: boolean
  isMobile?: boolean
}

function EnvironmentDot({
  environment,
  className,
}: {
  environment: ProjectEnvironment
  className?: string
}) {
  return (
    <span
      aria-hidden
      className={cn(
        'h-2 w-2 shrink-0 rounded-full',
        PROJECT_ENVIRONMENT_DOT_CLASS[environment.type],
        className,
      )}
    />
  )
}

export function EnvironmentSwitcher({
  projectId,
  collapsed = false,
  isMobile = false,
}: EnvironmentSwitcherProps) {
  const t = useT()
  const { environment, environments, setEnvironmentId } =
    useProjectEnvironment(projectId)

  const handleSelect = (next: ProjectEnvironment) => {
    if (next.id === environment.id) return
    setEnvironmentId(next.id)
    toast.success(t('Environment switched'))
  }

  const trigger = collapsed ? (
    <button
      type="button"
      aria-label={t('Switch environment')}
      className="flex h-8 w-full cursor-pointer items-center justify-center rounded-md border border-border bg-card/50 transition-colors hover:bg-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <EnvironmentDot environment={environment} className="h-2.5 w-2.5" />
    </button>
  ) : (
    <button
      type="button"
      aria-label={t('Switch environment')}
      className={cn(
        'flex w-full cursor-pointer items-center gap-2 rounded-md border border-border bg-card/50 px-2.5 text-start transition-colors hover:bg-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        isMobile ? 'h-10' : 'h-8',
      )}
    >
      <EnvironmentDot environment={environment} />
      <span
        className={cn(
          'min-w-0 flex-1 truncate font-medium text-foreground',
          isMobile ? 'text-[14px]' : 'text-[13px]',
        )}
      >
        {t(environment.name)}
      </span>
      <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
    </button>
  )

  return (
    <DropdownMenu>
      {collapsed ? (
        <Tooltip delayDuration={0}>
          <TooltipTrigger asChild>
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
          </TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            <p>{t(environment.name)}</p>
          </TooltipContent>
        </Tooltip>
      ) : (
        <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
      )}
      <DropdownMenuContent
        side={collapsed ? 'right' : 'bottom'}
        align="start"
        sideOffset={collapsed ? 12 : 4}
        className={cn(
          collapsed ? 'w-[240px]' : 'w-[var(--radix-dropdown-menu-trigger-width)] min-w-[220px]',
        )}
      >
        <DropdownMenuLabel className="text-[11px] font-medium text-muted-foreground">
          {t('Environments')}
        </DropdownMenuLabel>
        {environments.map((env) => {
          const isActive = env.id === environment.id
          return (
            <DropdownMenuItem
              key={env.id}
              onSelect={() => handleSelect(env)}
              className={cn('gap-2', isActive && 'bg-accent/50')}
            >
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <EnvironmentDot environment={env} />
              </span>
              <span className="min-w-0 flex-1 truncate text-[13px]">
                {t(env.name)}
              </span>
              <Check
                className={cn(
                  'h-3.5 w-3.5 shrink-0',
                  isActive ? 'text-foreground' : 'invisible',
                )}
              />
            </DropdownMenuItem>
          )
        })}
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onSelect={() => toast.info(t('Custom environments are coming soon'))}
          className="text-[13px]"
        >
          <MenuItemContent icon={Plus}>{t('Create environment')}</MenuItemContent>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
