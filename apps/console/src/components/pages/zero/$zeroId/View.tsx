import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useRouter } from '@tanstack/react-router'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { Check, Clock, FolderInput, Loader2, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { AuthFlowAccountSwitcher } from '@/components/global/auth/AuthFlowAccountSwitcher'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import {
  AuthFlowHeaderIcon,
  type AuthFlowHeaderIconComponent,
} from '@/components/global/auth/AuthFlowHeaderIcon'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useNow } from '@/hooks/use-now'
import type { ZeroProject } from '@/lib/appwrite/zero'
import { sdk, setProjectRegion } from '@/lib/appwrite/sdk'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT, type Translator } from '@/lib/i18n/translate'
import {
  performConsoleSignOut,
  refreshConsoleAccountAfterAuth,
} from '@/lib/react-query/hooks/auth'
import {
  useZero,
  zeroClaimOrganizationsQueryOptions,
} from '@/lib/react-query/hooks/zero'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatRemaining, getRemainingMs } from '@/lib/zero/countdown'
import { getZeroClaimPath, isZeroAccount } from '@/lib/zero/session'

type ViewProps = {
  zeroId: string
  secret?: string
}

function Header({
  icon,
  destructive = false,
  title,
  description,
}: {
  icon: AuthFlowHeaderIconComponent
  destructive?: boolean
  title: string
  description: React.ReactNode
}) {
  return (
    <div className="flex flex-col items-center gap-4 text-center">
      <AuthFlowHeaderIcon
        icon={icon}
        variant={destructive ? 'destructive' : 'neutral'}
      />
      <div className="space-y-1">
        <AuthFlowTitle>{title}</AuthFlowTitle>
        <AuthFlowDescription>{description}</AuthFlowDescription>
      </div>
    </div>
  )
}

function statusBadge(zero: ZeroProject, t: Translator) {
  if (zero.status === 'active') {
    return <Badge variant="info">{t('Building')}</Badge>
  }
  if (zero.status === 'expired') {
    return <Badge variant="warning">{t('Waiting for claim')}</Badge>
  }
  if (zero.status === 'claimed') {
    return <Badge variant="success">{t('Claimed')}</Badge>
  }
  return <Badge variant="error">{t('Deleted')}</Badge>
}

function Details({ zero, now }: { zero: ZeroProject; now: number }) {
  const t = useT()
  const expired =
    zero.status === 'expired' || getRemainingMs(zero.expiresAt, now) === 0
  return (
    <dl className="divide-y divide-border rounded-lg border border-border text-[13px]">
      <div className="flex items-center justify-between gap-4 px-4 py-3">
        <dt className="text-muted-foreground">{t('Project ID')}</dt>
        <dd className="min-w-0 truncate font-mono">{zero.projectId}</dd>
      </div>
      <div className="flex items-center justify-between gap-4 px-4 py-3">
        <dt className="text-muted-foreground">{t('Region')}</dt>
        <dd className="uppercase">{zero.region}</dd>
      </div>
      <div className="flex items-center justify-between gap-4 px-4 py-3">
        <dt className="text-muted-foreground">{t('Status')}</dt>
        <dd>{statusBadge(zero, t)}</dd>
      </div>
      <div className="flex items-center justify-between gap-4 px-4 py-3">
        <dt className="text-muted-foreground">
          {expired ? t('Deleted unless claimed by') : t('Build window ends')}
        </dt>
        <dd className="flex items-center gap-1.5">
          <DateTooltip
            date={expired ? zero.claimBy : zero.expiresAt}
            showFormattedDate
          />
          <span className="text-muted-foreground">
            (
            {formatRemaining(
              getRemainingMs(expired ? zero.claimBy : zero.expiresAt, now),
            )}
            )
          </span>
        </dd>
      </div>
    </dl>
  )
}

/** Organization picker value meaning "take over the project's own free organization". */
const NEW_ORGANIZATION = ''

