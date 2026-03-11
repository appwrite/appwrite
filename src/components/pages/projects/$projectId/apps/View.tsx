import { useState, useMemo } from 'react'
import { Plug2 } from 'lucide-react'
import { useParams } from '@tanstack/react-router'
import { ServiceHeader } from '../shared/ServiceHeader'
import { ConnectProject } from '../shared/ConnectProject'
import { PlatformDrawer } from './_components/PlatformDrawer'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  usePlatforms,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canCreatePlatform } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getPlatformDisplayName } from '@/lib/utils/platform'
import type { Models } from '@appwrite.io/console'

export type AppsInitialData = {
  project?: Awaited<
    ReturnType<typeof import('@/lib/react-query/hooks').fetchProject>
  >
  platforms: Models.Platform[]
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

const platformToSdkId: Record<string, string> = {
  web: 'web',
  'react-native': 'web',
  flutter: 'flutter',
  apple: 'apple',
  android: 'android',
  windows: 'web',
  linux: 'web',
}

export function View({ initialData }: ViewProps = {}) {
  const { projectId } = useParams({ strict: false })
  const [searchValue, setSearchValue] = useState('')
  const [connectDialogOpen, setConnectDialogOpen] = useState(false)
  const [connectInitialSdk, setConnectInitialSdk] = useState('web')
  const [platformDrawerOpen, setPlatformDrawerOpen] = useState(false)
  const [selectedPlatform, setSelectedPlatform] = useState<Models.Platform | null>(
    null,
  )

  // Use initialData on first paint so no loading skeleton flash
  const { platforms: platformsFromHook, isLoading } = usePlatforms(projectId)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const platforms = platformsFromHook?.length
    ? platformsFromHook
    : (initialData?.platforms ?? [])
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
        (p.hostname || '').toLowerCase().includes(q) ||
        (p.key || '').toLowerCase().includes(q) ||
        getPlatformDisplayName(p.type || '')
          .toLowerCase()
          .includes(q),
    )
  }, [platforms, searchValue])

  const handleAddApp = (platformId?: string) => {
    setConnectInitialSdk(platformToSdkId[platformId ?? 'web'] ?? 'web')
    setConnectDialogOpen(true)
  }

  const handlePlatformClick = (platform: Models.Platform) => {
    setSelectedPlatform(platform)
    setPlatformDrawerOpen(true)
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title="Apps"
        searchPlaceholder="Search apps..."
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        createLabel="Add app"
        onCreate={handleAddApp}
        createDisabled={noCreatePermission}
        createDisabledTooltip={
          noCreatePermission
            ? "You don't have permission to add apps."
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
                  No apps connected
                </h3>
                <p className="mb-6 max-w-sm text-[13px] text-muted-foreground">
                  Connect your first app to start building with Appwrite. Add
                  web apps, mobile apps, or server SDKs to get started.
                </p>
                <div className="my-6 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                  <div className="h-px flex-1 bg-border" />
                  <span className="font-medium text-foreground/80">
                    Connect with your stack
                  </span>
                  <div className="h-px flex-1 bg-border" />
                </div>
                <div className="flex w-full flex-wrap justify-center gap-2">
                  {supportedPlatforms.map(({ id, platform }) => (
                    <button
                      key={id}
                      type="button"
                      onClick={() => handleAddApp(id)}
                      className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-4 py-2 text-[13px] font-medium text-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                      <PlatformIcon platform={platform} size="sm" />
                      <span>{getPlatformDisplayName(platform)}</span>
                    </button>
                  ))}
                </div>
              </div>
            </EmptyState>
          ) : (
            <EmptyState
              icon={Plug2}
              variant="card"
              isEmpty={false}
              hasFilters={true}
              title="No apps match your search"
              description="Try a different search term."
            />
          )
        ) : (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {filteredPlatforms.map((platform: Models.Platform) => {
              const platformType = platform.type || 'web'
              const displayName =
                platform.name || getPlatformDisplayName(platformType)
              const identifier =
                platform.hostname || platform.key || platform.store || ''
              const initialIcon =
                platformType === 'web'
                  ? (platform.$id?.charCodeAt(0) ?? 0) % 2 === 0
                    ? 'ts'
                    : 'js'
                  : undefined

              return (
                <button
                  key={platform.$id}
                  type="button"
                  onClick={() => handlePlatformClick(platform)}
                  className="group flex cursor-pointer items-center gap-4 rounded-xl border border-border bg-card/50 p-4 text-left transition-colors hover:border-border hover:bg-card"
                >
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground transition-colors group-hover:bg-accent group-hover:text-foreground">
                    <PlatformIcon
                      platform={platformType}
                      size="md"
                      initialIcon={initialIcon}
                    />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-[14px] font-medium text-foreground">
                      {displayName}
                    </p>
                    {identifier && (
                      <p className="text-[12px] text-muted-foreground">
                        {identifier}
                      </p>
                    )}
                  </div>
                </button>
              )
            })}
          </div>
        )}
      </div>

      <ConnectProject
        open={connectDialogOpen}
        onOpenChange={setConnectDialogOpen}
        projectId={projectId ?? ''}
        initialSdk={connectInitialSdk}
      />

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
