import { Fragment, useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  lookupTerraformResource,
  recordTerraformDrift,
} from '@/lib/react-query/hooks/terraform'
import {
  setTerraformChangeConfirmer,
  type TerraformChangeRequest,
} from '@/lib/terraform/guard'
import {
  buildTerraformSkipConfirmPrefs,
  parseTerraformSkipConfirmProjectIds,
} from '@/lib/terraform/prefs'
import {
  getTerraformResourceId,
  getTerraformResourceKind,
  type TerraformResourceKind,
} from '@/lib/terraform/resource'
import type { TerraformResource } from '@/lib/terraform/state'
import { useT } from '@/lib/i18n/translate'

const RESOURCE_KIND_LABELS: Record<TerraformResourceKind, string> = {
  function: 'Function',
  site: 'Site',
  bucket: 'Bucket',
  database: 'Database',
  table: 'Table',
  collection: 'Collection',
  topic: 'Topic',
  provider: 'Provider',
  webhook: 'Webhook',
  rule: 'Domain',
  key: 'API key',
}

type PendingChange = {
  request: TerraformChangeRequest
  resource: TerraformResource
  resolve: (allowed: boolean) => void
}

/**
 * Asks before the console writes to a Terraform-managed resource. Mounted once
 * per project layout; the project SDK routes every such write through it.
 */
export function ConfirmTerraformChange() {
  const t = useT()
  const queryClient = useQueryClient()
  const [queue, setQueue] = useState<PendingChange[]>([])
  const [skipProject, setSkipProject] = useState(false)
  const queueRef = useRef(queue)
  const cancelRef = useRef<HTMLButtonElement>(null)
  queueRef.current = queue

  useEffect(() => {
    setTerraformChangeConfirmer({
      confirm: async (request) => {
        const account = getConsoleAccountFromCache(queryClient)
        const skipped = parseTerraformSkipConfirmProjectIds(account?.prefs)
        if (skipped.includes(request.projectId)) return true
        const resource = await lookupTerraformResource(
          queryClient,
          request.projectId,
          request.resource,
        )
        if (!resource) return true
        return new Promise<boolean>((resolve) => {
          setQueue((current) => [...current, { request, resource, resolve }])
        })
      },
      changed: (request) => {
        const account = getConsoleAccountFromCache(queryClient) as
          | Models.User
          | undefined
        recordTerraformDrift(queryClient, request.projectId, request.resource, {
          time: new Date().toISOString(),
          event: request.call,
          actorName: account?.name ?? '',
          actorType: 'admin',
          userAgent: '',
        })
      },
    })
    return () => {
      setTerraformChangeConfirmer(null)
      for (const pending of queueRef.current) pending.resolve(false)
    }
  }, [queryClient])

  const current = queue[0]

  const answer = (allowed: boolean) => {
    if (!current) return
    // One answer covers every queued write, so a bulk action asks once.
    for (const pending of queue) pending.resolve(allowed)
    setQueue([])
    if (allowed && skipProject) {
      const account = getConsoleAccountFromCache(queryClient)
      const prefs = account?.prefs
      void updateAccountPrefs(
        {
          ...prefs,
          ...buildTerraformSkipConfirmPrefs(prefs, current.request.projectId),
        },
        'terraform-skip-confirm',
      ).then((updated) =>
        syncConsoleAccountAfterMutation(queryClient, { apiResult: updated }),
      )
    }
    setSkipProject(false)
  }

  if (!current) return null

  const isDelete = queue.some(({ request }) => request.action === 'delete')
  const resources = [...new Set(queue.map(({ request }) => request.resource))]

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open) answer(false)
      }}
    >
      <DialogContent
        className="sm:max-w-md p-0"
        onOpenAutoFocus={(event) => {
          event.preventDefault()
          cancelRef.current?.focus()
        }}
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>
            {isDelete
              ? t('Delete a Terraform-managed resource?')
              : t('Update a Terraform-managed resource?')}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {isDelete
              ? t(
                  'Terraform created this resource. Your next terraform apply will recreate it unless you also remove it from your Terraform configuration.',
                )
              : t(
                  'Terraform created this resource. Your next terraform apply may overwrite this change unless you also update your Terraform configuration.',
                )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="space-y-3 px-6 py-4">
          <dl className="grid max-h-48 grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 overflow-y-auto text-[13px]">
            {resources.map((resource) => {
              const kind = getTerraformResourceKind(resource)
              return (
                <Fragment key={resource}>
                  <dt className="text-muted-foreground">
                    {kind ? t(RESOURCE_KIND_LABELS[kind]) : t('Resource')}
                  </dt>
                  <dd className="min-w-0 truncate text-end font-mono text-[12px] text-foreground">
                    {getTerraformResourceId(resource)}
                  </dd>
                </Fragment>
              )
            })}
            {resources.length === 1 ? (
              <>
                <dt className="text-muted-foreground">{t('Last apply')}</dt>
                <dd className="text-end text-foreground">
                  <DateTooltip date={current.resource.appliedAt} />
                </dd>
              </>
            ) : null}
          </dl>
          <label
            htmlFor="terraform-skip-confirm"
            className="flex items-center gap-2 text-[13px] text-muted-foreground"
          >
            <Checkbox
              id="terraform-skip-confirm"
              checked={skipProject}
              onCheckedChange={(checked) => setSkipProject(checked === true)}
            />
            {t("Don't ask again for this project")}
          </label>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            ref={cancelRef}
            type="button"
            variant="outline"
            className="h-9 text-[13px]"
            onClick={() => answer(false)}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            variant={isDelete ? 'destructive' : 'default'}
            className="h-9 text-[13px]"
            onClick={() => answer(true)}
          >
            {isDelete ? t('Delete anyway') : t('Update anyway')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
