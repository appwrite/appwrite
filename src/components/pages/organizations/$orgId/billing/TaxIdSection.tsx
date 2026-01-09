import { FileText, Pencil, Plus, CheckCircle2, AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { taxId } from '@/lib/utils/mock-data'
import { cn } from '@/lib/utils'

/**
 * TaxIdSection Component
 *
 * Displays and manages tax ID information:
 * - Tax ID type and value
 * - Verification status
 * - Update/add actions
 *
 * Props:
 * - onEditTaxId?: () => void - Callback to update tax ID
 *
 * Edge cases:
 * - No tax ID: Shows add tax ID prompt
 * - Unverified: Shows pending verification status
 */

interface TaxIdSectionProps {
  onEditTaxId?: () => void
}

const TAX_ID_LABELS: Record<string, string> = {
  vat: 'VAT ID',
  gst: 'GST Number',
  ein: 'EIN',
  other: 'Tax ID',
}

export function TaxIdSection({ onEditTaxId }: TaxIdSectionProps) {
  const hasTaxId = taxId && taxId.value

  if (!hasTaxId) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Tax ID</h3>
        </div>
        <div className="border-t border-border px-6 py-8 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
            <FileText className="h-6 w-6 text-muted-foreground" />
          </div>
          <p className="text-[13px] text-muted-foreground mb-4">
            No tax ID on file
          </p>
          <Button
            size="sm"
            className="h-9 gap-2 text-[13px]"
            onClick={onEditTaxId}
          >
            <Plus className="h-4 w-4" />
            Add tax ID
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">Tax ID</h3>
      </div>

      {/* Tax ID Display */}
      <div className="border-t border-border px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
              <FileText className="h-5 w-5 text-muted-foreground" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <p className="text-[13px] font-medium text-foreground">
                  {TAX_ID_LABELS[taxId.type] || 'Tax ID'}: {taxId.value}
                </p>
              </div>
              <p className="text-[12px] text-muted-foreground">
                {taxId.country}
              </p>
            </div>
          </div>
          <Badge
            variant={taxId.verified ? 'verified' : 'pending'}
            className="gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
          >
            {taxId.verified ? (
              <>
                <CheckCircle2 className="h-3.5 w-3.5" />
                Verified
              </>
            ) : (
              <>
                <AlertCircle className="h-3.5 w-3.5" />
                Pending verification
              </>
            )}
          </Badge>
        </div>
      </div>

      {/* Footer */}
      <div className="border-t border-border px-6 py-4 bg-muted/30">
        <Button
          variant="outline"
          size="sm"
          className="h-9 gap-2 text-[13px]"
          onClick={onEditTaxId}
        >
          <Pencil className="h-4 w-4" />
          Update tax ID
        </Button>
      </div>
    </div>
  )
}
