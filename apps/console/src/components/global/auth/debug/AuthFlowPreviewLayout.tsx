import type { ReactNode } from 'react'
import {
  AuthFlowShell,
  type AuthFlowShellWidth,
} from '@/components/global/auth/AuthFlowShell'

type AuthFlowPreviewLayoutProps = {
  children: ReactNode
  width?: AuthFlowShellWidth
  showLegal?: boolean
  showLogo?: boolean
  footer?: ReactNode | null
  accountSwitcher?: ReactNode
}

export function AuthFlowPreviewLayout({
  children,
  width = 'illustration',
  showLegal = true,
  showLogo = true,
  footer,
  accountSwitcher,
}: AuthFlowPreviewLayoutProps) {
  return (
    <AuthFlowShell
      width={width}
      showLegal={showLegal}
      showLogo={showLogo}
      footer={footer}
      accountSwitcher={accountSwitcher}
    >
      {children}
    </AuthFlowShell>
  )
}
