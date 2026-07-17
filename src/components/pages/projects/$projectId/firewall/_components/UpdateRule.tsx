import { useEffect, useState } from 'react'
import { WafRuleAction, type Models } from '@appwrite.io/console'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ConditionsBuilder } from './ConditionsBuilder'
import { FunctionSelector } from '@/components/global/shared/FunctionSelector'
import { SiteSelector } from '@/components/global/shared/SiteSelector'
import { RuleImpactPreview } from './RuleImpactPreview'
import { useUpdateFirewallRule } from '@/lib/react-query/hooks'
import {
  type FirewallCreatableAction,
  getRuleRateLimit,
  getRuleRedirect,
} from '@/lib/firewall/actions'
import {
  FIREWALL_RESOURCE_TYPES,
  createEmptyConditionDraft,
  draftsFromParsedConditions,
  parseFirewallConditions,
  serializeFirewallConditions,
  type FirewallConditionDraft,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { toast } from 'sonner'

interface UpdateRuleProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  rule: Models.WafRule | null
}

export function UpdateRule({
  open,
  onOpenChange,
  projectId,
  rule,
}: UpdateRuleProps) {
  const t = useT()
  const updateMutation = useUpdateFirewallRule(projectId)
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [resourceType, setResourceType] = useState<FirewallResourceType>('api')
  const [resourceId, setResourceId] = useState('')
  const [priority, setPriority] = useState(100)
  const [enabled, setEnabled] = useState(true)
  const [limit, setLimit] = useState(100)
  const [interval, setInterval] = useState(60)
  const [location, setLocation] = useState('/')
  const [statusCode, setStatusCode] = useState(302)
  const [conditions, setConditions] = useState<FirewallConditionDraft[]>([
    createEmptyConditionDraft(),
  ])

  useEffect(() => {
    if (!open || !rule) return
    setName(rule.name)
    setDescription(rule.description || '')
    setResourceType((rule.resourceType as FirewallResourceType) || 'api')
    setResourceId(rule.resourceId || '')
    setPriority(rule.priority)
    setEnabled(rule.enabled)
    const rateLimit = getRuleRateLimit(rule)
    setLimit(rateLimit?.limit ?? 100)
    setInterval(rateLimit?.interval ?? 60)
    const redirect = getRuleRedirect(rule)
    setLocation(redirect?.location ?? '/')
    setStatusCode(redirect?.statusCode ?? 302)
    setConditions(
      draftsFromParsedConditions(parseFirewallConditions(rule.conditions)),
    )
  }, [open, rule])

  if (!rule) return null

  const action = String(rule.action) as FirewallCreatableAction
  const needsResourceId = resourceType !== 'api'
  const canSubmit =
    name.trim().length > 0 &&
    (!needsResourceId || resourceId.trim().length > 0) &&
    (action !== WafRuleAction.RateLimit || (limit > 0 && interval > 0)) &&
    (action !== WafRuleAction.Redirect ||
      (location.trim().length > 0 && statusCode > 0))

  const handleSubmit = async () => {
    if (!canSubmit) return
    try {
      await updateMutation.mutateAsync({
        ruleId: rule.$id,
        action: rule.action,
        resourceType,
        resourceId: needsResourceId ? resourceId.trim() : '',
        name: name.trim(),
        description: description.trim(),
        priority,
        enabled,
        conditions: serializeFirewallConditions(conditions),
        limit,
        interval,
        location: location.trim(),
        statusCode,
      })
      toast.success(t('Firewall rule updated'))
      onOpenChange(false)
    } catch (error) {
      toast.error(
        getErrorMessage(error as Error, t('Failed to update firewall rule')),
      )
    }
  }

  const actionExtras =
    action === WafRuleAction.RateLimit ? (
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-limit" className="text-[12px]">
            {t('Request limit')}
          </Label>
          <Input
            id="update-firewall-limit"
            type="number"
            min={1}
            value={limit}
            onChange={(e) => setLimit(Number(e.target.value) || 1)}
            disabled={updateMutation.isPending}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-interval" className="text-[12px]">
            {t('Interval (seconds)')}
          </Label>
          <Input
            id="update-firewall-interval"
            type="number"
            min={1}
            value={interval}
            onChange={(e) => setInterval(Number(e.target.value) || 1)}
            disabled={updateMutation.isPending}
          />
        </div>
      </div>
    ) : action === WafRuleAction.Redirect ? (
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-location" className="text-[12px]">
            {t('Redirect location')}
          </Label>
          <Input
            id="update-firewall-location"
            value={location}
            onChange={(e) => setLocation(e.target.value)}
            disabled={updateMutation.isPending}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="update-firewall-status-code" className="text-[12px]">
            {t('Status code')}
          </Label>
          <Input
            id="update-firewall-status-code"
            type="number"
            min={300}
            max={399}
            value={statusCode}
            onChange={(e) => setStatusCode(Number(e.target.value) || 302)}
            disabled={updateMutation.isPending}
          />
        </div>
      </div>
    ) : null

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('Update firewall rule')}
      description={t('Modify the rule configuration and conditions.')}
      maxWidth="sm:max-w-xl"
    >
      <>
        <div className="border-t border-border shrink-0" />
        <div className="flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 overflow-y-auto">
            <div className="space-y-5 px-6 py-6">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2 sm:col-span-2">
                  <Label htmlFor="update-firewall-rule-name">
                    {t('Rule name')}
                  </Label>
                  <Input
                    id="update-firewall-rule-name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder={t('e.g., Deny suspicious IPs')}
                    disabled={updateMutation.isPending}
                  />
                </div>

                <div className="space-y-2 sm:col-span-2">
                  <Label htmlFor="update-firewall-rule-description">
                    {t('Description')}
                  </Label>
                  <Textarea
                    id="update-firewall-rule-description"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder={t(
                      'Optional description of what this rule does',
                    )}
                    rows={2}
                    disabled={updateMutation.isPending}
                  />
                </div>
              </div>

              <div className="space-y-3">
                <div className="space-y-2">
                  <Label>{t('Resource type')}</Label>
                  <Select
                    value={resourceType}
                    disabled={updateMutation.isPending}
                    onValueChange={(value) => {
                      setResourceType(value as FirewallResourceType)
                      setResourceId('')
                    }}
                  >
                    <SelectTrigger className="h-9 w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {FIREWALL_RESOURCE_TYPES.map((resource) => (
                        <SelectItem key={resource.value} value={resource.value}>
                          {t(resource.label)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {needsResourceId ? (
                  <div className="space-y-2">
                    <Label>{t('Resource ID')}</Label>
                    {resourceType === 'functions' ? (
                      <FunctionSelector
                        projectId={projectId}
                        value={resourceId}
                        onValueChange={setResourceId}
                        disabled={updateMutation.isPending}
                        triggerClassName="w-full justify-between font-normal"
                      />
                    ) : (
                      <SiteSelector
                        projectId={projectId}
                        value={resourceId}
                        onValueChange={setResourceId}
                        disabled={updateMutation.isPending}
                        triggerClassName="w-full justify-between font-normal"
                      />
                    )}
                  </div>
                ) : null}
              </div>

              <ConditionsBuilder
                conditions={conditions}
                onChange={setConditions}
                resourceType={resourceType}
                action={action}
                actionReadOnly
                actionExtras={actionExtras}
                disabled={updateMutation.isPending}
              />

              <div className="space-y-2">
                <Label htmlFor="update-firewall-priority">{t('Priority')}</Label>
                <Input
                  id="update-firewall-priority"
                  type="number"
                  min={0}
                  value={priority}
                  onChange={(e) => setPriority(Number(e.target.value) || 0)}
                  disabled={updateMutation.isPending}
                />
                <p className="text-[12px] text-muted-foreground">
                  {t('Lower numbers are evaluated first.')}
                </p>
              </div>

              <div className="flex items-center justify-between rounded-lg border border-border px-4 py-3">
                <div>
                  <p className="text-[13px] font-medium text-foreground">
                    {t('Enabled')}
                  </p>
                  <p className="mt-0.5 text-[12px] text-muted-foreground">
                    {t('Rule will be active immediately')}
                  </p>
                </div>
                <Switch
                  checked={enabled}
                  onCheckedChange={setEnabled}
                  disabled={updateMutation.isPending}
                />
              </div>

              <RuleImpactPreview
                conditions={conditions}
                action={action}
                resourceType={resourceType}
                resourceId={resourceId}
              />
            </div>
          </div>

          <div className="flex shrink-0 items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
            <Button
              onClick={handleSubmit}
              disabled={!canSubmit || updateMutation.isPending}
            >
              {t('Update')}
            </Button>
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={updateMutation.isPending}
            >
              {t('Cancel')}
            </Button>
          </div>
        </div>
      </>
    </BaseDrawer>
  )
}
