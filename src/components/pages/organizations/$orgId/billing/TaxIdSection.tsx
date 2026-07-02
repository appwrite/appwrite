import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  useOrganizationById,
  useUpdateOrganizationTaxId,
} from '@/lib/react-query/hooks'
import { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

/**
 * TaxIdSection Component
 *
 * Displays and manages tax ID information:
 * - Tax ID value
 * - Update/add actions
 *
 * Props:
 * - onEditTaxId?: () => void - Callback to update tax ID
 * - orgId?: string - Organization ID
 *
 * Edge cases:
 * - No tax ID: Shows add tax ID prompt
 */

interface TaxIdSectionProps {
  onEditTaxId?: () => void
  orgId?: string
}

export function TaxIdSection({ orgId }: TaxIdSectionProps) {
  const t = useT()
  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const updateTaxIdMutation = useUpdateOrganizationTaxId()
  const [taxId, setTaxId] = useState('')
  const [hasChanges, setHasChanges] = useState(false)

  // Initialize tax ID from organization
  useEffect(() => {
    if (organization?.billingTaxId) {
      setTaxId(organization.billingTaxId)
      setHasChanges(false)
    } else {
      setTaxId('')
      setHasChanges(false)
    }
  }, [organization?.billingTaxId])

  const handleTaxIdChange = (value: string) => {
    setTaxId(value)
    setHasChanges(value !== (organization?.billingTaxId || ''))
  }

  const handleUpdate = async () => {
    if (!orgId) return

    try {
      await updateTaxIdMutation.mutateAsync({
        organizationId: orgId,
        billingTaxId: taxId.trim() || undefined,
      })
      toast.success(t('Tax ID updated'))
      setHasChanges(false)
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to update tax ID'),
      )
    }
  }

  if (orgLoading && !organization) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">{t('Tax ID')}</h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">{t('Loading...')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">{t('Tax ID')}</h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <Input
          value={taxId}
          onChange={(e) => handleTaxIdChange(e.target.value)}
          placeholder={t('Enter tax ID (e.g., VAT, GST, EIN)')}
          className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
        />
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          onClick={handleUpdate}
          disabled={!hasChanges || updateTaxIdMutation.isPending}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
