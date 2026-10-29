import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { Settings } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { OrganizationDeleteSettingsCard } from '@/components/pages/organizations/$orgId/overview/_components/OrganizationDeleteSettingsCard'
import { OrganizationIdSettingsCard } from '@/components/pages/organizations/$orgId/overview/_components/OrganizationIdSettingsCard'
import { OrganizationMembersManager } from '@/components/pages/organizations/$orgId/overview/_components/OrganizationMembersManager'
import { OrganizationNameSettingsCard } from '@/components/pages/organizations/$orgId/overview/_components/OrganizationNameSettingsCard'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { apiNavItemClassName } from '@/lib/api-explorer/nav-styles'
import { canAccessOrgSettingsOverview } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  isPartnerOrganization,
  type PartnerAwareOrganization,
} from '@/lib/partners'
import { useOrganizationScopes, useOrganizations } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'

type SettingsSection = 'general' | 'members'

export function PartnerSettingsButton({ onClick }: { onClick: () => void }) {
  const t = useT()

  return (
    <Button
      size="icon"
      variant="ghost"
      className="h-8 w-8 cursor-pointer"
      onClick={onClick}
      aria-label={t('Settings')}
      title={t('Settings')}
    >
      <Settings className="h-4 w-4" />
    </Button>
  )
}

export function PartnerSettingsDialog({
  partnerId,
  partner,
  open,
  onOpenChange,
}: {
  partnerId: string
  partner: PartnerAwareOrganization | null
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const t = useT()
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(partnerId)
  const { organizations } = useOrganizations()
  const [section, setSection] = useState<SettingsSection>('general')

  const canEditSettings = canAccessOrgSettingsOverview(access)
  const settingsDisabledTooltip = t(
    "You don't have permission to update organization settings.",
  )
  const deleteDisabledTooltip = t(
    "You don't have permission to delete this organization.",
  )

  useEffect(() => {
    if (open) setSection('general')
  }, [open])

  const handleDeleted = () => {
    onOpenChange(false)
    const remainingPartners = organizations.filter(
      (org) => org.$id !== partnerId && isPartnerOrganization(org),
    )
    if (remainingPartners[0]) {
      void navigate({
        to: '/partners/$partnerId',
        params: { partnerId: remainingPartners[0].$id },
        replace: true,
      })
      return
    }
    void navigate({ to: '/', replace: true })
  }

  const slackUrl = partner?.programSlackChannelUrl
  const slackName = partner?.programSlackChannelName

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex h-[min(90dvh,800px)] max-h-[min(90dvh,800px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-4xl">
        <DialogHeader className="shrink-0 px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Partner settings')}</DialogTitle>
          <DialogDescription className="mt-2 text-[13px]">
            {t('Manage this partner organization and its team.')}
          </DialogDescription>
        </DialogHeader>
        <div className="shrink-0 border-t border-border" />
        <div className="flex min-h-0 flex-1 overflow-hidden">
          <nav className="w-44 shrink-0 overflow-y-auto border-e border-border p-3">
            <ul className="space-y-0.5">
              {(
                [
                  { id: 'general', label: t('General') },
                  { id: 'members', label: t('Members') },
                ] as const
              ).map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    className={cn(
                      apiNavItemClassName(section === item.id),
                      'w-full text-start',
                    )}
                    onClick={() => setSection(item.id)}
                  >
                    {item.label}
                  </button>
                </li>
              ))}
            </ul>
          </nav>
          {section === 'general' ? (
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-6">
              <OrganizationNameSettingsCard
                organizationId={partnerId}
                organizationName={partner?.name ?? ''}
                canUpdate={canEditSettings}
                disabledTooltip={settingsDisabledTooltip}
              />
              <OrganizationIdSettingsCard organizationId={partnerId} />
              <div className="overflow-hidden rounded-xl border border-border bg-card/50">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    {t('Slack channel')}
                  </h3>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <p className="mb-3 text-[13px] text-muted-foreground">
                    {t('Slack is assigned by Appwrite.')}
                  </p>
                  {slackUrl ? (
                    <a
                      href={slackUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="block text-[13px] text-foreground underline-offset-4 hover:underline"
                    >
                      {(slackName || slackUrl).replace(/^#/, '')}
                    </a>
                  ) : (
                    <p className="text-[13px] text-muted-foreground">
                      {t('No channel assigned yet.')}
                    </p>
                  )}
                </div>
              </div>
              {features.multiTenancy ? (
                <OrganizationDeleteSettingsCard
                  organizationId={partnerId}
                  organizationName={partner?.name ?? ''}
                  canDelete={canEditSettings}
                  disabledTooltip={deleteDisabledTooltip}
                  onDeleted={handleDeleted}
                />
              ) : null}
            </div>
          ) : (
            <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
              <OrganizationMembersManager
                organizationId={partnerId}
                inviteButtonClassName="h-8 cursor-pointer text-[12px]"
                searchWrapClassName="w-full"
                searchInputClassName="h-8 ps-8 text-[13px]"
                header={({ inviteButton, searchField }) => (
                  <div className="flex shrink-0 items-center gap-2 border-b border-border px-4 py-3">
                    <div className="min-w-0 flex-1">{searchField}</div>
                    {inviteButton}
                  </div>
                )}
              />
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
