import { CreditCard } from 'lucide-react'
import { formatPaymentCardBrand } from '@/components/pages/organizations/$orgId/billing/utils'
import { cn } from '@/lib/utils'

interface PaymentMethodBrandAvatarProps {
  brand?: string
  className?: string
}

export function PaymentMethodBrandAvatar({
  brand,
  className,
}: PaymentMethodBrandAvatarProps) {
  const label = formatPaymentCardBrand(brand)

  return (
    <CreditCard
      className={cn('h-4 w-4 shrink-0 text-muted-foreground', className)}
      aria-label={label}
    />
  )
}
