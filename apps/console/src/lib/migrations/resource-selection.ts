import {
  AppwriteMigrationResource,
  FirebaseMigrationResource,
  NHostMigrationResource,
  SupabaseMigrationResource,
} from '@appwrite.io/console'

/**
 * Which resources each wizard checkbox sends to POST /v1/migrations/*.
 *
 * The server transfers exactly what it is asked for and nothing more, and a
 * resource left out of the array is not an error anywhere -- the migration
 * reports "completed" with an empty error list having silently skipped it.
 * That makes every omission here invisible in production, so the mapping is
 * kept pure and covered by tests rather than living inside the wizard.
 *
 * Several resources also depend on a sibling being present in the same
 * request, and on being named before it; each is noted where it is emitted.
 */

type MigrationResource =
  | AppwriteMigrationResource
  | SupabaseMigrationResource
  | FirebaseMigrationResource

/** Provider families, which differ in the resources they can carry. */
export type MigrationProviderKind = 'appwrite' | 'firebase' | 'supabase'

export type ResourceGroupKey =
  | 'users'
  | 'databases'
  | 'storage'
  | 'functions'
  | 'messaging'

export interface ResourceFormState {
  users: { root: boolean; teams: boolean }
  databases: { root: boolean; rows: boolean }
  storage: { root: boolean }
  functions: { root: boolean; env: boolean; inactive: boolean }
  messaging: { root: boolean; messages: boolean }
}

export const INITIAL_RESOURCE_FORM: ResourceFormState = {
  users: { root: false, teams: false },
  databases: { root: false, rows: false },
  storage: { root: false },
  functions: { root: false, env: false, inactive: false },
  messaging: { root: false, messages: false },
}

/** All Appwrite resources for report and migration. */
export const APPWRITE_RESOURCES: AppwriteMigrationResource[] = [
  AppwriteMigrationResource.User,
  AppwriteMigrationResource.Team,
  AppwriteMigrationResource.Membership,
  AppwriteMigrationResource.Database,
  AppwriteMigrationResource.Table,
  AppwriteMigrationResource.Column,
  AppwriteMigrationResource.Index,
  AppwriteMigrationResource.Row,
  AppwriteMigrationResource.Document,
  AppwriteMigrationResource.Attribute,
  AppwriteMigrationResource.Collection,
  AppwriteMigrationResource.Bucket,
  AppwriteMigrationResource.File,
  AppwriteMigrationResource.Function,
  AppwriteMigrationResource.Environmentvariable,
  AppwriteMigrationResource.Deployment,
  AppwriteMigrationResource.Provider,
  AppwriteMigrationResource.Topic,
  AppwriteMigrationResource.Subscriber,
  AppwriteMigrationResource.Message,
]

/** Resources supported by Supabase migrations (Document/Attribute/Collection). */
export const SUPABASE_NHOST_RESOURCES: SupabaseMigrationResource[] = [
  SupabaseMigrationResource.User,
  SupabaseMigrationResource.Database,
  SupabaseMigrationResource.Collection,
  SupabaseMigrationResource.Attribute,
  SupabaseMigrationResource.Index,
  SupabaseMigrationResource.Document,
  SupabaseMigrationResource.Bucket,
  SupabaseMigrationResource.File,
]

/** Resources supported by NHost migrations (same shape as Supabase report). */
export const NHOST_RESOURCES: NHostMigrationResource[] = [
  NHostMigrationResource.User,
  NHostMigrationResource.Database,
  NHostMigrationResource.Collection,
  NHostMigrationResource.Attribute,
  NHostMigrationResource.Index,
  NHostMigrationResource.Document,
  NHostMigrationResource.Bucket,
  NHostMigrationResource.File,
]

/** Resources supported by Firebase (same as Supabase but no Index per prompt). */
export const FIREBASE_RESOURCES: FirebaseMigrationResource[] = [
  FirebaseMigrationResource.User,
  FirebaseMigrationResource.Database,
  FirebaseMigrationResource.Collection,
  FirebaseMigrationResource.Attribute,
  FirebaseMigrationResource.Document,
  FirebaseMigrationResource.Bucket,
  FirebaseMigrationResource.File,
]

function allowlistFor(kind: MigrationProviderKind): readonly string[] {
  switch (kind) {
    case 'appwrite':
      return APPWRITE_RESOURCES
    case 'firebase':
      return FIREBASE_RESOURCES
    case 'supabase':
      return SUPABASE_NHOST_RESOURCES
  }
}

export function resourceFormToResources(
  form: ResourceFormState,
  kind: MigrationProviderKind,
): MigrationResource[] {
  const out: MigrationResource[] = []

  if (kind === 'appwrite') {
    if (form.users.root) {
      out.push(AppwriteMigrationResource.User)
      // Memberships need the users and the teams they join, so they ride along
      // with the teams checkbox rather than standing on their own.
      if (form.users.teams) {
        out.push(
          AppwriteMigrationResource.Team,
          AppwriteMigrationResource.Membership,
        )
      }
    }
    if (form.databases.root) {
      out.push(
        AppwriteMigrationResource.Database,
        AppwriteMigrationResource.Table,
        AppwriteMigrationResource.Column,
        AppwriteMigrationResource.Index,
      )
      if (form.databases.rows) out.push(AppwriteMigrationResource.Row)
    }
    if (form.storage.root) {
      out.push(AppwriteMigrationResource.Bucket, AppwriteMigrationResource.File)
    }
    if (form.functions.root) {
      out.push(AppwriteMigrationResource.Function)
      if (form.functions.env) {
        out.push(AppwriteMigrationResource.Environmentvariable)
      }
      if (form.functions.inactive) {
        out.push(AppwriteMigrationResource.Deployment)
      }
    }
    if (form.messaging.root) {
      out.push(
        AppwriteMigrationResource.Provider,
        AppwriteMigrationResource.Topic,
      )
      // A subscriber links a topic to a user's target; importing one without
      // its user fails the whole migration, so it follows the users checkbox.
      if (form.users.root) out.push(AppwriteMigrationResource.Subscriber)
      if (form.messaging.messages) out.push(AppwriteMigrationResource.Message)
    }
  } else {
    const dbEnum =
      kind === 'firebase'
        ? FirebaseMigrationResource
        : SupabaseMigrationResource
    if (form.users.root) out.push(dbEnum.User)
    if (form.databases.root) {
      out.push(
        dbEnum.Database,
        dbEnum.Collection,
        dbEnum.Attribute,
        ...(kind === 'firebase' ? [] : [SupabaseMigrationResource.Index]),
        dbEnum.Document,
      )
    }
    if (form.storage.root) out.push(dbEnum.Bucket, dbEnum.File)
  }

  const allowed = new Set(allowlistFor(kind))
  return out.filter((resource) => allowed.has(resource))
}
