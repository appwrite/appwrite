// Onboarding mock data and types

export type OnboardingIntent =
  | 'deploy-site'
  | 'add-auth'
  | 'create-database'
  | 'manage-files'
  | 'run-functions'
  | 'explore'

export type OnboardingStep = {
  id: string
  title: string
  description: string
  completed: boolean
  skipped: boolean
  current: boolean
}

export type OnboardingPath = {
  id: OnboardingIntent
  title: string
  description: string
  icon: string
  steps: OnboardingStep[]
}

export type SDK = {
  id: string
  name: string
  icon: string
  language: string
}

export type Framework = {
  id: string
  name: string
  icon: string
  category: 'frontend' | 'fullstack' | 'static'
}

export type StarterTemplate = {
  id: string
  name: string
  description: string
  framework: string
  features: string[]
}

// Available SDKs
export const sdks: SDK[] = [
  { id: 'web', name: 'Web', icon: 'globe', language: 'JavaScript' },
  { id: 'flutter', name: 'Flutter', icon: 'smartphone', language: 'Dart' },
  { id: 'apple', name: 'Apple', icon: 'apple', language: 'Swift' },
  { id: 'android', name: 'Android', icon: 'smartphone', language: 'Kotlin' },
  {
    id: 'react-native',
    name: 'React Native',
    icon: 'smartphone',
    language: 'JavaScript',
  },
  { id: 'node', name: 'Node.js', icon: 'server', language: 'JavaScript' },
  { id: 'python', name: 'Python', icon: 'server', language: 'Python' },
  { id: 'php', name: 'PHP', icon: 'server', language: 'PHP' },
  { id: 'ruby', name: 'Ruby', icon: 'server', language: 'Ruby' },
  { id: 'deno', name: 'Deno', icon: 'server', language: 'TypeScript' },
]

// Available frameworks for hosting
export const frameworks: Framework[] = [
  { id: 'nextjs', name: 'Next.js', icon: 'N', category: 'fullstack' },
  { id: 'nuxt', name: 'Nuxt', icon: 'N', category: 'fullstack' },
  { id: 'sveltekit', name: 'SvelteKit', icon: 'S', category: 'fullstack' },
  { id: 'remix', name: 'Remix', icon: 'R', category: 'fullstack' },
  { id: 'astro', name: 'Astro', icon: 'A', category: 'fullstack' },
  { id: 'react', name: 'React', icon: 'R', category: 'frontend' },
  { id: 'vue', name: 'Vue', icon: 'V', category: 'frontend' },
  { id: 'svelte', name: 'Svelte', icon: 'S', category: 'frontend' },
  { id: 'angular', name: 'Angular', icon: 'A', category: 'frontend' },
  { id: 'static', name: 'Static', icon: 'H', category: 'static' },
]

// Starter templates
export const starterTemplates: StarterTemplate[] = [
  {
    id: 'blank',
    name: 'Blank project',
    description: 'Start from scratch with no pre-configured services',
    framework: 'any',
    features: [],
  },
  {
    id: 'auth-starter',
    name: 'Auth starter',
    description: 'User authentication with email/password and OAuth',
    framework: 'nextjs',
    features: ['auth', 'users'],
  },
  {
    id: 'blog',
    name: 'Blog',
    description: 'Content management with database and file storage',
    framework: 'nextjs',
    features: ['database', 'storage'],
  },
  {
    id: 'saas',
    name: 'SaaS starter',
    description: 'Full-stack SaaS with auth, database, and functions',
    framework: 'nextjs',
    features: ['auth', 'database', 'functions'],
  },
  {
    id: 'ecommerce',
    name: 'E-commerce',
    description: 'Product catalog with cart and checkout flow',
    framework: 'nextjs',
    features: ['auth', 'database', 'storage', 'functions'],
  },
]

