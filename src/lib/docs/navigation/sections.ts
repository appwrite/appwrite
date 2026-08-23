import type { DocsNavParent, DocsNavTree } from '../types'

export type DocsSectionNavConfig = {
  prefix: string
  parent: DocsNavParent
  navigation: DocsNavTree
}

/** Generated from ../website layout files. Run: bun run generate:docs-nav */
export const DOCS_SECTION_NAVS: DocsSectionNavConfig[] = [
    {
      prefix: "advanced/billing",
      parent: {
        href: "/docs",
        label: "Billing",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/advanced/billing",
            },
            {
              label: "Manage billing",
              href: "/docs/advanced/billing/payments",
            },
          ],
        },
        {
          label: "Plans",
          items: [
            {
              label: "Free",
              href: "/docs/advanced/billing/free",
            },
            {
              label: "Pro",
              href: "/docs/advanced/billing/pro",
            },
            {
              label: "Enterprise",
              href: "/docs/advanced/billing/enterprise",
            },
            {
              label: "Open source",
              href: "/docs/advanced/billing/oss",
            },
          ],
        },
        {
          label: "Add ons",
          items: [
            {
              label: "Compute",
              href: "/docs/advanced/billing/compute",
            },
            {
              label: "Phone OTP",
              href: "/docs/advanced/billing/phone-otp",
            },
            {
              label: "Image Transformations",
              href: "/docs/advanced/billing/image-transformations",
            },
            {
              label: "Database Reads and Writes",
              href: "/docs/advanced/billing/database-reads-and-writes",
            },
          ],
        },
        {
          label: "SLAs",
          items: [
            {
              label: "Support SLA",
              href: "/docs/advanced/billing/support-sla",
            },
            {
              label: "Uptime SLA",
              href: "/docs/advanced/billing/uptime-sla",
            },
          ],
        },
        {
          label: "Policies",
          items: [
            {
              label: "Fair use",
              href: "/docs/advanced/billing/fair-use-policy",
            },
            {
              label: "Abuse",
              href: "/docs/advanced/billing/abuse",
            },
            {
              label: "Refund",
              href: "/docs/advanced/billing/refund-policy",
            },
          ],
        },
      ],
    },
    {
      prefix: "advanced/migrations",
      parent: {
        href: "/docs",
        label: "Migrations",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/advanced/migrations",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "From Firebase",
              href: "/docs/advanced/migrations/firebase",
            },
            {
              label: "From Supabase",
              href: "/docs/advanced/migrations/supabase",
            },
            {
              label: "From Nhost",
              href: "/docs/advanced/migrations/nhost",
            },
            {
              label: "From Cloud",
              href: "/docs/advanced/migrations/cloud",
            },
            {
              label: "From self-hosted",
              href: "/docs/advanced/migrations/self-hosted",
            },
          ],
        },
      ],
    },
    {
      prefix: "advanced/security",
      parent: {
        href: "/docs",
        label: "Security",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/advanced/security",
            },
          ],
        },
        {
          label: "Compliances",
          items: [
            {
              label: "GDPR",
              href: "/docs/advanced/security/gdpr",
            },
            {
              label: "PCI",
              href: "/docs/advanced/security/pci",
            },
            {
              label: "SOC 2",
              href: "/docs/advanced/security/soc2",
            },
            {
              label: "HIPAA",
              href: "/docs/advanced/security/hipaa",
            },
            {
              label: "CCPA",
              href: "/docs/advanced/security/ccpa",
            },
          ],
        },
        {
          label: "Measures",
          items: [
            {
              label: "Authentication",
              href: "/docs/advanced/security/authentication",
            },
            {
              label: "Encryption",
              href: "/docs/advanced/security/encryption",
            },
            {
              label: "Multi-Factor authentication",
              href: "/docs/advanced/security/mfa",
            },
            {
              label: "HTTPS",
              href: "/docs/advanced/security/https",
            },
            {
              label: "TLS",
              href: "/docs/advanced/security/tls",
            },
            {
              label: "Backups",
              href: "/docs/advanced/security/backups",
            },
            {
              label: "Penetration tests",
              href: "/docs/advanced/security/penetration-tests",
            },
            {
              label: "Audit logs",
              href: "/docs/advanced/security/audit-logs",
            },
            {
              label: "Abuse protection",
              href: "/docs/advanced/security/abuse-protection",
            },
          ],
        },
        {
          label: "Access control",
          items: [
            {
              label: "Permissions",
              href: "/docs/advanced/security/permissions",
            },
            {
              label: "Roles",
              href: "/docs/advanced/security/roles",
            },
            {
              label: "Rate limits",
              href: "/docs/advanced/security/rate-limits",
            },
            {
              label: "Dev keys",
              href: "/docs/advanced/security/dev-keys",
            },
          ],
        },
      ],
    },
    {
      prefix: "advanced/self-hosting",
      parent: {
        href: "/docs",
        label: "Self-hosting",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/advanced/self-hosting",
            },
            {
              label: "Installation",
              href: "/docs/advanced/self-hosting/installation",
            },
          ],
        },
        {
          label: "Platform deployment",
          items: [
            {
              label: "AWS",
              href: "/docs/advanced/self-hosting/platforms/aws",
            },
            {
              label: "DigitalOcean",
              href: "/docs/advanced/self-hosting/platforms/digitalocean",
            },
            {
              label: "Google Cloud",
              href: "/docs/advanced/self-hosting/platforms/google-cloud",
            },
            {
              label: "Azure",
              href: "/docs/advanced/self-hosting/platforms/azure",
            },
            {
              label: "Coolify",
              href: "/docs/advanced/self-hosting/platforms/coolify",
            },
            {
              label: "Dokploy",
              href: "/docs/advanced/self-hosting/platforms/dokploy",
            },
          ],
        },
        {
          label: "Configuration",
          items: [
            {
              label: "Databases",
              href: "/docs/advanced/self-hosting/configuration/databases",
            },
            {
              label: "Environment variables",
              href: "/docs/advanced/self-hosting/configuration/environment-variables",
            },
            {
              label: "Email delivery",
              href: "/docs/advanced/self-hosting/configuration/email",
            },
            {
              label: "SMS delivery",
              href: "/docs/advanced/self-hosting/configuration/sms",
            },
            {
              label: "Functions",
              href: "/docs/advanced/self-hosting/configuration/functions",
            },
            {
              label: "Sites",
              href: "/docs/advanced/self-hosting/configuration/sites",
            },
            {
              label: "Storage",
              href: "/docs/advanced/self-hosting/configuration/storage",
            },
            {
              label: "TLS certificates",
              href: "/docs/advanced/self-hosting/configuration/tls-certificates",
            },
            {
              label: "Version control",
              href: "/docs/advanced/self-hosting/configuration/version-control",
            },
          ],
        },
        {
          label: "Production",
          items: [
            {
              label: "Preparation",
              href: "/docs/advanced/self-hosting/production",
            },
            {
              label: "Security",
              href: "/docs/advanced/self-hosting/production/security",
            },
            {
              label: "Scaling",
              href: "/docs/advanced/self-hosting/production/scaling",
            },
            {
              label: "Rate limits",
              href: "/docs/advanced/self-hosting/production/rate-limits",
            },
            {
              label: "Email delivery",
              href: "/docs/advanced/self-hosting/production/emails",
            },
            {
              label: "Error monitoring",
              href: "/docs/advanced/self-hosting/production/errors",
            },
            {
              label: "Backups",
              href: "/docs/advanced/self-hosting/production/backups",
            },
            {
              label: "Updates and migrations",
              href: "/docs/advanced/self-hosting/production/updates",
            },
            {
              label: "Debugging",
              href: "/docs/advanced/self-hosting/production/debugging",
            },
          ],
        },
      ],
    },
    {
      prefix: "apis",
      parent: {
        href: "/docs",
        label: "APIs",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/apis",
            },
          ],
        },
        {
          label: "Protocols",
          items: [
            {
              label: "REST",
              href: "/docs/apis/rest",
            },
            {
              label: "GraphQL",
              href: "/docs/apis/graphql",
            },
            {
              label: "Realtime",
              href: "/docs/apis/realtime",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Events",
              href: "/docs/apis/events",
            },
            {
              label: "Webhooks",
              href: "/docs/apis/webhooks",
            },
            {
              label: "Response codes",
              href: "/docs/apis/response-codes",
            },
          ],
        },
        {
          label: "Policies",
          items: [
            {
              label: "Release policy",
              href: "/docs/apis/release-policy",
            },
          ],
        },
      ],
    },
    {
      prefix: "apis/realtime",
      parent: {
        href: "/docs/apis",
        label: "Realtime",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/apis/realtime",
            },
            {
              label: "Authentication",
              href: "/docs/apis/realtime/authentication",
            },
            {
              label: "Subscribe",
              href: "/docs/apis/realtime/subscribe",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Channels",
              href: "/docs/apis/realtime/channels",
            },
            {
              label: "Queries",
              href: "/docs/apis/realtime/queries",
            },
            {
              label: "Payload",
              href: "/docs/apis/realtime/payload",
            },
            {
              label: "Presences",
              href: "/docs/apis/realtime/presences",
            },
          ],
        },
        {
          label: "Configuration",
          items: [
            {
              label: "Custom endpoint",
              href: "/docs/apis/realtime/custom-endpoint",
            },
          ],
        },
      ],
    },
    {
      prefix: "partners/apps",
      parent: {
        href: "/docs",
        label: "Apps",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/partners/apps",
            },
            {
              label: "Quick start",
              href: "/docs/partners/apps/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Registration",
              href: "/docs/partners/apps/registration",
            },
            {
              label: "Scopes",
              href: "/docs/partners/apps/scopes",
            },
            {
              label: "Consent",
              href: "/docs/partners/apps/consent",
            },
            {
              label: "Tokens",
              href: "/docs/partners/apps/tokens",
            },
            {
              label: "Device flow",
              href: "/docs/partners/apps/device-flow",
            },
          ],
        },
      ],
    },
    {
      prefix: "partners/project",
      parent: {
        href: "/docs",
        label: "Project",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/partners/project",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Auth methods",
              href: "/docs/partners/project/auth-methods",
            },
            {
              label: "OAuth providers",
              href: "/docs/partners/project/oauth",
            },
            {
              label: "API keys",
              href: "/docs/partners/project/api-keys",
            },
            {
              label: "Platforms",
              href: "/docs/partners/project/platforms",
            },
            {
              label: "Protocols",
              href: "/docs/partners/project/protocols",
            },
            {
              label: "Services",
              href: "/docs/partners/project/services",
            },
            {
              label: "Policies",
              href: "/docs/partners/project/policies",
            },
            {
              label: "Mock phones",
              href: "/docs/partners/project/mock-phones",
            },
            {
              label: "Environment variables",
              href: "/docs/partners/project/environment-variables",
            },
            {
              label: "SMTP",
              href: "/docs/partners/project/smtp",
            },
            {
              label: "Email templates",
              href: "/docs/partners/project/email-templates",
            },
            {
              label: "Labels",
              href: "/docs/partners/project/labels",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Provisioning",
              href: "/docs/partners/project/provisioning",
            },
            {
              label: "Key rotation",
              href: "/docs/partners/project/key-rotation",
            },
            {
              label: "Branded emails",
              href: "/docs/partners/project/branded-emails",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/ai",
      parent: {
        href: "/docs",
        label: "AI",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/ai",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Computer vision",
              href: "/docs/products/ai/computer-vision",
            },
            {
              label: "Natural language processing",
              href: "/docs/products/ai/natural-language",
            },
            {
              label: "Audio processing",
              href: "/docs/products/ai/audio-processing",
            },
          ],
        },
        {
          label: "Computer vision",
          items: [
            {
              label: "Image classification",
              href: "/docs/products/ai/tutorials/image-classification",
            },
            {
              label: "Object detection",
              href: "/docs/products/ai/tutorials/object-detection",
            },
          ],
        },
        {
          label: "Natural language processing",
          items: [
            {
              label: "Text generation",
              href: "/docs/products/ai/tutorials/text-generation",
            },
            {
              label: "Language translation",
              href: "/docs/products/ai/tutorials/language-translation",
            },
          ],
        },
        {
          label: "Audio processing",
          items: [
            {
              label: "Speech recognition",
              href: "/docs/products/ai/tutorials/speech-recognition",
            },
            {
              label: "Text to speech",
              href: "/docs/products/ai/tutorials/text-to-speech",
            },
            {
              label: "Music generation",
              href: "/docs/products/ai/tutorials/music-generation",
            },
          ],
        },
        {
          label: "Integrations",
          items: [
            {
              label: "Perplexity",
              href: "/docs/products/ai/integrations/perplexity",
            },
            {
              label: "Replicate",
              href: "/docs/products/ai/integrations/replicate",
            },
            {
              label: "OpenAI",
              href: "/docs/products/ai/integrations/openai",
            },
            {
              label: "Pinecone",
              href: "/docs/products/ai/integrations/pinecone",
            },
            {
              label: "ElevenLabs",
              href: "/docs/products/ai/integrations/elevenlabs",
            },
            {
              label: "LangChain",
              href: "/docs/products/ai/integrations/langchain",
            },
            {
              label: "Anyscale",
              href: "/docs/products/ai/integrations/anyscale",
            },
            {
              label: "LMNT",
              href: "/docs/products/ai/integrations/lmnt",
            },
            {
              label: "Together AI",
              href: "/docs/products/ai/integrations/togetherai",
            },
            {
              label: "fal.ai",
              href: "/docs/products/ai/integrations/fal-ai",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/auth",
      parent: {
        href: "/docs",
        label: "Auth",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/auth",
            },
            {
              label: "Quick start",
              href: "/docs/products/auth/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Accounts",
              href: "/docs/products/auth/accounts",
            },
            {
              label: "Users",
              href: "/docs/products/auth/users",
            },
            {
              label: "Teams",
              href: "/docs/products/auth/teams",
            },
            {
              label: "Impersonation",
              href: "/docs/products/auth/impersonation",
            },
            {
              label: "Preferences",
              href: "/docs/products/auth/preferences",
            },
            {
              label: "Labels",
              href: "/docs/products/auth/labels",
            },
            {
              label: "Security",
              href: "/docs/products/auth/security",
            },
            {
              label: "Email policies",
              href: "/docs/products/auth/email-policies",
            },
            {
              label: "Message templates",
              href: "/docs/products/auth/message-templates",
            },
            {
              label: "Tokens",
              href: "/docs/products/auth/tokens",
            },
            {
              label: "Identities",
              href: "/docs/products/auth/identities",
            },
            {
              label: "Presences",
              href: "/docs/products/auth/presences",
            },
            {
              label: "OAuth2 server",
              href: "/docs/products/auth/oauth-server",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Email and password login",
              href: "/docs/products/auth/email-password",
            },
            {
              label: "Phone (SMS) login",
              href: "/docs/products/auth/phone-sms",
            },
            {
              label: "Magic URL login",
              href: "/docs/products/auth/magic-url",
            },
            {
              label: "Email OTP login",
              href: "/docs/products/auth/email-otp",
            },
            {
              label: "OAuth2 login",
              href: "/docs/products/auth/oauth2",
            },
            {
              label: "Anonymous login",
              href: "/docs/products/auth/anonymous",
            },
            {
              label: "JWT login",
              href: "/docs/products/auth/jwt",
            },
            {
              label: "SSR login",
              href: "/docs/products/auth/server-side-rendering",
            },
            {
              label: "React library",
              href: "/docs/products/auth/react",
            },
            {
              label: "Custom token login",
              href: "/docs/products/auth/custom-token",
            },
            {
              label: "Multi-factor authentication",
              href: "/docs/products/auth/mfa",
            },
            {
              label: "Auth status check",
              href: "/docs/products/auth/checking-auth-status",
            },
            {
              label: "User verification",
              href: "/docs/products/auth/verify-user",
            },
            {
              label: "Team invites",
              href: "/docs/products/auth/team-invites",
            },
            {
              label: "Multi-tenancy",
              href: "/docs/products/auth/multi-tenancy",
            },
          ],
        },
        {
          label: "References",
          items: [
            {
              label: "Account API",
              href: "/docs/references/cloud/client-web/account",
            },
            {
              label: "Users API",
              href: "/docs/references/cloud/server-nodejs/users",
            },
            {
              label: "Teams API",
              href: "/docs/references/cloud/client-web/teams",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/auth/oauth-server",
      parent: {
        href: "/docs/products/auth",
        label: "OAuth2 server",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/auth/oauth-server",
            },
            {
              label: "Quick start",
              href: "/docs/products/auth/oauth-server/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Clients",
              href: "/docs/products/auth/oauth-server/clients",
            },
            {
              label: "Authorization",
              href: "/docs/products/auth/oauth-server/authorization",
            },
            {
              label: "Tokens",
              href: "/docs/products/auth/oauth-server/tokens",
            },
            {
              label: "Scopes",
              href: "/docs/products/auth/oauth-server/scopes",
            },
            {
              label: "Device flow",
              href: "/docs/products/auth/oauth-server/device-flow",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Sign in with your product",
              href: "/docs/products/auth/oauth-server/sign-in-with-your-product/step-1",
            },
            {
              label: "Custom scopes",
              href: "/docs/products/auth/oauth-server/custom-scopes/step-1",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/avatars",
      parent: {
        href: "/docs",
        label: "Avatars",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/avatars",
            },
            {
              label: "Quick start",
              href: "/docs/products/avatars/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "User initials",
              href: "/docs/products/avatars/initials",
            },
            {
              label: "QR codes",
              href: "/docs/products/avatars/qr-codes",
            },
            {
              label: "Country flags",
              href: "/docs/products/avatars/flags",
            },
            {
              label: "Browser icons",
              href: "/docs/products/avatars/browsers",
            },
            {
              label: "Payment methods",
              href: "/docs/products/avatars/payment-methods",
            },
            {
              label: "Favicons",
              href: "/docs/products/avatars/favicons",
            },
            {
              label: "Screenshots",
              href: "/docs/products/avatars/screenshots",
            },
            {
              label: "Image proxy",
              href: "/docs/products/avatars/image-manipulation",
            },
          ],
        },
        {
          label: "References",
          items: [
            {
              label: "Avatars API",
              href: "/docs/references/cloud/client-web/avatars",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/databases",
      parent: {
        href: "/docs",
        label: "Databases",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/databases",
            },
          ],
        },
        {
          label: "Appwrite databases",
          items: [
            {
              label: "TablesDB",
              href: "/docs/products/databases/tablesdb",
            },
            {
              label: "DocumentsDB",
              href: "/docs/products/databases/documentsdb",
            },
            {
              label: "VectorsDB",
              href: "/docs/products/databases/vectorsdb",
            },
          ],
        },
        {
          label: "Native databases",
          items: [
            {
              label: "PostgreSQL",
              href: "/docs/products/databases/postgresql",
            },
            {
              label: "MySQL",
              href: "/docs/products/databases/mysql",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/databases/documentsdb",
      parent: {
        href: "/docs/products/databases",
        label: "DocumentsDB",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/databases/documentsdb",
            },
            {
              label: "Quick start",
              href: "/docs/products/databases/documentsdb/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Databases",
              href: "/docs/products/databases/documentsdb/databases",
            },
            {
              label: "Collections",
              href: "/docs/products/databases/documentsdb/collections",
            },
            {
              label: "Documents",
              href: "/docs/products/databases/documentsdb/documents",
            },
            {
              label: "Permissions",
              href: "/docs/products/databases/documentsdb/permissions",
            },
            {
              label: "Queries",
              href: "/docs/products/databases/documentsdb/queries",
            },
            {
              label: "Order",
              href: "/docs/products/databases/documentsdb/order",
            },
            {
              label: "Backups",
              href: "/docs/products/databases/documentsdb/backups",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Pagination",
              href: "/docs/products/databases/documentsdb/pagination",
            },
            {
              label: "Transactions",
              href: "/docs/products/databases/documentsdb/transactions",
            },
            {
              label: "Bulk operations",
              href: "/docs/products/databases/documentsdb/bulk-operations",
            },
            {
              label: "Atomic numeric operations",
              href: "/docs/products/databases/documentsdb/atomic-numeric-operations",
            },
            {
              label: "Timestamp overrides",
              href: "/docs/products/databases/documentsdb/timestamp-overrides",
            },
            {
              label: "JSON imports",
              href: "/docs/products/databases/documentsdb/json-imports",
            },
            {
              label: "JSON exports",
              href: "/docs/products/databases/documentsdb/json-exports",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/databases/mysql",
      parent: {
        href: "/docs/products/databases",
        label: "MySQL",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/databases/mysql",
            },
            {
              label: "Quick start",
              href: "/docs/products/databases/mysql/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Tables and data types",
              href: "/docs/products/databases/mysql/concepts/tables",
            },
            {
              label: "Querying rows",
              href: "/docs/products/databases/mysql/concepts/queries",
            },
            {
              label: "Joins and relationships",
              href: "/docs/products/databases/mysql/concepts/joins",
            },
            {
              label: "Indexes",
              href: "/docs/products/databases/mysql/concepts/indexes",
            },
            {
              label: "Transactions",
              href: "/docs/products/databases/mysql/concepts/transactions",
            },
            {
              label: "Data modeling",
              href: "/docs/products/databases/mysql/concepts/data-modeling",
            },
            {
              label: "Security and access control",
              href: "/docs/products/databases/mysql/concepts/access-control",
            },
          ],
        },
        {
          label: "Connecting",
          items: [
            {
              label: "Connections",
              href: "/docs/products/databases/mysql/connections",
            },
            {
              label: "Connection pooling",
              href: "/docs/products/databases/mysql/connection-pooling",
            },
          ],
        },
        {
          label: "Manage",
          items: [
            {
              label: "Backups",
              href: "/docs/products/databases/mysql/backups",
            },
            {
              label: "Branches",
              href: "/docs/products/databases/mysql/branches",
            },
            {
              label: "High availability",
              href: "/docs/products/databases/mysql/high-availability",
            },
            {
              label: "Scaling",
              href: "/docs/products/databases/mysql/scaling",
            },
            {
              label: "Network security",
              href: "/docs/products/databases/mysql/network-security",
            },
            {
              label: "Monitoring",
              href: "/docs/products/databases/mysql/monitoring",
            },
            {
              label: "Maintenance",
              href: "/docs/products/databases/mysql/maintenance",
            },
          ],
        },
        {
          label: "Integrations",
          items: [
            {
              label: "Node.js drivers",
              href: "/docs/products/databases/mysql/integrations/drivers",
            },
            {
              label: "Prisma",
              href: "/docs/products/databases/mysql/integrations/prisma",
            },
            {
              label: "Drizzle",
              href: "/docs/products/databases/mysql/integrations/drizzle",
            },
            {
              label: "Auth.js",
              href: "/docs/products/databases/mysql/integrations/auth-js",
            },
            {
              label: "Better Auth",
              href: "/docs/products/databases/mysql/integrations/better-auth",
            },
            {
              label: "Laravel",
              href: "/docs/products/databases/mysql/integrations/laravel",
            },
            {
              label: "Rails",
              href: "/docs/products/databases/mysql/integrations/rails",
            },
            {
              label: "Django",
              href: "/docs/products/databases/mysql/integrations/django",
            },
            {
              label: "FastAPI",
              href: "/docs/products/databases/mysql/integrations/fastapi",
            },
            {
              label: "Spring Boot",
              href: "/docs/products/databases/mysql/integrations/spring-boot",
            },
            {
              label: "EF Core",
              href: "/docs/products/databases/mysql/integrations/ef-core",
            },
            {
              label: "GORM",
              href: "/docs/products/databases/mysql/integrations/gorm",
            },
            {
              label: "Next.js",
              href: "/docs/products/databases/mysql/integrations/nextjs",
            },
            {
              label: "dbt",
              href: "/docs/products/databases/mysql/integrations/dbt",
            },
            {
              label: "Metabase",
              href: "/docs/products/databases/mysql/integrations/metabase",
            },
            {
              label: "Grafana",
              href: "/docs/products/databases/mysql/integrations/grafana",
            },
            {
              label: "Retool",
              href: "/docs/products/databases/mysql/integrations/retool",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/databases/postgresql",
      parent: {
        href: "/docs/products/databases",
        label: "PostgreSQL",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/databases/postgresql",
            },
            {
              label: "Quick start",
              href: "/docs/products/databases/postgresql/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Tables and data types",
              href: "/docs/products/databases/postgresql/concepts/tables",
            },
            {
              label: "Querying rows",
              href: "/docs/products/databases/postgresql/concepts/queries",
            },
            {
              label: "Joins and relationships",
              href: "/docs/products/databases/postgresql/concepts/joins",
            },
            {
              label: "Indexes",
              href: "/docs/products/databases/postgresql/concepts/indexes",
            },
            {
              label: "Transactions",
              href: "/docs/products/databases/postgresql/concepts/transactions",
            },
            {
              label: "Data modeling",
              href: "/docs/products/databases/postgresql/concepts/data-modeling",
            },
            {
              label: "Security and access control",
              href: "/docs/products/databases/postgresql/concepts/access-control",
            },
          ],
        },
        {
          label: "Connecting",
          items: [
            {
              label: "Connections",
              href: "/docs/products/databases/postgresql/connections",
            },
            {
              label: "Connection pooling",
              href: "/docs/products/databases/postgresql/connection-pooling",
            },
          ],
        },
        {
          label: "Manage",
          items: [
            {
              label: "Extensions",
              href: "/docs/products/databases/postgresql/extensions",
            },
            {
              label: "Backups",
              href: "/docs/products/databases/postgresql/backups",
            },
            {
              label: "Branches",
              href: "/docs/products/databases/postgresql/branches",
            },
            {
              label: "High availability",
              href: "/docs/products/databases/postgresql/high-availability",
            },
            {
              label: "Scaling",
              href: "/docs/products/databases/postgresql/scaling",
            },
            {
              label: "Network security",
              href: "/docs/products/databases/postgresql/network-security",
            },
            {
              label: "Monitoring",
              href: "/docs/products/databases/postgresql/monitoring",
            },
            {
              label: "Maintenance",
              href: "/docs/products/databases/postgresql/maintenance",
            },
          ],
        },
        {
          label: "Integrations",
          items: [
            {
              label: "Node.js drivers",
              href: "/docs/products/databases/postgresql/integrations/drivers",
            },
            {
              label: "Prisma",
              href: "/docs/products/databases/postgresql/integrations/prisma",
            },
            {
              label: "Drizzle",
              href: "/docs/products/databases/postgresql/integrations/drizzle",
            },
            {
              label: "Auth.js",
              href: "/docs/products/databases/postgresql/integrations/auth-js",
            },
            {
              label: "Better Auth",
              href: "/docs/products/databases/postgresql/integrations/better-auth",
            },
            {
              label: "Laravel",
              href: "/docs/products/databases/postgresql/integrations/laravel",
            },
            {
              label: "Rails",
              href: "/docs/products/databases/postgresql/integrations/rails",
            },
            {
              label: "Django",
              href: "/docs/products/databases/postgresql/integrations/django",
            },
            {
              label: "FastAPI",
              href: "/docs/products/databases/postgresql/integrations/fastapi",
            },
            {
              label: "Spring Boot",
              href: "/docs/products/databases/postgresql/integrations/spring-boot",
            },
            {
              label: "EF Core",
              href: "/docs/products/databases/postgresql/integrations/ef-core",
            },
            {
              label: "GORM",
              href: "/docs/products/databases/postgresql/integrations/gorm",
            },
            {
              label: "Next.js",
              href: "/docs/products/databases/postgresql/integrations/nextjs",
            },
            {
              label: "dbt",
              href: "/docs/products/databases/postgresql/integrations/dbt",
            },
            {
              label: "Metabase",
              href: "/docs/products/databases/postgresql/integrations/metabase",
            },
            {
              label: "Grafana",
              href: "/docs/products/databases/postgresql/integrations/grafana",
            },
            {
              label: "Retool",
              href: "/docs/products/databases/postgresql/integrations/retool",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/databases/tablesdb",
      parent: {
        href: "/docs/products/databases",
        label: "Databases",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/databases/tablesdb",
            },
            {
              label: "Quick start",
              href: "/docs/products/databases/tablesdb/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Databases",
              href: "/docs/products/databases/tablesdb/databases",
            },
            {
              label: "Tables",
              href: "/docs/products/databases/tablesdb/tables",
            },
            {
              label: "Rows",
              href: "/docs/products/databases/tablesdb/rows",
            },
            {
              label: "Permissions",
              href: "/docs/products/databases/tablesdb/permissions",
            },
            {
              label: "Relationships",
              href: "/docs/products/databases/tablesdb/relationships",
            },
            {
              label: "Queries",
              href: "/docs/products/databases/tablesdb/queries",
            },
            {
              label: "Order",
              href: "/docs/products/databases/tablesdb/order",
            },
            {
              label: "Operators",
              href: "/docs/products/databases/tablesdb/operators",
            },
            {
              label: "Geo queries",
              href: "/docs/products/databases/tablesdb/geo-queries",
            },
            {
              label: "Backups",
              href: "/docs/products/databases/tablesdb/backups",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Pagination",
              href: "/docs/products/databases/tablesdb/pagination",
            },
            {
              label: "Transactions",
              href: "/docs/products/databases/tablesdb/transactions",
            },
            {
              label: "Type generation",
              href: "/docs/products/databases/tablesdb/type-generation",
            },
            {
              label: "Offline sync",
              href: "/docs/products/databases/tablesdb/offline",
            },
            {
              label: "Bulk operations",
              href: "/docs/products/databases/tablesdb/bulk-operations",
            },
            {
              label: "Atomic numeric operations",
              href: "/docs/products/databases/tablesdb/atomic-numeric-operations",
            },
            {
              label: "CSV imports",
              href: "/docs/products/databases/tablesdb/csv-imports",
            },
            {
              label: "CSV exports",
              href: "/docs/products/databases/tablesdb/csv-exports",
            },
            {
              label: "AI suggestions",
              href: "/docs/products/databases/tablesdb/ai-suggestions",
            },
            {
              label: "Timestamp overrides",
              href: "/docs/products/databases/tablesdb/timestamp-overrides",
            },
          ],
        },
        {
          label: "References",
          items: [
            {
              label: "TablesDB API",
              href: "/docs/references/cloud/client-web/tablesDB",
            },
            {
              label: "Legacy API",
              href: "/docs/references/cloud/client-web/databases",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/databases/vectorsdb",
      parent: {
        href: "/docs/products/databases",
        label: "VectorsDB",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/databases/vectorsdb",
            },
            {
              label: "Quick start",
              href: "/docs/products/databases/vectorsdb/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Databases",
              href: "/docs/products/databases/vectorsdb/databases",
            },
            {
              label: "Collections",
              href: "/docs/products/databases/vectorsdb/collections",
            },
            {
              label: "Documents",
              href: "/docs/products/databases/vectorsdb/documents",
            },
            {
              label: "Embeddings",
              href: "/docs/products/databases/vectorsdb/embeddings",
            },
            {
              label: "Permissions",
              href: "/docs/products/databases/vectorsdb/permissions",
            },
            {
              label: "Queries",
              href: "/docs/products/databases/vectorsdb/queries",
            },
            {
              label: "Order",
              href: "/docs/products/databases/vectorsdb/order",
            },
            {
              label: "Backups",
              href: "/docs/products/databases/vectorsdb/backups",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Pagination",
              href: "/docs/products/databases/vectorsdb/pagination",
            },
            {
              label: "Vector search",
              href: "/docs/products/databases/vectorsdb/vector-search",
            },
            {
              label: "Transactions",
              href: "/docs/products/databases/vectorsdb/transactions",
            },
            {
              label: "Bulk operations",
              href: "/docs/products/databases/vectorsdb/bulk-operations",
            },
            {
              label: "Timestamp overrides",
              href: "/docs/products/databases/vectorsdb/timestamp-overrides",
            },
            {
              label: "CSV imports",
              href: "/docs/products/databases/vectorsdb/csv-imports",
            },
            {
              label: "CSV exports",
              href: "/docs/products/databases/vectorsdb/csv-exports",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/functions",
      parent: {
        href: "/docs",
        label: "Functions",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/functions",
            },
            {
              label: "Quick start",
              href: "/docs/products/functions/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Functions",
              href: "/docs/products/functions/functions",
            },
            {
              label: "Deployments",
              href: "/docs/products/functions/deployments",
            },
            {
              label: "Executions",
              href: "/docs/products/functions/executions",
            },
            {
              label: "Domains",
              href: "/docs/products/functions/domains",
            },
            {
              label: "Environment variables",
              href: "/docs/products/functions/environment-variables",
            },
            {
              label: "Runtimes",
              href: "/docs/products/functions/runtimes",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Templates",
              href: "/docs/products/functions/templates",
            },
            {
              label: "Develop",
              href: "/docs/products/functions/develop",
            },
            {
              label: "Develop locally",
              href: "/docs/products/functions/develop-locally",
            },
            {
              label: "Deploy from Git",
              href: "/docs/products/functions/deploy-from-git",
            },
            {
              label: "Deploy manually",
              href: "/docs/products/functions/deploy-manually",
            },
            {
              label: "Execute",
              href: "/docs/products/functions/execute",
            },
          ],
        },
        {
          label: "References",
          items: [
            {
              label: "Functions API",
              href: "/docs/references/cloud/client-web/functions",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/messaging",
      parent: {
        href: "/docs",
        label: "Messaging",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/messaging",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Providers",
              href: "/docs/products/messaging/providers",
            },
            {
              label: "Topics",
              href: "/docs/products/messaging/topics",
            },
            {
              label: "Targets",
              href: "/docs/products/messaging/targets",
            },
            {
              label: "Messages",
              href: "/docs/products/messaging/messages",
            },
          ],
        },
        {
          label: "Providers",
          items: [
            {
              label: "Push with APNs",
              href: "/docs/products/messaging/apns",
            },
            {
              label: "Push with FCM",
              href: "/docs/products/messaging/fcm",
            },
            {
              label: "Email with Mailgun",
              href: "/docs/products/messaging/mailgun",
            },
            {
              label: "Email with Resend",
              href: "/docs/products/messaging/resend",
            },
            {
              label: "Email with SendGrid",
              href: "/docs/products/messaging/sendgrid",
            },
            {
              label: "Email with SMTP",
              href: "/docs/products/messaging/smtp",
            },
            {
              label: "SMS with Twilio",
              href: "/docs/products/messaging/twilio",
            },
            {
              label: "SMS with MSG91",
              href: "/docs/products/messaging/msg91",
            },
            {
              label: "SMS with Telesign",
              href: "/docs/products/messaging/telesign",
            },
            {
              label: "SMS with Textmagic",
              href: "/docs/products/messaging/textmagic",
            },
            {
              label: "SMS with Vonage",
              href: "/docs/products/messaging/vonage",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Send push notifications",
              href: "/docs/products/messaging/send-push-notifications",
            },
            {
              label: "Send email messages",
              href: "/docs/products/messaging/send-email-messages",
            },
            {
              label: "Send SMS messages",
              href: "/docs/products/messaging/send-sms-messages",
            },
          ],
        },
        {
          label: "References",
          items: [
            {
              label: "API reference",
              href: "/docs/references/cloud/client-web/messaging",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/network",
      parent: {
        href: "/docs",
        label: "Network",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/network",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Regions",
              href: "/docs/products/network/regions",
            },
            {
              label: "Edges",
              href: "/docs/products/network/edges",
            },
            {
              label: "CDN",
              href: "/docs/products/network/cdn",
            },
            {
              label: "Endpoints",
              href: "/docs/products/network/endpoints",
            },
          ],
        },
        {
          label: "Features",
          items: [
            {
              label: "Custom domains",
              href: "/docs/products/network/custom-domains",
            },
            {
              label: "DNS",
              href: "/docs/products/network/dns",
            },
            {
              label: "CAA records",
              href: "/docs/products/network/caa-records",
            },
            {
              label: "DDoS mitigation",
              href: "/docs/products/network/ddos",
            },
            {
              label: "TLS",
              href: "/docs/products/network/tls",
            },
            {
              label: "WAF",
              href: "/docs/products/network/waf",
            },
            {
              label: "Compression",
              href: "/docs/products/network/compression",
            },
            {
              label: "Caching",
              href: "/docs/products/network/caching",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/sites",
      parent: {
        href: "/docs",
        label: "Sites",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/sites",
            },
            {
              label: "Quick start",
              href: "/docs/products/sites/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Rendering",
              href: "/docs/products/sites/rendering",
            },
            {
              label: "Deployments",
              href: "/docs/products/sites/deployments",
            },
            {
              label: "Previews",
              href: "/docs/products/sites/previews",
            },
            {
              label: "Instant rollbacks",
              href: "/docs/products/sites/instant-rollbacks",
            },
            {
              label: "Logs",
              href: "/docs/products/sites/logs",
            },
            {
              label: "Domains",
              href: "/docs/products/sites/domains",
            },
            {
              label: "Environment variables",
              href: "/docs/products/sites/environment-variables",
            },
            {
              label: "Frameworks",
              href: "/docs/products/sites/frameworks",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Templates",
              href: "/docs/products/sites/templates",
            },
            {
              label: "Develop",
              href: "/docs/products/sites/develop",
            },
            {
              label: "Deploy from Git",
              href: "/docs/products/sites/deploy-from-git",
            },
            {
              label: "Deploy from CLI",
              href: "/docs/products/sites/deploy-from-cli",
            },
            {
              label: "Deploy manually",
              href: "/docs/products/sites/deploy-manually",
            },
          ],
        },
        {
          label: "References",
          items: [
            {
              label: "Sites API",
              href: "/docs/references/cloud/server-nodejs/sites",
            },
          ],
        },
      ],
    },
    {
      prefix: "products/storage",
      parent: {
        href: "/docs",
        label: "Storage",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/products/storage",
            },
            {
              label: "Quick start",
              href: "/docs/products/storage/quick-start",
            },
          ],
        },
        {
          label: "Concepts",
          items: [
            {
              label: "Buckets",
              href: "/docs/products/storage/buckets",
            },
            {
              label: "Folders",
              href: "/docs/products/storage/folders",
            },
            {
              label: "Permissions",
              href: "/docs/products/storage/permissions",
            },
            {
              label: "File tokens",
              href: "/docs/products/storage/file-tokens",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "Upload and download",
              href: "/docs/products/storage/upload-download",
            },
            {
              label: "Image transformations",
              href: "/docs/products/storage/images",
            },
            {
              label: "S3 API",
              href: "/docs/products/storage/s3",
            },
          ],
        },
        {
          label: "References",
          items: [
            {
              label: "Storage API",
              href: "/docs/references/cloud/client-web/storage",
            },
          ],
        },
      ],
    },
    {
      prefix: "quick-starts",
      parent: {
        href: "/docs",
        label: "Quick start",
      },
      navigation: [
        {
          label: "Web app",
          items: [
            {
              label: "TanStack Start",
              href: "/docs/quick-starts/tanstack-start",
            },
            {
              label: "Next.js",
              href: "/docs/quick-starts/nextjs",
            },
            {
              label: "React",
              href: "/docs/quick-starts/react",
            },
            {
              label: "Vue.js",
              href: "/docs/quick-starts/vue",
            },
            {
              label: "SvelteKit",
              href: "/docs/quick-starts/sveltekit",
            },
            {
              label: "Angular",
              href: "/docs/quick-starts/angular",
            },
            {
              label: "Nuxt",
              href: "/docs/quick-starts/nuxt",
            },
            {
              label: "Refine",
              href: "/docs/quick-starts/refine",
            },
            {
              label: "Solid",
              href: "/docs/quick-starts/solid",
            },
            {
              label: "Astro",
              href: "/docs/quick-starts/astro",
            },
            {
              label: "Web",
              href: "/docs/quick-starts/web",
            },
          ],
        },
        {
          label: "Mobile and native",
          items: [
            {
              label: "Flutter",
              href: "/docs/quick-starts/flutter",
            },
            {
              label: "React Native",
              href: "/docs/quick-starts/react-native",
            },
            {
              label: "Android",
              href: "/docs/quick-starts/android",
            },
            {
              label: "Apple",
              href: "/docs/quick-starts/apple",
            },
          ],
        },
        {
          label: "Server",
          items: [
            {
              label: "Node.js",
              href: "/docs/quick-starts/node",
            },
            {
              label: "Python",
              href: "/docs/quick-starts/python",
            },
            {
              label: ".NET",
              href: "/docs/quick-starts/dotnet",
            },
            {
              label: "PHP",
              href: "/docs/quick-starts/php",
            },
            {
              label: "Dart",
              href: "/docs/quick-starts/dart",
            },
            {
              label: "Ruby",
              href: "/docs/quick-starts/ruby",
            },
            {
              label: "Deno",
              href: "/docs/quick-starts/deno",
            },
            {
              label: "Go",
              href: "/docs/quick-starts/go",
            },
            {
              label: "Swift",
              href: "/docs/quick-starts/swift",
            },
            {
              label: "Kotlin",
              href: "/docs/quick-starts/kotlin",
            },
            {
              label: "Rust",
              href: "/docs/quick-starts/rust",
            },
          ],
        },
      ],
    },
    {
      prefix: "references",
      parent: {
        href: "/docs",
        label: "API references",
      },
      navigation: [],
    },
    {
      prefix: "tooling/ai",
      parent: {
        href: "/docs",
        label: "AI",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/tooling/ai",
            },
            {
              label: "Quick start prompts",
              href: "/docs/tooling/ai/quickstart-prompts",
            },
          ],
        },
        {
          label: "Tooling",
          items: [
            {
              label: "MCP servers",
              href: "/docs/tooling/ai/mcp-servers",
            },
            {
              label: "Agent skills",
              href: "/docs/tooling/ai/skills",
            },
            {
              label: "AGENTS.md",
              href: "/docs/tooling/ai/agents-md",
            },
            {
              label: "Assistant",
              href: "/docs/tooling/ai/assistant",
            },
            {
              label: "Appwrite Arena",
              href: "/docs/tooling/ai/arena",
            },
          ],
        },
        {
          label: "IDEs",
          items: [
            {
              label: "Claude Code",
              href: "/docs/tooling/ai/agents/claude-code",
            },
            {
              label: "Codex",
              href: "/docs/tooling/ai/agents/codex",
            },
            {
              label: "Cursor",
              href: "/docs/tooling/ai/agents/cursor",
            },
            {
              label: "VS Code",
              href: "/docs/tooling/ai/agents/vscode",
            },
            {
              label: "Zed",
              href: "/docs/tooling/ai/agents/zed",
            },
            {
              label: "OpenCode",
              href: "/docs/tooling/ai/agents/opencode",
            },
            {
              label: "Google Antigravity",
              href: "/docs/tooling/ai/agents/antigravity",
            },
          ],
        },
        {
          label: "Vibe coding",
          items: [
            {
              label: "Claude Desktop",
              href: "/docs/tooling/ai/vibe-coding/claude-desktop",
            },
            {
              label: "Lovable",
              href: "/docs/tooling/ai/vibe-coding/lovable",
            },
            {
              label: "Emergent",
              href: "/docs/tooling/ai/vibe-coding/emergent",
            },
            {
              label: "Bolt",
              href: "/docs/tooling/ai/vibe-coding/bolt",
            },
            {
              label: "Zenflow",
              href: "/docs/tooling/ai/vibe-coding/zenflow",
            },
          ],
        },
        {
          label: "Guides",
          items: [
            {
              label: "AI in Functions",
              href: "/docs/tooling/ai/ai-in-functions",
            },
            {
              label: "Vector DB and embeddings",
              href: "/docs/tooling/ai/vector-db-and-embeddings",
            },
            {
              label: "Persistent agents with Realtime",
              href: "/docs/tooling/ai/persistent-agents-with-realtime",
            },
          ],
        },
      ],
    },
    {
      prefix: "tooling/command-center",
      parent: {
        href: "/docs",
        label: "Command Center",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/tooling/command-center",
            },
            {
              label: "Shortcuts",
              href: "/docs/tooling/command-center/shortcuts",
            },
          ],
        },
      ],
    },
    {
      prefix: "tooling/command-line",
      parent: {
        href: "/docs",
        label: "CLI",
      },
      navigation: [
        {
          label: "Guides",
          items: [
            {
              label: "Installation",
              href: "/docs/tooling/command-line/installation",
            },
            {
              label: "Commands",
              href: "/docs/tooling/command-line/commands",
            },
            {
              label: "Non interactive",
              href: "/docs/tooling/command-line/non-interactive",
            },
            {
              label: "Generate SDK",
              href: "/docs/tooling/command-line/generate",
            },
          ],
        },
        {
          label: "Deployments",
          items: [
            {
              label: "Tables",
              href: "/docs/tooling/command-line/tables",
            },
            {
              label: "Functions",
              href: "/docs/tooling/command-line/functions",
            },
            {
              label: "Sites",
              href: "/docs/tooling/command-line/sites",
            },
            {
              label: "Teams",
              href: "/docs/tooling/command-line/teams",
            },
            {
              label: "Topics",
              href: "/docs/tooling/command-line/topics",
            },
            {
              label: "Buckets",
              href: "/docs/tooling/command-line/buckets",
            },
          ],
        },
      ],
    },
    {
      prefix: "tooling/terraform",
      parent: {
        href: "/docs",
        label: "Terraform provider",
      },
      navigation: [
        {
          label: "Getting started",
          items: [
            {
              label: "Overview",
              href: "/docs/tooling/terraform",
            },
            {
              label: "Configuration",
              href: "/docs/tooling/terraform/provider",
            },
          ],
        },
        {
          label: "Resources",
          items: [
            {
              label: "Databases",
              href: "/docs/tooling/terraform/resources/databases",
            },
            {
              label: "Storage",
              href: "/docs/tooling/terraform/resources/storage",
            },
            {
              label: "Messaging",
              href: "/docs/tooling/terraform/resources/messaging",
            },
            {
              label: "Auth",
              href: "/docs/tooling/terraform/resources/auth",
            },
            {
              label: "Functions",
              href: "/docs/tooling/terraform/resources/functions",
            },
            {
              label: "Sites",
              href: "/docs/tooling/terraform/resources/sites",
            },
            {
              label: "Webhooks",
              href: "/docs/tooling/terraform/resources/webhooks",
            },
            {
              label: "Backups",
              href: "/docs/tooling/terraform/resources/backups",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/android",
      parent: {
        href: "/docs/tutorials",
        label: "Android",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/android/step-1",
            },
            {
              label: "Create app",
              href: "/docs/tutorials/android/step-2",
            },
            {
              label: "Set up Appwrite",
              href: "/docs/tutorials/android/step-3",
            },
            {
              label: "Add authentication",
              href: "/docs/tutorials/android/step-4",
            },
            {
              label: "Add MainActivity",
              href: "/docs/tutorials/android/step-5",
            },
            {
              label: "Add database",
              href: "/docs/tutorials/android/step-6",
            },
            {
              label: "Create ideas page",
              href: "/docs/tutorials/android/step-7",
            },
            {
              label: "Next steps",
              href: "/docs/tutorials/android/step-8",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/apple",
      parent: {
        href: "/docs/tutorials",
        label: "Apple",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/apple/step-1",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/astro-ssr-auth",
      parent: {
        href: "/docs/tutorials",
        label: "Astro SSR",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/astro-ssr-auth/step-1",
            },
            {
              label: "Create project",
              href: "/docs/tutorials/astro-ssr-auth/step-2",
            },
            {
              label: "Initialize SDK",
              href: "/docs/tutorials/astro-ssr-auth/step-3",
            },
            {
              label: "Add a server hook",
              href: "/docs/tutorials/astro-ssr-auth/step-4",
            },
            {
              label: "Create sign up page",
              href: "/docs/tutorials/astro-ssr-auth/step-5",
            },
            {
              label: "Create account page",
              href: "/docs/tutorials/astro-ssr-auth/step-6",
            },
            {
              label: "OAuth authentication with SSR",
              href: "/docs/tutorials/astro-ssr-auth/step-7",
            },
            {
              label: "All set",
              href: "/docs/tutorials/astro-ssr-auth/step-8",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/flutter",
      parent: {
        href: "/docs/tutorials",
        label: "Flutter",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/flutter/step-1",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/nextjs",
      parent: {
        href: "/docs/tutorials",
        label: "Next.js",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/nextjs/step-1",
            },
            {
              label: "Create app",
              href: "/docs/tutorials/nextjs/step-2",
            },
            {
              label: "Set up Appwrite",
              href: "/docs/tutorials/nextjs/step-3",
            },
            {
              label: "Add authentication",
              href: "/docs/tutorials/nextjs/step-4",
            },
            {
              label: "Add navigation",
              href: "/docs/tutorials/nextjs/step-5",
            },
            {
              label: "Add database",
              href: "/docs/tutorials/nextjs/step-6",
            },
            {
              label: "Ideas page",
              href: "/docs/tutorials/nextjs/step-7",
            },
            {
              label: "Next steps",
              href: "/docs/tutorials/nextjs/step-8",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/nextjs-ssr-auth",
      parent: {
        href: "/docs/tutorials",
        label: "Next.js SSR",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/nextjs-ssr-auth/step-1",
            },
            {
              label: "Create project",
              href: "/docs/tutorials/nextjs-ssr-auth/step-2",
            },
            {
              label: "Initialize SDK",
              href: "/docs/tutorials/nextjs-ssr-auth/step-3",
            },
            {
              label: "Get the logged in user",
              href: "/docs/tutorials/nextjs-ssr-auth/step-4",
            },
            {
              label: "Create sign up page",
              href: "/docs/tutorials/nextjs-ssr-auth/step-5",
            },
            {
              label: "Create account page",
              href: "/docs/tutorials/nextjs-ssr-auth/step-6",
            },
            {
              label: "OAuth authentication with SSR",
              href: "/docs/tutorials/nextjs-ssr-auth/step-7",
            },
            {
              label: "All set",
              href: "/docs/tutorials/nextjs-ssr-auth/step-8",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/nuxt",
      parent: {
        href: "/docs/tutorials",
        label: "Nuxt",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/nuxt/step-1",
            },
            {
              label: "Create app",
              href: "/docs/tutorials/nuxt/step-2",
            },
            {
              label: "Set up Appwrite",
              href: "/docs/tutorials/nuxt/step-3",
            },
            {
              label: "Add authentication",
              href: "/docs/tutorials/nuxt/step-4",
            },
            {
              label: "Add navigation",
              href: "/docs/tutorials/nuxt/step-5",
            },
            {
              label: "Add database",
              href: "/docs/tutorials/nuxt/step-6",
            },
            {
              label: "Ideas page",
              href: "/docs/tutorials/nuxt/step-7",
            },
            {
              label: "Next steps",
              href: "/docs/tutorials/nuxt/step-8",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/nuxt-ssr-auth",
      parent: {
        href: "/docs/tutorials",
        label: "Nuxt SSR",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/nuxt-ssr-auth/step-1",
            },
            {
              label: "Create project",
              href: "/docs/tutorials/nuxt-ssr-auth/step-2",
            },
            {
              label: "Initialize SDK",
              href: "/docs/tutorials/nuxt-ssr-auth/step-3",
            },
            {
              label: "Add server middleware",
              href: "/docs/tutorials/nuxt-ssr-auth/step-4",
            },
            {
              label: "Create sign up page",
              href: "/docs/tutorials/nuxt-ssr-auth/step-5",
            },
            {
              label: "Create account page",
              href: "/docs/tutorials/nuxt-ssr-auth/step-6",
            },
            {
              label: "OAuth authentication with SSR",
              href: "/docs/tutorials/nuxt-ssr-auth/step-7",
            },
            {
              label: "Enable the sign up and account pages",
              href: "/docs/tutorials/nuxt-ssr-auth/step-8",
            },
            {
              label: "All set",
              href: "/docs/tutorials/nuxt-ssr-auth/step-9",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/react",
      parent: {
        href: "/docs/tutorials",
        label: "React",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/react/step-1",
            },
            {
              label: "Create app",
              href: "/docs/tutorials/react/step-2",
            },
            {
              label: "Set up Appwrite",
              href: "/docs/tutorials/react/step-3",
            },
            {
              label: "Add authentication",
              href: "/docs/tutorials/react/step-4",
            },
            {
              label: "Add navigation",
              href: "/docs/tutorials/react/step-5",
            },
            {
              label: "Add database",
              href: "/docs/tutorials/react/step-6",
            },
            {
              label: "Create ideas page",
              href: "/docs/tutorials/react/step-7",
            },
            {
              label: "Next steps",
              href: "/docs/tutorials/react/step-8",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/react-native",
      parent: {
        href: "/docs/tutorials",
        label: "React Native",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/react-native/step-1",
            },
            {
              label: "Create app",
              href: "/docs/tutorials/react-native/step-2",
            },
            {
              label: "Set up Appwrite",
              href: "/docs/tutorials/react-native/step-3",
            },
            {
              label: "Add authentication",
              href: "/docs/tutorials/react-native/step-4",
            },
            {
              label: "Add routing",
              href: "/docs/tutorials/react-native/step-5",
            },
            {
              label: "Add database",
              href: "/docs/tutorials/react-native/step-6",
            },
            {
              label: "Create ideas page",
              href: "/docs/tutorials/react-native/step-7",
            },
            {
              label: "Next steps",
              href: "/docs/tutorials/react-native/step-8",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/refine",
      parent: {
        href: "/docs/tutorials",
        label: "Refine",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/refine/step-1",
            },
            {
              label: "Create app",
              href: "/docs/tutorials/refine/step-2",
            },
            {
              label: "Set up Appwrite",
              href: "/docs/tutorials/refine/step-3",
            },
            {
              label: "Add authentication",
              href: "/docs/tutorials/refine/step-4",
            },
            {
              label: "Add database",
              href: "/docs/tutorials/refine/step-5",
            },
            {
              label: "Create CRUD pages",
              href: "/docs/tutorials/refine/step-6",
            },
            {
              label: "Next steps",
              href: "/docs/tutorials/refine/step-7",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/subscriptions-with-stripe",
      parent: {
        href: "/docs/tutorials",
        label: "Stripe",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/subscriptions-with-stripe/step-1",
            },
            {
              label: "Setup Stripe",
              href: "/docs/tutorials/subscriptions-with-stripe/step-2",
            },
            {
              label: "Create function",
              href: "/docs/tutorials/subscriptions-with-stripe/step-3",
            },
            {
              label: "Configure web platform",
              href: "/docs/tutorials/subscriptions-with-stripe/step-4",
            },
            {
              label: "All set",
              href: "/docs/tutorials/subscriptions-with-stripe/step-5",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/sveltekit",
      parent: {
        href: "/docs/tutorials",
        label: "SvelteKit",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/sveltekit/step-1",
            },
            {
              label: "Create app",
              href: "/docs/tutorials/sveltekit/step-2",
            },
            {
              label: "Set up Appwrite",
              href: "/docs/tutorials/sveltekit/step-3",
            },
            {
              label: "Add authentication",
              href: "/docs/tutorials/sveltekit/step-4",
            },
            {
              label: "Add navigation",
              href: "/docs/tutorials/sveltekit/step-5",
            },
            {
              label: "Add database",
              href: "/docs/tutorials/sveltekit/step-6",
            },
            {
              label: "Create ideas page",
              href: "/docs/tutorials/sveltekit/step-7",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/sveltekit-csr-auth",
      parent: {
        href: "/docs/tutorials",
        label: "SvelteKit",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/sveltekit-csr-auth/step-1",
            },
            {
              label: "Create project",
              href: "/docs/tutorials/sveltekit-csr-auth/step-2",
            },
            {
              label: "Initialize SDK",
              href: "/docs/tutorials/sveltekit-csr-auth/step-3",
            },
            {
              label: "Check if logged in",
              href: "/docs/tutorials/sveltekit-csr-auth/step-4",
            },
            {
              label: "Create login page",
              href: "/docs/tutorials/sveltekit-csr-auth/step-5",
            },
            {
              label: "Create signup page",
              href: "/docs/tutorials/sveltekit-csr-auth/step-6",
            },
            {
              label: "All set",
              href: "/docs/tutorials/sveltekit-csr-auth/step-7",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/sveltekit-ssr-auth",
      parent: {
        href: "/docs/tutorials",
        label: "SvelteKit SSR",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/sveltekit-ssr-auth/step-1",
            },
            {
              label: "Create project",
              href: "/docs/tutorials/sveltekit-ssr-auth/step-2",
            },
            {
              label: "Initialize SDK",
              href: "/docs/tutorials/sveltekit-ssr-auth/step-3",
            },
            {
              label: "Add a server hook",
              href: "/docs/tutorials/sveltekit-ssr-auth/step-4",
            },
            {
              label: "Create sign up page",
              href: "/docs/tutorials/sveltekit-ssr-auth/step-5",
            },
            {
              label: "Create account page",
              href: "/docs/tutorials/sveltekit-ssr-auth/step-6",
            },
            {
              label: "OAuth authentication with SSR",
              href: "/docs/tutorials/sveltekit-ssr-auth/step-7",
            },
            {
              label: "All set",
              href: "/docs/tutorials/sveltekit-ssr-auth/step-8",
            },
          ],
        },
      ],
    },
    {
      prefix: "tutorials/vue",
      parent: {
        href: "/docs/tutorials",
        label: "Vue",
      },
      navigation: [
        {
          label: "Steps",
          items: [
            {
              label: "Introduction",
              href: "/docs/tutorials/vue/step-1",
            },
            {
              label: "Create app",
              href: "/docs/tutorials/vue/step-2",
            },
            {
              label: "Set up Appwrite",
              href: "/docs/tutorials/vue/step-3",
            },
            {
              label: "Add authentication",
              href: "/docs/tutorials/vue/step-4",
            },
            {
              label: "Add navigation",
              href: "/docs/tutorials/vue/step-5",
            },
            {
              label: "Add database",
              href: "/docs/tutorials/vue/step-6",
            },
            {
              label: "Create ideas page",
              href: "/docs/tutorials/vue/step-7",
            },
            {
              label: "Next steps",
              href: "/docs/tutorials/vue/step-8",
            },
          ],
        },
      ],
    },
  ]
