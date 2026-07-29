import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Plus, X } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import {
  useDeleteAnalyticsProperty,
  useUpdateAnalyticsProperty,
  type UpdateAnalyticsPropertyInput,
} from '@/lib/react-query/hooks'
import { DeleteProperty } from './DeleteProperty'
import { IntegrationSnippets } from './IntegrationSnippets'

interface PropertySettingsProps {
  projectId: string
  property: Models.AnalyticsProperty
  /** When false, the form is read-only (no write permission). */
  canWrite?: boolean
}

function sameOrigins(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((value, index) => value === b[index])
}

export function PropertySettings({
  projectId,
  property,
  canWrite = true,
}: PropertySettingsProps) {
  const t = useT()
  const navigate = useNavigate()

  const [name, setName] = useState(property.name)
  const [domain, setDomain] = useState(property.domain)
  const [timezone, setTimezone] = useState(property.timezone)
  const [allowedOrigins, setAllowedOrigins] = useState<string[]>(
    property.allowedOrigins ?? [],
  )
  const [originInput, setOriginInput] = useState('')
  const [deleteOpen, setDeleteOpen] = useState(false)

  // Re-seed the form whenever the property is refetched.
  useEffect(() => {
    setName(property.name)
    setDomain(property.domain)
    setTimezone(property.timezone)
    setAllowedOrigins(property.allowedOrigins ?? [])
  }, [property])

  const updateMutation = useUpdateAnalyticsProperty(projectId)
  const deleteMutation = useDeleteAnalyticsProperty(projectId)

  // Only changed fields are sent: the backend does a sparse update, and
  // `false` / `''` / `[]` are real values rather than "unset".
  const applyUpdate = (
    patch: Omit<UpdateAnalyticsPropertyInput, 'propertyId'>,
    successMessage: string,
  ) => {
    updateMutation.mutate(
      { propertyId: property.$id, ...patch },
      {
        onSuccess: () => toast.success(successMessage),
        onError: (error) => toast.error(getErrorMessage(error)),
      },
    )
  }

  const handleDelete = (propertyId: string) => {
    deleteMutation.mutate(propertyId, {
      onSuccess: () => {
        setDeleteOpen(false)
        toast.success(t('Property deleted'))
        navigate({
          to: '/projects/$projectId/analytics',
          params: { projectId },
        })
      },
      onError: (error) => toast.error(getErrorMessage(error)),
    })
  }

  const addOrigin = () => {
    const value = originInput.trim()
    if (!value || allowedOrigins.includes(value)) return
    setAllowedOrigins((prev) => [...prev, value])
    setOriginInput('')
  }

  const isBusy = updateMutation.isPending || !canWrite
  const nameChanged = name.trim() !== property.name && !!name.trim()
  const domainChanged = domain.trim() !== property.domain
  const timezoneChanged = timezone.trim() !== property.timezone
  const originsChanged = !sameOrigins(
    allowedOrigins,
    property.allowedOrigins ?? [],
  )

  return (
    <div className="w-full px-4 py-4 sm:px-6">
      <div className="space-y-6">
        {/* Integration */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Integration')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Add tracking to your site or app to start collecting events.',
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <IntegrationSnippets projectId={projectId} property={property} />
          </div>
        </div>

        {/* Name */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Name')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <p className="text-[13px] text-muted-foreground">
              {t("Update this property's display name.")}
            </p>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t('Property name')}
              disabled={isBusy}
              className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={!nameChanged || isBusy}
              onClick={() =>
                applyUpdate({ name: name.trim() }, t('Name updated'))
              }
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Tracking and visibility */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Tracking')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Control whether this property accepts events and who can see its stats.',
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="flex items-center gap-3">
              <Switch
                id="property-enabled"
                checked={property.enabled}
                disabled={isBusy}
                onCheckedChange={(checked) =>
                  applyUpdate(
                    { enabled: checked },
                    checked ? t('Tracking enabled') : t('Tracking disabled'),
                  )
                }
              />
              <Label
                htmlFor="property-enabled"
                className="text-[13px] text-foreground"
              >
                {property.enabled
                  ? t('Tracking is enabled')
                  : t('Tracking is disabled')}
              </Label>
            </div>
            <div className="flex items-center gap-3">
              <Switch
                id="property-public"
                checked={property.public}
                disabled={isBusy}
                onCheckedChange={(checked) =>
                  applyUpdate(
                    { xpublic: checked },
                    checked
                      ? t('Stats are now public')
                      : t('Stats are now private'),
                  )
                }
              />
              <Label
                htmlFor="property-public"
                className="text-[13px] text-foreground"
              >
                {property.public
                  ? t('Stats are publicly viewable')
                  : t('Stats are private')}
              </Label>
            </div>
          </div>
        </div>

        {/* Domain */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Domain')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <p className="text-[13px] text-muted-foreground">
              {t('Primary domain being tracked. Optional for native apps.')}
            </p>
            <Input
              value={domain}
              onChange={(e) => setDomain(e.target.value)}
              placeholder={t('example.com')}
              disabled={isBusy}
              className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={!domainChanged || isBusy}
              onClick={() =>
                applyUpdate({ domain: domain.trim() }, t('Domain updated'))
              }
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Timezone */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Timezone')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <p className="text-[13px] text-muted-foreground">
              {t(
                'IANA timezone used to decide where each day starts and ends for stats.',
              )}
            </p>
            <Input
              value={timezone}
              onChange={(e) => setTimezone(e.target.value)}
              placeholder="UTC"
              disabled={isBusy}
              className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={!timezoneChanged || isBusy}
              onClick={() =>
                applyUpdate(
                  { timezone: timezone.trim() },
                  t('Timezone updated'),
                )
              }
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Allowed origins */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Allowed origins')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Origins allowed to send tracking events. Use * to allow all origins.',
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex max-w-sm items-center gap-2">
              <Input
                value={originInput}
                onChange={(e) => setOriginInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    addOrigin()
                  }
                }}
                placeholder={t('https://example.com')}
                disabled={isBusy}
                className="h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              />
              <Button
                variant="outline"
                size="sm"
                className="h-9 shrink-0 text-[13px]"
                onClick={addOrigin}
                disabled={!originInput.trim() || isBusy}
              >
                <Plus className="mr-1.5 h-3.5 w-3.5" />
                {t('Add')}
              </Button>
            </div>
            {allowedOrigins.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-2">
                {allowedOrigins.map((origin) => (
                  <span
                    key={origin}
                    className="inline-flex items-center gap-1.5 rounded-md bg-muted px-2 py-1 text-[12px] text-foreground"
                  >
                    {origin}
                    <button
                      type="button"
                      aria-label={t('Remove')}
                      disabled={isBusy}
                      onClick={() =>
                        setAllowedOrigins((prev) =>
                          prev.filter((value) => value !== origin),
                        )
                      }
                      className="text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                ))}
              </div>
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={!originsChanged || isBusy}
              onClick={() =>
                applyUpdate({ allowedOrigins }, t('Allowed origins updated'))
              }
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Identifiers */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Property details')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-3">
            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
              <span className="text-[13px] text-muted-foreground">
                {t('Property ID')}
              </span>
              <CopyableId id={property.$id} size="xs" />
            </div>
            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
              <span className="text-[13px] text-muted-foreground">
                {t('Snippet ID')}
              </span>
              {property.snippetId ? (
                <CopyableId id={property.snippetId} size="xs" />
              ) : (
                <span className="text-[13px] text-muted-foreground">-</span>
              )}
            </div>
            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
              <span className="text-[13px] text-muted-foreground">
                {t('Created')}
              </span>
              <DateTooltip
                date={new Date(property.$createdAt)}
                showFormattedDate
                className="text-[13px] text-foreground"
              />
            </div>
          </div>
        </div>

        {/* Delete */}
        {canWrite && (
          <div className="rounded-xl border border-red-500/30 bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Delete property')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                {t(
                  'Permanently delete this property and every event and session collected for it. This action cannot be undone.',
                )}
              </p>
            </div>
            <div className="border-t border-red-500/20" />
            <div className="px-6 py-4">
              <div className="flex items-center gap-3">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[14px] font-medium text-foreground">
                    {property.name}
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    {t('Last updated:')}{' '}
                    <DateTooltip
                      date={new Date(property.$updatedAt)}
                      showFormattedDate
                      className="text-foreground"
                    />
                  </p>
                </div>
              </div>
            </div>
            <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
              <Button
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setDeleteOpen(true)}
              >
                {t('Delete')}
              </Button>
            </div>
          </div>
        )}
      </div>

      <DeleteProperty
        property={property}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        onConfirm={handleDelete}
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
