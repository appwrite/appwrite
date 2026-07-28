import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import type { ProductId } from '@/lib/products/types'

const PRODUCT_TOOLS_ENDPOINT = 'https://<REGION>.cloud.appwrite.io/v1'

export type ProductToolsRealtimeExample = {
  channels: string[]
  events: string[]
}

export type ProductToolsCodeExample = {
  language: CodeBlockLanguage
  code: string
  caption?: string
}

export type ProductToolsTerraformExample = {
  href: string
  snippet: string
}

export type ProductToolsSectionContent = {
  codeExample: ProductToolsCodeExample
  realtime: ProductToolsRealtimeExample
  terraform: ProductToolsTerraformExample
}

const PRODUCT_TOOLS_CONTENT: Partial<Record<ProductId, ProductToolsSectionContent>> = {
  auth: {
    codeExample: {
      language: 'typescript',
      code: `import { Client, Account } from 'appwrite';

const client = new Client()
  .setEndpoint('${PRODUCT_TOOLS_ENDPOINT}')
  .setProject('<PROJECT_ID>');

const account = new Account(client);

const session = await account.createEmailPasswordSession({
  email: 'paige@example.com',
  password: 'password',
});

console.log(session.userId);`,
    },
    realtime: {
      channels: ['account', 'account.*.sessions'],
      events: [
        'users.*.create',
        'users.*.update',
        'sessions.*.create',
        'sessions.*.delete',
      ],
    },
    terraform: {
      href: '/docs/tooling/terraform/resources/auth',
      snippet: `resource "appwrite_auth_user" "admin" {
  user_id  = "admin"
  email    = "admin@example.com"
  password = var.admin_password
}`,
    },
  },
  databases: {
    codeExample: {
      language: 'typescript',
      code: `import { Client, TablesDB, ID } from 'appwrite';

const client = new Client()
  .setEndpoint('${PRODUCT_TOOLS_ENDPOINT}')
  .setProject('<PROJECT_ID>');

const tablesDB = new TablesDB(client);

const row = await tablesDB.createRow({
  databaseId: '<DATABASE_ID>',
  tableId: 'tasks',
  rowId: ID.unique(),
  data: { title: 'Ship v1', done: false },
});`,
    },
    realtime: {
      channels: ['databases.*.tables.*.rows.*'],
      events: [
        'databases.*.tables.*.rows.*.create',
        'databases.*.tables.*.rows.*.update',
        'databases.*.tables.*.rows.*.delete',
      ],
    },
    terraform: {
      href: '/docs/tooling/terraform/resources/databases',
      snippet: `resource "appwrite_tablesdb_table" "tasks" {
  database_id = appwrite_tablesdb.main.database_id
  table_id    = "tasks"
  name        = "Tasks"
}`,
    },
  },
  storage: {
    codeExample: {
      language: 'typescript',
      code: `import { Client, Storage, ID } from 'appwrite';

const client = new Client()
  .setEndpoint('${PRODUCT_TOOLS_ENDPOINT}')
  .setProject('<PROJECT_ID>');

const storage = new Storage(client);

const file = await storage.createFile({
  bucketId: 'avatars',
  fileId: ID.unique(),
  file: input.files[0],
});

console.log(file.$id);`,
    },
    realtime: {
      channels: ['buckets.*.files.*'],
      events: [
        'buckets.*.files.*.create',
        'buckets.*.files.*.update',
        'buckets.*.files.*.delete',
      ],
    },
    terraform: {
      href: '/docs/tooling/terraform/resources/storage',
      snippet: `resource "appwrite_storage_bucket" "avatars" {
  bucket_id = "avatars"
  name      = "Avatars"
}`,
    },
  },
  functions: {
    codeExample: {
      language: 'javascript',
      caption: 'A typical function handler.',
      code: `import { Client } from 'node-appwrite';

export default async ({ req, res, log }) => {
  log(\`\${req.method} \${req.path}\`);

  const { orderId } = JSON.parse(req.body);
  if (!orderId) {
    return res.json({ error: 'Missing orderId' }, 400);
  }

  const client = new Client()
    .setEndpoint(process.env.APPWRITE_FUNCTION_API_ENDPOINT)
    .setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID)
    .setKey(req.headers['x-appwrite-key']);

  log(\`Processing \${orderId} (\${process.env.APPWRITE_FUNCTION_NAME})\`);

  return res.json({ status: 'fulfilled', orderId });
};`,
    },
    realtime: {
      channels: ['functions.*.executions.*'],
      events: [
        'functions.*.executions.*.create',
        'functions.*.executions.*.update',
      ],
    },
    terraform: {
      href: '/docs/tooling/terraform/resources/functions',
      snippet: `resource "appwrite_function" "process_order" {
  function_id = "process-order"
  name        = "Process order"
  runtime     = "node-22"
}`,
    },
  },
  messaging: {
    codeExample: {
      language: 'typescript',
      code: `import { Client, Messaging, ID } from 'appwrite';

const client = new Client()
  .setEndpoint('${PRODUCT_TOOLS_ENDPOINT}')
  .setProject('<PROJECT_ID>');

const messaging = new Messaging(client);

await messaging.createEmail({
  messageId: ID.unique(),
  subject: 'Your verification code',
  content: '<p>Your code is 482910. It expires in 10 minutes.</p>',
  topics: [],
  users: ['<USER_ID>'],
});`,
    },
    realtime: {
      channels: ['messages'],
      events: ['messages.*.create', 'messages.*.update'],
    },
    terraform: {
      href: '/docs/tooling/terraform/resources/messaging',
      snippet: `resource "appwrite_messaging_topic" "receipts" {
  topic_id = "receipts"
  name     = "Receipts"
}`,
    },
  },
  sites: {
    codeExample: {
      language: 'typescript',
      code: `import { Client, Sites } from 'appwrite';

const client = new Client()
  .setEndpoint('${PRODUCT_TOOLS_ENDPOINT}')
  .setProject('<PROJECT_ID>');

const sites = new Sites(client);

const deployment = await sites.createDeployment({
  siteId: 'marketing-site',
  code: tarball,
  activate: true,
});`,
    },
    realtime: {
      channels: ['sites.*.deployments.*'],
      events: [
        'sites.*.deployments.*.create',
        'sites.*.deployments.*.update',
      ],
    },
    terraform: {
      href: '/docs/tooling/terraform/resources/sites',
      snippet: `resource "appwrite_site" "marketing" {
  site_id = "marketing-site"
  name    = "Marketing site"
}`,
    },
  },
}

