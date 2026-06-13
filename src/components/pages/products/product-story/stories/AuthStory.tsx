import { Fingerprint, KeyRound, LockKeyhole, Mail, ShieldCheck, Users } from 'lucide-react'
import { StoryAnimated } from '@/components/pages/products/product-story/StoryAnimated'
import {
  ProductStoryGrid,
  type ProductStoryFeature,
} from '@/components/pages/products/product-story/ProductStoryGrid'
import { StoryCodeBlock } from '@/components/pages/products/product-story/StoryCodeBlock'
import { StoryOAuthProviders } from '@/components/pages/products/product-story/StoryOAuthProviders'
import { StoryField, StoryOptionCard } from '@/components/pages/products/product-story/shared'

const CLIENT_AUTH_CODE = [
  { text: "import { Client, Account } from 'appwrite';", tone: 'plain' as const },
  { text: '', tone: 'plain' as const },
  { text: 'const client = new Client()', tone: 'plain' as const },
  { text: "  .setEndpoint('https://cloud.appwrite.io/v1')", tone: 'string' as const },
  { text: "  .setProject('<PROJECT_ID>');", tone: 'string' as const },
  { text: '', tone: 'plain' as const },
  { text: 'const account = new Account(client);', tone: 'plain' as const },
  { text: 'await account.createEmailPasswordSession({', tone: 'keyword' as const },
  { text: "  email: 'alex@acme.com',", tone: 'string' as const },
  { text: "  password: '********',", tone: 'string' as const },
  { text: '});', tone: 'plain' as const },
]

const SSR_AUTH_CODE = [
  { text: "import { Client, Account } from 'node-appwrite';", tone: 'plain' as const },
  { text: 'const client = new Client()', tone: 'plain' as const },
  { text: '  .setSession(sessionSecret);', tone: 'plain' as const },
  { text: 'const user = await account.get();', tone: 'keyword' as const },
]

const OAUTH_CODE = [
  { text: 'await account.createOAuth2Session({', tone: 'keyword' as const },
  { text: "  provider: OAuthProvider.Github,", tone: 'plain' as const },
  { text: "  success: 'https://app.example.com/oauth',", tone: 'string' as const },
  { text: '});', tone: 'plain' as const },
]

const FEATURES: ProductStoryFeature[] = [
  {
    id: 'sign-in',
    title: 'Sign-in methods',
    description: 'Email, OAuth, phone, and passwordless flows in one branded experience.',
    icon: KeyRound,
    className: 'lg:col-span-6',
    content: (
      <div className="mx-auto max-w-sm space-y-4">
        <StoryOAuthProviders />
        <StoryAnimated delayMs={400}>
          <div className="relative py-2 text-center text-[10px] text-muted-foreground">
            <span className="bg-card/50 px-2">or with email</span>
            <span className="absolute inset-x-0 top-1/2 -z-10 h-px bg-border" aria-hidden />
          </div>
        </StoryAnimated>
        <StoryAnimated delayMs={480}>
          <StoryField label="Email" value="alex@acme.com" active />
        </StoryAnimated>
        <StoryAnimated delayMs={560}>
          <StoryField label="Password" value="••••••••••" />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'methods',
    title: 'Configurable methods',
    description: 'Enable email, magic URL, OAuth, and more per project from the console.',
    icon: ShieldCheck,
    className: 'lg:col-span-6',
    content: (
      <div className="grid gap-2">
        <StoryAnimated delayMs={0}>
          <StoryOptionCard
            selected
            title="Email/password"
            description="Argon2 hashing built in."
            icon={<Mail className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={100}>
          <StoryOptionCard
            title="Magic URL"
            description="Passwordless email links."
            icon={<KeyRound className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={200}>
          <StoryOptionCard
            title="OAuth 2"
            description="Google, GitHub, X, Apple, and 40+ more."
            icon={<Users className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'sessions',
    title: 'Sessions & MFA',
    description: 'Issue JWTs, manage expiry, and enforce authenticator apps for sensitive accounts.',
    icon: LockKeyhole,
    className: 'lg:col-span-4',
    content: (
      <div className="grid gap-3 sm:grid-cols-2">
        <StoryAnimated delayMs={0}>
          <StoryField label="User ID" value="67f8a2040012ab34cd01" mono active />
        </StoryAnimated>
        <StoryAnimated delayMs={100}>
          <StoryField label="Session ID" value="67f8a2050044ef56gh78" mono />
        </StoryAnimated>
        <StoryAnimated delayMs={200}>
          <StoryField label="Expires" value="2026-06-12 18:30 UTC" />
        </StoryAnimated>
        <StoryAnimated delayMs={300}>
          <StoryField label="MFA" value="TOTP enabled" />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'permissions',
    title: 'Resource permissions',
    description: 'Scope database rows, storage files, and functions to signed-in users and teams.',
    icon: Fingerprint,
    className: 'lg:col-span-4',
    content: (
      <div className="space-y-2">
        <StoryAnimated delayMs={0}>
          <div className="rounded-lg border border-border bg-muted/10 px-3 py-2 text-[11px]">
            <p className="font-medium text-foreground">Read</p>
            <p className="mt-1 font-mono text-muted-foreground">user:67f8a2040012ab34cd01</p>
          </div>
        </StoryAnimated>
        <StoryAnimated delayMs={120}>
          <div className="rounded-lg border border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))] bg-[color-mix(in_srgb,var(--brand-cta)_6%,var(--background))] px-3 py-2 text-[11px]">
            <p className="font-medium text-foreground">Create</p>
            <p className="mt-1 font-mono text-muted-foreground">users</p>
          </div>
        </StoryAnimated>
        <StoryAnimated delayMs={240}>
          <p className="flex items-center gap-2 rounded-md border border-border bg-muted/10 px-3 py-2 text-[11px] text-muted-foreground">
            <LockKeyhole className="size-3.5 shrink-0" aria-hidden />
            Functions verify JWT before executing logic
          </p>
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'oauth-code',
    title: 'OAuth integration',
    description: 'Start OAuth sessions from client SDKs with redirect URLs you control.',
    icon: Users,
    className: 'lg:col-span-4',
    content: <StoryCodeBlock title="OAuth sign-in" language="TypeScript" lines={OAUTH_CODE} />,
  },
  {
    id: 'sdks',
    title: 'Client and SSR SDKs',
    description: 'Use the same Auth APIs in browsers, mobile apps, and server-rendered routes.',
    icon: Mail,
    className: 'lg:col-span-12',
    tall: true,
    content: (
      <div className="grid h-full gap-3 lg:grid-cols-2">
        <StoryCodeBlock title="Client SDK" language="TypeScript" lines={CLIENT_AUTH_CODE} />
        <StoryCodeBlock title="SSR route" language="TypeScript" lines={SSR_AUTH_CODE} />
      </div>
    ),
  },
]

export function AuthStory() {
  return <ProductStoryGrid features={FEATURES} />
}
