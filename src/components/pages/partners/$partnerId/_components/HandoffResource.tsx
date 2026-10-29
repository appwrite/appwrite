import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'
import {
  MARKETPLACE_APPS_LIMIT,
  organizationAppsQueryOptions,
  organizationDomainsQueryOptions,
  organizationProjectsQueryOptions,
  partnerClientsQueryOptions,
} from '@/lib/react-query/hooks'
import { buildConsoleUrl, openInNewTab } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export type HandoffResourceKind = 'project' | 'domain' | 'app'

type HandoffResourceProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  partnerId: string
  targetClientId?: string
  /** Preselect this project, domain, or app when the dialog opens. */
  initialResourceId?: string
  kind: HandoffResourceKind
}

const COPY: Record<
  HandoffResourceKind,
  {
    title: string
    resourceLabel: string
    selectPlaceholder: string
    empty: string
    descriptionClient: string
    descriptionPickClient: string
    success: string
    error: string
  }
> = {
  project: {
    title: 'Handoff project',
    resourceLabel: 'Project',
    selectPlaceholder: 'Select project',
    empty: 'No development projects to hand off yet.',
    descriptionClient: 'Move a development project into this client organization.',
    descriptionPickClient: 'Move a development project to a client organization.',
    success: 'Project handed off',
    error: 'Could not hand off project',
  },
  domain: {
    title: 'Handoff domain',
    resourceLabel: 'Domain',
    selectPlaceholder: 'Select domain',
    empty: 'No development domains to hand off yet.',
    descriptionClient: 'Move a development domain into this client organization.',
    descriptionPickClient: 'Move a development domain to a client organization.',
    success: 'Domain handed off',
    error: 'Could not hand off domain',
  },
  app: {
    title: 'Handoff app',
    resourceLabel: 'App',
    selectPlaceholder: 'Select app',
    empty: 'No development apps to hand off yet.',
    descriptionClient: 'Move a development app into this client organization.',
    descriptionPickClient: 'Move a development app to a client organization.',
    success: 'App handed off',
    error: 'Could not hand off app',
  },
}

function destinationHref(
  kind: HandoffResourceKind,
  destinationId: string,
  resourceId: string,
) {
  if (kind === 'domain') {
    return `/organizations/${destinationId}/domains/${resourceId}`
  }
  if (kind === 'app') {
    return `/organizations/${destinationId}/apps/${resourceId}`
  }
  return `/projects/${resourceId}`
}

