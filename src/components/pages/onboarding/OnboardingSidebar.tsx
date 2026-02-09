import { useOnboarding } from './OnboardingLayout'
import { cn } from '@/lib/utils'
import {
  ChevronLeft,
  Check,
  Circle,
  Globe,
  Users,
  Database,
  Folder,
  Zap,
  Compass,
  X,
  ExternalLink,
  LayoutDashboard,
} from 'lucide-react'
import { onboardingPaths, type OnboardingIntent } from './data'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

const intentIcons: Record<OnboardingIntent, typeof Globe> = {
  'deploy-site': Globe,
  'add-auth': Users,
  'create-database': Database,
  'manage-files': Folder,
  'run-functions': Zap,
  explore: Compass,
}

interface OnboardingSidebarProps {
  collapsed: boolean
  onToggleCollapse: () => void
}

export function OnboardingSidebar({
  collapsed,
  onToggleCollapse,
}: OnboardingSidebarProps) {
  const { state, setSelectedIntent, skipOnboarding } = useOnboarding()

  const currentPath = state.selectedIntent
    ? onboardingPaths.find((p) => p.id === state.selectedIntent)
    : null

  return (
    <TooltipProvider>
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex flex-col border-r border-border bg-background transition-all duration-200',
          collapsed ? 'w-[60px]' : 'w-[280px]',
        )}
      >
        {/* Collapse toggle */}
        <button
          onClick={onToggleCollapse}
          className="absolute -right-3 top-1/2 z-10 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md border border-border bg-card text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          <ChevronLeft
            className={cn(
              'h-3.5 w-3.5 transition-transform duration-200',
              collapsed && 'rotate-180',
            )}
          />
        </button>

        {/* Header */}
        <div className="flex h-14 items-center gap-3 border-b border-border px-4">
          <img
            src="https://appwrite.io/images/logos/logo.svg"
            alt="Appwrite"
            className="h-6 w-6 shrink-0"
          />
          {!collapsed && (
            <div className="flex-1 overflow-hidden">
              <p className="truncate text-sm font-medium">
                {state.projectName || 'New Project'}
              </p>
              <p className="truncate text-xs text-muted-foreground">
                Getting started
              </p>
            </div>
          )}
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto p-3">
          {/* Intent paths */}
          <div className="space-y-1">
            {!collapsed && (
              <p className="mb-2 px-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60">
                Choose your path
              </p>
            )}
            {onboardingPaths.map((path) => {
              const Icon = intentIcons[path.id]
              const isActive = state.selectedIntent === path.id
              const completedSteps = path.steps.filter(
                (s) => s.completed,
              ).length
              const totalSteps = path.steps.length
              const progress = (completedSteps / totalSteps) * 100

              const button = (
                <button
                  key={path.id}
                  onClick={() => setSelectedIntent(path.id)}
                  className={cn(
                    'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-all',
                    isActive
                      ? 'bg-accent text-foreground'
                      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                    collapsed && 'justify-center px-0',
                  )}
                >
                  <div className="relative shrink-0">
                    <Icon className="h-4 w-4" />
                    {completedSteps === totalSteps && completedSteps > 0 && (
                      <div className="absolute -right-1 -top-1 flex h-3 w-3 items-center justify-center rounded-full bg-green-500">
                        <Check className="h-2 w-2 text-white" />
                      </div>
                    )}
                  </div>
                  {!collapsed && (
                    <>
                      <div className="flex-1 overflow-hidden">
                        <p className="truncate text-sm font-medium">
                          {path.title}
                        </p>
                        {isActive && completedSteps > 0 && (
                          <div className="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-muted">
                            <div
                              className="h-full rounded-full bg-[#f02e65] transition-all"
                              style={{ width: `${progress}%` }}
                            />
                          </div>
                        )}
                      </div>
                      {!isActive && completedSteps > 0 && (
                        <span className="text-xs text-muted-foreground">
                          {completedSteps}/{totalSteps}
                        </span>
                      )}
                    </>
                  )}
                </button>
              )

              if (collapsed) {
                return (
                  <Tooltip key={path.id} delayDuration={0}>
                    <TooltipTrigger asChild>{button}</TooltipTrigger>
                    <TooltipContent side="right" sideOffset={8}>
                      <p>{path.title}</p>
                      {completedSteps > 0 && (
                        <p className="text-xs text-muted-foreground">
                          {completedSteps}/{totalSteps} completed
                        </p>
                      )}
                    </TooltipContent>
                  </Tooltip>
                )
              }

              return button
            })}
          </div>

          {/* Current path steps */}
          {currentPath && !collapsed && (
            <div className="mt-6 space-y-1">
              <p className="mb-2 px-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60">
                Steps
              </p>
              {currentPath.steps.map((step, index) => (
                <div
                  key={step.id}
                  className={cn(
                    'flex items-start gap-3 rounded-lg px-3 py-2 text-sm',
                    step.current
                      ? 'bg-[#f02e65]/10 text-foreground'
                      : step.completed
                        ? 'text-muted-foreground'
                        : 'text-muted-foreground/60',
                  )}
                >
                  <div className="mt-0.5 shrink-0">
                    {step.completed ? (
                      <div className="flex h-4 w-4 items-center justify-center rounded-full bg-green-500">
                        <Check className="h-2.5 w-2.5 text-white" />
                      </div>
                    ) : step.skipped ? (
                      <div className="flex h-4 w-4 items-center justify-center rounded-full border border-muted-foreground/30">
                        <X className="h-2.5 w-2.5" />
                      </div>
                    ) : step.current ? (
                      <div className="flex h-4 w-4 items-center justify-center rounded-full border-2 border-[#f02e65]">
                        <Circle className="h-1.5 w-1.5 fill-[#f02e65] text-[#f02e65]" />
                      </div>
                    ) : (
                      <div className="flex h-4 w-4 items-center justify-center rounded-full border border-muted-foreground/30">
                        <span className="text-[10px]">{index + 1}</span>
                      </div>
                    )}
                  </div>
                  <span className={cn(step.current && 'font-medium')}>
                    {step.title}
                  </span>
                </div>
              ))}
            </div>
          )}
        </nav>

        {/* Footer */}
        <div className="border-t border-border p-3">
          {collapsed ? (
            <Tooltip delayDuration={0}>
              <TooltipTrigger asChild>
                <button
                  onClick={skipOnboarding}
                  className="flex w-full items-center justify-center rounded-lg px-3 py-2.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                  <LayoutDashboard className="h-4 w-4" />
                </button>
              </TooltipTrigger>
              <TooltipContent side="right" sideOffset={8}>
                <p>Go to console</p>
              </TooltipContent>
            </Tooltip>
          ) : (
            <div className="space-y-1">
              <button
                onClick={skipOnboarding}
                className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <LayoutDashboard className="h-4 w-4" />
                Go to console
              </button>
              <a
                href="https://appwrite.io/docs"
                target="_blank"
                rel="noopener noreferrer"
                className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <ExternalLink className="h-4 w-4" />
                Documentation
              </a>
            </div>
          )}
        </div>
      </aside>
    </TooltipProvider>
  )
}
