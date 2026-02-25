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
  Palette,
  Settings,
  Navigation,
  Sparkles,
  ChevronLeft,
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
import { useTheme } from 'next-themes'
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

interface MenuItem {
  label: string
  onClick?: () => void
  icon?: React.ReactNode
  active?: boolean
  badge?: string | number
  variant?: 'button' | 'switch'
  switchValue?: boolean
  switchOnChange?: (checked: boolean) => void
  description?: string
  submenu?: MenuItem[]
}

interface MenuSection {
  title: string
  icon?: React.ReactNode
  items: MenuItem[]
}

export function DebugMenu({ actions = [] }: DebugMenuProps) {
  const { isDebugModeOpen: isVisible } = useDebugMode()
  const [isOpen, setIsOpen] = useState(false)
  const [planModalOpen, setPlanModalOpen] = useState(false)
  const [showError, setShowError] = useState(false)
  const [overrides, setOverrides] = useState<DebugOverrides>(loadDebugOverrides)
  const navigate = useNavigate()
  const location = useLocation()
  const { account } = useAuth()
  const { addMockBanner, clearAllBanners, banners } = usePromoBanner()
  const { setFavicon, getCurrentFavicon } = useFavicon()
  const [currentFavicon, setCurrentFavicon] = useState<string | null>(null)
  const { theme, setTheme } = useTheme()
  const [activeSubmenu, setActiveSubmenu] = useState<string | null>(null)

  // Get current orgId from URL params or account prefs
  const currentOrgId = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const orgIndex = pathParts.findIndex((part) => part === 'organizations')
    if (orgIndex >= 0 && pathParts[orgIndex + 1]) {
      return pathParts[orgIndex + 1]
    }
    return account?.prefs?.organization as string | undefined
  }, [location.pathname, account?.prefs?.organization])

  // Fetch organization plan when modal is open
  const { plan, isLoading: planLoading } = useOrganizationPlan(
    planModalOpen ? currentOrgId : null,
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
    const interval = setInterval(updateCurrentFavicon, 500)
    return () => clearInterval(interval)
  }, [getCurrentFavicon, isOpen])

  // Reset submenu when popover closes
  useEffect(() => {
    if (!isOpen) {
      setActiveSubmenu(null)
    }
  }, [isOpen])

  // Organize sections
  const sections: MenuSection[] = useMemo(() => {
    const themeOptions: MenuItem[] = [
      { label: 'Light', themeValue: 'light' },
      { label: 'Dark', themeValue: 'dark' },
      { label: 'System', themeValue: 'system' },
      { label: '🎨 Crazy', themeValue: 'crazy' },
      { label: '🥷 Stealth', themeValue: 'stealth' },
    ].map((opt) => ({
      label: opt.label,
      onClick: () => {
        setTheme(opt.themeValue)
        setIsOpen(false)
      },
      active: theme === opt.themeValue,
      icon: <Palette className="h-3 w-3" />,
    }))

    const faviconOptions: MenuItem[] = [
      { label: 'Default', faviconValue: 'default' },
      { label: 'Green', faviconValue: 'green' },
      { label: 'Orange', faviconValue: 'orange' },
      { label: 'Red', faviconValue: 'red' },
      { label: 'Theme', faviconValue: 'theme' },
      { label: 'Theme + Green', faviconValue: 'theme-green' },
      { label: 'Theme + Orange', faviconValue: 'theme-orange' },
      { label: 'Theme + Red', faviconValue: 'theme-red' },
    ].map((opt) => ({
      label: opt.label,
      onClick: () => {
        setFavicon(opt.faviconValue)
        setIsOpen(false)
      },
      active: currentFavicon === opt.faviconValue,
      icon: <Image className="h-3 w-3" />,
    }))

    return [
      {
        title: 'Appearance',
        icon: <Palette className="h-3.5 w-3.5" />,
        items: [
          {
            label: 'Theme',
            icon: <Palette className="h-3 w-3" />,
            submenu: themeOptions,
          },
          {
            label: 'Favicon',
            icon: <Image className="h-3 w-3" />,
            submenu: faviconOptions,
          },
        ],
      },
      {
        title: 'Development',
        icon: <Settings className="h-3.5 w-3.5" />,
        items: [
          {
            label: 'Disable initial loader',
            description: 'Skip fullscreen loader on first paint',
            variant: 'switch' as const,
            switchValue: overrides.disableInitialLoader,
            switchOnChange: (checked: boolean) => {
              setOverrides((prev) => ({
                ...prev,
                disableInitialLoader: checked,
              }))
              setDebugOverride('disableInitialLoader', checked)
            },
          },
          {
            label: 'Show native app bar',
            description:
              'App bar above header with back/forward and centered search (for future native OS app)',
            variant: 'switch' as const,
            switchValue: overrides.showNativeAppBar,
            switchOnChange: (checked: boolean) => {
              setOverrides((prev) => ({
                ...prev,
                showNativeAppBar: checked,
              }))
              setDebugOverride('showNativeAppBar', checked)
            },
          },
          {
            label: 'Reset overrides',
            onClick: () => {
              resetDebugOverrides()
              setOverrides(loadDebugOverrides())
            },
            icon: <RotateCcw className="h-3 w-3" />,
          },
          {
            label: 'View error page',
            onClick: () => {
              setShowError(true)
              setIsOpen(false)
            },
            icon: <AlertTriangle className="h-3 w-3" />,
          },
          {
            label: 'Log current state',
            onClick: () => {
              console.log('Debug: Current state logged')
              setIsOpen(false)
            },
          },
          {
            label: 'Clear local storage',
            onClick: () => {
              localStorage.clear()
              console.log('Debug: Local storage cleared')
              setIsOpen(false)
            },
          },
          {
            label: 'Reload page',
            onClick: () => {
              window.location.reload()
            },
          },
        ],
      },
      {
        title: 'Navigation',
        icon: <Navigation className="h-3.5 w-3.5" />,
        items: [
          {
            label: 'Sign In',
            onClick: () => {
              navigate({ to: '/sign-in' })
              setIsOpen(false)
            },
            icon: <LogIn className="h-3 w-3" />,
          },
          {
            label: 'Sign Up',
            onClick: () => {
              navigate({ to: '/sign-up' })
              setIsOpen(false)
            },
            icon: <UserPlus className="h-3 w-3" />,
          },
          {
            label: 'Sign Out',
            onClick: () => {
              navigate({ to: '/sign-out' })
              setIsOpen(false)
            },
            icon: <LogOut className="h-3 w-3" />,
          },
        ],
      },
      {
        title: 'Promos',
        icon: <Sparkles className="h-3.5 w-3.5" />,
        items: [
          {
            label: 'Add promo banner',
            onClick: () => {
              addMockBanner()
              setIsOpen(false)
            },
            icon: <Megaphone className="h-3 w-3" />,
            badge: banners.length > 0 ? banners.length : undefined,
          },
          ...(banners.length > 0
            ? [
                {
                  label: 'Clear all banners',
                  onClick: () => {
                    clearAllBanners()
                    setIsOpen(false)
                  },
                  icon: <Trash2 className="h-3 w-3" />,
                },
              ]
            : []),
        ],
      },
      ...(currentOrgId
        ? [
            {
              title: 'Organization',
              icon: <CreditCard className="h-3.5 w-3.5" />,
              items: [
                {
                  label: 'Show org plan',
                  onClick: () => {
                    setPlanModalOpen(true)
                    setIsOpen(false)
                  },
                  icon: <CreditCard className="h-3 w-3" />,
                },
              ],
            } as MenuSection,
          ]
        : []),
      ...(actions.length > 0
        ? [
            {
              title: 'Custom Actions',
              items: actions.map((action) => ({
                label: action.label,
                onClick: () => {
                  action.onClick()
                  setIsOpen(false)
                },
                icon: action.icon,
              })),
            } as MenuSection,
          ]
        : []),
    ]
  }, [
    theme,
    currentFavicon,
    overrides.disableInitialLoader,
    overrides.showNativeAppBar,
    banners.length,
    currentOrgId,
    actions,
    setTheme,
    setFavicon,
    navigate,
    addMockBanner,
    clearAllBanners,
  ])

  // Get current submenu items
  const currentSubmenu = useMemo(() => {
    if (!activeSubmenu) return null

    for (const section of sections) {
      for (const item of section.items) {
        const itemKey = `${section.title}-${item.label}`
        if (item.submenu && itemKey === activeSubmenu) {
          return {
            title: item.label,
            items: item.submenu,
            parentSection: section.title,
          }
        }
      }
    }
    return null
  }, [activeSubmenu, sections])

  if (!isVisible) return null

  if (showError) {
    ErrorTrigger()
    return null
  }

  return (
    <div className="fixed bottom-4 right-4 z-[9999]">
      <Popover open={isOpen} onOpenChange={setIsOpen}>
        <PopoverTrigger asChild>
          <button
            className="flex h-10 w-10 items-center justify-center rounded-full bg-[#6B46C1] text-white transition-all hover:bg-[#5B21B6] hover:scale-105 active:scale-95"
            aria-label="Debug menu"
          >
            <Bug className="h-5 w-5" />
          </button>
        </PopoverTrigger>
        <PopoverContent
          side="top"
          align="end"
          className="w-72 max-h-[85vh] overflow-y-auto border-[#9B87F5]/30 bg-[#1A1F2C] p-0"
        >
          <div className="sticky top-0 z-10 border-b border-[#9B87F5]/20 bg-[#1A1F2C] px-3 py-2.5">
            <div className="flex items-center gap-2">
              {currentSubmenu && (
                <button
                  onClick={() => setActiveSubmenu(null)}
                  className="flex-shrink-0 rounded p-0.5 transition-colors hover:bg-[#9B87F5]/20"
                  aria-label="Back"
                >
                  <ChevronLeft className="h-3.5 w-3.5 text-[#9B87F5]" />
                </button>
              )}
              <span className="text-xs font-semibold text-[#9B87F5]">
                {currentSubmenu ? currentSubmenu.title : 'Debug Menu'}
              </span>
            </div>
          </div>

          <div className="p-2">
            {currentSubmenu ? (
              // Render submenu
              <div className="space-y-0.5">
                {currentSubmenu.items.map((item, itemIndex) => (
                  <button
                    key={`submenu-${itemIndex}`}
                    onClick={item.onClick}
                    className={`flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-[12px] transition-colors ${
                      item.active
                        ? 'bg-[#9B87F5]/20 text-white'
                        : 'text-[#E5DEFF]/80 hover:bg-[#9B87F5]/15 hover:text-white'
                    }`}
                  >
                    {item.icon && (
                      <span className="flex-shrink-0">{item.icon}</span>
                    )}
                    <span className="flex-1">{item.label}</span>
                    {item.badge && (
                      <span className="flex-shrink-0 rounded-full bg-[#9B87F5]/30 px-1.5 py-0.5 text-[10px] font-medium text-[#9B87F5]">
                        {item.badge}
                      </span>
                    )}
                  </button>
                ))}
              </div>
            ) : (
              // Render main menu
              sections.map((section, sectionIndex) => (
                <div
                  key={section.title}
                  className={sectionIndex > 0 ? 'mt-4' : ''}
                >
                  <div className="mb-1.5 flex items-center gap-1.5 px-2">
                    {section.icon}
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-[#9B87F5]/70">
                      {section.title}
                    </span>
                  </div>
                  <div className="space-y-0.5">
                    {section.items.map((item, itemIndex) => {
                      if (item.variant === 'switch') {
                        return (
                          <div
                            key={`${section.title}-${itemIndex}`}
                            className="flex items-center justify-between gap-3 rounded-md px-2.5 py-2 transition-colors hover:bg-[#9B87F5]/10"
                          >
                            <div className="flex-1">
                              <div className="text-[12px] font-medium text-white">
                                {item.label}
                              </div>
                              {item.description && (
                                <div className="mt-0.5 text-[11px] text-[#9B87F5]/70">
                                  {item.description}
                                </div>
                              )}
                            </div>
                            <Switch
                              checked={item.switchValue}
                              onCheckedChange={item.switchOnChange}
                            />
                          </div>
                        )
                      }

                      const hasSubmenu = !!item.submenu
                      const itemKey = `${section.title}-${item.label}`

                      return (
                        <button
                          key={`${section.title}-${itemIndex}`}
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation()
                            if (hasSubmenu) {
                              setActiveSubmenu(itemKey)
                            } else if (item.onClick) {
                              item.onClick()
                            }
                          }}
                          className={`flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-[12px] transition-colors ${
                            item.active
                              ? 'bg-[#9B87F5]/20 text-white'
                              : 'text-[#E5DEFF]/80 hover:bg-[#9B87F5]/15 hover:text-white'
                          }`}
                        >
                          {item.icon && (
                            <span className="flex-shrink-0">{item.icon}</span>
                          )}
                          <span className="flex-1">{item.label}</span>
                          {item.badge && (
                            <span className="flex-shrink-0 rounded-full bg-[#9B87F5]/30 px-1.5 py-0.5 text-[10px] font-medium text-[#9B87F5]">
                              {item.badge}
                            </span>
                          )}
                          {hasSubmenu && !item.badge && (
                            <ChevronRight className="h-3 w-3 flex-shrink-0 opacity-40" />
                          )}
                        </button>
                      )
                    })}
                  </div>
                </div>
              ))
            )}
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
                <div className="text-muted-foreground">
                  Loading plan details...
                </div>
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
                        <p className="text-sm font-medium capitalize">
                          {String(plan.type)}
                        </p>
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
                        <p className="text-sm font-medium">
                          {String(plan.currency)}
                        </p>
                      </div>
                    )}
                    {plan.price !== undefined && (
                      <div>
                        <label className="text-xs font-medium text-muted-foreground">
                          Price
                        </label>
                        <p className="text-sm font-medium">
                          {'currency' in plan &&
                          plan.currency &&
                          plan.price !== undefined
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
                        <p className="text-sm font-medium capitalize">
                          {String(plan.interval)}
                        </p>
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
