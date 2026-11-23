import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { useBrowserAlertSettings } from '@/lib/browser-alerts/use-browser-alert-settings'
import {
  snoozeOf,
  withMutedOrganization,
  withMutedProject,
  type BeepPrefs,
} from '@/lib/notifications/beep-prefs'
import { useOrganizations } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useT, type Translator } from '@/lib/i18n/translate'

/** `3:45 PM` today, `tomorrow 9:00 AM` after midnight. */
export function formatUntil(
  until: Date,
  t: Translator,
  now: Date = new Date(),
): string {
  const time = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
  }).format(until)
  return until.toDateString() === now.toDateString()
    ? time
    : `${t('tomorrow')} ${time}`
}

type Change = (
  change: (current: BeepPrefs) => BeepPrefs,
  options?: { onError?: () => void },
) => void

function Section({
  title,
  footer,
  children,
}: {
  title: string
  footer?: string
  children: ReactNode
}) {
  return (
    <section className="border-b border-border px-4 py-3 last:border-b-0">
      <h4 className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {title}
      </h4>
      <div className="divide-y divide-border/60">{children}</div>
      {footer ? (
        <p className="mt-2 text-[11px] leading-relaxed text-muted-foreground">
          {footer}
        </p>
      ) : null}
    </section>
  )
}

function Row({
  title,
  subtitle,
  children,
}: {
  title: string
  subtitle?: string
  children: ReactNode
}) {
  return (
    <div className="flex items-center justify-between gap-3 py-2">
      <div className="min-w-0">
        <p className="truncate text-[13px] text-foreground">{title}</p>
        {subtitle ? (
          <p className="text-[11px] text-muted-foreground">{subtitle}</p>
        ) : null}
      </div>
      <div className="shrink-0">{children}</div>
    </div>
  )
}

function MutedProjectRow({
  projectId,
  disabled,
  onUnmute,
}: {
  projectId: string
  disabled: boolean
  onUnmute: () => void
}) {
  const t = useT()
  const { project } = useProject(projectId)
  return (
    <Row title={project?.name ?? projectId}>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-7 text-[12px]"
        disabled={disabled}
        onClick={onUnmute}
      >
        {t('Unmute')}
      </Button>
    </Row>
  )
}

/**
 * Where notifications reach the person: this browser, and Beep on their
 * phones. Beep's settings are the account's, so they follow every device.
 */
export function NotificationSettings({
  beep,
  isSaving,
  onChange,
}: {
  beep: BeepPrefs
  isSaving: boolean
  onChange: Change
}) {
  const t = useT()
  const browser = useBrowserAlertSettings()
  const { organizations } = useOrganizations()
  const onError = () => toast.error(t('Could not update push settings'))

  return (
    <div>
      <Section title={t('This browser')}>
        <Row
          title={t('Browser alerts')}
          subtitle={
            !browser.supported
              ? t('Not supported in this browser')
              : browser.permission === 'denied'
                ? t('Blocked in your browser settings')
                : t('Builds and alerts while the Console is in the background')
          }
        >
          <Switch
            checked={browser.alertsEnabled}
            disabled={!browser.supported || browser.isUpdating}
            onCheckedChange={(enabled) =>
              void browser.setAlertsEnabled(enabled)
            }
            aria-label={t('Browser alerts')}
          />
        </Row>
      </Section>

      <Section
        title={t('Mobile pushes')}
        footer={t(
          'Pushes go to the Beep app on every phone you are signed in on. Emails and this inbox are unaffected.',
        )}
      >
        <Row
          title={t('Resolved alerts')}
          subtitle={t('Get a push when an alert stops firing')}
        >
          <Switch
            checked={beep.resolved}
            disabled={isSaving}
            onCheckedChange={(resolved) =>
              onChange((current) => ({ ...current, resolved }), { onError })
            }
            aria-label={t('Resolved alerts')}
          />
        </Row>
      </Section>

      {organizations.length > 0 ? (
        <Section
          title={t('Organizations')}
          footer={t(
            'Muted organizations send no pushes, including budget alerts.',
          )}
        >
          {organizations.map((organization) => {
            const snooze = snoozeOf(beep, organization.$id)
            return (
              <Row
                key={organization.$id}
                title={organization.name}
                subtitle={
                  snooze
                    ? `${t('Snoozed until')} ${formatUntil(snooze.until, t)}`
                    : undefined
                }
              >
                <Switch
                  checked={
                    !beep.mutedOrganizationIds.includes(organization.$id)
                  }
                  disabled={isSaving}
                  onCheckedChange={(on) =>
                    onChange(
                      (current) =>
                        withMutedOrganization(current, organization.$id, !on),
                      { onError },
                    )
                  }
                  aria-label={`${t('Pushes from')} ${organization.name}`}
                />
              </Row>
            )
          })}
        </Section>
      ) : null}

      {beep.mutedProjectIds.length > 0 ? (
        <Section title={t('Muted projects')}>
          {beep.mutedProjectIds.map((projectId) => (
            <MutedProjectRow
              key={projectId}
              projectId={projectId}
              disabled={isSaving}
              onUnmute={() =>
                onChange(
                  (current) => withMutedProject(current, projectId, false),
                  { onError },
                )
              }
            />
          ))}
        </Section>
      ) : null}

      <div className="px-4 py-3">
        <Link
          to="/account"
          className="text-[12px] font-medium text-foreground hover:underline"
        >
          {t('Email and account notification settings')}
        </Link>
      </div>
    </div>
  )
}
