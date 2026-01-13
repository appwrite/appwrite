import { Mail, Phone, Bell } from 'lucide-react'
import { cn } from '@/lib/utils'

interface MessagingProviderIconProps {
  providerName?: string
  providerType?: 'email' | 'sms' | 'push'
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

const sizeClasses = {
  sm: 'h-5 w-5',
  md: 'h-6 w-6',
  lg: 'h-8 w-8',
}

// Map provider names to icon file names
const providerIconMap: Record<string, string> = {
  // SMS Providers
  twilio: 'twilio.svg',
  msg91: 'msg91.svg',
  telesign: 'telesign.svg',
  textmagic: 'textmagic.svg',
  vonage: 'vonage.svg',
  // Email Providers
  mailgun: 'mailgun.svg',
  sendgrid: 'sendgrid.svg',
  // Note: resend, smtp, fcm, apns don't have icons yet
}

export function MessagingProviderIcon({
  providerName,
  providerType,
  className,
  size = 'md',
}: MessagingProviderIconProps) {
  const normalized = providerName?.toLowerCase() || ''
  const iconFile = providerIconMap[normalized]
  const sizeClass = sizeClasses[size as keyof typeof sizeClasses]

  // If we have an icon file, use it
  if (iconFile) {
    return (
      <img
        src={`/icons/${iconFile}`}
        alt={providerName || 'Provider'}
        className={cn(
          sizeClass,
          // Make icons work in both light and dark mode
          'brightness-0 dark:brightness-100',
          className
        )}
      />
    )
  }

  // Fallback to type-based icons
  const FallbackIcon = providerType === 'email' ? Mail : providerType === 'sms' ? Phone : Bell

  return <FallbackIcon className={cn(sizeClass, className)} />
}
