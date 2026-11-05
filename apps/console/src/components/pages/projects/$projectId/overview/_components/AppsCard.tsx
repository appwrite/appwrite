import { Plug } from 'lucide-react'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { PlatformContextMenu } from '../../apps/_components/PlatformContextMenu'
import {
  OverviewListCard,
  OverviewListFiller,
  OverviewListFooter,
  OverviewListRow,
  OverviewListRowIcon,
  overviewListBodyHeight,
} from './OverviewListCard'
import type { AddAppKind } from '@/lib/add-app-wizard/types'
import { useAnalytics } from '@/hooks/use-analytics'
import { useT } from '@/lib/i18n/translate'
import {
  getPlatformDisplayName,
  getPlatformIdentifier,
  type ProjectPlatform,
} from '@/lib/utils/platform'

/** Apps and API keys list this many rows before "View all". */
export const OVERVIEW_CONNECT_ROW_LIMIT = 4

/**
 * Rows the side-by-side Apps and API keys cards both make room for: the
 * longer list plus its create row, up to the limit.
 */
export function overviewConnectRows(apps: number, keys: number): number {
  const rows = (count: number) =>
    Math.min(count + 1, OVERVIEW_CONNECT_ROW_LIMIT)
  return Math.max(rows(apps), rows(keys))
}

const ADD_APP_KINDS = [
  'web',
  'react-native',
  'flutter',
  'apple',
  'android',
  'windows',
  'linux',
] as const satisfies readonly AddAppKind[]

export function AppsCard({
  projectId,
  platforms,
  rows,
  onAdd,
  onSelect,
}: {
  projectId: string
  platforms: ProjectPlatform[]
  /** Rows both connect cards make room for (`overviewConnectRows`). */
  rows: number
  onAdd: (kind?: AddAppKind) => void
  onSelect: (platform: ProjectPlatform) => void
}) {
  const t = useT()
  const projectConnect = useProjectConnectDialog()
  const { track } = useAnalytics()
  const trackQuickStart = (option: string) =>
    track('Overview Quick Start Clicked', { resource: 'apps', option })
  const shown = platforms.slice(0, OVERVIEW_CONNECT_ROW_LIMIT)
  const bodyHeight = overviewListBodyHeight(rows)

  if (platforms.length === 0) {
    return (
      <OverviewListCard
        icon={Plug}
        title={t('Apps')}
        bodyHeight={bodyHeight}
        footer={
          <OverviewListFooter
            variant="action"
            label={t('Add app')}
            onClick={() => onAdd()}
            track={{
              event: 'Overview Resource Create Clicked',
              props: {
                resource: 'apps',
                placement: 'empty-card',
                action: 'create',
              },
            }}
          />
        }
      >
        <div className="flex flex-1 flex-col items-center justify-center px-4 py-5 text-center">
          <h4 className="text-[14px] font-medium text-foreground">
            {t('No apps connected')}
          </h4>
          <p className="mt-1 max-w-sm text-[12px] leading-5 text-muted-foreground">
            {t('Add each web, mobile, or desktop app that calls this project.')}
          </p>
          <div className="mt-4 flex flex-wrap justify-center gap-1.5">
            {ADD_APP_KINDS.map((kind) => (
              <button
                key={kind}
                type="button"
                onClick={() => {
                  trackQuickStart(kind)
                  onAdd(kind)
                }}
                data-analytics-track="manual"
                className="flex h-8 items-center gap-1.5 rounded-md border border-border bg-background px-2.5 text-[12px] text-foreground transition-colors hover:bg-muted/60"
              >
                <PlatformIcon platform={kind} size="sm" />
                {getPlatformDisplayName(kind)}
              </button>
            ))}
          </div>
          {projectConnect ? (
            <button
              type="button"
              onClick={() => {
                trackQuickStart('agent')
                projectConnect.openConnect('mcp')
              }}
              data-analytics-track="manual"
              className="mt-3 flex items-center gap-1.5 text-[12px] text-muted-foreground transition-colors hover:text-foreground"
            >
              <McpIcon className="h-3.5 w-3.5" />
              {t('Build with an agent')}
            </button>
          ) : null}
        </div>
      </OverviewListCard>
    )
  }

  return (
    <OverviewListCard
      icon={Plug}
      title={t('Apps')}
      count={platforms.length}
      bodyHeight={bodyHeight}
      action={{
        label: t('Add app'),
        onClick: () => onAdd(),
        track: {
          event: 'Overview Resource Create Clicked',
          props: { resource: 'apps', placement: 'header', action: 'create' },
        },
      }}
      footer={
        <OverviewListFooter
          label={t('View all apps')}
          link={{ to: '/projects/$projectId/apps', params: { projectId } }}
          track={{
            event: 'Overview Resource View All Clicked',
            props: { resource: 'apps' },
          }}
        />
      }
    >
      {shown.map((platform) => {
        const type = platform.type ?? 'web'
        const identifier = getPlatformIdentifier(platform)
        return (
          <PlatformContextMenu
            key={platform.$id}
            projectId={projectId}
            platform={platform}
            onUpdate={onSelect}
          >
            <OverviewListRow
              icon={
                <OverviewListRowIcon>
                  <PlatformIcon platform={type} size="sm" />
                </OverviewListRowIcon>
              }
              title={platform.name || getPlatformDisplayName(type)}
              meta={
                identifier ? (
                  <span className="truncate" dir="ltr">
                    {identifier}
                  </span>
                ) : undefined
              }
              trailing={
                <span className="rounded-md border border-border px-1.5 py-px text-[11px]">
                  {getPlatformDisplayName(type)}
                </span>
              }
              onClick={() => onSelect(platform)}
              track={{
                event: 'Overview Resource Opened',
                props: { resource: 'apps', platform: type },
              }}
            />
          </PlatformContextMenu>
        )
      })}
      {shown.length < OVERVIEW_CONNECT_ROW_LIMIT ? (
        <OverviewListFiller
          label={t('Add app')}
          description={
            rows - shown.length > 1
              ? t(
                  'Add each web, mobile, or desktop app that calls this project.',
                )
              : undefined
          }
          onClick={() => onAdd()}
          track={{
            event: 'Overview Resource Create Clicked',
            props: { resource: 'apps', placement: 'filler', action: 'create' },
          }}
        />
      ) : null}
    </OverviewListCard>
  )
}