export function View({ zeroId, secret }: ViewProps) {
  const t = useT()
  const now = useNow()
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const {
    account: accountUnknown,
    isLoading: accountLoading,
    isAuthenticated,
  } = useAuth()
  const account = accountUnknown as Models.User | undefined
  const anonymous = isAuthenticated && isZeroAccount(account)
  const member = isAuthenticated && !!account && !anonymous

  const zeroQuery = useZero(zeroId, secret, { poll: true })
  const zero = zeroQuery.data
  const organizationsQuery = useQuery(
    zeroClaimOrganizationsQueryOptions(member ? account.$id : null),
  )
  const organizations = organizationsQuery.data ?? []
  const ownsFreeOrganization = organizations.some((org) => org.isFree)

  const [organizationId, setOrganizationId] = useState<string>('')
  const [claimError, setClaimError] = useState<string | null>(null)
  const [moveError, setMoveError] = useState<string | null>(null)
  const [isLeaving, setIsLeaving] = useState(false)

  const claimPath = getZeroClaimPath(zeroId, secret ?? '')

  const leaveForAuth = async (page: '/sign-up' | '/sign-in') => {
    const destination = `${page}?redirect=${encodeURIComponent(claimPath)}`
    if (!anonymous) {
      navigate({ to: page, search: { redirect: claimPath } })
      return
    }
    // The anonymous session would bounce sign-in and sign-up back to the console.
    setIsLeaving(true)
    try {
      await performConsoleSignOut(queryClient, {
        destination,
        requireServerRevocation: true,
      })
    } catch {
      setIsLeaving(false)
      toast.error(t('Could not sign out. Try again.'))
    }
  }

  const claimMutation = useMutation({
    mutationFn: (target: string) =>
      sdk.forConsole.zero.claim({
        zeroId,
        secret: secret ?? '',
        organizationId: target === NEW_ORGANIZATION ? undefined : target,
      }),
    onMutate: () => {
      setClaimError(null)
      setMoveError(null)
    },
    onSuccess: async (claimed) => {
      setProjectRegion(claimed.projectId, claimed.region)
      toast.success(t('Project claimed'))
      await refreshConsoleAccountAfterAuth(queryClient)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['organizations'] }),
        queryClient.invalidateQueries({ queryKey: ['zero'] }),
      ])
      await router.invalidate()
      navigate({
        to: '/projects/$projectId',
        params: { projectId: claimed.projectId },
        replace: true,
      })
    },
    onError: (error: unknown, target: string) => {
      const message =
        error instanceof AppwriteException &&
        error.type === 'additional_resource_not_allowed' &&
        target === NEW_ORGANIZATION
          ? t(
              'You already have a free organization. Choose an organization to move the project into.',
            )
          : getErrorMessage(error, t('Could not claim the project'))
      if (target === NEW_ORGANIZATION) {
        setClaimError(message)
      } else {
        setMoveError(message)
      }
    },
  })

  const busy = claimMutation.isPending || isLeaving

  const accountSwitcher = member ? (
    <AuthFlowAccountSwitcher returnUrl={claimPath} disabled={busy} />
  ) : null

  const shell = (children: React.ReactNode, switcher = accountSwitcher) => (
    <AuthFlowShell width="narrow" accountSwitcher={switcher}>
      <AuthFlowNarrowCard>
        <div className="space-y-6">{children}</div>
      </AuthFlowNarrowCard>
    </AuthFlowShell>
  )

  const goToConsole = (
    <Button
      variant="outline"
      className="w-full"
      onClick={() => navigate({ to: '/' })}
    >
      {t('Go to console')}
    </Button>
  )

  if (accountLoading || (zeroQuery.isLoading && !!secret)) {
    return shell(
      <div className="flex flex-col items-center py-8 text-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground motion-reduce:animate-none" />
      </div>,
      null,
    )
  }

  if (!secret || !zero) {
    const error = zeroQuery.error
    const notFound =
      error instanceof AppwriteException && error.type === 'zero_not_found'
    return shell(
      <>
        <Header
          icon={TriangleAlert}
          destructive
          title={notFound ? t('Project not found') : t('Invalid claim link')}
          description={
            notFound
              ? t('This project does not exist or was already removed.')
              : t(
                  'This claim link is incomplete or its secret is wrong. Use the exact link you were given.',
                )
          }
        />
        {goToConsole}
      </>,
    )
  }

  if (zero.status === 'claimed') {
    return shell(
      <>
        <Header
          icon={Check}
          title={t('Project already claimed')}
          description={t(
            'This project now belongs to an account. Sign in to that account to open it.',
          )}
        />
        {goToConsole}
      </>,
    )
  }

  if (zero.status === 'deleted' || getRemainingMs(zero.claimBy, now) === 0) {
    return shell(
      <>
        <Header
          icon={TriangleAlert}
          destructive
          title={t('Project deleted')}
          description={t(
            'This project was not claimed in time, so it and its data were deleted.',
          )}
        />
        {member ? (
          goToConsole
        ) : (
          <Button
            variant="brandCta"
            className="w-full"
            onClick={() => void leaveForAuth('/sign-up')}
            disabled={busy}
          >
            {t('Create an account')}
          </Button>
        )}
      </>,
    )
  }

  const expired =
    zero.status === 'expired' || getRemainingMs(zero.expiresAt, now) === 0
  const description = expired
    ? t(
        'The build window ended and the project is paused. Claim it to bring it back with all its data.',
      )
    : t('Claim this project to keep it, with everything already built in it.')

  if (!member) {
    return shell(
      <>
        <Header
          icon={Clock}
          title={t('Claim your project')}
          description={description}
        />
        <Details zero={zero} now={now} />
        <div className="flex flex-col gap-2">
          <Button
            variant="brandCta"
            className="w-full"
            onClick={() => void leaveForAuth('/sign-up')}
            disabled={busy}
          >
            {t('Create an account')}
          </Button>
          <Button
            variant="outline"
            className="w-full"
            onClick={() => void leaveForAuth('/sign-in')}
            disabled={busy}
          >
            {t('Sign in')}
          </Button>
        </div>
      </>,
    )
  }

  return shell(
    <>
      <Header
        icon={Clock}
        title={t('Claim your project')}
        description={description}
      />
      <Details zero={zero} now={now} />
      {organizationsQuery.isLoading ? (
        <div className="flex justify-center py-2">
          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground motion-reduce:animate-none" />
        </div>
      ) : (
        <div className="space-y-6">
          {!ownsFreeOrganization && (
            <div className="space-y-2">
              <Button
                variant="brandCta"
                className="w-full"
                onClick={() => claimMutation.mutate(NEW_ORGANIZATION)}
                disabled={busy}
                {...analyticsAttrs('zero-claim')}
              >
                {t('Claim into a new free organization')}
              </Button>
              {claimError && (
                <p role="alert" className="text-[13px] text-destructive">
                  {claimError}
                </p>
              )}
            </div>
          )}
          {organizations.length > 0 && (
            <div className="space-y-3">
              <div className="space-y-1">
                <p className="flex items-center gap-1.5 text-sm font-medium">
                  <FolderInput className="size-4 text-muted-foreground" />
                  {t('Move into an existing organization')}
                </p>
                <p className="text-[13px] text-muted-foreground">
                  {t(
                    'The project moves into an organization you own and counts toward its plan.',
                  )}
                </p>
              </div>
              <Select
                value={organizationId}
                onValueChange={(value) => {
                  setOrganizationId(value)
                  setMoveError(null)
                }}
                disabled={busy}
              >
                <SelectTrigger
                  className="w-full"
                  aria-label={t('Organization')}
                >
                  <SelectValue placeholder={t('Select an organization')} />
                </SelectTrigger>
                <SelectContent>
                  {organizations.map((org) => (
                    <SelectItem key={org.$id} value={org.$id}>
                      {org.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {moveError && (
                <p role="alert" className="text-[13px] text-destructive">
                  {moveError}
                </p>
              )}
              <Button
                variant={ownsFreeOrganization ? 'brandCta' : 'outline'}
                className="w-full"
                onClick={() => claimMutation.mutate(organizationId)}
                disabled={busy || !organizationId}
                {...analyticsAttrs('zero-claim')}
              >
                {t('Move project')}
              </Button>
            </div>
          )}
        </div>
      )}
    </>,
  )
}
