import { useState, useEffect } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Headphones,
  MessageCircle,
  ExternalLink,
  Activity,
  Mail,
  Building2,
} from 'lucide-react'
import { useOrganizationPlan } from '@/lib/react-query/hooks'
import { getSupportHoursInLocalTime } from '@/lib/support'

const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL ||
  'https://appwrite.io/contact-us/enterprise'

interface SupportPopoverProps {
  orgId?: string | null
}

export function SupportPopover({ orgId }: SupportPopoverProps) {
  const [supportHours, setSupportHours] = useState(() =>
    getSupportHoursInLocalTime(),
  )
  const [isIframeLoaded, setIsIframeLoaded] = useState(false)
  const navigate = useNavigate()
  const { resolvedTheme } = useTheme()

  // Fetch organization plan to check for premium support
  const { plan: organizationPlan } = useOrganizationPlan(orgId)

  // Check if the plan supports premium support
  const hasPremiumSupport = organizationPlan?.premiumSupport === true

  // Determine badge theme based on current theme
  const badgeTheme = resolvedTheme === 'dark' ? 'dark' : 'light'

  const handleUpgrade = () => {
    if (orgId) {
      navigate({
        to: '/organizations/$orgId/change-plan',
        params: { orgId },
      })
    }
  }

  useEffect(() => {
    // Update every minute to keep status current
    const interval = setInterval(() => {
      setSupportHours(getSupportHoursInLocalTime())
    }, 60000)
    return () => clearInterval(interval)
  }, [])

  // Reset iframe loaded state when theme changes
  useEffect(() => {
    setIsIframeLoaded(false)
  }, [badgeTheme])

  return (
    <Popover>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-9 w-9 text-muted-foreground hover:bg-accent hover:text-foreground"
            >
              <Headphones className="h-4 w-4" />
              <span className="sr-only">Support</span>
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>
          <p>Support</p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent align="end" className="w-80 p-0">
        {/* Contact Support Section */}
        <div className="p-4">
          <div className="flex items-start gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
              <Mail className="h-4 w-4 text-primary" />
            </div>
            <div className="flex-1 space-y-1">
              <h4 className="text-sm font-medium">Contact Support</h4>
              <p className="text-xs text-muted-foreground">
                Get help from our support team
              </p>
            </div>
          </div>

          {hasPremiumSupport && (
            <div className="mt-4 rounded-lg border border-border bg-muted/20 p-3.5">
              <div className="flex items-center justify-between gap-3">
                <h3 className="text-[13px] font-semibold text-foreground tracking-tight">
                  Support hours
                </h3>
                <span
                  className={`shrink-0 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider ${
                    supportHours.isOpen
                      ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                      : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                  }`}
                >
                  <span
                    className={`h-1 w-1 rounded-full ${
                      supportHours.isOpen ? 'bg-emerald-500' : 'bg-amber-500'
                    }`}
                  />
                  {supportHours.isOpen ? 'Online' : 'Offline'}
                </span>
              </div>
              <p className="text-[12px] text-muted-foreground tabular-nums whitespace-nowrap mt-2">
                Mon–Fri {supportHours.startLocal} – {supportHours.endLocal}
              </p>
              <p className="text-[11px] text-muted-foreground/70 mt-1 font-mono tracking-tight">
                {supportHours.timezone}
              </p>
            </div>
          )}

          {hasPremiumSupport ? (
            orgId ? (
              <Button className="mt-3 w-full" size="sm" asChild>
                <Link to="/organizations/$orgId/support" params={{ orgId }}>
                  <MessageCircle className="mr-1.5 h-4 w-4" />
                  Contact Support
                </Link>
              </Button>
            ) : (
              <Button className="mt-3 w-full" size="sm" disabled>
                <MessageCircle className="mr-1.5 h-4 w-4" />
                Contact Support
              </Button>
            )
          ) : (
            <>
              <p className="mt-3 text-xs text-muted-foreground">
                Upgrade your plan to get email support.
              </p>
              <Button
                className="mt-2 w-full"
                size="sm"
                onClick={handleUpgrade}
                disabled={!orgId}
              >
                Upgrade
              </Button>
            </>
          )}
        </div>

        <Separator />

        {/* Enterprise & Community */}
        <div className="p-4">
          <h4 className="mb-3 text-xs font-medium uppercase tracking-wider text-muted-foreground">
            More options
          </h4>

          <div className="space-y-2">
            <a
              href={CONTACT_SALES_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors hover:bg-muted"
            >
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <Building2 className="h-4 w-4" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="font-medium text-foreground">
                  Enterprise & 24/7 support
                </p>
                <p className="text-xs text-muted-foreground">Contact sales</p>
              </div>
              <ExternalLink className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            </a>

            <a
              href="https://appwrite.io/discord"
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors hover:bg-muted"
            >
              <div className="flex h-8 w-8 items-center justify-center rounded-md bg-[#5865F2]/10">
                <svg
                  className="h-4 w-4 text-[#5865F2]"
                  viewBox="0 0 24 24"
                  fill="currentColor"
                >
                  <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z" />
                </svg>
              </div>
              <div className="flex-1">
                <p className="font-medium">Discord Community</p>
                <p className="text-xs text-muted-foreground">
                  Join 24k+ developers
                </p>
              </div>
              <ExternalLink className="h-3.5 w-3.5 text-muted-foreground" />
            </a>

            <a
              href="https://github.com/appwrite/appwrite/issues/new/choose"
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors hover:bg-muted"
            >
              <div className="flex h-8 w-8 items-center justify-center rounded-md bg-foreground/10">
                <svg
                  className="h-4 w-4"
                  viewBox="0 0 24 24"
                  fill="currentColor"
                >
                  <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
                </svg>
              </div>
              <div className="flex-1">
                <p className="font-medium">GitHub Issues</p>
                <p className="text-xs text-muted-foreground">
                  Report bugs or request features
                </p>
              </div>
              <ExternalLink className="h-3.5 w-3.5 text-muted-foreground" />
            </a>
          </div>
        </div>

        <Separator />

        {/* Status Page */}
        <div className="p-4">
          <div className="flex items-start gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10">
              <Activity className="h-4 w-4 text-emerald-500" />
            </div>
            <div className="flex-1 space-y-1">
              <h4 className="text-sm font-medium">System Status</h4>
              <p className="text-xs text-muted-foreground">
                Check the current status of our services
              </p>
            </div>
          </div>
          <div className="mt-3 overflow-hidden rounded-lg border border-border bg-background px-2.5 pt-2.5 pb-1.5">
            <iframe
              className={`block w-full transition-opacity duration-500 ${
                isIframeLoaded ? 'opacity-100' : 'opacity-0'
              }`}
              title="Appwrite Status"
              src={`https://status.appwrite.online/badge?theme=${badgeTheme}`}
              height="35"
              frameBorder="0"
              scrolling="no"
              onLoad={() => setIsIframeLoaded(true)}
              style={{ colorScheme: 'none', display: 'block' }}
            />
          </div>
        </div>
      </PopoverContent>
    </Popover>
  )
}
