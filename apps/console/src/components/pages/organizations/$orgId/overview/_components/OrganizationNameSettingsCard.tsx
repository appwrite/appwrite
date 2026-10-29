import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { sdk } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

function isPartnerClientsQueryKey(queryKey: readonly unknown[]) {
  return queryKey[0] === 'partners' && queryKey[2] === 'clients'
}

export function OrganizationNameSettingsCard({
  organizationId,
  organizationName,
  canUpdate,
  disabledTooltip,
  onUpdated,
}: {
  organizationId: string
  organizationName: string
  canUpdate: boolean
  disabledTooltip?: string
  onUpdated?: (name: string) => void | Promise<void>
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const [name, setName] = useState(organizationName)

  useEffect(() => {
    setName(organizationName)
  }, [organizationName])

  const updateName = useMutation({
    mutationFn: async () => {
      await sdk.forConsole.teams.updateName({
        teamId: organizationId,
        name: name.trim(),
      })
    },
    onSuccess: async () => {
      const nextName = name.trim()
      toast.success(t('Organization name updated successfully'))
      queryClient.setQueryData(
        ['organization', organizationId],
        (current: { name?: string } | undefined) =>
          current ? { ...current, name: nextName } : current,
      )
      queryClient.setQueriesData(
        {
          predicate: (query) => isPartnerClientsQueryKey(query.queryKey),
        },
        (current: unknown) => {
          if (!current || typeof current !== 'object') return current
          const record = current as {
            teams?: Array<{ $id: string; name?: string }>
            clients?: Array<{ $id: string; name?: string }>
          }
          if (Array.isArray(record.teams)) {
            return {
              ...record,
              teams: record.teams.map((team) =>
                team.$id === organizationId ? { ...team, name: nextName } : team,
              ),
            }
          }
          if (Array.isArray(record.clients)) {
            return {
              ...record,
              clients: record.clients.map((client) =>
                client.$id === organizationId
                  ? { ...client, name: nextName }
                  : client,
              ),
            }
          }
          return current
        },
      )
      await queryClient.refetchQueries({
        queryKey: ['organization', organizationId],
      })
      await queryClient.refetchQueries({
        queryKey: ['organizations', 'console'],
      })
      await queryClient.refetchQueries({
        predicate: (query) => isPartnerClientsQueryKey(query.queryKey),
      })
      await onUpdated?.(nextName)
    },
    onError: (error) => {
      toast.error(
        getErrorMessage(error, t('Failed to update organization name')),
      )
    },
  })

  const canSave =
    canUpdate &&
    name.trim().length > 0 &&
    name.trim() !== organizationName &&
    !updateName.isPending

  const updateButton = (
    <Button
      size="sm"
      className="h-9 text-[13px]"
      disabled={!canSave}
      onClick={() => updateName.mutate()}
    >
      {t('Update')}
    </Button>
  )

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Organization name')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          {t(
            "Update your organization's display name. This will be visible to all organization members.",
          )}
        </p>
        <Input
          value={name}
          onChange={(event) => setName(event.target.value)}
          placeholder={t('Organization name')}
          disabled={!canUpdate || updateName.isPending}
          className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
        />
      </div>
      <div className="border-t border-border bg-muted/30 px-6 py-4">
        {!canUpdate && disabledTooltip ? (
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="inline-flex">{updateButton}</span>
              </TooltipTrigger>
              <TooltipContent className="max-w-xs text-xs">
                {disabledTooltip}
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        ) : (
          updateButton
        )}
      </div>
    </div>
  )
}
