import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk, getApiEndpoint } from '@/lib/appwrite/sdk'
import {
  useProject,
  useUpdateAuthMethod,
  useUpdateOAuth2Provider,
} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import {
  Loader2,
  Mail,
  Key,
  Smartphone,
  UserPlus,
  Lock,
  Copy,
  Check,
  ExternalLink,
  Search,
} from 'lucide-react'
import {
  Drawer,
  DrawerContent,
  DrawerDescription,
  DrawerHeader,
  DrawerTitle,
} from '@/components/ui/drawer'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { AuthMethod, OAuthProvider } from '@appwrite.io/console'

interface AuthSettingsProps {
  projectId: string
}

// Auth method configuration
const AUTH_METHODS = [
  {
    key: AuthMethod.Emailpassword,
    label: 'Email/Password',
    icon: Mail,
  },
  {
    key: AuthMethod.Phone,
    label: 'Phone',
    icon: Smartphone,
  },
  {
    key: AuthMethod.Magicurl,
    label: 'Magic URL',
    icon: Key,
  },
  {
    key: AuthMethod.Emailotp,
    label: 'Email OTP',
    icon: Mail,
  },
  {
    key: AuthMethod.Anonymous,
    label: 'Anonymous',
    icon: UserPlus,
  },
  {
    key: AuthMethod.Invites,
    label: 'Team Invites',
    icon: UserPlus,
  },
  {
    key: AuthMethod.Jwt,
    label: 'JWT',
    icon: Lock,
  },
] as const

// Centralized OAuth provider configuration
const OAUTH_PROVIDER_CONFIG: Record<
  string,
  {
    key: OAuthProvider
    name: string
    icon: string
    docsUrl: string | null
    popular?: boolean
  }
> = {
  [OAuthProvider.Amazon]: {
    key: OAuthProvider.Amazon,
    name: 'Amazon',
    icon: 'amazon.svg',
    docsUrl: 'https://developer.amazon.com/apps-and-games/services-and-apis',
  },
  [OAuthProvider.Apple]: {
    key: OAuthProvider.Apple,
    name: 'Apple',
    icon: 'apple.svg',
    docsUrl: 'https://developer.apple.com/',
    popular: true,
  },
  [OAuthProvider.Auth0]: {
    key: OAuthProvider.Auth0,
    name: 'Auth0',
    icon: 'auth0.svg',
    docsUrl: 'https://auth0.com/developers',
  },
  [OAuthProvider.Authentik]: {
    key: OAuthProvider.Authentik,
    name: 'Authentik',
    icon: 'authentik.svg',
    docsUrl: 'https://goauthentik.io/integrations/sources/oauth/',
  },
  [OAuthProvider.Autodesk]: {
    key: OAuthProvider.Autodesk,
    name: 'Autodesk',
    icon: 'autodesk.svg',
    docsUrl:
      'https://forge.autodesk.com/en/docs/oauth/v2/developers_guide/overview/',
  },
  [OAuthProvider.Bitbucket]: {
    key: OAuthProvider.Bitbucket,
    name: 'Bitbucket',
    icon: 'bitbucket.svg',
    docsUrl: 'https://developer.atlassian.com/bitbucket',
  },
  [OAuthProvider.Bitly]: {
    key: OAuthProvider.Bitly,
    name: 'Bitly',
    icon: 'bitly.svg',
    docsUrl: 'https://dev.bitly.com/v4_documentation.html',
  },
  [OAuthProvider.Box]: {
    key: OAuthProvider.Box,
    name: 'Box',
    icon: 'box.svg',
    docsUrl: 'https://developer.box.com/reference/',
  },
  [OAuthProvider.Dailymotion]: {
    key: OAuthProvider.Dailymotion,
    name: 'Dailymotion',
    icon: 'dailymotion.svg',
    docsUrl: 'https://developers.dailymotion.com/api/',
  },
  [OAuthProvider.Discord]: {
    key: OAuthProvider.Discord,
    name: 'Discord',
    icon: 'discord.svg',
    docsUrl: 'https://discordapp.com/developers/docs/topics/oauth2',
  },
  [OAuthProvider.Disqus]: {
    key: OAuthProvider.Disqus,
    name: 'Disqus',
    icon: 'disqus.svg',
    docsUrl: 'https://disqus.com/api/docs/auth/',
  },
  [OAuthProvider.Dropbox]: {
    key: OAuthProvider.Dropbox,
    name: 'Dropbox',
    icon: 'dropbox.svg',
    docsUrl: 'https://www.dropbox.com/developers/documentation',
  },
  [OAuthProvider.Etsy]: {
    key: OAuthProvider.Etsy,
    name: 'Etsy',
    icon: 'etsy.svg',
    docsUrl: 'https://developers.etsy.com/',
  },
  [OAuthProvider.Facebook]: {
    key: OAuthProvider.Facebook,
    name: 'Facebook',
    icon: 'facebook.svg',
    docsUrl: 'https://developers.facebook.com/',
    popular: true,
  },
  [OAuthProvider.Figma]: {
    key: OAuthProvider.Figma,
    name: 'Figma',
    icon: 'figma.svg',
    docsUrl: 'https://www.figma.com/developers/api#access-tokens',
  },
  [OAuthProvider.Github]: {
    key: OAuthProvider.Github,
    name: 'GitHub',
    icon: 'github.svg',
    docsUrl: 'https://developer.github.com',
    popular: true,
  },
  [OAuthProvider.Gitlab]: {
    key: OAuthProvider.Gitlab,
    name: 'GitLab',
    icon: 'gitlab.svg',
    docsUrl: 'https://docs.gitlab.com/ee/api/',
  },
  [OAuthProvider.Google]: {
    key: OAuthProvider.Google,
    name: 'Google',
    icon: 'google.svg',
    docsUrl: 'https://support.google.com/googleapi/answer/6158849',
    popular: true,
  },
  [OAuthProvider.Linkedin]: {
    key: OAuthProvider.Linkedin,
    name: 'LinkedIn',
    icon: 'linkedin.svg',
    docsUrl: 'https://developer.linkedin.com/',
    popular: true,
  },
  [OAuthProvider.Microsoft]: {
    key: OAuthProvider.Microsoft,
    name: 'Microsoft',
    icon: 'microsoft.svg',
    docsUrl: 'https://developer.microsoft.com/en-us/',
    popular: true,
  },
  [OAuthProvider.Notion]: {
    key: OAuthProvider.Notion,
    name: 'Notion',
    icon: 'notion.svg',
    docsUrl: 'https://developers.notion.com/docs',
  },
  [OAuthProvider.Oidc]: {
    key: OAuthProvider.Oidc,
    name: 'OIDC',
    icon: 'oidc.svg',
    docsUrl: 'https://openid.net/connect/faq/',
  },
  [OAuthProvider.Okta]: {
    key: OAuthProvider.Okta,
    name: 'Okta',
    icon: 'okta.svg',
    docsUrl: 'https://developer.okta.com',
  },
  [OAuthProvider.Paypal]: {
    key: OAuthProvider.Paypal,
    name: 'PayPal',
    icon: 'paypal.svg',
    docsUrl: 'https://developer.paypal.com/docs/api/overview/',
  },
  [OAuthProvider.PaypalSandbox]: {
    key: OAuthProvider.PaypalSandbox,
    name: 'PayPal Sandbox',
    icon: 'paypal.svg',
    docsUrl: 'https://developer.paypal.com/docs/api/overview/',
  },
  [OAuthProvider.Podio]: {
    key: OAuthProvider.Podio,
    name: 'Podio',
    icon: 'podio.svg',
    docsUrl: 'https://developers.podio.com/doc/oauth-authorization',
  },
  [OAuthProvider.Salesforce]: {
    key: OAuthProvider.Salesforce,
    name: 'Salesforce',
    icon: 'salesforce.svg',
    docsUrl: 'https://developer.salesforce.com/docs/',
  },
  [OAuthProvider.Slack]: {
    key: OAuthProvider.Slack,
    name: 'Slack',
    icon: 'slack.svg',
    docsUrl: 'https://api.slack.com/',
  },
  [OAuthProvider.Spotify]: {
    key: OAuthProvider.Spotify,
    name: 'Spotify',
    icon: 'spotify.svg',
    docsUrl:
      'https://developer.spotify.com/documentation/general/guides/authorization-guide/',
  },
  [OAuthProvider.Stripe]: {
    key: OAuthProvider.Stripe,
    name: 'Stripe',
    icon: 'stripe.svg',
    docsUrl: 'https://stripe.com/docs/api',
  },
  [OAuthProvider.Tradeshift]: {
    key: OAuthProvider.Tradeshift,
    name: 'Tradeshift',
    icon: 'tradeshift.svg',
    docsUrl: 'https://developers.tradeshift.com/docs/api',
  },
  [OAuthProvider.TradeshiftBox]: {
    key: OAuthProvider.TradeshiftBox,
    name: 'Tradeshift Sandbox',
    icon: 'tradeshift.svg',
    docsUrl: 'https://developers.tradeshift.com/docs/api',
  },
  [OAuthProvider.Twitch]: {
    key: OAuthProvider.Twitch,
    name: 'Twitch',
    icon: 'twitch.svg',
    docsUrl: 'https://dev.twitch.tv/docs/auth',
  },
  [OAuthProvider.Wordpress]: {
    key: OAuthProvider.Wordpress,
    name: 'WordPress',
    icon: 'wordpress.svg',
    docsUrl: 'https://developer.wordpress.com/docs/oauth2/',
  },
  [OAuthProvider.X]: {
    key: OAuthProvider.X,
    name: 'X',
    icon: 'x.svg',
    docsUrl: 'https://developer.x.com/en/docs/authentication/oauth-2-0',
  },
  [OAuthProvider.Yahoo]: {
    key: OAuthProvider.Yahoo,
    name: 'Yahoo',
    icon: 'yahoo.svg',
    docsUrl: 'https://developer.yahoo.com/oauth2/guide/flows_authcode/',
  },
  [OAuthProvider.Yammer]: {
    key: OAuthProvider.Yammer,
    name: 'Yammer',
    icon: 'yammer.svg',
    docsUrl: 'https://developer.yammer.com/docs/oauth-2',
  },
  [OAuthProvider.Yandex]: {
    key: OAuthProvider.Yandex,
    name: 'Yandex',
    icon: 'yandex.svg',
    docsUrl: 'https://tech.yandex.com/oauth/',
  },
  [OAuthProvider.Zoho]: {
    key: OAuthProvider.Zoho,
    name: 'Zoho',
    icon: 'zoho.svg',
    docsUrl: 'https://www.zoho.com/crm/developer/docs/api/oauth-overview.html',
  },
  [OAuthProvider.Zoom]: {
    key: OAuthProvider.Zoom,
    name: 'Zoom',
    icon: 'zoom.svg',
    docsUrl: 'https://marketplace.zoom.us/docs/guides/auth/oauth/',
  },
}

