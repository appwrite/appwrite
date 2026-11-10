import { Check, Copy, Key, Pencil, Trash2 } from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { ApiKeyContextMenu } from '../../api-keys/_components/ApiKeyContextMenu'
import type { ApiKey } from '../../shared/ApiKeysList'
import {
  OverviewListCard,
  OverviewListCardSkeleton,
  OverviewListFiller,
  OverviewListFooter,
  OverviewListRow,
  OverviewListRowIcon,
  overviewListBodyHeight,
} from './OverviewListCard'
import { OVERVIEW_CONNECT_ROW_LIMIT } from './AppsCard'
import { useAnalytics } from '@/hooks/use-analytics'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

const SERVER_LANGUAGES = [
  { id: 'node', name: 'Node.js' },
  { id: 'python', name: 'Python' },
  { id: 'php', name: 'PHP' },
  { id: 'ruby', name: 'Ruby' },
  { id: 'go', name: 'Go' },
  { id: 'deno', name: 'Deno' },
  { id: 'bun', name: 'Bun' },
  { id: 'dart', name: 'Dart' },
  { id: 'swift', name: 'Swift' },
  { id: 'kotlin', name: 'Kotlin' },
  { id: 'java', name: 'Java' },
  { id: 'dotnet', name: '.NET' },
] as const

const EXPIRING_SOON_MS = 7 * 24 * 60 * 60 * 1000

function expirationStatus(expire: string | null) {
  if (!expire) return null
  const remaining = new Date(expire).getTime() - Date.now()
  if (remaining < 0) return 'expired'
  if (remaining <= EXPIRING_SOON_MS) return 'expiring'
  return null
}

/** Enough of the secret to tell keys apart without showing it. */
function shortenKey(key: string) {
  return `${key.slice(0, 12)}…${key.slice(-4)}`
}

