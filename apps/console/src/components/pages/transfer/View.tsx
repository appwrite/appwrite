import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle } from 'lucide-react'
import { toast } from 'sonner'
import { AppwriteException } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { useOrganizations } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

interface ClaimProjectTransferProps {
  transferId: string
  secret: string
}

export function ClaimProjectTransfer({
  transferId,
  secret,
}: ClaimProjectTransferProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { organizations, isLoading: organizationsLoading } = useOrganizations()
  const [teamId, setTeamId] = useState('')
  const hasLink = transferId !== '' && secret !== ''

  useEffect(() => {
    if (teamId === '' && organizations.length > 0) {
      setTeamId(organizations[0].$id)
    }
  }, [organizations, teamId])

  // The preview runs as the selected organization, so it reports whether that organization may claim it
  const preview = useQuery({
    queryKey: ['projectTransferPreview', transferId, teamId],
    queryFn: () =>
      sdk.forConsole
        .organization(teamId)
        .getProjectTransfer({ transferId, secret }),
    enabled: hasLink && teamId !== '',
    retry: false,
  })

  const claimMutation = useMutation({
    mutationFn: () =>
      sdk.forConsole
        .organization(teamId)
        .updateProjectTransferStatus({ transferId, secret }),
    onSuccess: async (project) => {
      toast.success(`${project.name} ${t('has been claimed')}`)
      await queryClient.invalidateQueries({ queryKey: ['projects'] })
      navigate({
        to: '/projects/$projectId',
        params: { projectId: project.$id },
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to claim project')))
    },
  })

  const isAlreadyOwner = preview.data?.senderId === teamId
  const canClaim =
    preview.isSuccess && !isAlreadyOwner && !claimMutation.isPending

  return (
    <div className="flex min-h-svh items-center justify-center bg-background px-4 py-10">
      <div className="w-full max-w-md rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h1 className="text-[15px] font-semibold text-foreground">
            {t('Claim project')}
          </h1>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Choose the organization that should receive this project.')}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {!hasLink ? (
            <Alert>
              <AlertTriangle className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {t(
                  'This transfer link is incomplete. Ask the sender to share it again.',
                )}
              </AlertDescription>
            </Alert>
          ) : !organizationsLoading && organizations.length === 0 ? (
            <Alert>
              <AlertTriangle className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {t(
                  'You need an organization to claim this project. Create one, then open the link again.',
                )}
              </AlertDescription>
            </Alert>
          ) : (
            <>
              {preview.data && (
                <div className="rounded-lg border border-border px-4 py-3">
                  <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    {t('Project')}
                  </p>
                  <p className="text-[15px] font-medium text-foreground mt-1">
                    {preview.data.resourceName}
                  </p>
                  <p className="text-[12px] text-muted-foreground mt-1">
                    {t('Link expires')}{' '}
                    <DateTooltip date={preview.data.expire} />
                  </p>
                </div>
              )}
              <div>
                <Label
                  htmlFor="transfer-organization"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Target organization')}
                </Label>
                <Select
                  value={teamId}
                  onValueChange={setTeamId}
                  disabled={organizationsLoading || claimMutation.isPending}
                >
                  <SelectTrigger
                    id="transfer-organization"
                    className="mt-2 h-9"
                  >
                    <SelectValue
                      placeholder={
                        organizationsLoading
                          ? t('Loading organizations...')
                          : t('Select organization')
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {organizations.map((organization) => (
                      <SelectItem
                        key={organization.$id}
                        value={organization.$id}
                      >
                        {organization.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              {preview.isError && (
                <Alert>
                  <AlertTriangle className="h-4 w-4" />
                  <AlertDescription className="text-[13px]">
                    {/* The generic 403 message would not say what to do next */}
                    {preview.error instanceof AppwriteException &&
                    preview.error.type === 'transfer_recipient_mismatch'
                      ? t(
                          'This link can only be claimed by a different organization. Select another one.',
                        )
                      : getErrorMessage(
                          preview.error,
                          t('This transfer link is no longer valid.'),
                        )}
                  </AlertDescription>
                </Alert>
              )}
              {isAlreadyOwner && (
                <Alert>
                  <AlertTriangle className="h-4 w-4" />
                  <AlertDescription className="text-[13px]">
                    {t(
                      'The project already belongs to this organization. Select a different one.',
                    )}
                  </AlertDescription>
                </Alert>
              )}
            </>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => navigate({ to: '/' })}
          >
            {t('Cancel')}
          </Button>
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={!canClaim}
            onClick={() => claimMutation.mutate()}
          >
            {t('Claim project')}
          </Button>
        </div>
      </div>
    </div>
  )
}
