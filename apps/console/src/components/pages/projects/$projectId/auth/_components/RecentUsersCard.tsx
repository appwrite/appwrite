import { Link, useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { CheckCircle2, Mail, Phone, XCircle } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { PhotoAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { useT } from '@/lib/i18n/translate'
import { UsersEmptyState } from './UsersEmptyState'

export const OVERVIEW_USERS_LIMIT = 5

/** Labels shown before the rest collapse into a count. */
const VISIBLE_LABELS = 2

const HEAD_CLASS =
  'py-2.5 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'

function ChannelBadge({
  label,
  verified,
}: {
  label: string
  verified: boolean
}) {
  const Icon = verified ? CheckCircle2 : XCircle
  return (
    <Badge
      variant={verified ? 'success' : 'warning'}
      className="shrink-0 text-[10px]"
    >
      <Icon className="h-3 w-3" />
      {label}
    </Badge>
  )
}

function Verification({ user }: { user: Models.User }) {
  const t = useT()
  if (!user.status) {
    return (
      <Badge variant="error" className="shrink-0 text-[10px]">
        {t('Blocked')}
      </Badge>
    )
  }
  if (!user.email && !user.phone) {
    return <span className="text-[12px] text-muted-foreground">-</span>
  }
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {user.email ? (
        <ChannelBadge label={t('Email')} verified={user.emailVerification} />
      ) : null}
      {user.phone ? (
        <ChannelBadge label={t('Phone')} verified={user.phoneVerification} />
      ) : null}
    </div>
  )
}

export function RecentUsersCard({
  projectId,
  users,
  totalLabel,
  onCreateUser,
  createDisabled,
}: {
  projectId: string
  /** Newest first. */
  users: Models.User[]
  totalLabel: string
  onCreateUser: () => void
  createDisabled: boolean
}) {
  const t = useT()
  const navigate = useNavigate()
  const visible = users.slice(0, OVERVIEW_USERS_LIMIT)

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex h-16 items-center justify-between gap-3 px-6">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold leading-5 text-foreground">
            {t('Recent users')}
          </h3>
          <p className="h-4 truncate text-[12px] leading-4 text-muted-foreground">
            {visible.length > 0
              ? `${totalLabel} ${t('users')}`
              : t('No users yet')}
          </p>
        </div>
        {visible.length > 0 ? (
          <Button
            size="sm"
            variant="outline"
            className="h-9 text-[13px]"
            asChild
          >
            <Link to="/projects/$projectId/auth/users" params={{ projectId }}>
              {t('View all')}
            </Link>
          </Button>
        ) : null}
      </div>
      <div className="border-t border-border" />
      {visible.length > 0 ? (
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead className={`${HEAD_CLASS} pe-4 ps-6`}>
                {t('User')}
              </TableHead>
              <TableHead className={`${HEAD_CLASS} px-4`}>
                {t('Contact')}
              </TableHead>
              <TableHead className={`${HEAD_CLASS} px-4`}>
                {t('Verification')}
              </TableHead>
              <TableHead className={`${HEAD_CLASS} px-4 text-center`}>
                {t('MFA')}
              </TableHead>
              <TableHead className={`${HEAD_CLASS} px-4`}>
                {t('Labels')}
              </TableHead>
              <TableHead className={`${HEAD_CLASS} px-4 text-end`}>
                {t('Last Active')}
              </TableHead>
              <TableHead className={`${HEAD_CLASS} pe-6 ps-4 text-end`}>
                {t('Joined')}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {visible.map((user) => {
              const labels = user.labels ?? []
              const hiddenLabels = labels.length - VISIBLE_LABELS
              return (
                <TableRow
                  key={user.$id}
                  className="cursor-pointer border-border"
                  onClick={(event) => {
                    // The ID's copy button stays a copy, not a navigation.
                    if ((event.target as HTMLElement).closest('button')) return
                    void navigate({
                      to: '/projects/$projectId/auth/users/$userId',
                      params: { projectId, userId: user.$id },
                    })
                  }}
                >
                  <TableCell className="py-3 pe-4 ps-6">
                    <div className="flex min-w-0 items-center gap-3">
                      <PhotoAvatar
                        projectId={projectId}
                        userId={user.$id}
                        name={user.name || user.email || ''}
                        size="sm"
                        className="shrink-0"
                      />
                      <div className="min-w-0">
                        <p className="truncate text-[13px] font-medium text-foreground">
                          {user.name || t('No name')}
                        </p>
                        <div className="mt-0.5">
                          <CopyableId id={user.$id} size="xs" />
                        </div>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {user.email || user.phone ? (
                      <div className="space-y-1">
                        {user.email ? (
                          <div className="flex min-w-0 items-center gap-1.5">
                            <Mail className="h-3 w-3 shrink-0 text-muted-foreground/60" />
                            <span className="truncate text-[12px] text-foreground">
                              {user.email}
                            </span>
                          </div>
                        ) : null}
                        {user.phone ? (
                          <div className="flex min-w-0 items-center gap-1.5">
                            <Phone className="h-3 w-3 shrink-0 text-muted-foreground/60" />
                            <span className="truncate text-[12px] text-foreground">
                              {user.phone}
                            </span>
                          </div>
                        ) : null}
                      </div>
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        -
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Verification user={user} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <div className="flex items-center justify-center">
                          {user.mfa ? (
                            <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                          ) : (
                            <XCircle className="h-4 w-4 text-muted-foreground/40" />
                          )}
                        </div>
                      </TooltipTrigger>
                      <TooltipContent>
                        <p className="text-xs">
                          {user.mfa
                            ? t('Multi-factor authentication enabled')
                            : t('Multi-factor authentication not enabled')}
                        </p>
                      </TooltipContent>
                    </Tooltip>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {labels.length > 0 ? (
                      <div className="flex flex-wrap items-center gap-1">
                        {labels.slice(0, VISIBLE_LABELS).map((label) => (
                          <Badge
                            key={label}
                            variant="outline"
                            className="text-[10px]"
                          >
                            {label}
                          </Badge>
                        ))}
                        {hiddenLabels > 0 ? (
                          <Badge variant="outline" className="text-[10px]">
                            +{hiddenLabels}
                          </Badge>
                        ) : null}
                      </div>
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        -
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    {user.accessedAt ? (
                      <DateTooltip
                        date={user.accessedAt}
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        {t('Never')}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="py-3 pe-6 ps-4 text-end">
                    <DateTooltip
                      date={user.$createdAt}
                      className="text-[12px] text-muted-foreground"
                    />
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      ) : (
        <div className="px-6">
          <UsersEmptyState
            projectId={projectId}
            onCreate={onCreateUser}
            createDisabled={createDisabled}
            createDisabledTooltip={t(
              "You don't have permission to create users.",
            )}
            variant="card"
          />
        </div>
      )}
    </div>
  )
}
