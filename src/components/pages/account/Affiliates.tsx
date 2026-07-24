import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, Copy, Gift, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Pagination } from '@/components/global/shared/Pagination'
import {
  AFFILIATE_ATTRIBUTION_DAYS,
  AFFILIATE_REWARD_AMOUNT_USD,
  affiliateQueryOptions,
  organizationsFullQueryOptions,
  useAffiliateReferrals,
  useAffiliateRewards,
  useCreateAffiliate,
  useCreateAffiliateRewardCredit,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import { buildAffiliateSignupUrl } from '@/lib/affiliate-referral'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

export type AccountAffiliatesInitialData = {
  affiliate?: Models.Affiliate | null
  referrals?: Models.AffiliateReferralList
  rewards?: Models.AffiliateRewardList
  organizations?: Models.Organization[]
}

function referralStatusVariant(
  status: string,
): 'pending' | 'success' | 'error' | 'info' {
  if (status === 'converted') return 'success'
  if (status === 'expired') return 'error'
  if (status === 'pending') return 'pending'
  return 'info'
}

function rewardStatusVariant(
  status: string,
): 'pending' | 'success' | 'info' {
  if (status === 'applied') return 'success'
  if (status === 'pending') return 'pending'
  return 'info'
}

function referralStatusLabel(status: string, t: (text: string) => string) {
  if (status === 'converted') return t('Converted')
  if (status === 'expired') return t('Expired')
  if (status === 'pending') return t('Pending')
  return status
}

function rewardStatusLabel(status: string, t: (text: string) => string) {
  if (status === 'applied') return t('Applied')
  if (status === 'pending') return t('Pending')
  return status
}

function OverviewCard({
  affiliate,
  onJoined,
}: {
  affiliate: Models.Affiliate | null
  onJoined: (affiliate: Models.Affiliate) => void
}) {
  const t = useT()
  const createAffiliate = useCreateAffiliate()
  const [copiedLink, setCopiedLink] = useState(false)

  const signupUrl = affiliate ? buildAffiliateSignupUrl(affiliate.code) : ''

  const handleJoin = async () => {
    try {
      const created = await createAffiliate.mutateAsync()
      toast.success(t('You joined the affiliates program'))
      onJoined(created)
    } catch (error) {
      toast.error(
        getErrorMessage(error, t('Failed to join the affiliates program')),
      )
    }
  }

  const handleCopyLink = async () => {
    if (!signupUrl) return
    try {
      await navigator.clipboard.writeText(signupUrl)
      setCopiedLink(true)
      toast.success(t('Referral link copied'))
      window.setTimeout(() => setCopiedLink(false), 2000)
    } catch {
      toast.error(t('Failed to copy referral link'))
    }
  }

  if (!affiliate) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Affiliates program')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Earn $10 in credits for every referral that upgrades to a Pro plan. Attribution lasts 180 days.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-3">
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Share your unique link. When someone signs up and upgrades to Pro within the attribution window, you earn credits you can apply to any organization you own.',
            )}
          </p>
          <ul className="list-disc pl-5 space-y-1 text-[13px] text-muted-foreground">
            <li>
              {t('Reward')}: {formatCurrency(AFFILIATE_REWARD_AMOUNT_USD)}
            </li>
            <li>
              {t('Attribution window')}: {AFFILIATE_ATTRIBUTION_DAYS}{' '}
              {t('days')}
            </li>
            <li>{t('Qualifying plan')}: Pro</li>
          </ul>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={createAffiliate.isPending}
            onClick={handleJoin}
            {...analyticsAttrs('join-affiliates')}
          >
            {createAffiliate.isPending ? (
              <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
            ) : null}
            {t('Join program')}
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Your referral link')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Share this link so new users are attributed to you. You earn $10 in credits when a referral upgrades to Pro.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Pending balance')}
            </p>
            <p className="mt-1 text-[22px] font-semibold text-foreground">
              {formatCurrency(affiliate.pendingBalance ?? 0)}
            </p>
          </div>
          <Badge
            variant={affiliate.status === 'active' ? 'success' : 'inactive'}
            className="text-[10px] shrink-0 w-fit"
          >
            {affiliate.status === 'active' ? t('Active') : t('Disabled')}
          </Badge>
        </div>

        <div className="space-y-2">
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Referral code')}
          </p>
          <CopyableId id={affiliate.code} displayText={affiliate.code} />
        </div>

        <div className="space-y-2">
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Referral link')}
          </p>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <code className="min-w-0 flex-1 truncate rounded-md border border-border bg-muted/40 px-3 py-2 text-[12px] text-foreground">
              {signupUrl}
            </code>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 shrink-0 text-[13px]"
              onClick={handleCopyLink}
              {...analyticsAttrs('copy-affiliate-link')}
            >
              {copiedLink ? (
                <Check className="mr-1.5 h-4 w-4" />
              ) : (
                <Copy className="mr-1.5 h-4 w-4" />
              )}
              {t('Copy link')}
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}

