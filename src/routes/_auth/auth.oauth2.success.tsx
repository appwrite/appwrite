import { createFileRoute } from '@tanstack/react-router'
import { OAuth2RelayCard } from '@/components/global/auth/OAuth2RelayCard'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_auth/auth/oauth2/success')({
  component: OAuth2SuccessPage,
  head: () => ({ meta: [{ title: pageTitle('Login successful') }] }),
})

function OAuth2SuccessPage() {
  const t = useT()
  return <OAuth2RelayCard title={t("You're now logged in")} />
}
