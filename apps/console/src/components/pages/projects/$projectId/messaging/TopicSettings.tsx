import { useState, useMemo } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import { Hash, Trash2 } from 'lucide-react'
import {
  useTopic,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canShowTopicSettingsTab } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'

export function View({
  initialTopic,
}: {
  initialTopic?: Models.Topic
} = {}) {
  const t = useT()
  const { projectId, topicId } = useParams({
    strict: false,
  })
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: topicFromHook, isLoading: topicLoading } = useTopic(
    projectId,
    topicId,
    initialTopic,
  )
  const topic = topicFromHook ?? initialTopic
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettingsTab = canShowTopicSettingsTab(access, features)

  const [name, setName] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Initialize form when topic loads
  useMemo(() => {
    if (topic) {
      setName(topic.name || '')
    }
  }, [topic])

  // Update name mutation
  const updateNameMutation = useMutation({
    mutationFn: async (name: string) => {
      if (!projectId || !topicId) {
        throw new Error('Project ID and Topic ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.updateTopic({
        topicId,
        name,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['topic', 'project', projectId, topicId],
      })
      toast.success(t('Topic name updated successfully'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to update topic name'))
    },
  })

  // Delete topic mutation
  const deleteTopicMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !topicId) {
        throw new Error('Project ID and Topic ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.deleteTopic({ topicId })
    },
    onSuccess: async () => {
      // Refetch topics list so the list view shows updated data (uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['topics', 'project', projectId],
      })
      toast.success(t('Topic deleted successfully'))
      navigate({
        to: '/projects/$projectId/messaging/topics',
        params: { projectId: projectId! },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete topic'))
    },
  })

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/messaging/topics/$topicId',
      params: { projectId: projectId!, topicId: topicId! },
    })
  }

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const topicIndex = pathParts.findIndex(
      (part, idx) => part === 'topics' && pathParts[idx + 1] === topicId,
    )

    if (topicIndex >= 0 && pathParts[topicIndex + 2]) {
      const tabFromPath = pathParts[topicIndex + 2]
      if (tabFromPath === 'settings') {
        return 'settings'
      }
    }

    return 'subscribers'
  }, [location.pathname, topicId])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'subscribers',
        label: t('Subscribers'),
        to: '/projects/$projectId/messaging/topics/$topicId',
        params: {
          projectId: projectId as string,
          topicId: topicId as string,
        },
      },
      ...(showSettingsTab
        ? [
            {
              id: 'settings' as const,
              label: t('Settings'),
              to: '/projects/$projectId/messaging/topics/$topicId/settings',
              params: {
                projectId: projectId as string,
                topicId: topicId as string,
              },
            },
          ]
        : []),
    ],
    [projectId, topicId, showSettingsTab, t],
  )

  if (topicLoading && !initialTopic) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">{t('Loading topic...')}</p>
        </div>
      </div>
    )
  }

  if (!topic) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">{t('Topic not found')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <DetailResourceHeaderTitle
            kind="topic"
            label={topic.name}
            resourceId={topic.$id}
            projectId={projectId}
            back={{
              onClick: handleBack,
              'aria-label': t('Back to topics'),
            }}
          />
        }
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Update Name Section - First Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Name')}
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground">
                {t(
                  "Update your topic's display name. This will be visible to all organization members.",
                )}
              </p>
              <Input
                id="topic-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('Topic name')}
                className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  name.trim() === topic.name ||
                  !name.trim() ||
                  updateNameMutation.isPending
                }
                onClick={() => updateNameMutation.mutate(name)}
>
                {t('Update')}
              </Button>
            </div>
          </div>

          {/* Overview Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Overview')}
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="space-y-4">
                <div className="flex items-start justify-between gap-4">
                  <div className="flex-1 min-w-0">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                      {t('Topic ID')}
                    </p>
                    <CopyableId id={topic.$id} size="sm" />
                  </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                      {t('Created')}
                    </p>
                    {topic.$createdAt ? (
                      <DateTooltip
                        date={topic.$createdAt}
                        className="text-[13px] text-foreground"
                        showFormattedDate
                      />
                    ) : (
                      <span className="text-[13px] text-muted-foreground/50 italic">
                        N/A
                      </span>
                    )}
                  </div>
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                      {t('Updated')}
                    </p>
                    <DateTooltip
                      date={topic.$updatedAt || topic.$createdAt}
                      className="text-[13px] text-foreground"
                      showFormattedDate
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Delete topic */}
          <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Delete topic')}
              </h3>
            </div>
            <div className="border-t border-destructive/20" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground">
                {t(
                  'Permanently delete this topic and all its subscribers. This action cannot be undone.',
                )}
              </p>

              {/* Topic Info Summary */}
              {topic && (
                <div className="flex items-center gap-3 mt-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                    <Hash className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-[14px] font-medium text-foreground truncate">
                      {topic.name}
                    </p>
                    {topic.emailTotal !== undefined ||
                    topic.smsTotal !== undefined ||
                    topic.pushTotal !== undefined ? (
                      <p className="text-[12px] text-muted-foreground">
                        {(topic.emailTotal || 0) +
                          (topic.smsTotal || 0) +
                          (topic.pushTotal || 0)}{' '}
                        {(topic.emailTotal || 0) +
                          (topic.smsTotal || 0) +
                          (topic.pushTotal || 0) !==
                        1
                          ? t('subscribers')
                          : t('subscriber')}
                      </p>
                    ) : null}
                  </div>
                </div>
              )}
            </div>

            <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
              <Button
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setDeleteDialogOpen(true)}
                disabled={deleteTopicMutation.isPending}
>
                <Trash2 className="me-1.5 h-4 w-4" />
                {t('Delete topic')}
              </Button>
            </div>
          </div>
        </div>

        {/* Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent
            className="sm:max-w-md p-0"
>
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Delete topic')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t(
                  'Are you sure you want to delete this topic? This action cannot be undone.',
                )}
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={deleteTopicMutation.isPending}
>
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                onClick={() => deleteTopicMutation.mutate()}
                disabled={deleteTopicMutation.isPending}
>
                {t('Delete')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
