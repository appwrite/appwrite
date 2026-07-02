import { useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Slider } from '@/components/ui/slider'
import { useT } from '@/lib/i18n/translate'

interface SampleDataModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onConfirm: (rowCount: number) => void
  isLoading?: boolean
}

export function SampleDataModal({
  open,
  onOpenChange,
  onConfirm,
  isLoading = false,
}: SampleDataModalProps) {
  const t = useT()
  const [rowCount, setRowCount] = useState(25)

  const handleConfirm = () => {
    onConfirm(rowCount)
  }

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
      if (!newOpen) {
        // Reset to default when closing
        setRowCount(25)
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Generate Sample Data')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t("Select the number of rows to generate. Sample data will be created based on your table's column types and constraints.")}
          </DialogDescription>
        </DialogHeader>

        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div className="space-y-2">
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">{t('Number of rows')}</span>
                <span className="font-medium">{rowCount}</span>
              </div>
              <Slider
                value={[rowCount]}
                onValueChange={(value) => setRowCount(value[0])}
                min={1}
                max={100}
                step={1}
                disabled={isLoading}
              />
            </div>
          </div>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleConfirm} disabled={isLoading}>
            {t('Create')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
