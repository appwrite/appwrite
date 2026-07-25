import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, Copy, Gift, Link2, Loader2, Plus, Trash2 } from 'lucide-react'
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
  AFFILIATE_ATTRIBUTION_DAYS,
  AFFILIATE_REWARD_AMOUNT_USD,
  affiliateLinksQueryOptions,
  buildAffiliateInviteUrl,
  countriesQueryOptions,
  organizationsFullQueryOptions,
  useAffiliateLinks,
  useAffiliateReferrals,
  useAffiliateRewards,
  useAffiliateUsage,
  useClaimAffiliateReward,
  useCreateAffiliateLink,
  useDeleteAffiliateLink,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

export type AccountAffiliatesInitialData = {
  links?: Models.AffiliateLinkList
  referrals?: Models.AffiliateReferralList
  rewards?: Models.AffiliateRewardList
  usage?: Models.UsageEventList
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

function OverviewCard({
  initialUsage,
}: {
  initialUsage?: Models.UsageEventList
}) {
  const t = useT()
  const { usage, clicks, signups, conversions, isLoading } = useAffiliateUsage()

  const resolvedUsage = usage ?? initialUsage
  const displayClicks = usage
    ? clicks
    : (resolvedUsage?.metrics
        ?.find((m) => m.metric === 'affiliates.clicks')
        ?.points?.reduce((sum, p) => sum + (p.value || 0), 0) ?? 0)
  const displaySignups = usage
    ? signups
    : (resolvedUsage?.metrics
        ?.find((m) => m.metric === 'affiliates.signups')
        ?.points?.reduce((sum, p) => sum + (p.value || 0), 0) ?? 0)
  const displayConversions = usage
    ? conversions
    : (resolvedUsage?.metrics
        ?.find((m) => m.metric === 'affiliates.conversions')
        ?.points?.reduce((sum, p) => sum + (p.value || 0), 0) ?? 0)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Affiliates program')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Create shareable links and earn $10 in credits when a referred user upgrades to Pro. Attribution lasts 180 days.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:gap-6">
          <div>
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Clicks')}
            </p>
            <p className="mt-1 text-[22px] font-semibold text-foreground">
              {isLoading && !resolvedUsage ? '…' : displayClicks}
            </p>
          </div>
          <div>
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Signups')}
            </p>
            <p className="mt-1 text-[22px] font-semibold text-foreground">
              {isLoading && !resolvedUsage ? '…' : displaySignups}
            </p>
          </div>
          <div>
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Conversions')}
            </p>
            <p className="mt-1 text-[22px] font-semibold text-foreground">
              {isLoading && !resolvedUsage ? '…' : displayConversions}
            </p>
          </div>
        </div>
        <ul className="list-disc pl-5 space-y-1 text-[13px] text-muted-foreground">
          <li>
            {t('Reward')}: {formatCurrency(AFFILIATE_REWARD_AMOUNT_USD)}
          </li>
          <li>
            {t('Attribution window')}: {AFFILIATE_ATTRIBUTION_DAYS} {t('days')}
          </li>
          <li>{t('Qualifying plan')}: Pro</li>
        </ul>
      </div>
    </div>
  )
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
            {createLink.isPending ? (
              <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
            ) : null}
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
            {deleteLink.isPending ? (
              <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
            ) : null}
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
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [createOpen, setCreateOpen] = useState(false)
  const [linkToDelete, setLinkToDelete] = useState<Models.AffiliateLink | null>(
    null,
  )
  const [copiedId, setCopiedId] = useState<string | null>(null)

  const requested = useAffiliateLinks(requestedPage - 1, DEFAULT_PAGE_SIZE)
  const displayed = useAffiliateLinks(displayedPage - 1, DEFAULT_PAGE_SIZE)

  useEffect(() => {
    if (
      requestedPage !== displayedPage &&
      !requested.isFetching &&
      requested.links
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedPage, displayedPage, requested.isFetching, requested.links])

  const isFirstPage = displayedPage === 1
  const links =
    isFirstPage && initialData && displayed.links.length === 0
      ? initialData.links
      : displayed.links
  const total =
    isFirstPage && initialData
      ? displayed.total || initialData.total
      : displayed.total

  const handleCopyInvite = async (linkId: string) => {
    try {
      await navigator.clipboard.writeText(buildAffiliateInviteUrl(linkId))
      setCopiedId(linkId)
      toast.success(t('Invite link copied'))
      window.setTimeout(() => setCopiedId(null), 2000)
    } catch {
      toast.error(t('Failed to copy invite link'))
    }
  }

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
        {displayed.isLoading && links.length === 0 ? (
          <div className="px-6 py-10 text-center text-[13px] text-muted-foreground">
            {t('Loading links...')}
          </div>
        ) : links.length === 0 ? (
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
                    {t('Link ID')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Status')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Created')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[160px]" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {links.map((link) => (
                  <TableRow key={link.$id}>
                    <TableCell className="px-4 py-3">
                      <span className="text-[13px] font-medium">
                        {link.name?.trim() || t('Untitled')}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <CopyableId
                        id={link.$id}
                        displayText={link.$id}
                        variant="inline"
                        size="sm"
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
                      <div className="flex items-center justify-end gap-1.5">
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          className="h-8 text-[12px]"
                          onClick={() => handleCopyInvite(link.$id)}
                          disabled={link.status !== 'active'}
                          {...analyticsAttrs('copy-affiliate-link')}
                        >
                          {copiedId === link.$id ? (
                            <Check className="mr-1.5 h-3.5 w-3.5" />
                          ) : (
                            <Copy className="mr-1.5 h-3.5 w-3.5" />
                          )}
                          {t('Copy invite')}
                        </Button>
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
                      </div>
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
                  itemLabel={t('links')}
                />
              </div>
            ) : null}
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
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const { data: countriesData } = useQuery(countriesQueryOptions())

  const requested = useAffiliateReferrals(requestedPage - 1, DEFAULT_PAGE_SIZE)
  const displayed = useAffiliateReferrals(displayedPage - 1, DEFAULT_PAGE_SIZE)

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
      ? displayed.total || initialData.total
      : displayed.total

  const linkNameById = useMemo(() => {
    const map = new Map<string, string>()
    links.forEach((link) => {
      map.set(link.$id, link.name?.trim() || link.$id)
    })
    return map
  }, [links])

  const countryNameByCode = useMemo(() => {
    const map = new Map<string, string>()
    countriesData?.countries?.forEach((country) => {
      map.set(country.code, country.name)
    })
    return map
  }, [countriesData])

  const getCountryFlagUrl = (countryCode?: string) => {
    if (!countryCode || countryCode === '--') return null
    return `${getBaseEndpoint()}/avatars/flags/${countryCode.toLowerCase()}?width=20&height=20&quality=100&project=console`
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
                  countryNameByCode.get(referral.referredUserCountry) ||
                  referral.referredUserCountry ||
                  t('Unknown')

                return (
                  <TableRow key={referral.$id}>
                    <TableCell className="px-4 py-3">
                      <span className="font-mono text-[13px] text-foreground">
                        {referral.referredUserMaskedId}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        {flagUrl ? (
                          <img
                            src={flagUrl}
                            alt=""
                            className="h-3.5 w-5 rounded-[2px] object-cover"
                            width={20}
                            height={14}
                          />
                        ) : null}
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

function ClaimRewardDialog({
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
  const claimReward = useClaimAffiliateReward()
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

  const handleClaim = async () => {
    if (!reward || !organizationId) return
    try {
      await claimReward.mutateAsync({
        rewardId: reward.$id,
        organizationId,
      })
      toast.success(t('Credits claimed for organization'))
      onOpenChange(false)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to claim credits')))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Claim credits')}</DialogTitle>
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
            disabled={claimReward.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            disabled={!organizationId || claimReward.isPending}
            onClick={handleClaim}
            {...analyticsAttrs('claim-affiliate-reward')}
          >
            {claimReward.isPending ? (
              <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
            ) : null}
            {t('Claim credits')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
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
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [selectedReward, setSelectedReward] =
    useState<Models.AffiliateReward | null>(null)

  const requested = useAffiliateRewards(requestedPage - 1, DEFAULT_PAGE_SIZE)
  const displayed = useAffiliateRewards(displayedPage - 1, DEFAULT_PAGE_SIZE)

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
      ? displayed.total || initialData.total
      : displayed.total

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

      <ClaimRewardDialog
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
  const { data: linksData } = useQuery(affiliateLinksQueryOptions(0, DEFAULT_PAGE_SIZE))
  const { data: organizationsFromQuery } = useQuery(
    organizationsFullQueryOptions(),
  )

  const links =
    linksData?.links ??
    initialData?.links?.links ??
    []
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
            'clicks',
            'signups',
            'conversions',
          ],
        },
        node: <OverviewCard initialUsage={initialData?.usage} />,
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
      initialData?.usage,
      links,
      organizations,
    ],
  )

  return <SettingsCardsList cards={cards} />
}
