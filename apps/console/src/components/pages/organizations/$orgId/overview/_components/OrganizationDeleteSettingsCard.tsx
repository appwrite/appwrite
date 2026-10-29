import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { InitialsAvatar, PhotoAvatar } from '@/components/global/shared/Avatar'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import {
  activeProjectsQueryOptions,
  deleteOrganization,
  mapOrganizationMembershipsToTeamMembers,
  organizationMembershipsQueryOptions,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export function OrganizationDeleteSettingsCard({
  organizationId,
  organizationName,
  canDelete,
  disabledTooltip,
  onDeleted,
}: {
  organizationId: string
  organizationName: string
  canDelete: boolean
  disabledTooltip?: string
  onDeleted?: () => void
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [confirmation, setConfirmation] = useState('')

  const membershipsQuery = useQuery(
    organizationMembershipsQueryOptions(organizationId, 0, 25),
  )
  const memberships = mapOrganizationMembershipsToTeamMembers(
    membershipsQuery.data,
    organizationId,
  )
  const membershipsTotal = membershipsQuery.data?.total ?? memberships.length

  const projectsQuery = useQuery(activeProjectsQueryOptions(organizationId, 0, 100))
  const activeProjects = projectsQuery.data?.projects ?? []
  const activeProjectsTotal = projectsQuery.data?.total ?? activeProjects.length

  const deleteMutation = useMutation({
    mutationFn: () => deleteOrganization(organizationId),
    onSuccess: async () => {
      toast.success(t('Organization deleted successfully'))
      setConfirmOpen(false)
      setConfirmation('')
      await queryClient.invalidateQueries({
        queryKey: ['organizations', 'console'],
      })
      await queryClient.removeQueries({
        queryKey: ['organization', organizationId],
      })
      onDeleted?.()
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to delete organization')))
    },
  })

  const deleteButton = (
    <Button
      variant="destructive"
      size="sm"
      className="h-9 text-[13px]"
      disabled={!canDelete || deleteMutation.isPending}
      onClick={() => setConfirmOpen(true)}
    >
      {t('Delete organization')}
    </Button>
  )

  return (
    <>
      <div className="overflow-hidden rounded-xl border border-destructive/50 bg-card/50">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Delete organization')}
          </h3>
        </div>
        <div className="border-t border-destructive/20" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Permanently delete this organization and all associated data. This action cannot be undone.',
            )}
          </p>
          {organizationName ? (
            <div className="mt-4 flex items-center gap-3">
              <InitialsAvatar name={organizationName} size="md" />
              <div className="min-w-0 flex-1">
                <p className="truncate text-[14px] font-medium text-foreground">
                  {organizationName}
                </p>
                <p className="text-[12px] text-muted-foreground">
                  {membershipsTotal}{' '}
                  {membershipsTotal !== 1 ? t('members') : t('member')} •{' '}
                  {activeProjectsTotal}{' '}
                  {activeProjectsTotal !== 1 ? t('projects') : t('project')}
                </p>
              </div>
              {memberships.length > 0 ? (
                <div className="flex items-center gap-2">
                  <div className="flex -space-x-2">
                    {memberships.slice(0, 4).map((member, index) => (
                      <div
                        key={member.$id}
                        className="relative h-6 w-6 shrink-0 overflow-hidden rounded-full ring-2 ring-background"
                        style={{ zIndex: 4 - index }}
                        title={member.userName}
                      >
                        <PhotoAvatar
                          userId={member.userId}
                          name={member.userName}
                          size="sm"
                          className="h-full w-full"
                        />
                      </div>
                    ))}
                    {membershipsTotal > 4 ? (
                      <div
                        className="relative flex h-6 w-6 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted text-[10px] font-medium leading-none text-muted-foreground ring-2 ring-background"
                        style={{ zIndex: 0 }}
                      >
                        {`+${membershipsTotal - 4}`}
                      </div>
                    ) : null}
                  </div>
                </div>
              ) : null}
            </div>
          ) : null}
        </div>
        <div className="border-t border-destructive/20 bg-destructive/5 px-6 py-4">
          {!canDelete && disabledTooltip ? (
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">{deleteButton}</span>
                </TooltipTrigger>
                <TooltipContent className="max-w-xs text-xs">
                  {disabledTooltip}
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            deleteButton
          )}
        </div>
      </div>

      <Dialog
        open={confirmOpen}
        onOpenChange={(open) => {
          if (deleteMutation.isPending) return
          setConfirmOpen(open)
          if (!open) setConfirmation('')
        }}
      >
        <DialogContent
          className="z-[130] p-0 sm:max-w-md"
          overlayClassName="z-[120]"
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete organization')}</DialogTitle>
            <DialogDescription className="mt-2 text-[13px]">
              {t('Are you sure you want to delete')}{' '}
              <span className="font-medium text-foreground">
                {organizationName}
              </span>{' '}
              {t(
                'and all its projects, databases, and files? This action cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="mb-4 mt-2 rounded-lg border border-border bg-muted/50 p-3">
              <div className="flex items-center gap-3">
                <InitialsAvatar name={organizationName} size="sm" />
                <div>
                  <p className="text-[13px] font-medium text-foreground">
                    {organizationName}
                  </p>
                  <p className="text-[11px] text-muted-foreground">
                    {membershipsTotal}{' '}
                    {membershipsTotal !== 1 ? t('members') : t('member')}{' '}
                    {t('will lose access')} • {activeProjectsTotal}{' '}
                    {activeProjectsTotal !== 1 ? t('projects') : t('project')}{' '}
                    {t('will be deleted')}
                  </p>
                </div>
              </div>
            </div>
            {activeProjects.length > 0 ? (
              <div className="mb-4">
                <p className="text-[12px] text-muted-foreground">
                  {t('Projects that will be deleted:')}{' '}
                  {activeProjects.slice(0, 5).map((project, index) => (
                    <span key={project.$id}>
                      {index > 0 ? ', ' : null}
                      <span
                        className="font-medium text-foreground"
                        title={project.name}
                      >
                        {formatProjectNameForDisplay(project.name)}
                      </span>
                    </span>
                  ))}
                  {activeProjectsTotal > 5 ? (
                    <span>
                      {' '}
                      and {activeProjectsTotal - 5} more
                    </span>
                  ) : null}
                </p>
              </div>
            ) : null}
            <label className="text-[13px] text-muted-foreground">
              {t('Type')}{' '}
              <span className="rounded bg-muted px-1.5 py-0.5 font-mono font-medium text-foreground">
                {organizationName}
              </span>{' '}
              {t('to confirm')}
            </label>
            <Input
              value={confirmation}
              onChange={(event) => setConfirmation(event.target.value)}
              placeholder={t('Enter organization name')}
              className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-red-500/50 focus:ring-0"
            />
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => {
                setConfirmOpen(false)
                setConfirmation('')
              }}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                confirmation !== organizationName || deleteMutation.isPending
              }
              onClick={() => deleteMutation.mutate()}
            >
              {t('Delete organization')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
