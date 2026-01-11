import { useState, useEffect, useMemo } from 'react'
import {
  Bug,
  ChevronRight,
  LogIn,
  UserPlus,
  LogOut,
  Megaphone,
  Trash2,
  RotateCcw,
  CreditCard,
  AlertTriangle,
  Image,
} from 'lucide-react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useNavigate, useLocation } from '@tanstack/react-router'
import { usePromoBanner } from './PromoBanner'
import { useDebugMode } from './DebugMode'
import { Switch } from '@/components/ui/switch'
import {
  loadDebugOverrides,
  resetDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
  type DebugOverrides,
} from '@/lib/debug-overrides'
import { useOrganizationPlan } from '@/lib/react-query/hooks'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useFavicon } from '@/hooks/use-favicon'

interface DebugAction {
  label: string
  onClick: () => void
  icon?: React.ReactNode
}

interface DebugMenuProps {
  actions?: DebugAction[]
}

// Component that throws an error during render to trigger error boundary
function ErrorTrigger() {
  throw new Error('Debug: Error page triggered from debug menu')
}

export function DebugMenu({ actions = [] }: DebugMenuProps) {
  const { isDebugModeOpen: isVisible } = useDebugMode()
  const [isOpen, setIsOpen] = useState(false)
  const [planModalOpen, setPlanModalOpen] = useState(false)
  const [showError, setShowError] = useState(false)
  const [overrides, setOverrides] = useState<DebugOverrides>(
    loadDebugOverrides,
  )
  const navigate = useNavigate()
  const location = useLocation()
  const { account } = useAuth()
  const { addMockBanner, clearAllBanners, banners } = usePromoBanner()
  const { setFavicon, getCurrentFavicon } = useFavicon()
  const [currentFavicon, setCurrentFavicon] = useState<string | null>(null)

  // Get current orgId from URL params or account prefs
  const currentOrgId = useMemo(() => {
    // Try to get from URL params first
    const pathParts = location.pathname.split('/').filter(Boolean)
    const orgIndex = pathParts.findIndex(part => part === 'organizations')
    if (orgIndex >= 0 && pathParts[orgIndex + 1]) {
      return pathParts[orgIndex + 1]
    }
    // Fallback to account prefs
    return account?.prefs?.organization as string | undefined
  }, [location.pathname, account?.prefs?.organization])

  // Fetch organization plan when modal is open
  const { plan, isLoading: planLoading } = useOrganizationPlan(
    planModalOpen ? currentOrgId : null
  )

  useEffect(() => {
    const unsubscribe = subscribeToDebugOverrides(setOverrides)
    return () => {
      unsubscribe?.()
    }
  }, [])

  // Update current favicon state when it changes
  useEffect(() => {
    const updateCurrentFavicon = () => {
      const favicon = getCurrentFavicon()
      setCurrentFavicon(favicon)
    }
    
    updateCurrentFavicon()
    // Check periodically in case favicon changes externally
    const interval = setInterval(updateCurrentFavicon, 500)
    return () => clearInterval(interval)
  }, [getCurrentFavicon, isOpen])

  // Auth navigation actions
  const authActions: DebugAction[] = [
    {
      label: 'Sign In',
      icon: <LogIn className="h-3.5 w-3.5" />,
      onClick: () => navigate({ to: '/sign-in' }),
    },
    {
      label: 'Sign Up',
      icon: <UserPlus className="h-3.5 w-3.5" />,
      onClick: () => navigate({ to: '/sign-up' }),
    },
    {
      label: 'Sign Out',
      icon: <LogOut className="h-3.5 w-3.5" />,
      onClick: () => navigate({ to: '/sign-out' }),
    },
  ]

  const promoActions = [
    {
      label: `Add promo banner${banners.length > 0 ? ` (${banners.length})` : ''}`,
      icon: <Megaphone className="h-3.5 w-3.5" />,
      onClick: () => addMockBanner(),
    },
    ...(banners.length > 0
      ? [
          {
            label: 'Clear all banners',
            icon: <Trash2 className="h-3.5 w-3.5" />,
            onClick: () => clearAllBanners(),
          },
        ]
      : []),
  ]

  // Organization plan action
  const orgPlanAction: DebugAction = {
    label: 'Show org plan',
    icon: <CreditCard className="h-3.5 w-3.5" />,
    onClick: () => {
      setPlanModalOpen(true)
    },
  }

  // Favicon actions with variant mapping
  const faviconActions: Array<DebugAction & { variant: string }> = [
    {
      label: 'Default',
      variant: 'default',
      icon: <Image className="h-3.5 w-3.5" />,
      onClick: () => setFavicon('default'),
    },
    {
      label: 'Green',
      variant: 'green',
      icon: <Image className="h-3.5 w-3.5" />,
      onClick: () => setFavicon('green'),
    },
    {
      label: 'Orange',
      variant: 'orange',
      icon: <Image className="h-3.5 w-3.5" />,
      onClick: () => setFavicon('orange'),
    },
    {
      label: 'Red',
      variant: 'red',
      icon: <Image className="h-3.5 w-3.5" />,
      onClick: () => setFavicon('red'),
    },
    {
      label: 'Theme-aware',
      variant: 'theme',
      icon: <Image className="h-3.5 w-3.5" />,
      onClick: () => setFavicon('theme'),
    },
    {
      label: 'Theme + Green',
      variant: 'theme-green',
      icon: <Image className="h-3.5 w-3.5" />,
      onClick: () => setFavicon('theme-green'),
    },
    {
      label: 'Theme + Orange',
      variant: 'theme-orange',
      icon: <Image className="h-3.5 w-3.5" />,
      onClick: () => setFavicon('theme-orange'),
    },
    {
      label: 'Theme + Red',
      variant: 'theme-red',
      icon: <Image className="h-3.5 w-3.5" />,
      onClick: () => setFavicon('theme-red'),
    },
  ]

  // Default actions for testing
  const defaultActions: DebugAction[] = [
    {
      label: 'View error page',
      icon: <AlertTriangle className="h-3.5 w-3.5" />,
      onClick: () => {
        setShowError(true)
      },
    },
    {
      label: 'Log current state',
      onClick: () => console.log('Debug: Current state logged'),
    },
    {
      label: 'Clear local storage',
      onClick: () => {
        localStorage.clear()
        console.log('Debug: Local storage cleared')
      },
    },
    {
      label: 'Reload page',
      onClick: () => window.location.reload(),
    },
  ]

  const allActions = actions.length > 0 ? actions : defaultActions

  if (!isVisible) return null

  // Render error trigger component to trigger error boundary
  if (showError) {
    ErrorTrigger()
    return null
  }

  return (
    <div className="fixed bottom-4 right-4 z-[9999]">
      <Popover open={isOpen} onOpenChange={setIsOpen}>
        <PopoverTrigger asChild>
          <button
            className="flex h-10 w-10 items-center justify-center rounded-full bg-[#6B46C1] text-white shadow-lg transition-all hover:bg-[#5B21B6] hover:scale-105 active:scale-95"
            aria-label="Debug menu"
          >
            <Bug className="h-5 w-5" />
          </button>
        </PopoverTrigger>
        <PopoverContent
          side="top"
          align="end"
          className="w-56 border-[#9B87F5]/30 bg-[#1A1F2C] p-1"
        >
          <div className="mb-1 border-b border-[#9B87F5]/20 px-2 py-1.5">
            <span className="text-xs font-medium text-[#9B87F5]">
              Debug Menu
            </span>
          </div>

          {/* Auth Section */}
          <div className="px-1 py-1.5">
            <p className="px-2 py-1 text-[10px] font-medium uppercase tracking-wider text-[#9B87F5]/60">
              Auth
            </p>
            {authActions.map((action) => (
              <button
                key={`auth-${action.label}`}
                onClick={() => {
                  action.onClick()
                  setIsOpen(false)
                }}
                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-[12px] text-[#E5DEFF]/80 transition-colors hover:bg-[#9B87F5]/20 hover:text-white"
              >
                {action.icon}
                {action.label}
                <ChevronRight className="ml-auto h-3 w-3 opacity-50" />
              </button>
            ))}
          </div>

          <div className="border-b border-[#9B87F5]/20" />

          {/* Promos Section */}
          <div className="px-1 py-1.5">
            <p className="px-2 py-1 text-[10px] font-medium uppercase tracking-wider text-[#9B87F5]/60">
              Promos
            </p>
            {promoActions.map((action) => (
              <button
                key={`promo-${action.label}`}
                onClick={() => {
                  action.onClick()
                  setIsOpen(false)
                }}
                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-[12px] text-[#E5DEFF]/80 transition-colors hover:bg-[#9B87F5]/20 hover:text-white"
              >
                {action.icon}
                {action.label}
                <ChevronRight className="ml-auto h-3 w-3 opacity-50" />
              </button>
            ))}
          </div>

          <div className="border-b border-[#9B87F5]/20" />

          {/* Overrides Section */}
          <div className="px-1 py-1.5">
            <p className="px-2 py-1 text-[10px] font-medium uppercase tracking-wider text-[#9B87F5]/60">
              Overrides
            </p>
            <div className="flex flex-col gap-1.5">
              <div className="flex w-full items-center justify-between gap-3 rounded-md px-2 py-1.5 text-[12px] text-[#E5DEFF]/80 transition-colors hover:bg-[#9B87F5]/10">
                <div className="flex flex-col gap-0.5">
                  <span className="text-[13px] text-white">
                    Disable initial loader
                  </span>
                  <span className="text-[11px] text-[#9B87F5]/70">
                    Skips the fullscreen loader on first paint.
                  </span>
                </div>
                <Switch
                  checked={overrides.disableInitialLoader}
                  onCheckedChange={(checked) => {
                    setOverrides((prev) => ({
                      ...prev,
                      disableInitialLoader: checked,
                    }))
                    setDebugOverride('disableInitialLoader', checked)
                  }}
                />
              </div>

              <button
                onClick={() => {
                  resetDebugOverrides()
                  setOverrides(loadDebugOverrides())
                }}
                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-[12px] text-[#E5DEFF]/80 transition-colors hover:bg-[#9B87F5]/20 hover:text-white"
              >
                <RotateCcw className="h-3.5 w-3.5" />
                Reset overrides
                <ChevronRight className="ml-auto h-3 w-3 opacity-50" />
              </button>
            </div>
          </div>

          <div className="border-b border-[#9B87F5]/20" />

          {/* Favicon Section */}
          <div className="px-1 py-1.5">
            <p className="px-2 py-1 text-[10px] font-medium uppercase tracking-wider text-[#9B87F5]/60">
              Favicon {currentFavicon && `(${currentFavicon})`}
            </p>
            {faviconActions.map((action) => {
              const isActive = currentFavicon === action.variant
              return (
                <button
                  key={`favicon-${action.variant}`}
                  onClick={() => {
                    action.onClick()
                    setIsOpen(false)
                  }}
                  className={`flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-[12px] text-[#E5DEFF]/80 transition-colors hover:bg-[#9B87F5]/20 hover:text-white ${
                    isActive ? 'bg-[#9B87F5]/30' : ''
                  }`}
                >
                  {action.icon}
                  {action.label}
                  <ChevronRight className="ml-auto h-3 w-3 opacity-50" />
                </button>
              )
            })}
          </div>

          <div className="border-b border-[#9B87F5]/20" />

          {/* Organization Section */}
          {currentOrgId && (
            <>
              <div className="px-1 py-1.5">
                <p className="px-2 py-1 text-[10px] font-medium uppercase tracking-wider text-[#9B87F5]/60">
                  Organization
                </p>
                <button
                  onClick={() => {
                    orgPlanAction.onClick()
                    setIsOpen(false)
                  }}
                  className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-[12px] text-[#E5DEFF]/80 transition-colors hover:bg-[#9B87F5]/20 hover:text-white"
                >
                  {orgPlanAction.icon}
                  {orgPlanAction.label}
                  <ChevronRight className="ml-auto h-3 w-3 opacity-50" />
                </button>
              </div>
              <div className="border-b border-[#9B87F5]/20" />
            </>
          )}

          {/* Actions Section */}
          <div>
            <div className="px-2 py-1">
              <span className="text-[10px] font-medium uppercase tracking-wider text-[#9B87F5]/60">
                Actions
              </span>
            </div>
            <div className="flex flex-col gap-0.5">
              {allActions.map((action, index) => (
                <button
                  key={`action-${index}`}
                  onClick={() => {
                    action.onClick()
                    setIsOpen(false)
                  }}
                  className="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left text-[13px] text-white/90 transition-colors hover:bg-[#9B87F5]/20 hover:text-white"
                >
                  <span className="flex items-center gap-2">
                    {action.icon}
                    {action.label}
                  </span>
                  <ChevronRight className="h-3.5 w-3.5 text-[#9B87F5]/50" />
                </button>
              ))}
            </div>
          </div>
        </PopoverContent>
      </Popover>

      {/* Organization Plan Modal */}
      <Dialog open={planModalOpen} onOpenChange={setPlanModalOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Organization Plan Details</DialogTitle>
            <DialogDescription>
              Current plan information for the organization
            </DialogDescription>
          </DialogHeader>
          <div className="mt-4">
            {planLoading ? (
              <div className="flex items-center justify-center py-8">
                <div className="text-muted-foreground">Loading plan details...</div>
              </div>
            ) : plan ? (
              <div className="space-y-4">
                <div className="rounded-lg border bg-card p-4">
                  <div className="space-y-3">
                    {plan.name && (
                      <div>
                        <label className="text-xs font-medium text-muted-foreground">
                          Plan Name
                        </label>
                        <p className="text-sm font-medium">{plan.name}</p>
                      </div>
                    )}
                    {'type' in plan && plan.type && (
                      <div>
                        <label className="text-xs font-medium text-muted-foreground">
                          Plan Type
                        </label>
                        <p className="text-sm font-medium capitalize">{String(plan.type)}</p>
                      </div>
                    )}
                    {'tier' in plan && plan.tier !== undefined && (
                      <div>
                        <label className="text-xs font-medium text-muted-foreground">
                          Tier
                        </label>
                        <p className="text-sm font-mono text-muted-foreground">
                          {String(plan.tier)}
                        </p>
                      </div>
                    )}
                    {'billingPlan' in plan && plan.billingPlan && (
                      <div>
                        <label className="text-xs font-medium text-muted-foreground">
                          Billing Plan
                        </label>
                        <p className="text-sm font-mono text-muted-foreground">
                          {String(plan.billingPlan)}
                        </p>
                      </div>
                    )}
                    {'currency' in plan && plan.currency && (
                      <div>
                        <label className="text-xs font-medium text-muted-foreground">
                          Currency
                        </label>
                        <p className="text-sm font-medium">{String(plan.currency)}</p>
                      </div>
                    )}
                    {plan.price !== undefined && (
                      <div>
                        <label className="text-xs font-medium text-muted-foreground">
                          Price
                        </label>
                        <p className="text-sm font-medium">
                          {'currency' in plan && plan.currency && plan.price !== undefined
                            ? `${plan.currency} ${plan.price}`
                            : plan.price}
                        </p>
                      </div>
                    )}
                    {'interval' in plan && plan.interval && (
                      <div>
                        <label className="text-xs font-medium text-muted-foreground">
                          Billing Interval
                        </label>
                        <p className="text-sm font-medium capitalize">{String(plan.interval)}</p>
                      </div>
                    )}
                  </div>
                </div>
                <div className="rounded-lg border bg-muted/50 p-4">
                  <label className="text-xs font-medium text-muted-foreground mb-2 block">
                    Full Plan Data
                  </label>
                  <pre className="text-xs overflow-auto max-h-96">
                    {JSON.stringify(plan, null, 2)}
                  </pre>
                </div>
              </div>
            ) : (
              <div className="flex items-center justify-center py-8">
                <div className="text-muted-foreground">
                  {currentOrgId
                    ? 'Failed to load plan details'
                    : 'No organization selected'}
                </div>
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