// Get all providers as an array
const OAUTH_PROVIDERS = Object.values(OAUTH_PROVIDER_CONFIG)

// Helper to get provider config
function getProviderConfig(providerKey: string) {
  return OAUTH_PROVIDER_CONFIG[providerKey] || null
}

// Helper to get provider data from project's oAuthProviders array
function getProviderData(projectData: unknown, providerKey: string) {
  const oAuthProviders = projectData?.oAuthProviders || []
  return oAuthProviders.find((p: unknown) => p.key === providerKey) || null
}

// Standard Provider Form Component
function StandardProviderForm({
  formData,
  setFormData,
  redirectUri,
  providerName,
  error,
}: {
  formData: { enabled: boolean; appId: string; secret: string }
  setFormData: (data: {
    enabled: boolean
    appId: string
    secret: string
  }) => void
  redirectUri: string
  providerName: string
  error: string
}) {
  const CopyableInput = ({
    value,
    label,
  }: {
    value: string
    label: string
  }) => {
    const [copied, setCopied] = useState(false)
    const handleCopy = () => {
      navigator.clipboard.writeText(value)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    }
    return (
      <div className="relative">
        <Input value={value} readOnly className="pr-10 font-mono text-[13px]" />
        <button
          type="button"
          onClick={handleCopy}
          className="absolute right-2 top-1/2 -translate-y-1/2 flex items-center justify-center h-7 w-7 rounded-md hover:bg-accent transition-colors"
          aria-label={`Copy ${label}`}
        >
          {copied ? (
            <Check className="h-4 w-4 text-emerald-500" />
          ) : (
            <Copy className="h-4 w-4 text-muted-foreground" />
          )}
        </button>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="rounded-lg border border-border bg-muted/30 p-4">
        <div className="flex items-center justify-between">
          <div className="space-y-0.5">
            <Label
              htmlFor="provider-enabled"
              className="text-[13px] font-semibold text-foreground"
            >
              {formData.enabled ? 'Enabled' : 'Disabled'}
            </Label>
            <p className="text-[12px] text-muted-foreground">
              {formData.enabled
                ? 'This provider is currently active'
                : 'This provider is currently inactive'}
            </p>
          </div>
          <Switch
            id="provider-enabled"
            checked={formData.enabled}
            onCheckedChange={(checked) =>
              setFormData({ ...formData, enabled: checked })
            }
          />
        </div>
      </div>
      <div className="space-y-2">
        <Label htmlFor="provider-app-id" className="text-[12px]">
          App ID <span className="text-destructive">*</span>
        </Label>
        <Input
          id="provider-app-id"
          value={formData.appId}
          onChange={(e) => setFormData({ ...formData, appId: e.target.value })}
          placeholder="Enter ID"
          autoFocus
          className="text-[13px]"
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor="provider-secret" className="text-[12px]">
          App Secret <span className="text-destructive">*</span>
        </Label>
        <Input
          id="provider-secret"
          type="password"
          value={formData.secret}
          onChange={(e) => setFormData({ ...formData, secret: e.target.value })}
          placeholder="Enter App Secret"
          className="text-[13px]"
        />
      </div>
      <div className="space-y-2">
        <Label className="text-[12px]">URI</Label>
        <CopyableInput value={redirectUri} label="Redirect URI" />
        <Alert>
          <AlertDescription className="text-[12px]">
            To complete set up, add this OAuth2 redirect URI to your{' '}
            {providerName} app configuration.
          </AlertDescription>
        </Alert>
      </div>
      {error && (
        <Alert variant="destructive">
          <AlertDescription className="text-[13px]">{error}</AlertDescription>
        </Alert>
      )}
    </div>
  )
}

// Special Provider Form Component
function SpecialProviderForm({
  providerKey,
  formData,
  setFormData,
  redirectUri,
  providerName,
  error,
}: {
  providerKey: string
  formData: unknown
  setFormData: (data: unknown) => void
  redirectUri: string
  providerName: string
  error: string
}) {
  const CopyableInput = ({
    value,
    label,
  }: {
    value: string
    label: string
  }) => {
    const [copied, setCopied] = useState(false)
    const handleCopy = () => {
      navigator.clipboard.writeText(value)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    }
    return (
      <div className="relative">
        <Input value={value} readOnly className="pr-10 font-mono text-[13px]" />
        <button
          type="button"
          onClick={handleCopy}
          className="absolute right-2 top-1/2 -translate-y-1/2 flex items-center justify-center h-7 w-7 rounded-md hover:bg-accent transition-colors"
          aria-label={`Copy ${label}`}
        >
          {copied ? (
            <Check className="h-4 w-4 text-emerald-500" />
          ) : (
            <Copy className="h-4 w-4 text-muted-foreground" />
          )}
        </button>
      </div>
    )
  }

  const updateField = (field: string, value: unknown) => {
    setFormData({ ...formData, [field]: value })
  }

  switch (providerKey) {
    case OAuthProvider.Apple:
      return (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label
                  htmlFor="apple-enabled"
                  className="text-[13px] font-semibold text-foreground"
                >
                  {formData.enabled ? 'Enabled' : 'Disabled'}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {formData.enabled
                    ? 'This provider is currently active'
                    : 'This provider is currently inactive'}
                </p>
              </div>
              <Switch
                id="apple-enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) => updateField('enabled', checked)}
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="apple-services-id" className="text-[12px]">
              Services ID <span className="text-destructive">*</span>
            </Label>
            <Input
              id="apple-services-id"
              value={formData.servicesId || ''}
              onChange={(e) => updateField('servicesId', e.target.value)}
              placeholder="com.company.appname"
              autoFocus
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="apple-key-id" className="text-[12px]">
              Key ID <span className="text-destructive">*</span>
            </Label>
            <Input
              id="apple-key-id"
              value={formData.keyId || ''}
              onChange={(e) => updateField('keyId', e.target.value)}
              placeholder="SHAB13ROFN"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="apple-team-id" className="text-[12px]">
              Team ID <span className="text-destructive">*</span>
            </Label>
            <Input
              id="apple-team-id"
              value={formData.teamId || ''}
              onChange={(e) => updateField('teamId', e.target.value)}
              placeholder="ELA2CD3AED"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="apple-p8" className="text-[12px]">
              P8 File <span className="text-destructive">*</span>
            </Label>
            <Textarea
              id="apple-p8"
              value={formData.p8 || ''}
              onChange={(e) => updateField('p8', e.target.value)}
              placeholder="Paste P8 certificate content here"
              className="text-[13px] font-mono min-h-[120px]"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[12px]">URI</Label>
            <CopyableInput value={redirectUri} label="Redirect URI" />
            <Alert>
              <AlertDescription className="text-[12px]">
                To complete set up, add this OAuth2 redirect URI to your{' '}
                {providerName} app configuration.
              </AlertDescription>
            </Alert>
          </div>
          {error && (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          )}
        </div>
      )

    case OAuthProvider.Auth0:
      return (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label
                  htmlFor="auth0-enabled"
                  className="text-[13px] font-semibold text-foreground"
                >
                  {formData.enabled ? 'Enabled' : 'Disabled'}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {formData.enabled
                    ? 'This provider is currently active'
                    : 'This provider is currently inactive'}
                </p>
              </div>
              <Switch
                id="auth0-enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) => updateField('enabled', checked)}
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="auth0-client-id" className="text-[12px]">
              Client ID <span className="text-destructive">*</span>
            </Label>
            <Input
              id="auth0-client-id"
              value={formData.clientId || ''}
              onChange={(e) => updateField('clientId', e.target.value)}
              placeholder="Enter ID"
              autoFocus
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="auth0-client-secret" className="text-[12px]">
              Client Secret <span className="text-destructive">*</span>
            </Label>
            <Input
              id="auth0-client-secret"
              type="password"
              value={formData.clientSecret || ''}
              onChange={(e) => updateField('clientSecret', e.target.value)}
              placeholder="Enter Client Secret"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="auth0-domain" className="text-[12px]">
              Auth0 Domain <span className="text-destructive">*</span>
            </Label>
            <Input
              id="auth0-domain"
              value={formData.auth0Domain || ''}
              onChange={(e) => updateField('auth0Domain', e.target.value)}
              placeholder="your-tenant.auth0.com"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[12px]">URI</Label>
            <CopyableInput value={redirectUri} label="Redirect URI" />
            <Alert>
              <AlertDescription className="text-[12px]">
                To complete set up, add this OAuth2 redirect URI to your{' '}
                {providerName} app configuration.
              </AlertDescription>
            </Alert>
          </div>
          {error && (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          )}
        </div>
      )

    case OAuthProvider.Authentik:
      return (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label
                  htmlFor="authentik-enabled"
                  className="text-[13px] font-semibold text-foreground"
                >
                  {formData.enabled ? 'Enabled' : 'Disabled'}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {formData.enabled
                    ? 'This provider is currently active'
                    : 'This provider is currently inactive'}
                </p>
              </div>
              <Switch
                id="authentik-enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) => updateField('enabled', checked)}
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="authentik-client-id" className="text-[12px]">
              Client ID <span className="text-destructive">*</span>
            </Label>
            <Input
              id="authentik-client-id"
              value={formData.clientId || ''}
              onChange={(e) => updateField('clientId', e.target.value)}
              placeholder="Enter ID"
              autoFocus
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="authentik-client-secret" className="text-[12px]">
              Client Secret <span className="text-destructive">*</span>
            </Label>
            <Input
              id="authentik-client-secret"
              type="password"
              value={formData.clientSecret || ''}
              onChange={(e) => updateField('clientSecret', e.target.value)}
              placeholder="Enter Client Secret"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="authentik-domain" className="text-[12px]">
              Authentik Base-Domain <span className="text-destructive">*</span>
            </Label>
            <Input
              id="authentik-domain"
              value={formData.authentikDomain || ''}
              onChange={(e) => updateField('authentikDomain', e.target.value)}
              placeholder="Your Authentik domain"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[12px]">URI</Label>
            <CopyableInput value={redirectUri} label="Redirect URI" />
            <Alert>
              <AlertDescription className="text-[12px]">
                To complete set up, add this OAuth2 redirect URI to your{' '}
                {providerName} app configuration.
              </AlertDescription>
            </Alert>
          </div>
          {error && (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          )}
        </div>
      )

    case OAuthProvider.Gitlab:
      return (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label
                  htmlFor="gitlab-enabled"
                  className="text-[13px] font-semibold text-foreground"
                >
                  {formData.enabled ? 'Enabled' : 'Disabled'}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {formData.enabled
                    ? 'This provider is currently active'
                    : 'This provider is currently inactive'}
                </p>
              </div>
              <Switch
                id="gitlab-enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) => updateField('enabled', checked)}
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="gitlab-app-id" className="text-[12px]">
              App ID <span className="text-destructive">*</span>
            </Label>
            <Input
              id="gitlab-app-id"
              value={formData.appId || ''}
              onChange={(e) => updateField('appId', e.target.value)}
              placeholder="Enter ID"
              autoFocus
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="gitlab-app-secret" className="text-[12px]">
              App Secret <span className="text-destructive">*</span>
            </Label>
            <Input
              id="gitlab-app-secret"
              type="password"
              value={formData.appSecret || ''}
              onChange={(e) => updateField('appSecret', e.target.value)}
              placeholder="Enter App Secret"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="gitlab-endpoint" className="text-[12px]">
              Endpoint
            </Label>
            <Input
              id="gitlab-endpoint"
              value={formData.endpoint || ''}
              onChange={(e) => updateField('endpoint', e.target.value)}
              placeholder="Your endpoint"
              className="text-[13px]"
            />
            <p className="text-[11px] text-muted-foreground">
              Optional. Leave empty to use GitLab.com
            </p>
          </div>
          <div className="space-y-2">
            <Label className="text-[12px]">URI</Label>
            <CopyableInput value={redirectUri} label="Redirect URI" />
            <Alert>
              <AlertDescription className="text-[12px]">
                To complete set up, add this OAuth2 redirect URI to your{' '}
                {providerName} app configuration.
              </AlertDescription>
            </Alert>
          </div>
          {error && (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          )}
        </div>
      )

    case OAuthProvider.Google:
      return (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label
                  htmlFor="google-enabled"
                  className="text-[13px] font-semibold text-foreground"
                >
                  {formData.enabled ? 'Enabled' : 'Disabled'}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {formData.enabled
                    ? 'This provider is currently active'
                    : 'This provider is currently inactive'}
                </p>
              </div>
              <Switch
                id="google-enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) => updateField('enabled', checked)}
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="google-app-id" className="text-[12px]">
              App ID <span className="text-destructive">*</span>
            </Label>
            <Input
              id="google-app-id"
              value={formData.appId || ''}
              onChange={(e) => updateField('appId', e.target.value)}
              placeholder="Enter ID"
              autoFocus
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="google-app-secret" className="text-[12px]">
              App Secret <span className="text-destructive">*</span>
            </Label>
            <Input
              id="google-app-secret"
              type="password"
              value={formData.appSecret || ''}
              onChange={(e) => updateField('appSecret', e.target.value)}
              placeholder="Enter App Secret"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[12px]">URI</Label>
            <CopyableInput value={redirectUri} label="Redirect URI" />
            <Alert>
              <AlertDescription className="text-[12px]">
                To complete the setup, create an OAuth2 client ID with "Web
                application" as the application type, then add this redirect URI
                to your {providerName} configuration.
              </AlertDescription>
            </Alert>
          </div>
          {error && (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          )}
        </div>
      )

    case OAuthProvider.Microsoft:
      return (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label
                  htmlFor="microsoft-enabled"
                  className="text-[13px] font-semibold text-foreground"
                >
                  {formData.enabled ? 'Enabled' : 'Disabled'}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {formData.enabled
                    ? 'This provider is currently active'
                    : 'This provider is currently inactive'}
                </p>
              </div>
              <Switch
                id="microsoft-enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) => updateField('enabled', checked)}
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label
              htmlFor="microsoft-application-client-id"
              className="text-[12px]"
            >
              Application (client) ID{' '}
              <span className="text-destructive">*</span>
            </Label>
            <Input
              id="microsoft-application-client-id"
              value={formData.applicationClientId || ''}
              onChange={(e) =>
                updateField('applicationClientId', e.target.value)
              }
              placeholder="Enter ID"
              autoFocus
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="microsoft-client-secret" className="text-[12px]">
              Client Secret <span className="text-destructive">*</span>
            </Label>
            <Input
              id="microsoft-client-secret"
              type="password"
              value={formData.clientSecret || ''}
              onChange={(e) => updateField('clientSecret', e.target.value)}
              placeholder="Enter Client Secret"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="microsoft-target-tenant" className="text-[12px]">
              Target Tenant
            </Label>
            <Input
              id="microsoft-target-tenant"
              value={formData.targetTenant || ''}
              onChange={(e) => updateField('targetTenant', e.target.value)}
              placeholder="'common','organizations','consumers' or your TenantID"
              className="text-[13px]"
            />
            <p className="text-[11px] text-muted-foreground">
              Optional. Use 'common', 'organizations', 'consumers', or a
              specific Tenant ID
            </p>
          </div>
          <div className="space-y-2">
            <Label className="text-[12px]">URI</Label>
            <CopyableInput value={redirectUri} label="Redirect URI" />
            <Alert>
              <AlertDescription className="text-[12px]">
                To complete set up, add this OAuth2 redirect URI to your{' '}
                {providerName} app configuration.
              </AlertDescription>
            </Alert>
          </div>
          {error && (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          )}
        </div>
      )

    case OAuthProvider.Oidc:
      return (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label
                  htmlFor="oidc-enabled"
                  className="text-[13px] font-semibold text-foreground"
                >
                  {formData.enabled ? 'Enabled' : 'Disabled'}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {formData.enabled
                    ? 'This provider is currently active'
                    : 'This provider is currently inactive'}
                </p>
              </div>
              <Switch
                id="oidc-enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) => updateField('enabled', checked)}
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="oidc-client-id" className="text-[12px]">
              Client ID <span className="text-destructive">*</span>
            </Label>
            <Input
              id="oidc-client-id"
              value={formData.clientId || ''}
              onChange={(e) => updateField('clientId', e.target.value)}
              placeholder="Enter ID"
              autoFocus
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="oidc-client-secret" className="text-[12px]">
              Client Secret <span className="text-destructive">*</span>
            </Label>
            <Input
              id="oidc-client-secret"
              type="password"
              value={formData.clientSecret || ''}
              onChange={(e) => updateField('clientSecret', e.target.value)}
              placeholder="Enter Client Secret"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="oidc-well-known" className="text-[12px]">
              Well-Known Endpoint
            </Label>
            <Input
              id="oidc-well-known"
              value={formData.wellKnownEndpoint || ''}
              onChange={(e) => updateField('wellKnownEndpoint', e.target.value)}
              placeholder="https://example.com/.well-known/openid-configuration"
              className="text-[13px]"
            />
            <p className="text-[11px] text-muted-foreground">
              Optional. If provided, other endpoints are not required.
            </p>
          </div>
          <div className="space-y-2">
            <Label htmlFor="oidc-authorization" className="text-[12px]">
              Authorization Endpoint
            </Label>
            <Input
              id="oidc-authorization"
              value={formData.authorizationEndpoint || ''}
              onChange={(e) =>
                updateField('authorizationEndpoint', e.target.value)
              }
              placeholder="https://example.com/authorize"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="oidc-token" className="text-[12px]">
              Token Endpoint
            </Label>
            <Input
              id="oidc-token"
              value={formData.tokenEndpoint || ''}
              onChange={(e) => updateField('tokenEndpoint', e.target.value)}
              placeholder="https://example.com/token"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="oidc-userinfo" className="text-[12px]">
              User Info Endpoint
            </Label>
            <Input
              id="oidc-userinfo"
              value={formData.userinfoEndpoint || ''}
              onChange={(e) => updateField('userinfoEndpoint', e.target.value)}
              placeholder="https://example.com/userinfo"
              className="text-[13px]"
            />
            <p className="text-[11px] text-muted-foreground">
              Required if Well-Known endpoint is not provided. All three
              endpoints (Authorization, Token, User Info) must be provided
              together.
            </p>
          </div>
          <div className="space-y-2">
            <Label className="text-[12px]">URI</Label>
            <CopyableInput value={redirectUri} label="Redirect URI" />
            <Alert>
              <AlertDescription className="text-[12px]">
                To complete set up, add this OAuth2 redirect URI to your{' '}
                {providerName} app configuration.
              </AlertDescription>
            </Alert>
          </div>
          {error && (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          )}
        </div>
      )

    case OAuthProvider.Okta:
      return (
        <div className="space-y-4">
          <div className="rounded-lg border border-border bg-muted/30 p-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label
                  htmlFor="okta-enabled"
                  className="text-[13px] font-semibold text-foreground"
                >
                  {formData.enabled ? 'Enabled' : 'Disabled'}
                </Label>
                <p className="text-[12px] text-muted-foreground">
                  {formData.enabled
                    ? 'This provider is currently active'
                    : 'This provider is currently inactive'}
                </p>
              </div>
              <Switch
                id="okta-enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) => updateField('enabled', checked)}
              />
            </div>
          </div>
          <div className="space-y-2">
            <Label htmlFor="okta-client-id" className="text-[12px]">
              Client ID <span className="text-destructive">*</span>
            </Label>
            <Input
              id="okta-client-id"
              value={formData.clientId || ''}
              onChange={(e) => updateField('clientId', e.target.value)}
              placeholder="Enter ID"
              autoFocus
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="okta-client-secret" className="text-[12px]">
              Client Secret <span className="text-destructive">*</span>
            </Label>
            <Input
              id="okta-client-secret"
              type="password"
              value={formData.clientSecret || ''}
              onChange={(e) => updateField('clientSecret', e.target.value)}
              placeholder="Enter Client Secret"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="okta-domain" className="text-[12px]">
              Okta Domain <span className="text-destructive">*</span>
            </Label>
            <Input
              id="okta-domain"
              value={formData.oktaDomain || ''}
              onChange={(e) => updateField('oktaDomain', e.target.value)}
              placeholder="dev-1337.okta.com"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label
              htmlFor="okta-authorization-server-id"
              className="text-[12px]"
            >
              Authorization Server ID{' '}
              <span className="text-destructive">*</span>
            </Label>
            <Input
              id="okta-authorization-server-id"
              value={formData.authorizationServerId || ''}
              onChange={(e) =>
                updateField('authorizationServerId', e.target.value)
              }
              placeholder="default"
              className="text-[13px]"
            />
          </div>
          <div className="space-y-2">
            <Label className="text-[12px]">URI</Label>
            <CopyableInput value={redirectUri} label="Redirect URI" />
            <Alert>
              <AlertDescription className="text-[12px]">
                To complete set up, add this OAuth2 redirect URI to your{' '}
                {providerName} app configuration.
              </AlertDescription>
            </Alert>
          </div>
          {error && (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px]">
                {error}
              </AlertDescription>
            </Alert>
          )}
        </div>
      )

    default:
      return null
  }
}