export function HandoffResource({
  open,
  onOpenChange,
  partnerId,
  targetClientId,
  initialResourceId,
  kind,
}: HandoffResourceProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const copy = COPY[kind]
  const [resourceId, setResourceId] = useState('')
  const [clientId, setClientId] = useState(targetClientId ?? '')

  const projectsQuery = useQuery({
    ...organizationProjectsQueryOptions(partnerId),
    enabled: open && !!partnerId && kind === 'project',
  })
  const domainsQuery = useQuery({
    ...organizationDomainsQueryOptions(
      partnerId,
      0,
      MARKETPLACE_APPS_LIMIT,
    ),
    enabled: open && !!partnerId && kind === 'domain',
  })
  const appsQuery = useQuery({
    ...organizationAppsQueryOptions(partnerId),
    enabled: open && !!partnerId && kind === 'app',
  })
  const clientsQuery = useQuery({
    ...partnerClientsQueryOptions(partnerId),
    enabled: open && !!partnerId && !targetClientId,
  })

  const items =
    kind === 'project'
      ? (projectsQuery.data?.projects ?? []).map((project) => ({
          $id: project.$id,
          label: project.name || project.$id,
        }))
      : kind === 'domain'
        ? (domainsQuery.data?.domains ?? []).map((domain) => ({
            $id: domain.$id,
            label: domain.domain || domain.$id,
          }))
        : (appsQuery.data?.apps ?? []).map((app) => ({
            $id: app.$id,
            label: app.name || app.$id,
          }))
  const itemsFetched =
    kind === 'project'
      ? projectsQuery.isFetched
      : kind === 'domain'
        ? domainsQuery.isFetched
        : appsQuery.isFetched
  const clients = (clientsQuery.data?.teams ?? []).filter(
    (client) => client.$id !== partnerId,
  )
  const destinationId = targetClientId || clientId

  useEffect(() => {
    if (!open) {
      setResourceId('')
      setClientId(targetClientId ?? '')
      return
    }
    setResourceId(initialResourceId ?? '')
    setClientId(targetClientId ?? '')
  }, [open, targetClientId, initialResourceId])

  useEffect(() => {
    if (!open || !itemsFetched || items.length !== 1) return
    setResourceId((current) => current || items[0].$id)
  }, [open, itemsFetched, items])

  useEffect(() => {
    if (!open || targetClientId || !clientsQuery.isFetched) return
    if (clients.length !== 1) return
    setClientId((current) => current || clients[0].$id)
  }, [open, targetClientId, clientsQuery.isFetched, clients])

  const transferMutation = useMutation({
    mutationFn: async () => {
      if (!resourceId || !destinationId) return
      if (kind === 'project') {
        await sdk.forConsole.projects.updateTeam({
          projectId: resourceId,
          teamId: destinationId,
        })
        return
      }
      if (kind === 'domain') {
        await sdk.forConsole.domains.updateTeam({
          domainId: resourceId,
          teamId: destinationId,
        })
        return
      }
      await sdk.forConsole.apps.updateTeam({
        appId: resourceId,
        teamId: destinationId,
      })
    },
    onSuccess: async () => {
      toast.success(t(copy.success))
      if (kind !== 'project' && destinationId && resourceId) {
        openInNewTab(
          buildConsoleUrl(destinationHref(kind, destinationId, resourceId)),
        )
      }
      onOpenChange(false)
      if (kind === 'project') {
        await queryClient.refetchQueries({ queryKey: ['projects'] })
        await queryClient.refetchQueries({
          queryKey: ['organization-projects', partnerId],
        })
        if (destinationId) {
          await queryClient.refetchQueries({
            queryKey: ['organization-projects', destinationId],
          })
        }
        return
      }
      if (kind === 'domain') {
        await queryClient.refetchQueries({
          queryKey: ['domains', 'organization', partnerId],
        })
        if (destinationId) {
          await queryClient.refetchQueries({
            queryKey: ['domains', 'organization', destinationId],
          })
        }
        return
      }
      await queryClient.refetchQueries({
        queryKey: ['apps', 'organization', partnerId],
      })
      if (destinationId) {
        await queryClient.refetchQueries({
          queryKey: ['apps', 'organization', destinationId],
        })
      }
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t(copy.error)))
    },
  })

  const canSubmit =
    !!resourceId && !!destinationId && !transferMutation.isPending
  const selectId = `handoff-${kind}`

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (transferMutation.isPending) return
        onOpenChange(nextOpen)
      }}
    >
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t(copy.title)}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(targetClientId ? copy.descriptionClient : copy.descriptionPickClient)}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="space-y-4 px-6 pb-4 pt-4">
          <div className="space-y-2">
            <Label htmlFor={selectId}>{t(copy.resourceLabel)}</Label>
            {items.length === 0 ? (
              <p className="text-[13px] text-muted-foreground">{t(copy.empty)}</p>
            ) : (
              <Select value={resourceId} onValueChange={setResourceId}>
                <SelectTrigger id={selectId} className="w-full">
                  <SelectValue placeholder={t(copy.selectPlaceholder)} />
                </SelectTrigger>
                <SelectContent>
                  {items.map((item) => (
                    <SelectItem key={item.$id} value={item.$id}>
                      {item.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>
          {targetClientId ? null : (
            <div className="space-y-2">
              <Label htmlFor={`${selectId}-client`}>{t('Client')}</Label>
              {clients.length === 0 ? (
                <p className="text-[13px] text-muted-foreground">
                  {t('No client organizations yet.')}
                </p>
              ) : (
                <Select value={clientId} onValueChange={setClientId}>
                  <SelectTrigger id={`${selectId}-client`} className="w-full">
                    <SelectValue placeholder={t('Select client')} />
                  </SelectTrigger>
                  <SelectContent>
                    {clients.map((client) => (
                      <SelectItem key={client.$id} value={client.$id}>
                        {client.name || client.$id}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </div>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            disabled={transferMutation.isPending}
            onClick={() => onOpenChange(false)}
          >
            {t('Cancel')}
          </Button>
          <Button
            disabled={!canSubmit}
            onClick={() => transferMutation.mutate()}
          >
            {t('Handoff')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

type TransferProps = Omit<HandoffResourceProps, 'kind'>

export function TransferProject(props: TransferProps) {
  return <HandoffResource kind="project" {...props} />
}

export function TransferDomain(props: TransferProps) {
  return <HandoffResource kind="domain" {...props} />
}

export function TransferApp(props: TransferProps) {
  return <HandoffResource kind="app" {...props} />
}
