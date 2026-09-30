import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Copy, FileJson, Layers, Pencil, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { getVideosServiceTabs } from '../View'
import { CreateProfile } from '../_components/CreateProfile'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateVideo } from '@/lib/console-access-checks'
import {
  useDeleteVideoProfile,
  useOrganizationScopes,
  useProject,
  useVideoProfiles,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'

const HEAD_CLASSNAME =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const [searchValue, setSearchValue] = useState('')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingProfile, setEditingProfile] =
    useState<Models.VideoProfile | null>(null)
  const [deletingProfile, setDeletingProfile] =
    useState<Models.VideoProfile | null>(null)

  const { data, isLoading, error } = useVideoProfiles(projectId)
  const deleteMutation = useDeleteVideoProfile(projectId)

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canCreateVideo(access, features)

  const tabs: Tab[] = useMemo(
    () =>
      getVideosServiceTabs(projectId).map((tab) => ({
        ...tab,
        label: t(tab.label),
      })),
    [projectId, t],
  )

  const profiles = useMemo(() => {
    const all = [...(data?.profiles ?? [])].sort(
      (a, b) => b.width * b.height - a.width * a.height,
    )
    const query = searchValue.trim().toLowerCase()
    if (!query) return all
    return all.filter(
      (profile) =>
        profile.name.toLowerCase().includes(query) ||
        profile.$id.toLowerCase().includes(query),
    )
  }, [data?.profiles, searchValue])

  const openCreate = () => {
    setEditingProfile(null)
    setDialogOpen(true)
  }

  const openUpdate = (profile: Models.VideoProfile) => {
    setEditingProfile(profile)
    setDialogOpen(true)
  }

  const confirmDelete = () => {
    if (!deletingProfile) return
    deleteMutation.mutate(deletingProfile.$id, {
      onSuccess: () => {
        toast.success(t('Profile deleted'))
        setDeletingProfile(null)
      },
      onError: (err) => {
        toast.error(getErrorMessage(err) || t('Failed to delete profile'))
      },
    })
  }

  const totalProfiles = data?.profiles?.length ?? 0

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Videos')}
        tabs={tabs}
        activeTab="profiles"
        searchPlaceholder={t('Search profiles...')}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        createLabel={t('Create profile')}
        onCreate={openCreate}
        createDisabled={!canWrite}
        createDisabledTooltip={
          !canWrite
            ? t("You don't have permission to manage video profiles.")
            : undefined
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {error && totalProfiles === 0 ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-sm text-muted-foreground">
              {getErrorMessage(error) || t('Failed to load profiles')}
            </p>
          </div>
        ) : isLoading && totalProfiles === 0 ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              {t('Loading profiles...')}
            </p>
          </div>
        ) : profiles.length === 0 ? (
          totalProfiles > 0 ? (
            <EmptyState
              icon={Layers}
              isEmpty={false}
              hasFilters
              variant="card"
            />
          ) : (
            <EmptyState
              icon={Layers}
              title={t('No profiles')}
              description={t(
                'Create a profile to choose the resolution and bitrate of your renditions.',
              )}
              isEmpty
              hasFilters={false}
              variant="card"
            />
          )
        ) : (
          <div className="overflow-hidden rounded-lg border border-border bg-card">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className={HEAD_CLASSNAME}>{t('Name')}</TableHead>
                  <TableHead className={HEAD_CLASSNAME}>
                    {t('Resolution')}
                  </TableHead>
                  <TableHead className={HEAD_CLASSNAME}>
                    {t('Video bitrate')}
                  </TableHead>
                  <TableHead className={HEAD_CLASSNAME}>
                    {t('Audio bitrate')}
                  </TableHead>
                  <TableHead className={HEAD_CLASSNAME}>
                    {t('Created')}
                  </TableHead>
                  <TableHead
                    className={`${HEAD_CLASSNAME} text-end w-[60px]`}
                  />
                </TableRow>
              </TableHeader>
              <TableBody>
                {profiles.map((profile) => (
                  <TableRow key={profile.$id}>
                    <TableCell className="px-4 py-3">
                      <div className="flex min-w-0 flex-col gap-1">
                        <span className="truncate text-[13px] font-medium">
                          {profile.name}
                        </span>
                        <CopyableId id={profile.$id} size="xs" maxWidth={140} />
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      {formatResolution(profile.width, profile.height)}
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      {formatBitrate(profile.videoBitRate, 'kbps')}
                    </TableCell>
                    <TableCell className="px-4 py-3 font-mono text-[13px]">
                      {formatBitrate(profile.audioBitRate, 'kbps')}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip date={profile.$createdAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            aria-label={`${t('Actions for')} ${profile.name}`}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          <DropdownMenuItem
                            disabled={!canWrite}
                            onClick={() => openUpdate(profile)}
                          >
                            <MenuItemContent icon={Pencil}>
                              {t('Update')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuSub>
                            <DropdownMenuSubTrigger>
                              <MenuItemIcon icon={Copy} />
                              {t('Copy')}
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent className="w-44">
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(t('ID'), profile.$id)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy ID')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(t('Name'), profile.name)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy name')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyResourceAsJson(() => profile)
                                }
                              >
                                <MenuItemContent icon={FileJson}>
                                  {t('Copy as JSON')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuSubContent>
                          </DropdownMenuSub>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            disabled={!canWrite}
                            onClick={() => setDeletingProfile(profile)}
                          >
                            <MenuItemContent icon={Trash2}>
                              {t('Delete')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </div>

      <CreateProfile
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        projectId={projectId}
        profile={editingProfile}
      />
      <ConfirmActionDialog
        open={!!deletingProfile}
        onOpenChange={(open) => {
          if (!open) setDeletingProfile(null)
        }}
        title={t('Delete profile')}
        description={`${t('Delete')} "${deletingProfile?.name ?? ''}"? ${t('Renditions already encoded with this profile stay playable. This action cannot be undone.')}`}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={confirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </div>
  )
}