function ReferralsCard({
  affiliate,
  initialData,
}: {
  affiliate: Models.Affiliate | null
  initialData?: Models.AffiliateReferralList
}) {
  const t = useT()
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const enabled = !!affiliate

  const requested = useAffiliateReferrals(
    requestedPage - 1,
    DEFAULT_PAGE_SIZE,
    enabled,
  )
  const displayed = useAffiliateReferrals(
    displayedPage - 1,
    DEFAULT_PAGE_SIZE,
    enabled,
  )

  useEffect(() => {
    if (
      requestedPage !== displayedPage &&
      !requested.isFetching &&
      requested.referrals
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [
    requestedPage,
    displayedPage,
    requested.isFetching,
    requested.referrals,
  ])

  const isFirstPage = displayedPage === 1
  const referrals =
    isFirstPage && initialData && displayed.referrals.length === 0
      ? initialData.referrals
      : displayed.referrals
  const total =
    isFirstPage && initialData
      ? (displayed.total || initialData.total)
      : displayed.total

  if (!affiliate) return null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Referrals')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'People who signed up with your referral code. Converted referrals earn you credits.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      {displayed.isLoading && referrals.length === 0 ? (
        <div className="px-6 py-10 text-center text-[13px] text-muted-foreground">
          {t('Loading referrals...')}
        </div>
      ) : referrals.length === 0 ? (
        <div className="px-6 py-6">
          <EmptyState
            icon={Gift}
            title={t('No referrals yet')}
            description={t(
              'Share your referral link to start earning credits.',
            )}
          />
        </div>
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('User ID')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Status')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Attributed')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Expires at')}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {referrals.map((referral) => (
                <TableRow key={referral.$id}>
                  <TableCell className="px-4 py-3">
                    <CopyableId
                      id={referral.referredUserId}
                      variant="inline"
                      size="sm"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Badge
                      variant={referralStatusVariant(referral.status)}
                      className="text-[10px] shrink-0"
                    >
                      {referralStatusLabel(referral.status, t)}
                    </Badge>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DateTooltip date={referral.attributedAt} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DateTooltip date={referral.expiresAt} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          {total > DEFAULT_PAGE_SIZE ? (
            <div className="border-t border-border px-4 py-3">
              <Pagination
                currentPage={displayedPage}
                totalItems={total}
                pageSize={DEFAULT_PAGE_SIZE}
                onPageChange={setRequestedPage}
                onPageSizeChange={() => {}}
                showPageSizeSelector={false}
                itemLabel={t('referrals')}
              />
            </div>
          ) : null}
        </>
      )}
    </div>
  )
}