// Providers that need special forms
const SPECIAL_PROVIDERS = [
  OAuthProvider.Apple,
  OAuthProvider.Auth0,
  OAuthProvider.Authentik,
  OAuthProvider.Gitlab,
  OAuthProvider.Google,
  OAuthProvider.Microsoft,
  OAuthProvider.Oidc,
  OAuthProvider.Okta,
] as const

function isSpecialProvider(providerKey: string): boolean {
  return SPECIAL_PROVIDERS.includes(providerKey as unknown)
}

export function AuthSettings({ projectId }: AuthSettingsProps) {
  const queryClient = useQueryClient()
  const { project, isLoading: projectLoading } = useProject(projectId)

  // Get raw project data for auth methods and OAuth providers
  const { data: rawProjectData } = useQuery({
    queryKey: ['project', projectId],
    queryFn: async () => {
      const response = await sdk.forConsole.projects.get({ projectId })
      return response
    },
    enabled: !!projectId,
    staleTime: 5 * 60 * 1000,
  })

  // State for optimistic auth method updates (only stores changes, not full state)
  const [optimisticAuthMethods, setOptimisticAuthMethods] = useState<
    Record<string, boolean>
  >({})
  const [updatingAuthMethods, setUpdatingAuthMethods] = useState<Set<string>>(
    new Set(),
  )
  // Track last submitted values to prevent layout shifts
  const lastSubmittedAuthMethods = useRef<Record<string, boolean>>({})

  // State for OAuth provider search
  const [providerSearch, setProviderSearch] = useState('')

  // State for OAuth provider drawer
  const [selectedProvider, setSelectedProvider] = useState<string | null>(null)
  const [providerDrawerOpen, setProviderDrawerOpen] = useState(false)

  // Standard form data
  const [standardFormData, setStandardFormData] = useState<{
    enabled: boolean
    appId: string
    secret: string
  }>({
    enabled: false,
    appId: '',
    secret: '',
  })

  // Special form data (for providers with custom forms)
  const [specialFormData, setSpecialFormData] = useState<unknown>({})

  const [providerError, setProviderError] = useState('')

  // Compute base auth methods from project data (instant, no delay)
  const baseAuthMethods = useMemo(() => {
    if (!rawProjectData) {
      return {
        [AuthMethod.Emailpassword]: false,
        [AuthMethod.Phone]: false,
        [AuthMethod.Magicurl]: false,
        [AuthMethod.Emailotp]: false,
        [AuthMethod.Anonymous]: false,
        [AuthMethod.Invites]: false,
        [AuthMethod.Jwt]: false,
      }
    }
    const projectData = rawProjectData as unknown
    return {
      [AuthMethod.Emailpassword]: projectData.authEmailPassword ?? false,
      [AuthMethod.Phone]: projectData.authPhone ?? false,
      [AuthMethod.Magicurl]: projectData.authUsersAuthMagicURL ?? false,
      [AuthMethod.Emailotp]: projectData.authEmailOtp ?? false,
      [AuthMethod.Anonymous]: projectData.authAnonymous ?? false,
      [AuthMethod.Invites]: projectData.authInvites ?? false,
      [AuthMethod.Jwt]: projectData.authJWT ?? false,
    }
  }, [rawProjectData])

  // Clear optimistic updates when server value matches expected value
  useEffect(() => {
    Object.keys(lastSubmittedAuthMethods.current).forEach((method) => {
      const expectedValue = lastSubmittedAuthMethods.current[method]
      const serverValue =
        baseAuthMethods[method as keyof typeof baseAuthMethods]

      // If server value matches what we expect, clear the optimistic update
      if (serverValue === expectedValue) {
        setOptimisticAuthMethods((prev) => {
          const next = { ...prev }
          delete next[method]
          return next
        })
        setUpdatingAuthMethods((prev) => {
          const next = new Set(prev)
          next.delete(method)
          return next
        })
        // Remove from tracking
        delete lastSubmittedAuthMethods.current[method]
      }
    })
  }, [baseAuthMethods])

  // Merge base auth methods with optimistic updates
  const authMethods = useMemo(() => {
    return { ...baseAuthMethods, ...optimisticAuthMethods }
  }, [baseAuthMethods, optimisticAuthMethods])

  // Get project endpoint for redirect URI (centralized in SDK)
  const projectEndpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  // Mutation for auth methods
  const updateAuthMethodMutation = useUpdateAuthMethod(projectId)

  // Mutation for OAuth providers
  const updateOAuth2Mutation = useUpdateOAuth2Provider(projectId)

  // Handle auth method toggle
  const handleAuthMethodToggle = (method: string, checked: boolean) => {
    // Optimistically update UI
    setOptimisticAuthMethods((prev) => ({ ...prev, [method]: checked }))
    setUpdatingAuthMethods((prev) => new Set(prev).add(method))
    // Track the value we're submitting
    lastSubmittedAuthMethods.current[method] = checked

    updateAuthMethodMutation.mutate(
      { method, status: checked },
      {
        onSuccess: () => {
          const methodLabel =
            AUTH_METHODS.find((m) => m.key === method)?.label || method
          toast.success(`${methodLabel} authentication has been updated`)
          queryClient.invalidateQueries({ queryKey: ['project', projectId] })
          // Don't clear optimistic update here - let the useEffect handle it when server value matches
          // Track analytics: Submit.AuthStatusUpdate
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to update authentication method')
          // Revert optimistic update on error
          setOptimisticAuthMethods((prev) => {
            const next = { ...prev }
            delete next[method]
            return next
          })
          setUpdatingAuthMethods((prev) => {
            const next = new Set(prev)
            next.delete(method)
            return next
          })
          // Remove from tracking
          delete lastSubmittedAuthMethods.current[method]
        },
      },
    )
  }

  // Handle provider card click
  const handleProviderClick = (providerKey: string) => {
    const projectData = rawProjectData as unknown
    const providerData = getProviderData(projectData, providerKey)

    setSelectedProvider(providerKey)
    setProviderError('')

    if (isSpecialProvider(providerKey)) {
      // Load special form data based on provider type
      loadSpecialFormData(providerKey, providerData)
    } else {
      // Load standard form data
      setStandardFormData({
        enabled: providerData?.enabled ?? false,
        appId: providerData?.appId || '',
        secret: providerData?.secret || '',
      })
    }

    setProviderDrawerOpen(true)
  }

  // Load special form data based on provider type
  const loadSpecialFormData = (providerKey: string, providerData: unknown) => {
    if (!providerData) {
      // Initialize empty form based on provider type
      switch (providerKey) {
        case OAuthProvider.Apple:
          setSpecialFormData({
            enabled: false,
            servicesId: '',
            keyId: '',
            teamId: '',
            p8: '',
          })
          break
        case OAuthProvider.Auth0:
          setSpecialFormData({
            enabled: false,
            clientId: '',
            clientSecret: '',
            auth0Domain: '',
          })
          break
        case OAuthProvider.Authentik:
          setSpecialFormData({
            enabled: false,
            clientId: '',
            clientSecret: '',
            authentikDomain: '',
          })
          break
        case OAuthProvider.Gitlab:
          setSpecialFormData({
            enabled: false,
            appId: '',
            appSecret: '',
            endpoint: '',
          })
          break
        case OAuthProvider.Google:
          setSpecialFormData({
            enabled: false,
            appId: '',
            appSecret: '',
          })
          break
        case OAuthProvider.Microsoft:
          setSpecialFormData({
            enabled: false,
            applicationClientId: '',
            clientSecret: '',
            targetTenant: '',
          })
          break
        case OAuthProvider.Oidc:
          setSpecialFormData({
            enabled: false,
            clientId: '',
            clientSecret: '',
            wellKnownEndpoint: '',
            authorizationEndpoint: '',
            tokenEndpoint: '',
            userinfoEndpoint: '',
          })
          break
        case OAuthProvider.Okta:
          setSpecialFormData({
            enabled: false,
            clientId: '',
            clientSecret: '',
            oktaDomain: '',
            authorizationServerId: '',
          })
          break
        default:
          setSpecialFormData({})
      }
      return
    }

    // Parse existing provider data
    const enabled = providerData.enabled ?? false
    const appId = providerData.appId || ''
    const secret = providerData.secret || ''

    // Try to parse secret as JSON for special providers
    let secretData: unknown = {}
    if (secret) {
      try {
        secretData = JSON.parse(secret)
      } catch {
        // If not JSON, treat as plain string
        secretData = { clientSecret: secret }
      }
    }

    switch (providerKey) {
      case OAuthProvider.Apple:
        setSpecialFormData({
          enabled,
          servicesId: appId,
          keyId: secretData.keyID || '',
          teamId: secretData.teamID || '',
          p8: secretData.p8 || '',
        })
        break
      case OAuthProvider.Auth0:
        setSpecialFormData({
          enabled,
          clientId: appId,
          clientSecret: secretData.clientSecret || '',
          auth0Domain: secretData.auth0Domain || '',
        })
        break
      case OAuthProvider.Authentik:
        setSpecialFormData({
          enabled,
          clientId: appId,
          clientSecret: secretData.clientSecret || '',
          authentikDomain: secretData.authentikDomain || '',
        })
        break
      case OAuthProvider.Gitlab:
        setSpecialFormData({
          enabled,
          appId,
          appSecret: secretData.clientSecret || secret,
          endpoint: secretData.endpoint || '',
        })
        break
      case OAuthProvider.Google:
        setSpecialFormData({
          enabled,
          appId,
          appSecret: secret,
        })
        break
      case OAuthProvider.Microsoft:
        setSpecialFormData({
          enabled,
          applicationClientId: appId,
          clientSecret: secretData.clientSecret || secret,
          targetTenant: secretData.tenantID || '',
        })
        break
      case OAuthProvider.Oidc:
        setSpecialFormData({
          enabled,
          clientId: appId,
          clientSecret: secretData.clientSecret || secret,
          wellKnownEndpoint: secretData.wellKnownEndpoint || '',
          authorizationEndpoint: secretData.authorizationEndpoint || '',
          tokenEndpoint: secretData.tokenEndpoint || '',
          userinfoEndpoint: secretData.userinfoEndpoint || '',
        })
        break
      case OAuthProvider.Okta:
        setSpecialFormData({
          enabled,
          clientId: appId,
          clientSecret: secretData.clientSecret || secret,
          oktaDomain: secretData.oktaDomain || '',
          authorizationServerId: secretData.authorizationServerId || '',
        })
        break
    }
  }

  // Reset drawer state when it closes
  useEffect(() => {
    if (!providerDrawerOpen) {
      setSelectedProvider(null)
      setStandardFormData({
        enabled: false,
        appId: '',
        secret: '',
      })
      setSpecialFormData({})
      setProviderError('')
    }
  }, [providerDrawerOpen])

  // Handle provider form submit
  const handleProviderSubmit = () => {
    if (!selectedProvider) return

    let appId: string
    let secret: string
    let enabled: boolean

    if (isSpecialProvider(selectedProvider)) {
      // Validate and serialize special provider data
      const result = validateAndSerializeSpecialProvider(
        selectedProvider,
        specialFormData,
      )
      if (!result.valid) {
        setProviderError(result.error || 'Validation failed')
        return
      }
      appId = result.appId
      secret = result.secret
      enabled = specialFormData.enabled
    } else {
      // Standard provider validation
      if (!standardFormData.appId.trim()) {
        setProviderError('App ID is required')
        return
      }
      if (!standardFormData.secret.trim()) {
        setProviderError('App Secret is required')
        return
      }
      appId = standardFormData.appId.trim()
      secret = standardFormData.secret.trim()
      enabled = standardFormData.enabled
    }

    setProviderError('')

    updateOAuth2Mutation.mutate(
      {
        provider: selectedProvider,
        appId,
        secret,
        enabled,
      },
      {
        onSuccess: () => {
          const providerConfig = getProviderConfig(selectedProvider)
          const providerName = providerConfig?.name || selectedProvider
          toast.success(`${providerName} authentication has been updated`)
          queryClient.invalidateQueries({ queryKey: ['project', projectId] })
          setProviderDrawerOpen(false)
          setSelectedProvider(null)
          // Track analytics: Submit.ProviderUpdate
        },
        onError: (error: Error) => {
          setProviderError(error.message || 'Failed to update OAuth provider')
        },
      },
    )
  }

  // Validate and serialize special provider data
  const validateAndSerializeSpecialProvider = (
    providerKey: string,
    formData: unknown,
  ): { valid: boolean; appId: string; secret: string; error?: string } => {
    switch (providerKey) {
      case OAuthProvider.Apple:
        if (!formData.servicesId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Services ID is required',
          }
        }
        if (!formData.keyId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Key ID is required',
          }
        }
        if (!formData.teamId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Team ID is required',
          }
        }
        if (!formData.p8?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'P8 file is required',
          }
        }
        return {
          valid: true,
          appId: formData.servicesId.trim(),
          secret: JSON.stringify({
            keyID: formData.keyId.trim(),
            teamID: formData.teamId.trim(),
            p8: formData.p8.trim(),
          }),
        }

      case OAuthProvider.Auth0:
        if (!formData.clientId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Client ID is required',
          }
        }
        if (!formData.clientSecret?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Client Secret is required',
          }
        }
        if (!formData.auth0Domain?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Auth0 Domain is required',
          }
        }
        return {
          valid: true,
          appId: formData.clientId.trim(),
          secret: JSON.stringify({
            clientSecret: formData.clientSecret.trim(),
            auth0Domain: formData.auth0Domain.trim(),
          }),
        }

      case OAuthProvider.Authentik:
        if (!formData.clientId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Client ID is required',
          }
        }
        if (!formData.clientSecret?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Client Secret is required',
          }
        }
        if (!formData.authentikDomain?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Authentik Base-Domain is required',
          }
        }
        return {
          valid: true,
          appId: formData.clientId.trim(),
          secret: JSON.stringify({
            clientSecret: formData.clientSecret.trim(),
            authentikDomain: formData.authentikDomain.trim(),
          }),
        }

      case OAuthProvider.Gitlab:
        if (!formData.appId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'App ID is required',
          }
        }
        if (!formData.appSecret?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'App Secret is required',
          }
        }
        const gitlabSecret: unknown = {
          clientSecret: formData.appSecret.trim(),
        }
        if (formData.endpoint?.trim()) {
          gitlabSecret.endpoint = formData.endpoint.trim()
        }
        return {
          valid: true,
          appId: formData.appId.trim(),
          secret: JSON.stringify(gitlabSecret),
        }

      case OAuthProvider.Google:
        if (!formData.appId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'App ID is required',
          }
        }
        if (!formData.appSecret?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'App Secret is required',
          }
        }
        return {
          valid: true,
          appId: formData.appId.trim(),
          secret: formData.appSecret.trim(), // Google uses plain string
        }

      case OAuthProvider.Microsoft:
        if (!formData.applicationClientId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Application (client) ID is required',
          }
        }
        if (!formData.clientSecret?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Client Secret is required',
          }
        }
        const microsoftSecret: unknown = {
          clientSecret: formData.clientSecret.trim(),
        }
        if (formData.targetTenant?.trim()) {
          microsoftSecret.tenantID = formData.targetTenant.trim()
        }
        return {
          valid: true,
          appId: formData.applicationClientId.trim(),
          secret: JSON.stringify(microsoftSecret),
        }

      case OAuthProvider.Oidc:
        if (!formData.clientId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Client ID is required',
          }
        }
        if (!formData.clientSecret?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Client Secret is required',
          }
        }
        const hasWellKnown = formData.wellKnownEndpoint?.trim()
        const hasAllEndpoints =
          formData.authorizationEndpoint?.trim() &&
          formData.tokenEndpoint?.trim() &&
          formData.userinfoEndpoint?.trim()

        if (!hasWellKnown && !hasAllEndpoints) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error:
              'Either Well-Known endpoint or all three endpoints (Authorization, Token, User Info) are required',
          }
        }

        const oidcSecret: unknown = {
          clientSecret: formData.clientSecret.trim(),
        }
        if (hasWellKnown) {
          oidcSecret.wellKnownEndpoint = formData.wellKnownEndpoint.trim()
        } else {
          oidcSecret.authorizationEndpoint =
            formData.authorizationEndpoint.trim()
          oidcSecret.tokenEndpoint = formData.tokenEndpoint.trim()
          oidcSecret.userinfoEndpoint = formData.userinfoEndpoint.trim()
        }
        return {
          valid: true,
          appId: formData.clientId.trim(),
          secret: JSON.stringify(oidcSecret),
        }

      case OAuthProvider.Okta:
        if (!formData.clientId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Client ID is required',
          }
        }
        if (!formData.clientSecret?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Client Secret is required',
          }
        }
        if (!formData.oktaDomain?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Okta Domain is required',
          }
        }
        if (!formData.authorizationServerId?.trim()) {
          return {
            valid: false,
            appId: '',
            secret: '',
            error: 'Authorization Server ID is required',
          }
        }
        return {
          valid: true,
          appId: formData.clientId.trim(),
          secret: JSON.stringify({
            clientSecret: formData.clientSecret.trim(),
            oktaDomain: formData.oktaDomain.trim(),
            authorizationServerId: formData.authorizationServerId.trim(),
          }),
        }

      default:
        return {
          valid: false,
          appId: '',
          secret: '',
          error: 'Unknown provider type',
        }
    }
  }

  // Check if provider form has changes
  const hasProviderChanges = useMemo(() => {
    if (!selectedProvider) return false
    const projectData = rawProjectData as unknown
    const providerData = getProviderData(projectData, selectedProvider)

    if (!providerData) {
      // If no existing data, check if form has any values
      if (isSpecialProvider(selectedProvider)) {
        return Object.values(specialFormData).some((v) => {
          if (typeof v === 'boolean') return v !== false
          if (typeof v === 'string') return v.trim() !== ''
          return false
        })
      } else {
        return (
          standardFormData.enabled ||
          standardFormData.appId.trim() !== '' ||
          standardFormData.secret.trim() !== ''
        )
      }
    }

    if (isSpecialProvider(selectedProvider)) {
      // Compare special form data with existing provider data
      const enabled = providerData.enabled ?? false
      const appId = providerData.appId || ''
      const secret = providerData.secret || ''

      // Parse secret
      let secretData: unknown = {}
      if (secret) {
        try {
          secretData = JSON.parse(secret)
        } catch {
          secretData = { clientSecret: secret }
        }
      }

      // Compare based on provider type
      switch (selectedProvider) {
        case OAuthProvider.Apple:
          return (
            specialFormData.enabled !== enabled ||
            specialFormData.servicesId !== appId ||
            specialFormData.keyId !== (secretData.keyID || '') ||
            specialFormData.teamId !== (secretData.teamID || '') ||
            specialFormData.p8 !== (secretData.p8 || '')
          )
        case OAuthProvider.Auth0:
          return (
            specialFormData.enabled !== enabled ||
            specialFormData.clientId !== appId ||
            specialFormData.clientSecret !== (secretData.clientSecret || '') ||
            specialFormData.auth0Domain !== (secretData.auth0Domain || '')
          )
        case OAuthProvider.Authentik:
          return (
            specialFormData.enabled !== enabled ||
            specialFormData.clientId !== appId ||
            specialFormData.clientSecret !== (secretData.clientSecret || '') ||
            specialFormData.authentikDomain !==
              (secretData.authentikDomain || '')
          )
        case OAuthProvider.Gitlab:
          return (
            specialFormData.enabled !== enabled ||
            specialFormData.appId !== appId ||
            specialFormData.appSecret !== (secretData.clientSecret || secret) ||
            specialFormData.endpoint !== (secretData.endpoint || '')
          )
        case OAuthProvider.Google:
          return (
            specialFormData.enabled !== enabled ||
            specialFormData.appId !== appId ||
            specialFormData.appSecret !== secret
          )
        case OAuthProvider.Microsoft:
          return (
            specialFormData.enabled !== enabled ||
            specialFormData.applicationClientId !== appId ||
            specialFormData.clientSecret !==
              (secretData.clientSecret || secret) ||
            specialFormData.targetTenant !== (secretData.tenantID || '')
          )
        case OAuthProvider.Oidc:
          return (
            specialFormData.enabled !== enabled ||
            specialFormData.clientId !== appId ||
            specialFormData.clientSecret !==
              (secretData.clientSecret || secret) ||
            specialFormData.wellKnownEndpoint !==
              (secretData.wellKnownEndpoint || '') ||
            specialFormData.authorizationEndpoint !==
              (secretData.authorizationEndpoint || '') ||
            specialFormData.tokenEndpoint !==
              (secretData.tokenEndpoint || '') ||
            specialFormData.userinfoEndpoint !==
              (secretData.userinfoEndpoint || '')
          )
        case OAuthProvider.Okta:
          return (
            specialFormData.enabled !== enabled ||
            specialFormData.clientId !== appId ||
            specialFormData.clientSecret !==
              (secretData.clientSecret || secret) ||
            specialFormData.oktaDomain !== (secretData.oktaDomain || '') ||
            specialFormData.authorizationServerId !==
              (secretData.authorizationServerId || '')
          )
        default:
          return false
      }
    } else {
      // Standard provider comparison
      return (
        standardFormData.enabled !== (providerData.enabled ?? false) ||
        standardFormData.appId !== (providerData.appId || '') ||
        standardFormData.secret !== (providerData.secret || '')
      )
    }
  }, [selectedProvider, standardFormData, specialFormData, rawProjectData])

  // Get OAuth providers filtered and sorted (popular first, then enabled, then others)
  const filteredAndSortedProviders = useMemo(() => {
    const projectData = rawProjectData as unknown

    // Filter by search query
    const filtered = OAUTH_PROVIDERS.filter((provider) =>
      provider.name.toLowerCase().includes(providerSearch.toLowerCase()),
    )

    // Sort: popular first, then enabled, then others
    return filtered.sort((a, b) => {
      const aIsPopular = a.popular ?? false
      const bIsPopular = b.popular ?? false

      // Popular providers first
      if (aIsPopular && !bIsPopular) return -1
      if (!aIsPopular && bIsPopular) return 1

      // Within popular/non-popular groups, sort by enabled status
      const aProviderData = getProviderData(projectData, a.key)
      const bProviderData = getProviderData(projectData, b.key)
      const aEnabled = aProviderData?.enabled ?? false
      const bEnabled = bProviderData?.enabled ?? false

      if (aEnabled !== bEnabled) {
        return aEnabled ? -1 : 1
      }

      // Finally, sort alphabetically
      return a.name.localeCompare(b.name)
    })
  }, [rawProjectData, providerSearch])

  // Separate popular and other providers
  const { popularProviders, otherProviders } = useMemo(() => {
    const popular: typeof OAUTH_PROVIDERS = []
    const other: typeof OAUTH_PROVIDERS = []

    filteredAndSortedProviders.forEach((provider) => {
      if (provider.popular) {
        popular.push(provider)
      } else {
        other.push(provider)
      }
    })

    return { popularProviders: popular, otherProviders: other }
  }, [filteredAndSortedProviders])

  // Get redirect URI for selected provider
  const redirectUri = useMemo(() => {
    if (!selectedProvider || !projectId) return ''
    return `${projectEndpoint}/account/sessions/oauth2/callback/${selectedProvider}/${projectId}`
  }, [selectedProvider, projectId, projectEndpoint])

  if (projectLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const selectedProviderName = selectedProvider
    ? getProviderConfig(selectedProvider)?.name || selectedProvider
    : ''

  return (
    <div className="space-y-6">
      {/* Auth Methods Section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Auth methods
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-4">
            Enable the authentication methods you wish to use.
          </p>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {AUTH_METHODS.map((method) => {
              const Icon = method.icon
              const isUpdating = updatingAuthMethods.has(method.key)
              const enabled = authMethods[method.key] ?? false

              return (
                <div
                  key={method.key}
                  className={cn(
                    'rounded-lg border border-border bg-card/50 p-4 transition-colors',
                    isUpdating && 'opacity-75',
                    !isUpdating && 'hover:bg-card',
                  )}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <Icon className="h-4 w-4 text-muted-foreground" />
                      <Label
                        htmlFor={method.key}
                        className="text-[13px] font-medium text-foreground cursor-pointer"
                      >
                        {method.label}
                      </Label>
                    </div>
                    <div className="flex items-center gap-2">
                      {isUpdating && (
                        <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                      )}
                      <Switch
                        id={method.key}
                        checked={enabled}
                        onCheckedChange={(checked) =>
                          handleAuthMethodToggle(method.key, checked)
                        }
                        disabled={isUpdating}
                      />
                    </div>
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      </div>

      {/* OAuth2 Providers Section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            OAuth2 providers
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-4">
            Configure OAuth2 providers for social login authentication.
          </p>

          {/* Search Input */}
          <div className="mb-6">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                type="text"
                placeholder="Search providers..."
                value={providerSearch}
                onChange={(e) => setProviderSearch(e.target.value)}
                className="pl-9 h-9 text-[13px]"
              />
            </div>
          </div>

          {/* Popular Providers */}
          {popularProviders.length > 0 && (
            <div className="mb-6">
              <h4 className="text-[13px] font-medium text-foreground mb-3">
                Popular
              </h4>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {popularProviders.map((provider) => {
                  const projectData = rawProjectData as unknown
                  const providerData = getProviderData(
                    projectData,
                    provider.key,
                  )
                  const enabled = providerData?.enabled ?? false

                  return (
                    <button
                      key={provider.key}
                      type="button"
                      onClick={() => handleProviderClick(provider.key)}
                      className="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-border bg-card/50 p-4 text-left transition-colors hover:bg-card"
                    >
                      <div className="flex min-w-0 items-center gap-2">
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                          <img
                            src={`/icons/${provider.icon}`}
                            alt=""
                            className={`h-5 w-5 ${PUBLIC_ICON_MUTED_CLASSES}`}
                          />
                        </div>
                        <span className="text-[13px] font-medium text-foreground">
                          {provider.name}
                        </span>
                      </div>
                      <Badge
                        variant={enabled ? 'default' : 'secondary'}
                        className={cn(
                          'shrink-0 text-[11px]',
                          enabled
                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                            : 'bg-muted text-muted-foreground',
                        )}
                      >
                        {enabled ? 'enabled' : 'disabled'}
                      </Badge>
                    </button>
                  )
                })}
              </div>
            </div>
          )}

          {/* Other Providers */}
          {otherProviders.length > 0 && (
            <div>
              {popularProviders.length > 0 && (
                <>
                  <div className="my-6 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                    <div className="h-px flex-1 bg-border" />
                    <span className="font-medium text-foreground/80">
                      All Providers
                    </span>
                    <div className="h-px flex-1 bg-border" />
                  </div>
                </>
              )}
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {otherProviders.map((provider) => {
                  const projectData = rawProjectData as unknown
                  const providerData = getProviderData(
                    projectData,
                    provider.key,
                  )
                  const enabled = providerData?.enabled ?? false

                  return (
                    <button
                      key={provider.key}
                      type="button"
                      onClick={() => handleProviderClick(provider.key)}
                      className="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-border bg-card/50 p-4 text-left transition-colors hover:bg-card"
                    >
                      <div className="flex min-w-0 items-center gap-2">
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                          <img
                            src={`/icons/${provider.icon}`}
                            alt=""
                            className={`h-5 w-5 ${PUBLIC_ICON_MUTED_CLASSES}`}
                          />
                        </div>
                        <span className="text-[13px] font-medium text-foreground">
                          {provider.name}
                        </span>
                      </div>
                      <Badge
                        variant={enabled ? 'default' : 'secondary'}
                        className={cn(
                          'shrink-0 text-[11px]',
                          enabled
                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                            : 'bg-muted text-muted-foreground',
                        )}
                      >
                        {enabled ? 'enabled' : 'disabled'}
                      </Badge>
                    </button>
                  )
                })}
              </div>
            </div>
          )}

          {/* Empty State */}
          {filteredAndSortedProviders.length === 0 && (
            <div className="text-center py-8">
              <p className="text-[13px] text-muted-foreground">
                No providers found matching "{providerSearch}"
              </p>
            </div>
          )}
        </div>
      </div>

      {/* OAuth Provider Configuration Drawer */}
      <Drawer
        open={providerDrawerOpen}
        onOpenChange={setProviderDrawerOpen}
        direction="right"
      >
        <DrawerContent className="h-full p-0 flex flex-col">
          <DrawerHeader className="px-6 pt-6 text-left shrink-0">
            <DrawerTitle className="text-[15px]">
              {selectedProviderName} OAuth2 settings
            </DrawerTitle>
            <DrawerDescription className="text-[13px] mt-2">
              To use {selectedProviderName} authentication in your application,
              first fill in this form.
              {selectedProvider &&
                getProviderConfig(selectedProvider)?.docsUrl && (
                  <>
                    {' '}
                    For more info you can visit the{' '}
                    <a
                      href={getProviderConfig(selectedProvider)!.docsUrl!}
                      target="_blank"
                      rel="noreferrer"
                      className="text-foreground underline hover:no-underline inline-flex items-center gap-1"
                    >
                      docs
                      <ExternalLink className="h-3 w-3" />
                    </a>
                    .
                  </>
                )}
            </DrawerDescription>
          </DrawerHeader>
          <div className="border-t border-border shrink-0" />
          <div className="px-6 pb-4 pt-4 overflow-y-auto flex-1 min-h-0">
            {selectedProvider && isSpecialProvider(selectedProvider) ? (
              <SpecialProviderForm
                providerKey={selectedProvider}
                formData={specialFormData}
                setFormData={setSpecialFormData}
                redirectUri={redirectUri}
                providerName={selectedProviderName}
                error={providerError}
              />
            ) : (
              <StandardProviderForm
                formData={standardFormData}
                setFormData={setStandardFormData}
                redirectUri={redirectUri}
                providerName={selectedProviderName}
                error={providerError}
              />
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start shrink-0">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleProviderSubmit}
              disabled={updateOAuth2Mutation.isPending || !hasProviderChanges}
            >
              Update
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setProviderDrawerOpen(false)}
              disabled={updateOAuth2Mutation.isPending}
            >
              Cancel
            </Button>
          </div>
        </DrawerContent>
      </Drawer>
    </div>
  )
}