export function ApiKeysCard({
  projectId,
  apiKeys,
  isLoading,
  rows,
  copiedField,
  onCreate,
  onUpdate,
  onDelete,
  onCopy,
}: {
  projectId: string
  apiKeys: ApiKey[]
  isLoading: boolean
  /** Rows both connect cards make room for (`overviewConnectRows`). */
  rows: number
  copiedField: string | null
  onCreate: () => void
  onUpdate: (keyId: string) => void
  onDelete: (keyId: string) => void
  onCopy: (text: string, field: string) => void
}) {
  const t = useT()
  const { track } = useAnalytics()
  const bodyHeight = overviewListBodyHeight(rows)

  if (isLoading) {
    return <OverviewListCardSkeleton bodyHeight={bodyHeight} rows={2} />
  }

  if (apiKeys.length === 0) {
    return (
      <OverviewListCard
        icon={Key}
        title={t('API keys')}
        bodyHeight={bodyHeight}
        footer={
          <OverviewListFooter
            variant="action"
            label={t('Create API key')}
            onClick={onCreate}
            track={{
              event: 'Overview Resource Create Clicked',
              props: {
                resource: 'api-keys',
                placement: 'empty-card',
                action: 'create',
              },
            }}
          />
        }
      >
        <div className="flex flex-1 flex-col items-center justify-center px-4 py-5 text-center">
          <h4 className="text-[14px] font-medium text-foreground">
            {t('No API keys created')}
          </h4>
          <p className="mt-1 max-w-sm text-[12px] leading-5 text-muted-foreground">
            {t('Give your servers and scripts scoped access to this project.')}
          </p>
          <div className="mt-4 flex flex-wrap justify-center gap-1.5">
            {SERVER_LANGUAGES.map(({ id, name }) => (
              <button
                key={id}
                type="button"
                onClick={() => {
                  track('Overview Quick Start Clicked', {
                    resource: 'api-keys',
                    option: id,
                  })
                  onCreate()
                }}
                data-analytics-track="manual"
                className="flex h-8 items-center gap-1.5 rounded-md border border-border bg-background px-2.5 text-[12px] text-foreground transition-colors hover:bg-muted/60"
              >
                <LanguageIcon language={id} size="sm" />
                {name}
              </button>
            ))}
          </div>
        </div>
      </OverviewListCard>
    )
  }

  const shown = apiKeys.slice(0, OVERVIEW_CONNECT_ROW_LIMIT)

  return (
    <OverviewListCard
      icon={Key}
      title={t('API keys')}
      count={apiKeys.length}
      bodyHeight={bodyHeight}
      action={{
        label: t('Create API key'),
        onClick: onCreate,
        track: {
          event: 'Overview Resource Create Clicked',
          props: {
            resource: 'api-keys',
            placement: 'header',
            action: 'create',
          },
        },
      }}
      footer={
        <OverviewListFooter
          label={t('View all API keys')}
          link={{ to: '/projects/$projectId/api-keys', params: { projectId } }}
          track={{
            event: 'Overview Resource View All Clicked',
            props: { resource: 'api-keys' },
          }}
        />
      }
    >
      {shown.map((apiKey) => {
        const status = expirationStatus(apiKey.expire)
        const copyField = `apiKey-${apiKey.id}`
        return (
          <ApiKeyContextMenu
            key={apiKey.id}
            projectId={projectId}
            apiKey={{
              id: apiKey.id,
              name: apiKey.name,
              key: apiKey.key,
              scopes: apiKey.scopes,
              expire: apiKey.expire,
            }}
            onUpdate={onUpdate}
          >
            <OverviewListRow
              icon={
                <OverviewListRowIcon>
                  <Key className="h-4 w-4" />
                </OverviewListRowIcon>
              }
              title={apiKey.name}
              meta={
                <>
                  <code dir="ltr" className="truncate font-mono text-[11px]">
                    {shortenKey(apiKey.key)}
                  </code>
                  <span aria-hidden className="text-muted-foreground/50">
                    ·
                  </span>
                  <span className="shrink-0 whitespace-nowrap">
                    {apiKey.lastUsed ? (
                      <>
                        {t('Last used')}{' '}
                        <DateTooltip
                          date={apiKey.lastUsed}
                          className="text-[12px] text-muted-foreground"
                        />
                      </>
                    ) : (
                      t('Never used')
                    )}
                  </span>
                </>
              }
              trailing={
                <>
                  {status === 'expired' ? (
                    <Badge variant="error" className="shrink-0 text-[10px]">
                      {t('Expired')}
                    </Badge>
                  ) : status === 'expiring' ? (
                    <Badge variant="warning" className="shrink-0 text-[10px]">
                      {t('Expires soon')}
                    </Badge>
                  ) : null}
                  <button
                    type="button"
                    onClick={(event) => {
                      event.stopPropagation()
                      onCopy(apiKey.key, copyField)
                    }}
                    className="cursor-pointer rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    title={t('Copy key')}
                    aria-label={t('Copy key')}
                  >
                    {copiedField === copyField ? (
                      <Check className="h-3.5 w-3.5 text-emerald-500" />
                    ) : (
                      <Copy className="h-3.5 w-3.5" />
                    )}
                  </button>
                  <span onClick={(event) => event.stopPropagation()}>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <RowActionsMenuTrigger />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem
                          onSelect={() =>
                            openDialogAfterOverlayCloses(() =>
                              onUpdate(apiKey.id),
                            )
                          }
                        >
                          <MenuItemContent icon={Pencil}>
                            {t('Update')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          onSelect={() =>
                            openDialogAfterOverlayCloses(() =>
                              onDelete(apiKey.id),
                            )
                          }
                        >
                          <MenuItemContent icon={Trash2}>
                            {t('Delete')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </span>
                </>
              }
              onClick={() => onUpdate(apiKey.id)}
              track={{
                event: 'Overview Resource Opened',
                props: { resource: 'api-keys' },
              }}
            />
          </ApiKeyContextMenu>
        )
      })}
      {shown.length < OVERVIEW_CONNECT_ROW_LIMIT ? (
        <OverviewListFiller
          label={t('Create API key')}
          description={
            rows - shown.length > 1
              ? t(
                  'Use a key per server or environment, with only the scopes it needs.',
                )
              : undefined
          }
          onClick={onCreate}
          track={{
            event: 'Overview Resource Create Clicked',
            props: {
              resource: 'api-keys',
              placement: 'filler',
              action: 'create',
            },
          }}
        />
      ) : null}
    </OverviewListCard>
  )
}