function ApplyRewardDialog({
  reward,
  open,
  onOpenChange,
  organizations,
}: {
  reward: Models.AffiliateReward | null
  open: boolean
  onOpenChange: (open: boolean) => void
  organizations: Models.Organization[]
}) {
  const t = useT()
  const applyCredit = useCreateAffiliateRewardCredit()
  const [organizationId, setOrganizationId] = useState('')

  useEffect(() => {
    if (!open) {
      setOrganizationId('')
      return
    }
    if (organizations.length === 1) {
      setOrganizationId(organizations[0]!.$id)
    }
  }, [open, organizations])

  const handleApply = async () => {
    if (!reward || !organizationId) return
    try {
      await applyCredit.mutateAsync({
        rewardId: reward.$id,
        organizationId,
      })
      toast.success(t('Credits applied to organization'))
      onOpenChange(false)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to apply credits')))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Apply credits')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Choose an organization you own to receive these affiliate credits.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-4 space-y-3">
          <p className="text-[13px] text-muted-foreground">
            {t('Amount')}:{' '}
            <span className="font-medium text-foreground">
              {formatCurrency(reward?.amount ?? 0)}
            </span>
          </p>
          <div className="space-y-2">
            <label className="text-[13px] font-medium text-foreground">
              {t('Organization')}
            </label>
            <Select value={organizationId} onValueChange={setOrganizationId}>
              <SelectTrigger className="h-9">
                <SelectValue placeholder={t('Select organization')} />
              </SelectTrigger>
              <SelectContent>
                {organizations.map((org) => (
                  <SelectItem key={org.$id} value={org.$id}>
                    {org.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={applyCredit.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            disabled={!organizationId || applyCredit.isPending}
            onClick={handleApply}
          >
            {applyCredit.isPending ? (
              <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
            ) : null}
            {t('Apply credits')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function RewardsCard({
  affiliate,
  initialData,
  organizations,
}: {
  affiliate: Models.Affiliate | null
  initialData?: Models.AffiliateRewardList
  organizations: Models.Organization[]
}) {
  const t = useT()
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [selectedReward, setSelectedReward] =
    useState<Models.AffiliateReward | null>(null)
  const enabled = !!affiliate

  const requested = useAffiliateRewards(
    requestedPage - 1,
    DEFAULT_PAGE_SIZE,
    enabled,
  )
  const displayed = useAffiliateRewards(
    displayedPage - 1,
    DEFAULT_PAGE_SIZE,
    enabled,
  )

  useEffect(() => {
    if (
      requestedPage !== displayedPage &&
      !requested.isFetching &&
      requested.rewards
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedPage, displayedPage, requested.isFetching, requested.rewards])

  const isFirstPage = displayedPage === 1
  const rewards =
    isFirstPage && initialData && displayed.rewards.length === 0
      ? initialData.rewards
      : displayed.rewards
  const total =
    isFirstPage && initialData
      ? (displayed.total || initialData.total)
      : displayed.total

  const orgNameById = useMemo(() => {
    const map = new Map<string, string>()
    organizations.forEach((org) => map.set(org.$id, org.name))
    return map
  }, [organizations])

  if (!affiliate) return null

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Rewards')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Credits earned from converted referrals. Apply pending rewards to an organization you own.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        {displayed.isLoading && rewards.length === 0 ? (
          <div className="px-6 py-10 text-center text-[13px] text-muted-foreground">
            {t('Loading rewards...')}
          </div>
        ) : rewards.length === 0 ? (
          <div className="px-6 py-6">
            <EmptyState
              icon={Gift}
              title={t('No rewards yet')}
              description={t(
                'Rewards appear here after a referral upgrades to Pro.',
              )}
            />
          </div>
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Amount')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Status')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Organization')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Created')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[120px]" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {rewards.map((reward) => (
                  <TableRow key={reward.$id}>
                    <TableCell className="px-4 py-3">
                      <span className="text-[13px] font-medium">
                        {formatCurrency(reward.amount)}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Badge
                        variant={rewardStatusVariant(reward.status)}
                        className="text-[10px] shrink-0"
                      >
                        {rewardStatusLabel(reward.status, t)}
                      </Badge>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <span className="text-[13px] text-muted-foreground">
                        {reward.teamId
                          ? (orgNameById.get(reward.teamId) ?? reward.teamId)
                          : t('Not applied')}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip date={reward.$createdAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-right">
                      {reward.status === 'pending' ? (
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          className="h-8 text-[12px]"
                          onClick={() => setSelectedReward(reward)}
                          disabled={organizations.length === 0}
                          {...analyticsAttrs('apply-affiliate-reward')}
                        >
                          {t('Apply')}
                        </Button>
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            {total > DEFAULT_PAGE_SIZE ? (
              <div className="border-t border-border px-4 py-3">
                <Pagination
                  currentPage={displayedPage}
                  totalItems={total}
                  pageSize={DEFAULT_PAGE_SIZE}
                  onPageChange={setRequestedPage}
                  onPageSizeChange={() => {}}
                  showPageSizeSelector={false}
                  itemLabel={t('rewards')}
                />
              </div>
            ) : null}
          </>
        )}
      </div>

      <ApplyRewardDialog
        reward={selectedReward}
        open={!!selectedReward}
        onOpenChange={(open) => {
          if (!open) setSelectedReward(null)
        }}
        organizations={organizations}
      />
    </>
  )
}

export function AccountAffiliatesPage({
  initialData,
}: {
  initialData?: AccountAffiliatesInitialData
} = {}) {
  const { data: affiliateFromQuery } = useQuery(affiliateQueryOptions())
  const { data: organizationsFromQuery } = useQuery(
    organizationsFullQueryOptions(),
  )

  const [joinedAffiliate, setJoinedAffiliate] =
    useState<Models.Affiliate | null>(null)

  const affiliate =
    joinedAffiliate ??
    affiliateFromQuery ??
    initialData?.affiliate ??
    null

  const organizations =
    organizationsFromQuery ?? initialData?.organizations ?? []

  const cards = useMemo<SettingsCardItem[]>(
    () => [
      {
        id: 'affiliates-overview',
        search: {
          title: 'Affiliates program',
          keywords: [
            'affiliate',
            'referral',
            'credits',
            'earn',
            'reward',
            'pro',
          ],
        },
        node: (
          <OverviewCard
            affiliate={affiliate}
            onJoined={(created) => setJoinedAffiliate(created)}
          />
        ),
      },
      ...(affiliate
        ? [
            {
              id: 'affiliates-referrals',
              search: {
                title: 'Referrals',
                keywords: ['referral', 'referred', 'converted', 'pending'],
              },
              node: (
                <ReferralsCard
                  affiliate={affiliate}
                  initialData={initialData?.referrals}
                />
              ),
            },
            {
              id: 'affiliates-rewards',
              search: {
                title: 'Rewards',
                keywords: ['reward', 'credits', 'apply', 'pending', 'balance'],
              },
              node: (
                <RewardsCard
                  affiliate={affiliate}
                  initialData={initialData?.rewards}
                  organizations={organizations}
                />
              ),
            },
          ]
        : []),
    ],
    [affiliate, initialData?.referrals, initialData?.rewards, organizations],
  )

  return <SettingsCardsList cards={cards} />
}