export function getProductToolsContent(
  productId: ProductId,
): ProductToolsSectionContent | null {
  return PRODUCT_TOOLS_CONTENT[productId] ?? null
}

export const PRODUCT_TOOLS_LINKS = {
  api: '/docs/apis/rest',
  console: '/docs/tooling/command-center',
  realtime: '/docs/apis/realtime',
  sdks: '/docs/sdks',
  cli: '/docs/tooling/command-line/installation',
  terraform: '/docs/tooling/terraform',
  mcp: '/docs/tooling/ai/mcp-servers',
  skills: '/docs/tooling/ai/skills',
} as const

export const PRODUCT_TOOLS_SDK_PLATFORMS = [
  'web',
  'flutter',
  'react-native',
  'apple',
  'android',
] as const

/** Official server SDKs from /docs/sdks#server (excluding client platforms shown above). */
export const PRODUCT_TOOLS_SERVER_SDKS = [
  'Node.js',
  'Python',
  'Dart',
  'PHP',
  'Ruby',
  '.NET',
  'Go',
  'Swift',
  'Kotlin',
  'Rust',
] as const

export const PRODUCT_TOOLS_TOTAL_SDK_COUNT =
  PRODUCT_TOOLS_SDK_PLATFORMS.length + PRODUCT_TOOLS_SERVER_SDKS.length