// Onboarding paths with steps
export const onboardingPaths: OnboardingPath[] = [
  {
    id: 'deploy-site',
    title: 'Deploy a site',
    description: 'Connect a repository and deploy to the edge',
    icon: 'globe',
    steps: [
      {
        id: 'connect-repo',
        title: 'Connect repository',
        description: 'Link your GitHub, GitLab, or Bitbucket repository',
        completed: false,
        skipped: false,
        current: true,
      },
      {
        id: 'select-framework',
        title: 'Select framework',
        description: 'Choose your build framework and configure settings',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'configure-domain',
        title: 'Configure domain',
        description: 'Set up custom domains and SSL certificates',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'deploy',
        title: 'Deploy',
        description: 'Trigger your first deployment',
        completed: false,
        skipped: false,
        current: false,
      },
    ],
  },
  {
    id: 'add-auth',
    title: 'Add authentication',
    description: 'Secure your app with user authentication',
    icon: 'users',
    steps: [
      {
        id: 'install-sdk',
        title: 'Install SDK',
        description: 'Add the Appwrite SDK to your project',
        completed: false,
        skipped: false,
        current: true,
      },
      {
        id: 'init-client',
        title: 'Initialize client',
        description: 'Configure the Appwrite client with your project',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'create-account',
        title: 'Create account',
        description: 'Implement user registration',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'create-session',
        title: 'Create session',
        description: 'Implement user login',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'get-user',
        title: 'Get current user',
        description: 'Fetch the authenticated user',
        completed: false,
        skipped: false,
        current: false,
      },
    ],
  },
  {
    id: 'create-database',
    title: 'Create a database',
    description: 'Store and query structured data',
    icon: 'database',
    steps: [
      {
        id: 'create-db',
        title: 'Create database',
        description: 'Create a new database instance',
        completed: false,
        skipped: false,
        current: true,
      },
      {
        id: 'create-collection',
        title: 'Create collection',
        description: 'Define a collection with attributes',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'add-document',
        title: 'Add document',
        description: 'Insert your first document',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'query-documents',
        title: 'Query documents',
        description: 'Fetch and filter documents',
        completed: false,
        skipped: false,
        current: false,
      },
    ],
  },
  {
    id: 'manage-files',
    title: 'Upload and manage files',
    description: 'Store and serve files at scale',
    icon: 'folder',
    steps: [
      {
        id: 'create-bucket',
        title: 'Create bucket',
        description: 'Create a storage bucket',
        completed: false,
        skipped: false,
        current: true,
      },
      {
        id: 'upload-file',
        title: 'Upload file',
        description: 'Upload your first file',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'get-file',
        title: 'Get file',
        description: 'Retrieve and display files',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'file-preview',
        title: 'Preview file',
        description: 'Generate previews and thumbnails',
        completed: false,
        skipped: false,
        current: false,
      },
    ],
  },
  {
    id: 'run-functions',
    title: 'Run serverless functions',
    description: 'Execute backend code on demand',
    icon: 'zap',
    steps: [
      {
        id: 'create-function',
        title: 'Create function',
        description: 'Create a new serverless function',
        completed: false,
        skipped: false,
        current: true,
      },
      {
        id: 'write-code',
        title: 'Write code',
        description: 'Implement your function logic',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'deploy-function',
        title: 'Deploy',
        description: 'Deploy your function',
        completed: false,
        skipped: false,
        current: false,
      },
      {
        id: 'execute-function',
        title: 'Execute',
        description: 'Trigger your function',
        completed: false,
        skipped: false,
        current: false,
      },
    ],
  },
  {
    id: 'explore',
    title: 'Explore everything',
    description: 'Browse all features at your own pace',
    icon: 'compass',
    steps: [
      {
        id: 'tour-console',
        title: 'Console tour',
        description: 'Quick overview of the console',
        completed: false,
        skipped: false,
        current: true,
      },
      {
        id: 'read-docs',
        title: 'Read documentation',
        description: 'Explore the full documentation',
        completed: false,
        skipped: false,
        current: false,
      },
    ],
  },
]

