export type ImpersonationHeader =
  | { type: 'none' }
  | { type: 'id'; value: string }
  | { type: 'email'; value: string }
  | { type: 'phone'; value: string };

export class ApiError extends Error {
  code?: number;
  type?: string;

  constructor(message: string, options?: { code?: number; type?: string }) {
    super(message);
    this.name = 'ApiError';
    this.code = options?.code;
    this.type = options?.type;
  }
}

export type AppwriteError = {
  message?: string;
  type?: string;
  code?: number;
};

export type Session = {
  $id: string;
  userId: string;
};

export type Account = {
  $id: string;
  name: string;
  email: string | null;
  phone: string | null;
  labels: string[];
  impersonator: boolean | null;
  impersonatorUserId: string | null;
};

export type User = {
  $id: string;
  name: string;
  email: string | null;
  phone: string | null;
  status: boolean;
  labels: string[];
  impersonator: boolean | null;
};

export type UserList = {
  users: User[];
  total: number;
};

type RequestOptions = {
  endpoint: string;
  projectId: string;
  path: string;
  method?: 'GET' | 'POST' | 'DELETE';
  impersonation?: ImpersonationHeader;
  payload?: unknown;
};

const buildUrl = (endpoint: string, path: string) => {
  const normalizedBase = endpoint.endsWith('/') ? endpoint : `${endpoint}/`;
  return new URL(path.replace(/^\//, ''), normalizedBase);
};

export const request = async <T>({
  endpoint,
  projectId,
  path,
  method = 'GET',
  impersonation = { type: 'none' },
  payload,
}: RequestOptions): Promise<T> => {
  const url = buildUrl(endpoint, path);
  const headers: Record<string, string> = {
    'content-type': 'application/json',
    'x-appwrite-project': projectId,
  };

  if (impersonation.type === 'id') {
    headers['x-appwrite-impersonate-user-id'] = impersonation.value;
  }

  if (impersonation.type === 'email') {
    headers['x-appwrite-impersonate-user-email'] = impersonation.value;
  }

  if (impersonation.type === 'phone') {
    headers['x-appwrite-impersonate-user-phone'] = impersonation.value;
  }

  const response = await fetch(url, {
    method,
    headers,
    credentials: 'include',
    body: payload ? JSON.stringify(payload) : undefined,
  });

  if (response.status === 204) {
    return {} as T;
  }

  const body = (await response.json()) as T | AppwriteError;

  if (!response.ok) {
    const error = body as AppwriteError;
    throw new ApiError(error.message ?? `Request failed with status ${response.status}`, {
      code: error.code ?? response.status,
      type: error.type,
    });
  }

  return body as T;
};

export const createEmailSession = (input: {
  endpoint: string;
  projectId: string;
  email: string;
  password: string;
}) =>
  request<Session>({
    endpoint: input.endpoint,
    projectId: input.projectId,
    path: '/account/sessions/email',
    method: 'POST',
    payload: {
      email: input.email,
      password: input.password,
    },
  });

export const getAccount = (input: {
  endpoint: string;
  projectId: string;
  impersonation: ImpersonationHeader;
}) =>
  request<Account>({
    endpoint: input.endpoint,
    projectId: input.projectId,
    path: '/account',
    impersonation: input.impersonation,
  });

export const listUsers = (input: {
  endpoint: string;
  projectId: string;
  impersonation: ImpersonationHeader;
  search: string;
}) => {
  const params = new URLSearchParams();

  if (input.search.trim()) {
    params.set('search', input.search.trim());
  }

  params.set('total', 'true');

  return request<UserList>({
    endpoint: input.endpoint,
    projectId: input.projectId,
    path: `/users?${params.toString()}`,
    impersonation: input.impersonation,
  });
};

export const deleteCurrentSession = (input: {
  endpoint: string;
  projectId: string;
}) =>
  request<Record<string, never>>({
    endpoint: input.endpoint,
    projectId: input.projectId,
    path: '/account/sessions/current',
    method: 'DELETE',
  });
