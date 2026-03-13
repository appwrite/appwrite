import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ApiError,
  createEmailSession,
  deleteCurrentSession,
  getAccount,
  listUsers,
} from './api';
import type { ImpersonationHeader, User } from './api';

const storageKeys = {
  endpoint: 'impersonation-demo-endpoint',
  projectId: 'impersonation-demo-project-id',
} as const;

const defaultEndpoint = import.meta.env.VITE_APPWRITE_ENDPOINT ?? 'http://localhost/v1';
const defaultProjectId = import.meta.env.VITE_APPWRITE_PROJECT ?? '';

const getStoredValue = (key: string, fallback: string) => localStorage.getItem(key) ?? fallback;

function App() {
  const queryClient = useQueryClient();

  const [endpoint, setEndpoint] = useState(() => getStoredValue(storageKeys.endpoint, defaultEndpoint));
  const [projectId, setProjectId] = useState(() => getStoredValue(storageKeys.projectId, defaultProjectId));
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [search, setSearch] = useState('');
  const [impersonation, setImpersonation] = useState<ImpersonationHeader>({ type: 'none' });

  useEffect(() => {
    localStorage.setItem(storageKeys.endpoint, endpoint);
  }, [endpoint]);

  useEffect(() => {
    localStorage.setItem(storageKeys.projectId, projectId);
  }, [projectId]);

  const accountQuery = useQuery({
    queryKey: ['account', endpoint, projectId, impersonation],
    queryFn: () => getAccount({ endpoint, projectId, impersonation }),
    enabled: Boolean(endpoint && projectId),
    retry: false,
  });

  const canBrowseUsers = Boolean(accountQuery.data?.impersonator || accountQuery.data?.impersonatorUserId);

  const usersQuery = useQuery({
    queryKey: ['users', endpoint, projectId, impersonation, search],
    queryFn: () => listUsers({ endpoint, projectId, impersonation, search }),
    enabled: Boolean(endpoint && projectId && canBrowseUsers),
    retry: false,
  });

  const loginMutation = useMutation({
    mutationFn: () => createEmailSession({ endpoint, projectId, email, password }),
    onSuccess: () => {
      setPassword('');
      setImpersonation({ type: 'none' });
      queryClient.invalidateQueries();
    },
  });

  const logoutMutation = useMutation({
    mutationFn: () => deleteCurrentSession({ endpoint, projectId }),
    onSettled: () => {
      setImpersonation({ type: 'none' });
      queryClient.clear();
    },
  });

  const activeIdentity = accountQuery.data;
  const activeProof = useMemo(() => {
    if (!activeIdentity) {
      return 'Sign in with a user that already has the impersonator attribute enabled.';
    }

    if (activeIdentity.impersonatorUserId) {
      return `You are currently acting as ${activeIdentity.$id}, while the original impersonator is ${activeIdentity.impersonatorUserId}.`;
    }

    if (activeIdentity.impersonator) {
      return `You are signed in as an impersonator (${activeIdentity.$id}) and can browse users before selecting a target.`;
    }

    return `You are signed in as a normal user (${activeIdentity.$id}). Impersonation headers will be ignored.`;
  }, [activeIdentity]);

  const handleLogin = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    loginMutation.mutate();
  };

  const setTarget = (type: ImpersonationHeader['type'], user: User) => {
    if (type === 'id') {
      setImpersonation({ type, value: user.$id });
      return;
    }

    if (type === 'email' && user.email) {
      setImpersonation({ type, value: user.email });
      return;
    }

    if (type === 'phone' && user.phone) {
      setImpersonation({ type, value: user.phone });
    }
  };

  const clearImpersonation = () => {
    setImpersonation({ type: 'none' });
  };

  const accountError = accountQuery.error instanceof ApiError ? accountQuery.error : null;
  const isSignedOut = accountError?.code === 401;

  return (
    <main className="layout">
      <header className="hero">
        <div>
          <p className="eyebrow">CSR demo</p>
          <h1>Appwrite impersonation proof-of-concept</h1>
          <p className="lede">
            This TanStack Query client signs in with an Appwrite user session, lists users when the
            logged-in account is allowed to impersonate, and switches the active account by sending
            the new impersonation headers.
          </p>
        </div>
        <div className="proof-card">
          <span className="proof-label">Current proof</span>
          <p>{activeProof}</p>
        </div>
      </header>

      <section className="grid">
        <article className="card">
          <h2>Connection</h2>
          <label>
            Endpoint
            <input value={endpoint} onChange={(event) => setEndpoint(event.target.value)} />
          </label>
          <label>
            Project ID
            <input value={projectId} onChange={(event) => setProjectId(event.target.value)} />
          </label>
          <p className="muted">
            Defaults come from <code>VITE_APPWRITE_ENDPOINT</code> and{' '}
            <code>VITE_APPWRITE_PROJECT</code>. Values persist in local storage.
          </p>
        </article>

        <article className="card">
          <h2>Session</h2>
          <form className="stack" onSubmit={handleLogin}>
            <label>
              Email
              <input
                autoComplete="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
              />
            </label>
            <label>
              Password
              <input
                autoComplete="current-password"
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
              />
            </label>
            <div className="actions">
              <button type="submit" disabled={loginMutation.isPending || !projectId || !endpoint}>
                {loginMutation.isPending ? 'Signing in...' : 'Create email session'}
              </button>
              <button
                type="button"
                className="button-secondary"
                disabled={!accountQuery.data || logoutMutation.isPending}
                onClick={() => logoutMutation.mutate()}
              >
                {logoutMutation.isPending ? 'Signing out...' : 'Delete current session'}
              </button>
            </div>
          </form>
          <p className="muted">
            This demo is fully CSR. It relies on the browser session cookie set by
            <code> /account/sessions/email </code>
            and sends follow-up requests with <code>credentials: 'include'</code>.
          </p>
          {loginMutation.error ? <p className="error">{loginMutation.error.message}</p> : null}
        </article>
      </section>

      <section className="grid">
        <article className="card">
          <h2>Active account</h2>
          {accountQuery.isPending ? <p>Loading account...</p> : null}
          {accountQuery.error && !isSignedOut ? <p className="error">{accountQuery.error.message}</p> : null}
          {activeIdentity ? (
            <dl className="facts">
              <Fact label="Account ID" value={activeIdentity.$id} />
              <Fact label="Name" value={activeIdentity.name || '(empty)'} />
              <Fact label="Email" value={activeIdentity.email || '(empty)'} />
              <Fact label="Phone" value={activeIdentity.phone || '(empty)'} />
              <Fact label="Impersonator" value={String(Boolean(activeIdentity.impersonator))} />
              <Fact
                label="Original impersonator"
                value={activeIdentity.impersonatorUserId ?? '(none)'}
              />
            </dl>
          ) : (
            <p className="muted">
              {isSignedOut ? 'No authenticated browser session yet.' : 'No authenticated session yet.'}
            </p>
          )}
        </article>

        <article className="card">
          <h2>Active headers</h2>
          <dl className="facts">
            <Fact
              label="Browser auth"
              value={accountQuery.data ? 'Session cookie is active' : 'No browser session'}
            />
            <Fact label="Impersonation mode" value={impersonation.type} />
            <Fact
              label="Header value"
              value={impersonation.type === 'none' ? '(none)' : impersonation.value}
            />
          </dl>
          <div className="actions">
            <button type="button" className="button-secondary" onClick={clearImpersonation}>
              Clear impersonation
            </button>
          </div>
        </article>
      </section>

      <section className="card">
        <div className="section-header">
          <div>
            <h2>User browser</h2>
            <p className="muted">
              This list only works when the authenticated user is an impersonator or already inside
              an impersonated session.
            </p>
          </div>
          <input
            className="search"
            placeholder="Search users"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>

        {!accountQuery.data && isSignedOut ? <p className="muted">Create a session to load users.</p> : null}
        {accountQuery.data && !canBrowseUsers && accountQuery.isSuccess ? (
          <p className="warning">
            This account cannot browse users yet. Mark it as an impersonator first, then sign in again.
          </p>
        ) : null}
        {usersQuery.isPending ? <p>Loading users...</p> : null}
        {usersQuery.error ? <p className="error">{usersQuery.error.message}</p> : null}

        {usersQuery.data ? (
          <>
            <p className="muted">{usersQuery.data.total} users returned.</p>
            <div className="user-list">
              {usersQuery.data.users.map((user) => (
                <article key={user.$id} className="user-row">
                  <div>
                    <strong>{user.name || '(anonymous)'}</strong>
                    <p className="muted">
                      <code>{user.$id}</code>
                    </p>
                    <p className="muted">
                      {user.email || '(no email)'} · {user.phone || '(no phone)'}
                    </p>
                  </div>
                  <div className="actions wrap">
                    <button type="button" onClick={() => setTarget('id', user)}>
                      Use ID header
                    </button>
                    <button
                      type="button"
                      className="button-secondary"
                      disabled={!user.email}
                      onClick={() => setTarget('email', user)}
                    >
                      Use email header
                    </button>
                    <button
                      type="button"
                      className="button-secondary"
                      disabled={!user.phone}
                      onClick={() => setTarget('phone', user)}
                    >
                      Use phone header
                    </button>
                  </div>
                </article>
              ))}
            </div>
          </>
        ) : null}
      </section>
    </main>
  );
}

type FactProps = {
  label: string;
  value: string;
};

function Fact({ label, value }: FactProps) {
  return (
    <>
      <dt>{label}</dt>
      <dd>{value}</dd>
    </>
  );
}

export default App;