// Code snippets for SDK examples
export const codeSnippets = {
  install: {
    web: 'npm install appwrite',
    flutter: 'flutter pub add appwrite',
    apple: 'pod install Appwrite',
    android: "implementation 'io.appwrite:sdk-for-android:4.0.0'",
    'react-native': 'npm install appwrite',
    node: 'npm install node-appwrite',
    python: 'pip install appwrite',
    php: 'composer require appwrite/appwrite',
    ruby: 'gem install appwrite',
    deno: "import { Client } from 'https://deno.land/x/appwrite/mod.ts'",
  },
  initClient: {
    web: `import { Client } from 'appwrite';

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');`,
    node: `const { Client } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<API_KEY>');`,
    python: `from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<API_KEY>')`,
    flutter: `import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');`,
  },
  createAccount: {
    web: `import { Account, ID } from 'appwrite';

const account = new Account(client);

const user = await account.create(
    ID.unique(),
    'email@example.com',
    'password',
    'Name'
);`,
    node: `const { Account, ID } = require('node-appwrite');

const account = new Account(client);

const user = await account.create(
    ID.unique(),
    'email@example.com',
    'password',
    'Name'
);`,
  },
  createSession: {
    web: `const session = await account.createEmailPasswordSession(
    'email@example.com',
    'password'
);`,
  },
  getUser: {
    web: `const user = await account.get();
console.log(user);`,
  },
  createDatabase: {
    web: `import { Databases, ID } from 'appwrite';

const databases = new Databases(client);

const database = await databases.create(
    ID.unique(),
    'My Database'
);`,
  },
  createCollection: {
    web: `const collection = await databases.createCollection(
    '<DATABASE_ID>',
    ID.unique(),
    'Articles'
);

// Add attributes
await databases.createStringAttribute(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    'title',
    255,
    true
);`,
  },
  createDocument: {
    web: `const document = await databases.createDocument(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    ID.unique(),
    {
        title: 'Hello World',
        content: 'This is my first document'
    }
);`,
  },
  listDocuments: {
    web: `import { Query } from 'appwrite';

const documents = await databases.listDocuments(
    '<DATABASE_ID>',
    '<COLLECTION_ID>',
    [
        Query.equal('status', 'published'),
        Query.orderDesc('$createdAt'),
        Query.limit(10)
    ]
);`,
  },
  createBucket: {
    web: `import { Storage, ID } from 'appwrite';

const storage = new Storage(client);

const bucket = await storage.createBucket(
    ID.unique(),
    'Images'
);`,
  },
  uploadFile: {
    web: `const file = await storage.createFile(
    '<BUCKET_ID>',
    ID.unique(),
    document.getElementById('file').files[0]
);`,
  },
  getFile: {
    web: `const file = await storage.getFile(
    '<BUCKET_ID>',
    '<FILE_ID>'
);

// Get file URL for preview
const url = storage.getFilePreview(
    '<BUCKET_ID>',
    '<FILE_ID>'
);`,
  },
  createFunction: {
    web: `import { Functions, ID } from 'appwrite';

const functions = new Functions(client);

const func = await functions.create(
    ID.unique(),
    'My Function',
    'node-18.0'
);`,
  },
  executeFunction: {
    web: `const execution = await functions.createExecution(
    '<FUNCTION_ID>',
    JSON.stringify({ key: 'value' })
);

console.log(execution.responseBody);`,
  },
}

// Regions
export const regions = [
  { id: 'fra', name: 'Frankfurt', flag: '🇩🇪' },
  { id: 'nyc', name: 'New York', flag: '🇺🇸' },
  { id: 'sfo', name: 'San Francisco', flag: '🇺🇸' },
  { id: 'sgp', name: 'Singapore', flag: '🇸🇬' },
  { id: 'syd', name: 'Sydney', flag: '🇦🇺' },
]

// Git providers
export const gitProviders = [
  { id: 'github', name: 'GitHub', icon: 'github' },
  { id: 'gitlab', name: 'GitLab', icon: 'gitlab' },
  { id: 'bitbucket', name: 'Bitbucket', icon: 'bitbucket' },
]

// Mock repositories
export const mockRepositories = [
  {
    id: '507f1f77bcf86cd799439300',
    name: 'my-nextjs-app',
    owner: 'username',
    provider: 'github',
    updatedAt: '2 hours ago',
  },
  {
    id: '507f1f77bcf86cd799439301',
    name: 'portfolio-site',
    owner: 'username',
    provider: 'github',
    updatedAt: '1 day ago',
  },
  {
    id: '507f1f77bcf86cd799439302',
    name: 'blog-frontend',
    owner: 'username',
    provider: 'github',
    updatedAt: '3 days ago',
  },
  {
    id: '507f1f77bcf86cd799439303',
    name: 'ecommerce-store',
    owner: 'username',
    provider: 'github',
    updatedAt: '1 week ago',
  },
  {
    id: '507f1f77bcf86cd799439304',
    name: 'dashboard-app',
    owner: 'username',
    provider: 'github',
    updatedAt: '2 weeks ago',
  },
]
