import { useState, useMemo } from 'react'
import { Plug2 } from 'lucide-react'
import { useNavigate, useParams } from '@tanstack/react-router'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
  RESOURCE_CARD_SHELL_CLASSNAME,
} from '../shared/ResourceCard'
import { ServiceHeader } from '../shared/ServiceHeader'
import { PlatformDrawer } from './_components/PlatformDrawer'
import { PlatformContextMenu } from './_components/PlatformContextMenu'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import {
  usePlatforms,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canCreatePlatform } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  getPlatformDisplayName,
  getPlatformIdentifier,
  getPlatformSearchText,
  type ProjectPlatform,
} from '@/lib/utils/platform'
import type { AddAppKind } from '@/lib/add-app-wizard/types'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type AppsInitialData = {
  platforms: ProjectPlatform[]
}

type ViewProps = {
  initialData?: AppsInitialData
}

const supportedPlatforms = [
  { id: 'web', platform: 'web' },
  { id: 'react-native', platform: 'react-native' },
  { id: 'flutter', platform: 'flutter' },
  { id: 'apple', platform: 'apple' },
  { id: 'android', platform: 'android' },
  { id: 'windows', platform: 'windows' },
  { id: 'linux', platform: 'linux' },
] as const

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const projectConnect = useProjectConnectDialog()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const [searchValue, setSearchValue] = useState('')
  const [platformDrawerOpen, setPlatformDrawerOpen] = useState(false)
  const [selectedPlatform, setSelectedPlatform] =
    useState<ProjectPlatform | null>(null)

  const { platforms: platformsFromHook, isLoading } = usePlatforms(projectId)
  const platforms =
    platformsFromHook.length > 0
      ? platformsFromHook
      : isLoading
        ? (initialData?.platforms ?? [])
        : platformsFromHook
  const showLoading = isLoading && platforms.length === 0 && !initialData

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreatePlatform(access, features)

  const filteredPlatforms = useMemo(() => {
    if (!searchValue.trim()) return platforms
    const q = searchValue.toLowerCase()
    return platforms.filter(
      (p) =>
        (p.name || '').toLowerCase().includes(q) ||
        getPlatformSearchText(p).toLowerCase().includes(q) ||
        getPlatformDisplayName(p.type || '')
          .toLowerCase()
          .includes(q),
    )
  }, [platforms, searchValue])

  const goToAddAppWizard = (kind?: AddAppKind) => {
    if (!projectId) return
    navigate({
      to: '/projects/$projectId/apps/add',
      params: { projectId },
      search: kind
        ? { kind, configureStep: 'details' as const }
        : {},
    })
  }

  const handlePlatformClick = (platform: ProjectPlatform) => {
    setSelectedPlatform(platform)
    setPlatformDrawerOpen(true)
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Apps')}
        searchPlaceholder={t('Search apps...')}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        createLabel={t('Add app')}
        createAnalyticsAction="add-platform"
        onCreate={() => goToAddAppWizard()}
        createDisabled={noCreatePermission}
        createDisabledTooltip={
          noCreatePermission
            ? t("You don't have permission to add apps.")
            : undefined
        }
        showFilters={false}
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {showLoading ? (
          <div className="rounded-xl border border-border bg-card/50">
            <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
              {Array.from({ length: 6 }).map((_, i) => (
                <div
                  key={i}
                  className="flex items-center gap-4 rounded-lg border border-border/50 bg-muted/20 p-4"
                >
                  <div className="h-10 w-10 shrink-0 animate-pulse rounded-lg bg-muted" />
                  <div className="min-w-0 flex-1 space-y-2">
                    <div className="h-4 w-24 animate-pulse rounded bg-muted" />
                    <div className="h-3 w-32 animate-pulse rounded bg-muted" />
                  </div>
                </div>
              ))}
            </div>
          </div>
        ) : filteredPlatforms.length === 0 ? (
          platforms.length === 0 ? (
            <EmptyState icon={Plug2} variant="card" isEmpty={true}>
              <div className="flex flex-col items-center text-center">
                <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                  <Plug2 className="h-6 w-6 text-muted-foreground" />
                </div>
                <h3 className="mb-2 text-[15px] font-medium text-foreground">
                  {t('No apps connected')}
                </h3>
                <p className="mb-6 max-w-sm text-[13px] text-muted-foreground">
                  {t(
                    'Connect your first app to start building with Appwrite. Add web apps, mobile apps, or server SDKs to get started.', // pragma: allowlist secret
                  )}
                </p>
                <div className="my-6 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                  <div className="h-px flex-1 bg-border" />
                  <span className="font-medium text-foreground/80">
                    {t('Connect with your stack')}
                  </span>
                  <div className="h-px flex-1 bg-border" />
                </div>
                <div className="flex w-full flex-wrap justify-center gap-2">
                  {supportedPlatforms.map(({ id, platform }) => (
                    <button
                      key={id}
                      type="button"
                      onClick={() => goToAddAppWizard(id as AddAppKind)}
                      className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-4 py-2 text-[13px] font-medium text-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                      <PlatformIcon platform={platform} size="sm" />
                      <span>{getPlatformDisplayName(platform)}</span>
                    </button>
                  ))}
                </div>
                {projectConnect ? (
                  <>
                    <div className="my-6 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                      <div className="h-px flex-1 bg-border" />
                      <span className="font-medium text-foreground/80">
                        {t('or')}
                      </span>
                      <div className="h-px flex-1 bg-border" />
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      size="lg"
                      className="gap-1.5"
                      onClick={() => projectConnect.openConnect('mcp')}
                    >
                      <McpIcon className="h-4 w-4" />
                      {t('Build with an agent')}
                    </Button>
                  </>
                ) : null}
              </div>
            </EmptyState>
          ) : (
            <EmptyState
              icon={Plug2}
              variant="card"
              isEmpty={false}
              hasFilters={true}
              title={t('No apps match your search')}
              description={t('Try a different search term.')}
            />
          )
        ) : (
          <div className={RESOURCE_CARD_GRID_CLASSNAME}>
            {filteredPlatforms.map((platform: ProjectPlatform) => {
              const platformType = platform.type || 'web'
              const displayName =
                platform.name || getPlatformDisplayName(platformType)
              const identifier = getPlatformIdentifier(platform)
              const initialIcon =
                platformType === 'web'
                  ? (platform.$id?.charCodeAt(0) ?? 0) % 2 === 0
                    ? 'ts'
                    : 'js'
                  : undefined

              return (
                <PlatformContextMenu
                  key={platform.$id}
                  projectId={projectId ?? ''}
                  platform={platform}
                  onUpdate={handlePlatformClick}
                >
                  <button
                    type="button"
                    onClick={() => handlePlatformClick(platform)}
                    className={cn(
                      RESOURCE_CARD_PADDED_CLASSNAME,
                      RESOURCE_CARD_INTERACTIVE_CLASSNAME,
                      'flex items-center gap-4 text-start',
                      RESOURCE_CARD_SHELL_CLASSNAME,
                    )}
                  >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40 text-muted-foreground transition-colors group-hover:bg-accent group-hover:text-foreground">
                      <PlatformIcon
                        platform={platformType}
                        size="md"
                        initialIcon={initialIcon}
                      />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p
                        className="truncate text-[14px] font-medium text-foreground"
                        title={displayName}
                      >
                        {displayName}
                      </p>
                      {identifier && (
                        <p
                          className="truncate text-[12px] text-muted-foreground"
                          title={identifier}
                        >
                          {identifier}
                        </p>
                      )}
                    </div>
                  </button>
                </PlatformContextMenu>
              )
            })}
          </div>
        )}
      </div>

      <PlatformDrawer
        open={platformDrawerOpen}
        onOpenChange={(open) => {
          setPlatformDrawerOpen(open)
          if (!open) setSelectedPlatform(null)
        }}
        projectId={projectId ?? ''}
        platform={selectedPlatform}
      />
    </div>
  )
}
