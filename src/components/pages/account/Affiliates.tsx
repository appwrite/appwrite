import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Gift, Globe, Link2, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
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
import {
  affiliateLinksQueryOptions,
  buildAffiliateInviteUrl,
  organizationsFullQueryOptions,
  useAffiliateLinks,
  useAffiliateReferrals,
  useAffiliateRewards,
  useCountryLookups,
  useCreateAffiliateLink,
  useDeleteAffiliateLink,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import {
  getCountryDisplayName,
  normalizeCountryCode,
} from '@/lib/locale/country-lookups'
import { AffiliatesOverview } from './_components/AffiliatesOverview'
import { AffiliatesProgramEmpty } from './_components/AffiliatesProgramEmpty'
import { ClaimAffiliateReward } from './_components/ClaimAffiliateReward'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

export type AccountAffiliatesInitialData = {
  links?: Models.AffiliateLinkList
  referrals?: Models.AffiliateReferralList
  rewards?: Models.AffiliateRewardList
  pendingRewards?: Models.AffiliateRewardList
  usage?: Models.UsageEventList
  organizations?: Models.Organization[]
  /** Set by the route loader so first paint matches empty vs dashboard. */
  isProgramEmpty?: boolean
}

const AFFILIATE_PAGE_SIZE_OPTIONS = [10, 25, 50, 100]
/** Enough links for referral/reward name lookup without paging the lookup itself. */
const AFFILIATE_LINK_LOOKUP_LIMIT = 100

function useAffiliateCardPagination(defaultPageSize = DEFAULT_PAGE_SIZE) {
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(defaultPageSize)

  const handlePageChange = useCallback((page: number) => {
    setRequestedPage(page)
  }, [])

  const handlePageSizeChange = useCallback((size: number) => {
    setPageSize(size)
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [])

  return {
    requestedPage,
    displayedPage,
    setDisplayedPage,
    pageSize,
    handlePageChange,
    handlePageSizeChange,
  }
}

function ReferralCountryFlag({ flagUrl }: { flagUrl: string | null }) {
  const [failed, setFailed] = useState(false)

  if (!flagUrl || failed) {
    return (
      <Globe
        className="h-4 w-4 shrink-0 text-muted-foreground/60"
        aria-hidden
      />
    )
  }

  return (
    <img
      src={flagUrl}
      alt=""
      className="h-4 w-4 shrink-0 rounded-sm border border-border/30 object-cover shadow-sm"
      width={16}
      height={16}
      onError={() => setFailed(true)}
    />
  )
}

function referralStatusVariant(
  status: string,
): 'pending' | 'success' | 'error' | 'info' {
  if (status === 'converted') return 'success'
  if (status === 'expired') return 'error'
  if (status === 'pending') return 'pending'
  return 'info'
}

function rewardStatusVariant(status: string): 'pending' | 'success' | 'info' {
  if (status === 'claimed') return 'success'
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
  if (status === 'claimed') return t('Claimed')
  if (status === 'pending') return t('Pending')
  return status
}

function CreateLinkDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const t = useT()
  const createLink = useCreateAffiliateLink()
  const [linkId, setLinkId] = useState<string | undefined>(undefined)
  const [name, setName] = useState('')

  useEffect(() => {
    if (!open) {
      setLinkId(undefined)
      setName('')
    }
  }, [open])

  const handleCreate = async () => {
    try {
      await createLink.mutateAsync({
        linkId,
        name: name.trim() || undefined,
      })
      toast.success(t('Affiliate link created'))
      onOpenChange(false)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create affiliate link')))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Create link')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Create a shareable invite link. The link ID is your referral code.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-4 space-y-4">
          <div className="space-y-2">
            <Label htmlFor="affiliate-link-name">{t('Name')}</Label>
            <Input
              id="affiliate-link-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t('Optional name')}
              maxLength={128}
              className="h-9"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="affiliate-link-id">{t('Link ID')}</Label>
            <IdInput
              id="affiliate-link-id"
              value={linkId}
              onChange={setLinkId}
              placeholder={t('Leave blank to auto-generate')}
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={createLink.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            disabled={createLink.isPending}
            onClick={handleCreate}
            {...analyticsAttrs('create-affiliate-link')}
          >
            {t('Create')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function DeleteLinkDialog({
  link,
  open,
  onOpenChange,
}: {
  link: Models.AffiliateLink | null
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const t = useT()
  const deleteLink = useDeleteAffiliateLink()

  const handleDelete = async () => {
    if (!link) return
    try {
      await deleteLink.mutateAsync(link.$id)
      toast.success(t('Affiliate link deleted'))
      onOpenChange(false)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete affiliate link')))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Delete link')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Existing referrals and rewards keep their history. New visits to this invite URL will stop working.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={deleteLink.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            disabled={deleteLink.isPending}
            onClick={handleDelete}
            {...analyticsAttrs('delete-affiliate-link')}
          >
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function LinksCard({
  initialData,
}: {
  initialData?: Models.AffiliateLinkList
}) {
  const t = useT()
  const {
    requestedPage,
    displayedPage,
    setDisplayedPage,
    pageSize,
    handlePageChange,
    handlePageSizeChange,
  } = useAffiliateCardPagination()
  const [createOpen, setCreateOpen] = useState(false)
  const [linkToDelete, setLinkToDelete] = useState<Models.AffiliateLink | null>(
    null,
  )
  const requested = useAffiliateLinks(requestedPage - 1, pageSize)
  const displayed = useAffiliateLinks(displayedPage - 1, pageSize)

  useEffect(() => {
    if (requestedPage !== displayedPage && !requested.isFetching && requested.isSuccess) {
      setDisplayedPage(requestedPage)
    }
  }, [
    requestedPage,
    displayedPage,
    requested.isFetching,
    requested.isSuccess,
    setDisplayedPage,
  ])

  const isFirstPage = displayedPage === 1
  const matchesDefaultPageSize = pageSize === DEFAULT_PAGE_SIZE
  const links =
    isFirstPage &&
    matchesDefaultPageSize &&
    initialData &&
    displayed.links.length === 0
      ? initialData.links
      : displayed.links
  const total =
    isFirstPage && matchesDefaultPageSize && initialData
      ? displayed.total || initialData.total
      : displayed.total

  useEffect(() => {
    if (total <= 0) return
    const maxPage = Math.max(1, Math.ceil(total / pageSize))
    if (requestedPage > maxPage) handlePageChange(maxPage)
    if (displayedPage > maxPage) setDisplayedPage(maxPage)
  }, [
    total,
    pageSize,
    requestedPage,
    displayedPage,
    handlePageChange,
    setDisplayedPage,
  ])

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Links')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Share invite links to attribute signups. Clicks are tracked automatically.',
              )}
            </p>
          </div>
          <Button
            size="sm"
            className="h-9 shrink-0 text-[13px]"
            onClick={() => setCreateOpen(true)}
            {...analyticsAttrs('create-affiliate-link')}
          >
            <Plus className="mr-1.5 h-4 w-4" />
            {t('Create link')}
          </Button>
        </div>
        <div className="border-t border-border" />
        {displayed.isLoading && links.length === 0 && total === 0 ? (
          <div className="px-6 py-10 text-center text-[13px] text-muted-foreground">
            {t('Loading links...')}
          </div>
        ) : total === 0 ? (
          <div className="px-6 py-6">
            <EmptyState
              icon={Link2}
              title={t('No links yet')}
              description={t(
                'Create your first invite link to start referring users.',
              )}
            />
          </div>
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Name')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Invite link')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Status')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Created')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[100px]" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {links.map((link) => {
                  const inviteUrl = buildAffiliateInviteUrl(link.$id)
                  return (
                    <TableRow key={link.$id}>
                      <TableCell className="px-4 py-3">
                        <span className="text-[13px] font-medium">
                          {link.name?.trim() || t('Untitled')}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 max-w-[min(420px,40vw)]">
                        <CopyableId
                          id={inviteUrl}
                          displayText={inviteUrl}
                          variant="inline"
                          size="sm"
                          constrainToContainer
                          copyToastLabel="Invite link"
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <Badge
                          variant={
                            link.status === 'active' ? 'success' : 'inactive'
                          }
                          className="text-[10px] shrink-0"
                        >
                          {link.status === 'active'
                            ? t('Active')
                            : t('Disabled')}
                        </Badge>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <DateTooltip date={link.$createdAt} />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          className="h-8 w-8 p-0"
                          onClick={() => setLinkToDelete(link)}
                          aria-label={t('Delete')}
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
            <div className="border-t border-border px-4">
              <Pagination
                currentPage={displayedPage}
                totalItems={total}
                pageSize={pageSize}
                pageSizeOptions={AFFILIATE_PAGE_SIZE_OPTIONS}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel={t('links')}
                scrollToTopOnPageChange={false}
              />
            </div>
          </>
        )}
      </div>

      <CreateLinkDialog open={createOpen} onOpenChange={setCreateOpen} />
      <DeleteLinkDialog
        link={linkToDelete}
        open={!!linkToDelete}
        onOpenChange={(open) => {
          if (!open) setLinkToDelete(null)
        }}
      />
    </>
  )
}

function ReferralsCard({
  initialData,
  links,
}: {
  initialData?: Models.AffiliateReferralList
  links: Models.AffiliateLink[]
}) {
  const t = useT()
  const {
    requestedPage,
    displayedPage,
    setDisplayedPage,
    pageSize,
    handlePageChange,
    handlePageSizeChange,
  } = useAffiliateCardPagination()
  const { lookups: countryLookups } = useCountryLookups()

  const requested = useAffiliateReferrals(requestedPage - 1, pageSize)
  const displayed = useAffiliateReferrals(displayedPage - 1, pageSize)

  useEffect(() => {
    if (requestedPage !== displayedPage && !requested.isFetching && requested.isSuccess) {
      setDisplayedPage(requestedPage)
    }
  }, [
    requestedPage,
    displayedPage,
    requested.isFetching,
    requested.isSuccess,
    setDisplayedPage,
  ])

  const isFirstPage = displayedPage === 1
  const matchesDefaultPageSize = pageSize === DEFAULT_PAGE_SIZE
  const referrals =
    isFirstPage &&
    matchesDefaultPageSize &&
    initialData &&
    displayed.referrals.length === 0
      ? initialData.referrals
      : displayed.referrals
  const total =
    isFirstPage && matchesDefaultPageSize && initialData
      ? displayed.total || initialData.total
      : displayed.total

  useEffect(() => {
    if (total <= 0) return
    const maxPage = Math.max(1, Math.ceil(total / pageSize))
    if (requestedPage > maxPage) handlePageChange(maxPage)
    if (displayedPage > maxPage) setDisplayedPage(maxPage)
  }, [
    total,
    pageSize,
    requestedPage,
    displayedPage,
    handlePageChange,
    setDisplayedPage,
  ])

  const linkNameById = useMemo(() => {
    const map = new Map<string, string>()
    links.forEach((link) => {
      map.set(link.$id, link.name?.trim() || link.$id)
    })
    return map
  }, [links])

  const getCountryFlagUrl = (countryCode?: string) => {
    const code = normalizeCountryCode(countryCode)
    if (!code) return null
    return `${getBaseEndpoint()}/avatars/flags/${code.toLowerCase()}?width=20&height=20&quality=100&project=console`
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Referrals')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Signups attributed to your invite links. Converted referrals earn you credits.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      {displayed.isLoading && referrals.length === 0 && total === 0 ? (
        <div className="px-6 py-10 text-center text-[13px] text-muted-foreground">
          {t('Loading referrals...')}
        </div>
      ) : total === 0 ? (
        <div className="px-6 py-6">
          <EmptyState
            icon={Gift}
            title={t('No referrals yet')}
            description={t(
              'Share an invite link to start attributing signups.',
            )}
          />
        </div>
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('User')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Country')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Link')}
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
              {referrals.map((referral) => {
                const flagUrl = getCountryFlagUrl(referral.referredUserCountry)
                const countryLabel =
                  getCountryDisplayName(
                    referral.referredUserCountry,
                    countryLookups,
                  ) ?? t('Unknown')

                return (
                  <TableRow key={referral.$id}>
                    <TableCell className="px-4 py-3">
                      <span className="font-mono text-[13px] text-foreground">
                        {referral.referredUserMaskedId}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <ReferralCountryFlag flagUrl={flagUrl} />
                        <span className="text-[13px] text-muted-foreground">
                          {countryLabel}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <span className="text-[13px] text-muted-foreground">
                        {linkNameById.get(referral.linkId) ?? referral.linkId}
                      </span>
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
                )
              })}
            </TableBody>
          </Table>
          <div className="border-t border-border px-4">
            <Pagination
              currentPage={displayedPage}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={AFFILIATE_PAGE_SIZE_OPTIONS}
              onPageChange={handlePageChange}
              onPageSizeChange={handlePageSizeChange}
              itemLabel={t('referrals')}
              scrollToTopOnPageChange={false}
            />
          </div>
        </>
      )}
    </div>
  )
}

function RewardsCard({
  initialData,
  organizations,
  links,
}: {
  initialData?: Models.AffiliateRewardList
  organizations: Models.Organization[]
  links: Models.AffiliateLink[]
}) {
  const t = useT()
  const {
    requestedPage,
    displayedPage,
    setDisplayedPage,
    pageSize,
    handlePageChange,
    handlePageSizeChange,
  } = useAffiliateCardPagination()
  const [selectedReward, setSelectedReward] =
    useState<Models.AffiliateReward | null>(null)

  const requested = useAffiliateRewards(requestedPage - 1, pageSize)
  const displayed = useAffiliateRewards(displayedPage - 1, pageSize)

  useEffect(() => {
    if (requestedPage !== displayedPage && !requested.isFetching && requested.isSuccess) {
      setDisplayedPage(requestedPage)
    }
  }, [
    requestedPage,
    displayedPage,
    requested.isFetching,
    requested.isSuccess,
    setDisplayedPage,
  ])

  const isFirstPage = displayedPage === 1
  const matchesDefaultPageSize = pageSize === DEFAULT_PAGE_SIZE
  const rewards =
    isFirstPage &&
    matchesDefaultPageSize &&
    initialData &&
    displayed.rewards.length === 0
      ? initialData.rewards
      : displayed.rewards
  const total =
    isFirstPage && matchesDefaultPageSize && initialData
      ? displayed.total || initialData.total
      : displayed.total

  useEffect(() => {
    if (total <= 0) return
    const maxPage = Math.max(1, Math.ceil(total / pageSize))
    if (requestedPage > maxPage) handlePageChange(maxPage)
    if (displayedPage > maxPage) setDisplayedPage(maxPage)
  }, [
    total,
    pageSize,
    requestedPage,
    displayedPage,
    handlePageChange,
    setDisplayedPage,
  ])

  const orgNameById = useMemo(() => {
    const map = new Map<string, string>()
    organizations.forEach((org) => map.set(org.$id, org.name))
    return map
  }, [organizations])

  const linkNameById = useMemo(() => {
    const map = new Map<string, string>()
    links.forEach((link) => {
      map.set(link.$id, link.name?.trim() || link.$id)
    })
    return map
  }, [links])

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Rewards')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Credits earned from converted referrals. Claim pending rewards to an organization you own.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        {displayed.isLoading && rewards.length === 0 && total === 0 ? (
          <div className="px-6 py-10 text-center text-[13px] text-muted-foreground">
            {t('Loading rewards...')}
          </div>
        ) : total === 0 ? (
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
                    {t('Link')}
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
                      <span className="text-[13px] text-muted-foreground">
                        {linkNameById.get(reward.linkId) ?? reward.linkId}
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
                          : t('Not claimed')}
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
                          {...analyticsAttrs('claim-affiliate-reward')}
                        >
                          {t('Claim')}
                        </Button>
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <div className="border-t border-border px-4">
              <Pagination
                currentPage={displayedPage}
                totalItems={total}
                pageSize={pageSize}
                pageSizeOptions={AFFILIATE_PAGE_SIZE_OPTIONS}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel={t('rewards')}
                scrollToTopOnPageChange={false}
              />
            </div>
          </>
        )}
      </div>

      <ClaimAffiliateReward
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
  const { data: linksPageData } = useQuery(
    affiliateLinksQueryOptions(0, DEFAULT_PAGE_SIZE),
  )
  const { data: linksLookupData } = useQuery({
    ...affiliateLinksQueryOptions(0, AFFILIATE_LINK_LOOKUP_LIMIT),
    enabled:
      (linksPageData?.total ?? initialData?.links?.total ?? 0) > 0,
  })
  const { data: organizationsFromQuery } = useQuery({
    ...organizationsFullQueryOptions(),
    enabled:
      (linksPageData?.total ?? initialData?.links?.total ?? 0) > 0,
  })

  const links =
    linksLookupData?.links ??
    linksPageData?.links ??
    initialData?.links?.links ??
    []
  const organizations =
    organizationsFromQuery ?? initialData?.organizations ?? []

  // Prefer live query total after create/delete; fall back to loader for first paint.
  const totalLinks = linksPageData?.total ?? initialData?.links?.total

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
            'clicks',
            'signups',
            'conversions',
            'funnel',
            'analytics',
            'chart',
          ],
        },
        node: (
          <AffiliatesOverview
            initialUsage={initialData?.usage}
            initialPendingRewards={initialData?.pendingRewards}
            links={links}
            organizations={organizations}
          />
        ),
      },
      {
        id: 'affiliates-links',
        search: {
          title: 'Links',
          keywords: ['link', 'invite', 'share', 'referral code'],
        },
        node: <LinksCard initialData={initialData?.links} />,
      },
      {
        id: 'affiliates-referrals',
        search: {
          title: 'Referrals',
          keywords: ['referral', 'signup', 'converted', 'pending', 'country'],
        },
        node: (
          <ReferralsCard
            initialData={initialData?.referrals}
            links={links}
          />
        ),
      },
      {
        id: 'affiliates-rewards',
        search: {
          title: 'Rewards',
          keywords: ['reward', 'credits', 'claim', 'pending', 'balance'],
        },
        node: (
          <RewardsCard
            initialData={initialData?.rewards}
            organizations={organizations}
            links={links}
          />
        ),
      },
    ],
    [
      initialData?.links,
      initialData?.referrals,
      initialData?.rewards,
      initialData?.pendingRewards,
      initialData?.usage,
      links,
      organizations,
    ],
  )

  // Stay blank until loader (or cache) resolves so we never flash the wrong branch.
  if (totalLinks === undefined) {
    return null
  }

  if (totalLinks === 0) {
    return <AffiliatesProgramEmpty />
  }

  return <SettingsCardsList cards={cards} />
}
