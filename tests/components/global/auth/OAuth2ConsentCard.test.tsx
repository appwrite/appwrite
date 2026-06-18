import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { OAuth2ConsentCard } from '@/components/global/auth/OAuth2ConsentCard'

const approve = vi.fn()
const reject = vi.fn()

vi.mock('@/lib/appwrite/sdk', () => ({
  sdk: {
    forConsole: {
      oauth2: { approve: () => approve(), reject: () => reject() },
    },
  },
}))

vi.mock('sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn() },
}))

const grant = {
  $id: 'grant-1',
  scopes: ['openid', 'profile', 'email'],
  authorizationDetails: '',
  redirectUri: 'https://client.example.com/callback',
} as unknown as Models.Oauth2Grant

const app = {
  name: 'Example App',
  tagline: 'An example client.',
  logoUri: '',
  clientUri: 'https://client.example.com',
  privacyPolicyUrl: '',
  termsUrl: '',
} as unknown as Models.App

function renderCard() {
  const queryClient = new QueryClient({
    defaultOptions: { mutations: { retry: false }, queries: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <OAuth2ConsentCard grant={grant} app={app} flow="authorization" />
    </QueryClientProvider>,
  )
}

describe('OAuth2ConsentCard redirect contract', () => {
  let assign: ReturnType<typeof vi.fn>

  beforeEach(() => {
    approve.mockReset()
    reject.mockReset()
    assign = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: {
        assign,
        href: 'http://localhost/oauth2/consent',
        pathname: '/oauth2/consent',
        search: '',
      },
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('navigates to the returned code URL after approving', async () => {
    const codeUrl =
      'https://client.example.com/callback?code=CODE123&state=STATE456'
    approve.mockResolvedValue({ redirectUrl: codeUrl })

    renderCard()
    fireEvent.click(screen.getByRole('button', { name: /authorize/i }))

    await waitFor(() => expect(approve).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(assign).toHaveBeenCalledWith(codeUrl))
  })

  it('navigates to the returned access_denied URL after cancelling', async () => {
    const denyUrl =
      'https://client.example.com/callback?error=access_denied&state=STATE456'
    reject.mockResolvedValue({ redirectUrl: denyUrl })

    renderCard()
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }))

    await waitFor(() => expect(reject).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(assign).toHaveBeenCalledWith(denyUrl))
  })
})
