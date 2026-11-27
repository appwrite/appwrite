import { useMemo } from 'react'
import { Outlet, useLocation, useNavigate } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { Bell, Crosshair, Settings } from 'lucide-react'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { MONITOR_SETTINGS_CARD_INDEX } from '@/lib/settings-search/monitor-settings-cards'
import { isProbeType } from '@/lib/alerts/probes'
import { monitorQueryOptions } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

const SECTIONS = [
  {
    id: 'general',
    label: 'General',
    icon: Settings,
    to: '/projects/$projectId/monitors/$monitorId/settings',
    keywords: ['name', 'status', 'pause', 'delete', 'id', 'created'],
  },
  {
    id: 'condition',
    label: 'Condition',
    icon: Crosshair,
    to: '/projects/$projectId/monitors/$monitorId/settings/condition',
    keywords: ['metric', 'threshold', 'window', 'target', 'url', 'interval'],
  },
  {
    id: 'notifications',
    label: 'Notifications',
    icon: Bell,
    to: '/projects/$projectId/monitors/$monitorId/settings/notifications',
    keywords: ['notify', 'roles', 'escalation', 'severity', 'cooldown'],
  },
] as const

/** The monitor's settings, a section at a time, as functions and sites lay theirs out. */
export function MonitorSettingsLayout({
  projectId,
  monitorId,
}: {
  projectId: string
  monitorId: string
}) {
  const t = useT()
  const navigate = useNavigate()
  const location = useLocation()
  const { data: monitor } = useQuery(monitorQueryOptions(projectId, monitorId))
  const probe = !!monitor && isProbeType(monitor.type)
  const path = location.pathname.replace(/\/$/, '')
  const active =
    SECTIONS.find((section) => path.endsWith(`/settings/${section.id}`))?.id ??
    'general'

  const navItems = useMemo(
    () =>
      SECTIONS.map((section) => ({
        id: section.id,
        // A synthetic check has a target where a usage monitor has a condition.
        label: t(
          section.id === 'condition' && probe ? 'Target' : section.label,
        ),
        icon: section.icon,
        keywords: [...section.keywords],
        to: section.to,
        params: { projectId, monitorId },
      })),
    [t, probe, projectId, monitorId],
  )

  return (
    <SettingsLayoutShell
      navItems={navItems}
      activeSectionId={active}
      cardIndex={MONITOR_SETTINGS_CARD_INDEX}
      onNavigateToSection={(sectionId) => {
        const section = SECTIONS.find((entry) => entry.id === sectionId)
        if (section) {
          void navigate({ to: section.to, params: { projectId, monitorId } })
        }
      }}
    >
      <Outlet />
    </SettingsLayoutShell>
  )
}
