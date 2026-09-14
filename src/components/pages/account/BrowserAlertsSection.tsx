import { Bell, BellOff, BellRing, Loader2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useBrowserAlertSettings } from '@/lib/browser-alerts/use-browser-alert-settings'
import { useConsoleImpersonationActive } from '@/hooks/use-console-impersonation-active'
import { useT } from '@/lib/i18n/translate'

function permissionBadgeVariant(
  permission: ReturnType<typeof useBrowserAlertSettings>['permission'],
): 'success' | 'error' | 'warning' | 'info' {
  if (permission === 'granted') return 'success'
  if (permission === 'denied') return 'error'
  if (permission === 'default') return 'warning'
  return 'info'
}

const INCLUDED_ALERTS = [
  {
    id: 'build-completion',
    titleKey: 'Build completion',
    descriptionKey:
      'Site and function builds that finish while you are away.',
  },
] as const

export function BrowserAlertsSection() {
  const t = useT()
  // The opt-out lives in account prefs, which are not saved while impersonating.
  const isImpersonating = useConsoleImpersonationActive()
  const {
    supported,
    permission,
    optedOut,
    alertsEnabled,
    isUpdating,
    isSendingTest,
    setAlertsEnabled,
    sendTestNotification,
  } = useBrowserAlertSettings()

  const permissionLabel =
    permission === 'granted'
      ? t('Allowed')
      : permission === 'denied'
        ? t('Blocked')
        : permission === 'default'
          ? t('Not set')
          : t('Not supported')

  const consoleAlertsLabel = alertsEnabled
    ? t('Enabled')
    : optedOut
      ? t('Disabled')
      : t('Waiting for browser permission')

  const switchChecked = !optedOut && permission !== 'denied'
  const switchHelper = !supported
    ? t('This browser does not support desktop notifications.')
    : permission === 'denied'
      ? t(
          'Notifications are blocked by your browser. Allow them in browser settings for this site.',
        )
      : alertsEnabled
        ? t(
            'You will receive desktop alerts from the Console while this tab is in the background.',
          )
        : optedOut
          ? t('Browser alerts are turned off in the Console.')
          : t(
              'Turn on alerts and allow notifications when your browser prompts you.',
            )

  return (
    <div
      data-card-id="browser-alerts"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Browser alerts')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Receive desktop alerts from the Console when this tab is in the background or another app has focus.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="flex items-start justify-between gap-4 rounded-lg border border-border bg-muted/30 p-4">
          <div className="min-w-0 space-y-1">
            <div className="flex items-center gap-2">
              {alertsEnabled ? (
                <BellRing className="h-4 w-4 shrink-0 text-muted-foreground" />
              ) : (
                <BellOff className="h-4 w-4 shrink-0 text-muted-foreground" />
              )}
              <Label
                htmlFor="browser-alerts-toggle"
                className="text-[13px] font-semibold text-foreground cursor-pointer"
              >
                {t('Browser alerts')}
              </Label>
            </div>
            <p className="text-[12px] leading-relaxed text-muted-foreground">
              {switchHelper}
            </p>
          </div>
          <Switch
            id="browser-alerts-toggle"
            checked={switchChecked}
            onCheckedChange={(checked) => void setAlertsEnabled(checked)}
            disabled={
              isImpersonating ||
              !supported ||
              isUpdating ||
              permission === 'denied'
            }
          />
        </div>

        <div className="rounded-lg border border-border divide-y divide-border overflow-hidden">
          <div className="flex items-center justify-between gap-3 px-4 py-3">
            <span className="text-[13px] text-muted-foreground">
              {t('Browser permission')}
            </span>
            <Badge
              variant={permissionBadgeVariant(permission)}
              className="text-[10px] shrink-0"
            >
              {permissionLabel}
            </Badge>
          </div>
          <div className="flex items-center justify-between gap-3 px-4 py-3">
            <span className="text-[13px] text-muted-foreground">
              {t('Console alerts')}
            </span>
            <Badge
              variant={alertsEnabled ? 'success' : 'warning'}
              className="text-[10px] shrink-0"
            >
              {consoleAlertsLabel}
            </Badge>
          </div>
        </div>

        <div>
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Included alerts')}
          </p>
          <ul className="mt-2 divide-y divide-border rounded-lg border border-border overflow-hidden">
            {INCLUDED_ALERTS.map((alert) => (
              <li key={alert.id} className="px-4 py-3">
                <p className="text-[13px] font-medium text-foreground">
                  {t(alert.titleKey)}
                </p>
                <p className="mt-0.5 text-[12px] leading-relaxed text-muted-foreground">
                  {t(alert.descriptionKey)}
                </p>
              </li>
            ))}
          </ul>
        </div>

        {permission === 'denied' ? (
          <p className="text-[12px] leading-relaxed text-muted-foreground">
            {t(
              'Open your browser settings, find notification permissions for this site, allow notifications, then refresh this page.',
            )}
          </p>
        ) : null}
      </div>
      {alertsEnabled ? (
        <>
          <div className="border-t border-border" />
          <div className="px-6 py-4 bg-muted/30">
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="h-9 text-[13px]"
              disabled={isSendingTest}
              onClick={() => void sendTestNotification()}
            >
              {isSendingTest ? (
                <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
              ) : (
                <Bell className="mr-1.5 h-3.5 w-3.5" />
              )}
              {t('Send test notification')}
            </Button>
          </div>
        </>
      ) : null}
    </div>
  )
}
