import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { FolderInput, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { sdk } from '@/lib/appwrite/sdk'
import { PERSONAL_ORG_NAME } from '@/lib/ensure-personal-org'
import { useOrganizations } from '@/lib/react-query/hooks'
import { createOrganization } from '@/lib/react-query/hooks/organizations'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { AuthFlowAccountSwitcher } from '@/components/global/auth/AuthFlowAccountSwitcher'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { useAuthAccountSwitch } from '@/components/global/auth/useAuthAccountSwitch'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
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
  const hasOrganizations = organizationsLoading || organizations.length > 0
  const { features } = useConsoleProfile()

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
    // Keeps the details while the recipient is selected, so the picker doesn't flicker
    placeholderData: keepPreviousData,
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

  // Someone who signed up to claim a link has no organization to receive it yet
  const createOrganizationMutation = useMutation({
    mutationFn: () => createOrganization({ name: PERSONAL_ORG_NAME }),
    onSuccess: async (organization) => {
      if (!('$id' in organization)) return
      await queryClient.invalidateQueries({ queryKey: ['organizations'] })
      setTeamId(organization.$id)
    },
    onError: (error) => {
      toast.error(
        getErrorMessage(error, t('Could not create the organization')),
      )
    },
  })

  const recipientId = preview.data?.recipientId ?? ''
  const isRecipientMember = organizations.some(
    (organization) => organization.$id === recipientId,
  )
  const isReserved =
    recipientId !== '' && !organizationsLoading && !isRecipientMember

  // Only one organization may claim it, so pick that one for the user
  useEffect(() => {
    if (recipientId !== '' && isRecipientMember && teamId !== recipientId) {
      setTeamId(recipientId)
    }
  }, [recipientId, isRecipientMember, teamId])

  const isAlreadyOwner = preview.data?.senderId === teamId
  const canClaim =
    preview.isSuccess &&
    !isAlreadyOwner &&
    (recipientId === '' || teamId === recipientId) &&
    !claimMutation.isPending

  const problem = preview.isError
    ? getErrorMessage(
        preview.error,
        t('This transfer link is no longer valid.'),
      )
    : isAlreadyOwner
      ? t(
          'The project already belongs to this organization. Select a different one.',
        )
      : null

  const switchAccount = useAuthAccountSwitch()
  const goToDashboard = () => navigate({ to: '/' })

  return (
    <main id="main-content" className="h-[100dvh] max-h-[100dvh]">
      <AuthFlowShell
        width="narrow"
        showLegal={false}
        accountSwitcher={
          <AuthFlowAccountSwitcher disabled={claimMutation.isPending} />
        }
      >
        <AuthFlowNarrowCard>
          {!hasLink ? (
            <div className="space-y-6">
              <div className="flex flex-col items-center gap-4 text-center">
                <AuthFlowHeaderIcon
                  icon={TriangleAlert}
                  variant="destructive"
                />
                <div className="space-y-1">
                  <AuthFlowTitle>{t('Claim project')}</AuthFlowTitle>
                  <AuthFlowDescription>
                    {t(
                      'This transfer link is incomplete. Ask the sender to share it again.',
                    )}
                  </AuthFlowDescription>
                </div>
              </div>
              <Button
                variant="outline"
                className="w-full"
                onClick={goToDashboard}
              >
                {t('Go to dashboard')}
              </Button>
            </div>
          ) : !hasOrganizations ? (
            <div className="space-y-6">
              <div className="flex flex-col items-center gap-4 text-center">
                <AuthFlowHeaderIcon icon={FolderInput} />
                <div className="space-y-1">
                  <AuthFlowTitle>{t('Claim project')}</AuthFlowTitle>
                  <AuthFlowDescription>
                    {t(
                      'A project moves into one of your organizations. Create one to receive it.',
                    )}
                  </AuthFlowDescription>
                </div>
              </div>
              <div className="flex flex-col gap-2">
                <Button
                  variant="brandCta"
                  className="w-full"
                  disabled={createOrganizationMutation.isPending}
                  onClick={() => createOrganizationMutation.mutate()}
                >
                  {features.billing
                    ? t('Create a free organization')
                    : t('Create organization')}
                </Button>
                <Button
                  variant="outline"
                  className="w-full"
                  disabled={createOrganizationMutation.isPending}
                  onClick={goToDashboard}
                >
                  {t('Go to dashboard')}
                </Button>
              </div>
            </div>
          ) : isReserved ? (
            <div className="space-y-6">
              <div className="flex flex-col items-center gap-4 text-center">
                <AuthFlowHeaderIcon
                  icon={TriangleAlert}
                  variant="destructive"
                />
                <div className="space-y-1">
                  <AuthFlowTitle>{t('Claim project')}</AuthFlowTitle>
                  <AuthFlowDescription>
                    {t(
                      'This transfer is reserved for an organization you are not part of:',
                    )}{' '}
                    <span className="text-foreground font-medium break-words">
                      {preview.data?.recipientName || recipientId}
                    </span>
                    .
                  </AuthFlowDescription>
                </div>
              </div>
              <div className="flex flex-col gap-2">
                <Button
                  variant="brandCta"
                  className="w-full"
                  onClick={() => void switchAccount()}
                >
                  {t('Use a different account')}
                </Button>
                <Button
                  variant="outline"
                  className="w-full"
                  onClick={goToDashboard}
                >
                  {t('Go to dashboard')}
                </Button>
              </div>
            </div>
          ) : (
            <div className="space-y-6">
              <div className="flex flex-col items-center gap-4 text-center">
                <AuthFlowHeaderIcon icon={FolderInput} />
                <div className="space-y-1">
                  <AuthFlowTitle>{t('Claim project')}</AuthFlowTitle>
                  <AuthFlowDescription>
                    {preview.data ? (
                      <>
                        <span className="text-foreground font-medium break-words">
                          {preview.data.resourceName}
                        </span>{' '}
                        {t('will move from')}{' '}
                        <span className="text-foreground font-medium break-words">
                          {preview.data.senderName}
                        </span>{' '}
                        {t('to the organization you choose.')}{' '}
                        {t('Link expires')}{' '}
                        <DateTooltip date={preview.data.expire} />.
                      </>
                    ) : (
                      t(
                        'Choose the organization that should receive this project.',
                      )
                    )}
                  </AuthFlowDescription>
                </div>
              </div>

              <div className="space-y-2">
                <Label
                  htmlFor="transfer-organization"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Target organization')}
                </Label>
                <Select
                  value={teamId}
                  onValueChange={setTeamId}
                  disabled={
                    organizationsLoading ||
                    recipientId !== '' ||
                    claimMutation.isPending
                  }
                >
                  <SelectTrigger id="transfer-organization" className="w-full">
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
                {problem ? (
                  <p className="text-[12px] text-destructive">{problem}</p>
                ) : recipientId !== '' ? (
                  <p className="text-[12px] text-muted-foreground">
                    {t('Only this organization can claim this link.')}
                  </p>
                ) : null}
              </div>

              <div className="flex flex-col gap-2">
                <Button
                  variant="brandCta"
                  className="w-full"
                  disabled={!canClaim}
                  onClick={() => claimMutation.mutate()}
                >
                  {t('Claim project')}
                </Button>
                <Button
                  variant="outline"
                  className="w-full"
                  disabled={claimMutation.isPending}
                  onClick={goToDashboard}
                >
                  {t('Cancel')}
                </Button>
              </div>
            </div>
          )}
        </AuthFlowNarrowCard>
      </AuthFlowShell>
    </main>
  )
}
