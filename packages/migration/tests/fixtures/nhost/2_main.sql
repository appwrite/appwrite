--
-- PostgreSQL database dump
--

-- Dumped from database version 14.6 (Debian 14.6-1.pgdg110+1)
-- Dumped by pg_dump version 15.3

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

CREATE SCHEMA auth;


ALTER SCHEMA auth OWNER TO nhost_admin;

--
-- Name: hdb_catalog; Type: SCHEMA; Schema: -; Owner: nhost_hasura
--

CREATE SCHEMA hdb_catalog;


ALTER SCHEMA hdb_catalog OWNER TO nhost_hasura;

--
-- Name: pgbouncer; Type: SCHEMA; Schema: -; Owner: nhost_admin
--

CREATE SCHEMA pgbouncer;


ALTER SCHEMA pgbouncer OWNER TO nhost_admin;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: postgres
--

-- *not* creating schema, since initdb creates it


ALTER SCHEMA public OWNER TO postgres;

--
-- Name: storage; Type: SCHEMA; Schema: -; Owner: nhost_admin
--

CREATE SCHEMA storage;


ALTER SCHEMA storage OWNER TO nhost_admin;

--
-- Name: citext; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA public;


--
-- Name: EXTENSION citext; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION citext IS 'data type for case-insensitive character strings';


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: email; Type: DOMAIN; Schema: auth; Owner: nhost_auth_admin
--

CREATE DOMAIN auth.email AS public.citext
	CONSTRAINT email_check CHECK (((VALUE)::text ~ '^[a-zA-Z0-9.!#$%&''*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$'::text));


ALTER DOMAIN auth.email OWNER TO nhost_auth_admin;

--
-- Name: set_current_timestamp_updated_at(); Type: FUNCTION; Schema: auth; Owner: nhost_auth_admin
--

CREATE FUNCTION auth.set_current_timestamp_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  _new record;
BEGIN
  _new := new;
  _new. "updated_at" = now();
  RETURN _new;
END;
$$;


ALTER FUNCTION auth.set_current_timestamp_updated_at() OWNER TO nhost_auth_admin;

--
-- Name: gen_hasura_uuid(); Type: FUNCTION; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE FUNCTION hdb_catalog.gen_hasura_uuid() RETURNS uuid
    LANGUAGE sql
    AS $$select gen_random_uuid()$$;


ALTER FUNCTION hdb_catalog.gen_hasura_uuid() OWNER TO nhost_hasura;

--
-- Name: user_lookup(text); Type: FUNCTION; Schema: pgbouncer; Owner: postgres
--

CREATE FUNCTION pgbouncer.user_lookup(i_username text, OUT uname text, OUT phash text) RETURNS record
    LANGUAGE plpgsql SECURITY DEFINER
    AS $$
BEGIN
    SELECT usename, passwd FROM pg_catalog.pg_shadow
    WHERE usename = i_username INTO uname, phash;
    RETURN;
END;
$$;


ALTER FUNCTION pgbouncer.user_lookup(i_username text, OUT uname text, OUT phash text) OWNER TO postgres;

--
-- Name: protect_default_bucket_delete(); Type: FUNCTION; Schema: storage; Owner: nhost_storage_admin
--

CREATE FUNCTION storage.protect_default_bucket_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  IF OLD.ID = 'default' THEN
    RAISE EXCEPTION 'Can not delete default bucket';
  END IF;
  RETURN OLD;
END;
$$;


ALTER FUNCTION storage.protect_default_bucket_delete() OWNER TO nhost_storage_admin;

--
-- Name: protect_default_bucket_update(); Type: FUNCTION; Schema: storage; Owner: nhost_storage_admin
--

CREATE FUNCTION storage.protect_default_bucket_update() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  IF OLD.ID = 'default' AND NEW.ID <> 'default' THEN
    RAISE EXCEPTION 'Can not rename default bucket';
  END IF;
  RETURN NEW;
END;
$$;


ALTER FUNCTION storage.protect_default_bucket_update() OWNER TO nhost_storage_admin;

--
-- Name: set_current_timestamp_updated_at(); Type: FUNCTION; Schema: storage; Owner: nhost_storage_admin
--

CREATE FUNCTION storage.set_current_timestamp_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  _new record;
BEGIN
  _new := new;
  _new. "updated_at" = now();
  RETURN _new;
END;
$$;


ALTER FUNCTION storage.set_current_timestamp_updated_at() OWNER TO nhost_storage_admin;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: migrations; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.migrations (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    hash character varying(40) NOT NULL,
    executed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE auth.migrations OWNER TO nhost_auth_admin;

--
-- Name: TABLE migrations; Type: COMMENT; Schema: auth; Owner: nhost_auth_admin
--

COMMENT ON TABLE auth.migrations IS 'Internal table for tracking migrations. Don''t modify its structure as Hasura Auth relies on it to function properly.';


--
-- Name: provider_requests; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.provider_requests (
    id uuid NOT NULL,
    options jsonb
);


ALTER TABLE auth.provider_requests OWNER TO nhost_auth_admin;

--
-- Name: TABLE provider_requests; Type: COMMENT; Schema: auth; Owner: nhost_auth_admin
--

COMMENT ON TABLE auth.provider_requests IS 'Oauth requests, inserted before redirecting to the provider''s site. Don''t modify its structure as Hasura Auth relies on it to function properly.';


--
-- Name: providers; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.providers (
    id text NOT NULL
);


ALTER TABLE auth.providers OWNER TO nhost_auth_admin;

--
-- Name: TABLE providers; Type: COMMENT; Schema: auth; Owner: nhost_auth_admin
--

COMMENT ON TABLE auth.providers IS 'List of available Oauth providers. Don''t modify its structure as Hasura Auth relies on it to function properly.';


--
-- Name: refresh_token_types; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.refresh_token_types (
    value text NOT NULL,
    comment text
);


ALTER TABLE auth.refresh_token_types OWNER TO nhost_auth_admin;

--
-- Name: refresh_tokens; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.refresh_tokens (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    user_id uuid NOT NULL,
    metadata jsonb,
    type text DEFAULT 'regular'::text NOT NULL,
    refresh_token_hash character varying(255)
);


ALTER TABLE auth.refresh_tokens OWNER TO nhost_auth_admin;

--
-- Name: TABLE refresh_tokens; Type: COMMENT; Schema: auth; Owner: nhost_auth_admin
--

COMMENT ON TABLE auth.refresh_tokens IS 'User refresh tokens. Hasura auth uses them to rotate new access tokens as long as the refresh token is not expired. Don''t modify its structure as Hasura Auth relies on it to function properly.';


--
-- Name: roles; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.roles (
    role text NOT NULL
);


ALTER TABLE auth.roles OWNER TO nhost_auth_admin;

--
-- Name: TABLE roles; Type: COMMENT; Schema: auth; Owner: nhost_auth_admin
--

COMMENT ON TABLE auth.roles IS 'Persistent Hasura roles for users. Don''t modify its structure as Hasura Auth relies on it to function properly.';


--
-- Name: user_providers; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.user_providers (
    id uuid DEFAULT public.gen_random_uuid() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    user_id uuid NOT NULL,
    access_token text NOT NULL,
    refresh_token text,
    provider_id text NOT NULL,
    provider_user_id text NOT NULL
);


ALTER TABLE auth.user_providers OWNER TO nhost_auth_admin;

--
-- Name: TABLE user_providers; Type: COMMENT; Schema: auth; Owner: nhost_auth_admin
--

COMMENT ON TABLE auth.user_providers IS 'Active providers for a given user. Don''t modify its structure as Hasura Auth relies on it to function properly.';


--
-- Name: user_roles; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.user_roles (
    id uuid DEFAULT public.gen_random_uuid() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    user_id uuid NOT NULL,
    role text NOT NULL
);


ALTER TABLE auth.user_roles OWNER TO nhost_auth_admin;

--
-- Name: TABLE user_roles; Type: COMMENT; Schema: auth; Owner: nhost_auth_admin
--

COMMENT ON TABLE auth.user_roles IS 'Roles of users. Don''t modify its structure as Hasura Auth relies on it to function properly.';


--
-- Name: user_security_keys; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.user_security_keys (
    id uuid DEFAULT public.gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    credential_id text NOT NULL,
    credential_public_key bytea,
    counter bigint DEFAULT 0 NOT NULL,
    transports character varying(255) DEFAULT ''::character varying NOT NULL,
    nickname text
);


ALTER TABLE auth.user_security_keys OWNER TO nhost_auth_admin;

--
-- Name: TABLE user_security_keys; Type: COMMENT; Schema: auth; Owner: nhost_auth_admin
--

COMMENT ON TABLE auth.user_security_keys IS 'User webauthn security keys. Don''t modify its structure as Hasura Auth relies on it to function properly.';


--
-- Name: users; Type: TABLE; Schema: auth; Owner: nhost_auth_admin
--

CREATE TABLE auth.users (
    id uuid DEFAULT public.gen_random_uuid() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    last_seen timestamp with time zone,
    disabled boolean DEFAULT false NOT NULL,
    display_name text DEFAULT ''::text NOT NULL,
    avatar_url text DEFAULT ''::text NOT NULL,
    locale character varying(2) NOT NULL,
    email auth.email,
    phone_number text,
    password_hash text,
    email_verified boolean DEFAULT false NOT NULL,
    phone_number_verified boolean DEFAULT false NOT NULL,
    new_email auth.email,
    otp_method_last_used text,
    otp_hash text,
    otp_hash_expires_at timestamp with time zone DEFAULT now() NOT NULL,
    default_role text DEFAULT 'user'::text NOT NULL,
    is_anonymous boolean DEFAULT false NOT NULL,
    totp_secret text,
    active_mfa_type text,
    ticket text,
    ticket_expires_at timestamp with time zone DEFAULT now() NOT NULL,
    metadata jsonb,
    webauthn_current_challenge text,
    CONSTRAINT active_mfa_types_check CHECK (((active_mfa_type = 'totp'::text) OR (active_mfa_type = 'sms'::text)))
);


ALTER TABLE auth.users OWNER TO nhost_auth_admin;

--
-- Name: TABLE users; Type: COMMENT; Schema: auth; Owner: nhost_auth_admin
--

COMMENT ON TABLE auth.users IS 'User account information. Don''t modify its structure as Hasura Auth relies on it to function properly.';


--
-- Name: hdb_action_log; Type: TABLE; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE TABLE hdb_catalog.hdb_action_log (
    id uuid DEFAULT hdb_catalog.gen_hasura_uuid() NOT NULL,
    action_name text,
    input_payload jsonb NOT NULL,
    request_headers jsonb NOT NULL,
    session_variables jsonb NOT NULL,
    response_payload jsonb,
    errors jsonb,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    response_received_at timestamp with time zone,
    status text NOT NULL,
    CONSTRAINT hdb_action_log_status_check CHECK ((status = ANY (ARRAY['created'::text, 'processing'::text, 'completed'::text, 'error'::text])))
);


ALTER TABLE hdb_catalog.hdb_action_log OWNER TO nhost_hasura;

--
-- Name: hdb_cron_event_invocation_logs; Type: TABLE; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE TABLE hdb_catalog.hdb_cron_event_invocation_logs (
    id text DEFAULT hdb_catalog.gen_hasura_uuid() NOT NULL,
    event_id text,
    status integer,
    request json,
    response json,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE hdb_catalog.hdb_cron_event_invocation_logs OWNER TO nhost_hasura;

--
-- Name: hdb_cron_events; Type: TABLE; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE TABLE hdb_catalog.hdb_cron_events (
    id text DEFAULT hdb_catalog.gen_hasura_uuid() NOT NULL,
    trigger_name text NOT NULL,
    scheduled_time timestamp with time zone NOT NULL,
    status text DEFAULT 'scheduled'::text NOT NULL,
    tries integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    next_retry_at timestamp with time zone,
    CONSTRAINT valid_status CHECK ((status = ANY (ARRAY['scheduled'::text, 'locked'::text, 'delivered'::text, 'error'::text, 'dead'::text])))
);


ALTER TABLE hdb_catalog.hdb_cron_events OWNER TO nhost_hasura;

--
-- Name: hdb_metadata; Type: TABLE; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE TABLE hdb_catalog.hdb_metadata (
    id integer NOT NULL,
    metadata json NOT NULL,
    resource_version integer DEFAULT 1 NOT NULL
);


ALTER TABLE hdb_catalog.hdb_metadata OWNER TO nhost_hasura;

--
-- Name: hdb_scheduled_event_invocation_logs; Type: TABLE; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE TABLE hdb_catalog.hdb_scheduled_event_invocation_logs (
    id text DEFAULT hdb_catalog.gen_hasura_uuid() NOT NULL,
    event_id text,
    status integer,
    request json,
    response json,
    created_at timestamp with time zone DEFAULT now()
);


ALTER TABLE hdb_catalog.hdb_scheduled_event_invocation_logs OWNER TO nhost_hasura;

--
-- Name: hdb_scheduled_events; Type: TABLE; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE TABLE hdb_catalog.hdb_scheduled_events (
    id text DEFAULT hdb_catalog.gen_hasura_uuid() NOT NULL,
    webhook_conf json NOT NULL,
    scheduled_time timestamp with time zone NOT NULL,
    retry_conf json,
    payload json,
    header_conf json,
    status text DEFAULT 'scheduled'::text NOT NULL,
    tries integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    next_retry_at timestamp with time zone,
    comment text,
    CONSTRAINT valid_status CHECK ((status = ANY (ARRAY['scheduled'::text, 'locked'::text, 'delivered'::text, 'error'::text, 'dead'::text])))
);


ALTER TABLE hdb_catalog.hdb_scheduled_events OWNER TO nhost_hasura;

--
-- Name: hdb_schema_notifications; Type: TABLE; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE TABLE hdb_catalog.hdb_schema_notifications (
    id integer NOT NULL,
    notification json NOT NULL,
    resource_version integer DEFAULT 1 NOT NULL,
    instance_id uuid NOT NULL,
    updated_at timestamp with time zone DEFAULT now(),
    CONSTRAINT hdb_schema_notifications_id_check CHECK ((id = 1))
);


ALTER TABLE hdb_catalog.hdb_schema_notifications OWNER TO nhost_hasura;

--
-- Name: hdb_version; Type: TABLE; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE TABLE hdb_catalog.hdb_version (
    hasura_uuid uuid DEFAULT hdb_catalog.gen_hasura_uuid() NOT NULL,
    version text NOT NULL,
    upgraded_on timestamp with time zone NOT NULL,
    cli_state jsonb DEFAULT '{}'::jsonb NOT NULL,
    console_state jsonb DEFAULT '{}'::jsonb NOT NULL,
    ee_client_id text,
    ee_client_secret text
);


ALTER TABLE hdb_catalog.hdb_version OWNER TO nhost_hasura;

--
-- Name: TestTable; Type: TABLE; Schema: public; Owner: nhost_hasura
--

CREATE TABLE public."TestTable" (
    string text NOT NULL,
    "integer" bigint NOT NULL,
    "boolean" boolean NOT NULL,
    date date NOT NULL
);


ALTER TABLE public."TestTable" OWNER TO nhost_hasura;

--
-- Name: buckets; Type: TABLE; Schema: storage; Owner: nhost_storage_admin
--

CREATE TABLE storage.buckets (
    id text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    download_expiration integer DEFAULT 30 NOT NULL,
    min_upload_file_size integer DEFAULT 1 NOT NULL,
    max_upload_file_size integer DEFAULT 50000000 NOT NULL,
    cache_control text DEFAULT 'max-age=3600'::text,
    presigned_urls_enabled boolean DEFAULT true NOT NULL,
    CONSTRAINT download_expiration_valid_range CHECK (((download_expiration >= 1) AND (download_expiration <= 604800)))
);


ALTER TABLE storage.buckets OWNER TO nhost_storage_admin;

--
-- Name: files; Type: TABLE; Schema: storage; Owner: nhost_storage_admin
--

CREATE TABLE storage.files (
    id uuid DEFAULT public.gen_random_uuid() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    bucket_id text DEFAULT 'default'::text NOT NULL,
    name text,
    size integer,
    mime_type text,
    etag text,
    is_uploaded boolean DEFAULT false,
    uploaded_by_user_id uuid
);


ALTER TABLE storage.files OWNER TO nhost_storage_admin;

--
-- Name: FunctionalDefaultTestTable; Type: TABLE; Schema: public; Owner: nhost_hasura
--
CREATE SEQUENCE IF NOT EXISTS public.test_data_id_seq;

-- Table Definition
CREATE TABLE public."FunctionalDefaultTestTable" (
    "id" int4 NOT NULL DEFAULT nextval('public.test_data_id_seq'::regclass),
    PRIMARY KEY ("id")
);

-- Indices
CREATE UNIQUE INDEX test_data_pkey ON public."FunctionalDefaultTestTable" USING btree (id);

-- Change table owner
ALTER TABLE public."FunctionalDefaultTestTable" OWNER TO nhost_hasura;

--
-- Name: schema_migrations; Type: TABLE; Schema: storage; Owner: nhost_storage_admin
--

CREATE TABLE storage.schema_migrations (
    version bigint NOT NULL,
    dirty boolean NOT NULL
);


ALTER TABLE storage.schema_migrations OWNER TO nhost_storage_admin;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.migrations (id, name, hash, executed_at) FROM stdin;
0	create-migrations-table	9c0c864e0ccb0f8d1c77ab0576ef9f2841ec1b68	2023-07-25 11:03:00.726234
1	create-initial-tables	c16083c88329c867581a9c73c3f140783a1a5df4	2023-07-25 11:03:00.800607
2	custom-user-fields	78236c9c2b50da88786bcf50099dd290f820e000	2023-07-25 11:03:00.805145
3	discord-twitch-providers	857db1e92c7a8034e61a3d88ea672aec9b424036	2023-07-25 11:03:00.809131
4	provider-request-options	42428265112b904903d9ad7833d8acf2812a00ed	2023-07-25 11:03:00.813437
5	table-comments	78f76f88eff3b11ebab9be4f2469020dae017110	2023-07-25 11:03:00.815186
6	setup-webauthn	87ba279363f8ecf8b450a681938a74b788cf536c	2023-07-25 11:03:00.829301
7	add_authenticator_nickname	d32fd62bb7a441eea48c5434f5f3744f2e334288	2023-07-25 11:03:00.832627
8	workos-provider	0727238a633ff119bedcbebfec6a9ea83b2bd01d	2023-07-25 11:03:00.83641
9	rename-authenticator-to-security-key	fd7e00bef4d141a6193cf9642afd88fb6fe2b283	2023-07-25 11:03:00.840136
10	azuread-provider	f492ff4780f8210016e1c12fa0ed83eb4278a780	2023-07-25 11:03:00.84337
11	add_refresh_token_hash_column	62a2cd295f63153dd9f16f3159d1ab2a49b01c2f	2023-07-25 11:03:00.852059
12	add_refresh_token_metadata	3daa907e813d1e8b72107112a89916909702897c	2023-07-25 11:03:00.858989
13	add_refresh_token_type	5f2472c56df4c4735f6add046782680eb27484e5	2023-07-25 11:03:00.862626
14	alter_refresh_token_type	a059cb9fda67f286e6bd2765f8aa7ea1e4a7fd6c	2023-07-25 11:03:00.879516
15	rename_refresh_token_column	71e1d7fa6e6056fa193b4ff4d6f8e61cf3f5cd9f	2023-07-25 11:03:00.883585
\.


--
-- Data for Name: provider_requests; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.provider_requests (id, options) FROM stdin;
\.


--
-- Data for Name: providers; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.providers (id) FROM stdin;
github
facebook
twitter
google
apple
linkedin
windowslive
spotify
strava
gitlab
bitbucket
discord
twitch
workos
azuread
\.


--
-- Data for Name: refresh_token_types; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.refresh_token_types (value, comment) FROM stdin;
regular	Regular refresh token
pat	Personal access token
\.


--
-- Data for Name: refresh_tokens; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.refresh_tokens (id, created_at, expires_at, user_id, metadata, type, refresh_token_hash) FROM stdin;
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.roles (role) FROM stdin;
user
anonymous
me
\.


--
-- Data for Name: user_providers; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.user_providers (id, created_at, updated_at, user_id, access_token, refresh_token, provider_id, provider_user_id) FROM stdin;
\.


--
-- Data for Name: user_roles; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.user_roles (id, created_at, user_id, role) FROM stdin;
1503b765-f2a4-45de-956d-05c42a7e48de	2023-07-25 11:04:13.96605+00	8ff692dc-3f4f-4be1-879c-aafa46eadf10	me
9c231460-8803-4269-b689-212e56ee7e72	2023-07-25 11:04:13.96605+00	8ff692dc-3f4f-4be1-879c-aafa46eadf10	user
\.


--
-- Data for Name: user_security_keys; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.user_security_keys (id, user_id, credential_id, credential_public_key, counter, transports, nickname) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: auth; Owner: nhost_auth_admin
--

COPY auth.users (id, created_at, updated_at, last_seen, disabled, display_name, avatar_url, locale, email, phone_number, password_hash, email_verified, phone_number_verified, new_email, otp_method_last_used, otp_hash, otp_hash_expires_at, default_role, is_anonymous, totp_secret, active_mfa_type, ticket, ticket_expires_at, metadata, webauthn_current_challenge) FROM stdin;
8ff692dc-3f4f-4be1-879c-aafa46eadf10	2023-07-25 11:04:13.96605+00	2023-07-25 11:04:13.96605+00	\N	f	test@test.com	https://s.gravatar.com/avatar/b642b4217b34b1e8d3bd915fc65c4452?r=g&default=blank	en	test@test.com	\N	$2a$10$ARQ/f.K6OmCjZ8XF0U.6fezPMlxDqsmcl0Rs6xQVkvj62u7gcSzOW	f	f	\N	\N	\N	2023-07-25 11:04:13.96605+00	user	f	\N	\N	verifyEmail:d8eddf37-8a59-4a63-a67f-7a2baf5d2fbb	2023-08-24 11:04:13.95+00	{}	\N
\.


--
-- Data for Name: hdb_action_log; Type: TABLE DATA; Schema: hdb_catalog; Owner: nhost_hasura
--

COPY hdb_catalog.hdb_action_log (id, action_name, input_payload, request_headers, session_variables, response_payload, errors, created_at, response_received_at, status) FROM stdin;
\.


--
-- Data for Name: hdb_cron_event_invocation_logs; Type: TABLE DATA; Schema: hdb_catalog; Owner: nhost_hasura
--

COPY hdb_catalog.hdb_cron_event_invocation_logs (id, event_id, status, request, response, created_at) FROM stdin;
\.


--
-- Data for Name: hdb_cron_events; Type: TABLE DATA; Schema: hdb_catalog; Owner: nhost_hasura
--

COPY hdb_catalog.hdb_cron_events (id, trigger_name, scheduled_time, status, tries, created_at, next_retry_at) FROM stdin;
\.


--
-- Data for Name: hdb_metadata; Type: TABLE DATA; Schema: hdb_catalog; Owner: nhost_hasura
--

COPY hdb_catalog.hdb_metadata (id, metadata, resource_version) FROM stdin;
1	{"sources":[{"configuration":{"connection_info":{"database_url":{"from_env":"HASURA_GRAPHQL_DATABASE_URL"},"isolation_level":"read-committed","pool_settings":{"connection_lifetime":600,"idle_timeout":180,"max_connections":50,"retries":1},"use_prepared_statements":true}},"kind":"postgres","name":"default","tables":[{"configuration":{"column_config":{"id":{"custom_name":"id"},"options":{"custom_name":"options"}},"custom_column_names":{"id":"id","options":"options"},"custom_name":"authProviderRequests","custom_root_fields":{"delete":"deleteAuthProviderRequests","delete_by_pk":"deleteAuthProviderRequest","insert":"insertAuthProviderRequests","insert_one":"insertAuthProviderRequest","select":"authProviderRequests","select_aggregate":"authProviderRequestsAggregate","select_by_pk":"authProviderRequest","update":"updateAuthProviderRequests","update_by_pk":"updateAuthProviderRequest"}},"table":{"name":"provider_requests","schema":"auth"}},{"array_relationships":[{"name":"userProviders","using":{"foreign_key_constraint_on":{"column":"provider_id","table":{"name":"user_providers","schema":"auth"}}}}],"configuration":{"column_config":{"id":{"custom_name":"id"}},"custom_column_names":{"id":"id"},"custom_name":"authProviders","custom_root_fields":{"delete":"deleteAuthProviders","delete_by_pk":"deleteAuthProvider","insert":"insertAuthProviders","insert_one":"insertAuthProvider","select":"authProviders","select_aggregate":"authProvidersAggregate","select_by_pk":"authProvider","update":"updateAuthProviders","update_by_pk":"updateAuthProvider"}},"table":{"name":"providers","schema":"auth"}},{"array_relationships":[{"name":"refreshTokens","using":{"foreign_key_constraint_on":{"column":"type","table":{"name":"refresh_tokens","schema":"auth"}}}}],"configuration":{"column_config":{},"custom_column_names":{},"custom_name":"authRefreshTokenTypes","custom_root_fields":{"delete":"deleteAuthRefreshTokenTypes","delete_by_pk":"deleteAuthRefreshTokenType","insert":"insertAuthRefreshTokenTypes","insert_one":"insertAuthRefreshTokenType","select":"authRefreshTokenTypes","select_aggregate":"authRefreshTokenTypesAggregate","select_by_pk":"authRefreshTokenType","update":"updateAuthRefreshTokenTypes","update_by_pk":"updateAuthRefreshTokenType"}},"is_enum":true,"table":{"name":"refresh_token_types","schema":"auth"}},{"configuration":{"column_config":{"created_at":{"custom_name":"createdAt"},"expires_at":{"custom_name":"expiresAt"},"refresh_token_hash":{"custom_name":"refreshTokenHash"},"user_id":{"custom_name":"userId"}},"custom_column_names":{"created_at":"createdAt","expires_at":"expiresAt","refresh_token_hash":"refreshTokenHash","user_id":"userId"},"custom_name":"authRefreshTokens","custom_root_fields":{"delete":"deleteAuthRefreshTokens","delete_by_pk":"deleteAuthRefreshToken","insert":"insertAuthRefreshTokens","insert_one":"insertAuthRefreshToken","select":"authRefreshTokens","select_aggregate":"authRefreshTokensAggregate","select_by_pk":"authRefreshToken","update":"updateAuthRefreshTokens","update_by_pk":"updateAuthRefreshToken"}},"delete_permissions":[{"permission":{"filter":{"_and":[{"user_id":{"_eq":"X-Hasura-User-Id"}},{"type":{"_eq":"pat"}}]}},"role":"user"}],"object_relationships":[{"name":"user","using":{"foreign_key_constraint_on":"user_id"}}],"select_permissions":[{"permission":{"columns":["id","created_at","expires_at","metadata","type","user_id"],"filter":{"user_id":{"_eq":"X-Hasura-User-Id"}}},"role":"user"}],"table":{"name":"refresh_tokens","schema":"auth"}},{"array_relationships":[{"name":"userRoles","using":{"foreign_key_constraint_on":{"column":"role","table":{"name":"user_roles","schema":"auth"}}}},{"name":"usersByDefaultRole","using":{"foreign_key_constraint_on":{"column":"default_role","table":{"name":"users","schema":"auth"}}}}],"configuration":{"column_config":{"role":{"custom_name":"role"}},"custom_column_names":{"role":"role"},"custom_name":"authRoles","custom_root_fields":{"delete":"deleteAuthRoles","delete_by_pk":"deleteAuthRole","insert":"insertAuthRoles","insert_one":"insertAuthRole","select":"authRoles","select_aggregate":"authRolesAggregate","select_by_pk":"authRole","update":"updateAuthRoles","update_by_pk":"updateAuthRole"}},"table":{"name":"roles","schema":"auth"}},{"configuration":{"column_config":{"access_token":{"custom_name":"accessToken"},"created_at":{"custom_name":"createdAt"},"id":{"custom_name":"id"},"provider_id":{"custom_name":"providerId"},"provider_user_id":{"custom_name":"providerUserId"},"refresh_token":{"custom_name":"refreshToken"},"updated_at":{"custom_name":"updatedAt"},"user_id":{"custom_name":"userId"}},"custom_column_names":{"access_token":"accessToken","created_at":"createdAt","id":"id","provider_id":"providerId","provider_user_id":"providerUserId","refresh_token":"refreshToken","updated_at":"updatedAt","user_id":"userId"},"custom_name":"authUserProviders","custom_root_fields":{"delete":"deleteAuthUserProviders","delete_by_pk":"deleteAuthUserProvider","insert":"insertAuthUserProviders","insert_one":"insertAuthUserProvider","select":"authUserProviders","select_aggregate":"authUserProvidersAggregate","select_by_pk":"authUserProvider","update":"updateAuthUserProviders","update_by_pk":"updateAuthUserProvider"}},"object_relationships":[{"name":"provider","using":{"foreign_key_constraint_on":"provider_id"}},{"name":"user","using":{"foreign_key_constraint_on":"user_id"}}],"table":{"name":"user_providers","schema":"auth"}},{"configuration":{"column_config":{"created_at":{"custom_name":"createdAt"},"id":{"custom_name":"id"},"role":{"custom_name":"role"},"user_id":{"custom_name":"userId"}},"custom_column_names":{"created_at":"createdAt","id":"id","role":"role","user_id":"userId"},"custom_name":"authUserRoles","custom_root_fields":{"delete":"deleteAuthUserRoles","delete_by_pk":"deleteAuthUserRole","insert":"insertAuthUserRoles","insert_one":"insertAuthUserRole","select":"authUserRoles","select_aggregate":"authUserRolesAggregate","select_by_pk":"authUserRole","update":"updateAuthUserRoles","update_by_pk":"updateAuthUserRole"}},"object_relationships":[{"name":"roleByRole","using":{"foreign_key_constraint_on":"role"}},{"name":"user","using":{"foreign_key_constraint_on":"user_id"}}],"table":{"name":"user_roles","schema":"auth"}},{"configuration":{"column_config":{"credential_id":{"custom_name":"credentialId"},"credential_public_key":{"custom_name":"credentialPublicKey"},"id":{"custom_name":"id"},"user_id":{"custom_name":"userId"}},"custom_column_names":{"credential_id":"credentialId","credential_public_key":"credentialPublicKey","id":"id","user_id":"userId"},"custom_name":"authUserSecurityKeys","custom_root_fields":{"delete":"deleteAuthUserSecurityKeys","delete_by_pk":"deleteAuthUserSecurityKey","insert":"insertAuthUserSecurityKeys","insert_one":"insertAuthUserSecurityKey","select":"authUserSecurityKeys","select_aggregate":"authUserSecurityKeysAggregate","select_by_pk":"authUserSecurityKey","update":"updateAuthUserSecurityKeys","update_by_pk":"updateAuthUserSecurityKey"}},"object_relationships":[{"name":"user","using":{"foreign_key_constraint_on":"user_id"}}],"table":{"name":"user_security_keys","schema":"auth"}},{"array_relationships":[{"name":"refreshTokens","using":{"foreign_key_constraint_on":{"column":"user_id","table":{"name":"refresh_tokens","schema":"auth"}}}},{"name":"roles","using":{"foreign_key_constraint_on":{"column":"user_id","table":{"name":"user_roles","schema":"auth"}}}},{"name":"securityKeys","using":{"foreign_key_constraint_on":{"column":"user_id","table":{"name":"user_security_keys","schema":"auth"}}}},{"name":"userProviders","using":{"foreign_key_constraint_on":{"column":"user_id","table":{"name":"user_providers","schema":"auth"}}}}],"configuration":{"column_config":{"active_mfa_type":{"custom_name":"activeMfaType"},"avatar_url":{"custom_name":"avatarUrl"},"created_at":{"custom_name":"createdAt"},"default_role":{"custom_name":"defaultRole"},"disabled":{"custom_name":"disabled"},"display_name":{"custom_name":"displayName"},"email":{"custom_name":"email"},"email_verified":{"custom_name":"emailVerified"},"id":{"custom_name":"id"},"is_anonymous":{"custom_name":"isAnonymous"},"last_seen":{"custom_name":"lastSeen"},"locale":{"custom_name":"locale"},"new_email":{"custom_name":"newEmail"},"otp_hash":{"custom_name":"otpHash"},"otp_hash_expires_at":{"custom_name":"otpHashExpiresAt"},"otp_method_last_used":{"custom_name":"otpMethodLastUsed"},"password_hash":{"custom_name":"passwordHash"},"phone_number":{"custom_name":"phoneNumber"},"phone_number_verified":{"custom_name":"phoneNumberVerified"},"ticket":{"custom_name":"ticket"},"ticket_expires_at":{"custom_name":"ticketExpiresAt"},"totp_secret":{"custom_name":"totpSecret"},"updated_at":{"custom_name":"updatedAt"},"webauthn_current_challenge":{"custom_name":"currentChallenge"}},"custom_column_names":{"active_mfa_type":"activeMfaType","avatar_url":"avatarUrl","created_at":"createdAt","default_role":"defaultRole","disabled":"disabled","display_name":"displayName","email":"email","email_verified":"emailVerified","id":"id","is_anonymous":"isAnonymous","last_seen":"lastSeen","locale":"locale","new_email":"newEmail","otp_hash":"otpHash","otp_hash_expires_at":"otpHashExpiresAt","otp_method_last_used":"otpMethodLastUsed","password_hash":"passwordHash","phone_number":"phoneNumber","phone_number_verified":"phoneNumberVerified","ticket":"ticket","ticket_expires_at":"ticketExpiresAt","totp_secret":"totpSecret","updated_at":"updatedAt","webauthn_current_challenge":"currentChallenge"},"custom_name":"users","custom_root_fields":{"delete":"deleteUsers","delete_by_pk":"deleteUser","insert":"insertUsers","insert_one":"insertUser","select":"users","select_aggregate":"usersAggregate","select_by_pk":"user","update":"updateUsers","update_by_pk":"updateUser"}},"object_relationships":[{"name":"defaultRoleByRole","using":{"foreign_key_constraint_on":"default_role"}}],"table":{"name":"users","schema":"auth"}},{"table":{"name":"TestTable","schema":"public"}},{"array_relationships":[{"name":"files","using":{"foreign_key_constraint_on":{"column":"bucket_id","table":{"name":"files","schema":"storage"}}}}],"configuration":{"column_config":{"cache_control":{"custom_name":"cacheControl"},"created_at":{"custom_name":"createdAt"},"download_expiration":{"custom_name":"downloadExpiration"},"id":{"custom_name":"id"},"max_upload_file_size":{"custom_name":"maxUploadFileSize"},"min_upload_file_size":{"custom_name":"minUploadFileSize"},"presigned_urls_enabled":{"custom_name":"presignedUrlsEnabled"},"updated_at":{"custom_name":"updatedAt"}},"custom_column_names":{"cache_control":"cacheControl","created_at":"createdAt","download_expiration":"downloadExpiration","id":"id","max_upload_file_size":"maxUploadFileSize","min_upload_file_size":"minUploadFileSize","presigned_urls_enabled":"presignedUrlsEnabled","updated_at":"updatedAt"},"custom_name":"buckets","custom_root_fields":{"delete":"deleteBuckets","delete_by_pk":"deleteBucket","insert":"insertBuckets","insert_one":"insertBucket","select":"buckets","select_aggregate":"bucketsAggregate","select_by_pk":"bucket","update":"updateBuckets","update_by_pk":"updateBucket"}},"table":{"name":"buckets","schema":"storage"}},{"configuration":{"column_config":{"bucket_id":{"custom_name":"bucketId"},"created_at":{"custom_name":"createdAt"},"etag":{"custom_name":"etag"},"id":{"custom_name":"id"},"is_uploaded":{"custom_name":"isUploaded"},"mime_type":{"custom_name":"mimeType"},"name":{"custom_name":"name"},"size":{"custom_name":"size"},"updated_at":{"custom_name":"updatedAt"},"uploaded_by_user_id":{"custom_name":"uploadedByUserId"}},"custom_column_names":{"bucket_id":"bucketId","created_at":"createdAt","etag":"etag","id":"id","is_uploaded":"isUploaded","mime_type":"mimeType","name":"name","size":"size","updated_at":"updatedAt","uploaded_by_user_id":"uploadedByUserId"},"custom_name":"files","custom_root_fields":{"delete":"deleteFiles","delete_by_pk":"deleteFile","insert":"insertFiles","insert_one":"insertFile","select":"files","select_aggregate":"filesAggregate","select_by_pk":"file","update":"updateFiles","update_by_pk":"updateFile"}},"object_relationships":[{"name":"bucket","using":{"foreign_key_constraint_on":"bucket_id"}}],"table":{"name":"files","schema":"storage"}}]}],"version":3}	7
\.


--
-- Data for Name: hdb_scheduled_event_invocation_logs; Type: TABLE DATA; Schema: hdb_catalog; Owner: nhost_hasura
--

COPY hdb_catalog.hdb_scheduled_event_invocation_logs (id, event_id, status, request, response, created_at) FROM stdin;
\.


--
-- Data for Name: hdb_scheduled_events; Type: TABLE DATA; Schema: hdb_catalog; Owner: nhost_hasura
--

COPY hdb_catalog.hdb_scheduled_events (id, webhook_conf, scheduled_time, retry_conf, payload, header_conf, status, tries, created_at, next_retry_at, comment) FROM stdin;
\.


--
-- Data for Name: hdb_schema_notifications; Type: TABLE DATA; Schema: hdb_catalog; Owner: nhost_hasura
--

COPY hdb_catalog.hdb_schema_notifications (id, notification, resource_version, instance_id, updated_at) FROM stdin;
1	{"metadata":false,"remote_schemas":[],"sources":[],"data_connectors":[]}	7	3e840c3c-50b3-4860-9c05-dca4f48ff1ba	2023-07-25 11:03:01.079463+00
\.


--
-- Data for Name: hdb_version; Type: TABLE DATA; Schema: hdb_catalog; Owner: nhost_hasura
--

COPY hdb_catalog.hdb_version (hasura_uuid, version, upgraded_on, cli_state, console_state, ee_client_id, ee_client_secret) FROM stdin;
1ca70c2d-0475-468f-ae8c-7b2a1ae60887	48	2023-07-25 11:02:37.40342+00	{}	{}	\N	\N
\.


--
-- Data for Name: TestTable; Type: TABLE DATA; Schema: public; Owner: nhost_hasura
--

COPY public."TestTable" (string, "integer", "boolean", date) FROM stdin;
Hello World	42	f	2004-03-30
\.


--
-- Data for Name: buckets; Type: TABLE DATA; Schema: storage; Owner: nhost_storage_admin
--

COPY storage.buckets (id, created_at, updated_at, download_expiration, min_upload_file_size, max_upload_file_size, cache_control, presigned_urls_enabled) FROM stdin;
default	2023-07-25 11:02:53.50777+00	2023-07-25 11:02:53.50777+00	30	1	50000000	max-age=3600	t
\.


--
-- Data for Name: files; Type: TABLE DATA; Schema: storage; Owner: nhost_storage_admin
--

COPY storage.files (id, created_at, updated_at, bucket_id, name, size, mime_type, etag, is_uploaded, uploaded_by_user_id) FROM stdin;
ea86457d-594b-4ea0-bb63-3b3fbc08ff47	2023-07-25 12:13:07.057526+00	2023-07-25 12:13:07.221984+00	default	tulips.png	679233	image/png	"2e57bf7a8a9bc49b3eacca90c921a4ae"	t	\N
\.


--
-- Data for Name: schema_migrations; Type: TABLE DATA; Schema: storage; Owner: nhost_storage_admin
--

COPY storage.schema_migrations (version, dirty) FROM stdin;
3	f
\.


--
-- Name: migrations migrations_name_key; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.migrations
    ADD CONSTRAINT migrations_name_key UNIQUE (name);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: provider_requests provider_requests_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.provider_requests
    ADD CONSTRAINT provider_requests_pkey PRIMARY KEY (id);


--
-- Name: providers providers_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.providers
    ADD CONSTRAINT providers_pkey PRIMARY KEY (id);


--
-- Name: refresh_token_types refresh_token_types_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.refresh_token_types
    ADD CONSTRAINT refresh_token_types_pkey PRIMARY KEY (value);


--
-- Name: refresh_tokens refresh_tokens_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.refresh_tokens
    ADD CONSTRAINT refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (role);


--
-- Name: user_providers user_providers_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_providers
    ADD CONSTRAINT user_providers_pkey PRIMARY KEY (id);


--
-- Name: user_providers user_providers_provider_id_provider_user_id_key; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_providers
    ADD CONSTRAINT user_providers_provider_id_provider_user_id_key UNIQUE (provider_id, provider_user_id);


--
-- Name: user_providers user_providers_user_id_provider_id_key; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_providers
    ADD CONSTRAINT user_providers_user_id_provider_id_key UNIQUE (user_id, provider_id);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (id);


--
-- Name: user_roles user_roles_user_id_role_key; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_roles
    ADD CONSTRAINT user_roles_user_id_role_key UNIQUE (user_id, role);


--
-- Name: user_security_keys user_security_key_credential_id_key; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_security_keys
    ADD CONSTRAINT user_security_key_credential_id_key UNIQUE (credential_id);


--
-- Name: user_security_keys user_security_keys_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_security_keys
    ADD CONSTRAINT user_security_keys_pkey PRIMARY KEY (id);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_phone_number_key; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT users_phone_number_key UNIQUE (phone_number);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: hdb_action_log hdb_action_log_pkey; Type: CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_action_log
    ADD CONSTRAINT hdb_action_log_pkey PRIMARY KEY (id);


--
-- Name: hdb_cron_event_invocation_logs hdb_cron_event_invocation_logs_pkey; Type: CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_cron_event_invocation_logs
    ADD CONSTRAINT hdb_cron_event_invocation_logs_pkey PRIMARY KEY (id);


--
-- Name: hdb_cron_events hdb_cron_events_pkey; Type: CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_cron_events
    ADD CONSTRAINT hdb_cron_events_pkey PRIMARY KEY (id);


--
-- Name: hdb_metadata hdb_metadata_pkey; Type: CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_metadata
    ADD CONSTRAINT hdb_metadata_pkey PRIMARY KEY (id);


--
-- Name: hdb_metadata hdb_metadata_resource_version_key; Type: CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_metadata
    ADD CONSTRAINT hdb_metadata_resource_version_key UNIQUE (resource_version);


--
-- Name: hdb_scheduled_event_invocation_logs hdb_scheduled_event_invocation_logs_pkey; Type: CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_scheduled_event_invocation_logs
    ADD CONSTRAINT hdb_scheduled_event_invocation_logs_pkey PRIMARY KEY (id);


--
-- Name: hdb_scheduled_events hdb_scheduled_events_pkey; Type: CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_scheduled_events
    ADD CONSTRAINT hdb_scheduled_events_pkey PRIMARY KEY (id);


--
-- Name: hdb_schema_notifications hdb_schema_notifications_pkey; Type: CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_schema_notifications
    ADD CONSTRAINT hdb_schema_notifications_pkey PRIMARY KEY (id);


--
-- Name: hdb_version hdb_version_pkey; Type: CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_version
    ADD CONSTRAINT hdb_version_pkey PRIMARY KEY (hasura_uuid);


--
-- Name: TestTable TestTable_pkey; Type: CONSTRAINT; Schema: public; Owner: nhost_hasura
--

ALTER TABLE ONLY public."TestTable"
    ADD CONSTRAINT "TestTable_pkey" PRIMARY KEY (string);


--
-- Name: buckets buckets_pkey; Type: CONSTRAINT; Schema: storage; Owner: nhost_storage_admin
--

ALTER TABLE ONLY storage.buckets
    ADD CONSTRAINT buckets_pkey PRIMARY KEY (id);


--
-- Name: files files_pkey; Type: CONSTRAINT; Schema: storage; Owner: nhost_storage_admin
--

ALTER TABLE ONLY storage.files
    ADD CONSTRAINT files_pkey PRIMARY KEY (id);


--
-- Name: schema_migrations schema_migrations_pkey; Type: CONSTRAINT; Schema: storage; Owner: nhost_storage_admin
--

ALTER TABLE ONLY storage.schema_migrations
    ADD CONSTRAINT schema_migrations_pkey PRIMARY KEY (version);


--
-- Name: hdb_cron_event_invocation_event_id; Type: INDEX; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE INDEX hdb_cron_event_invocation_event_id ON hdb_catalog.hdb_cron_event_invocation_logs USING btree (event_id);


--
-- Name: hdb_cron_event_status; Type: INDEX; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE INDEX hdb_cron_event_status ON hdb_catalog.hdb_cron_events USING btree (status);


--
-- Name: hdb_cron_events_unique_scheduled; Type: INDEX; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE UNIQUE INDEX hdb_cron_events_unique_scheduled ON hdb_catalog.hdb_cron_events USING btree (trigger_name, scheduled_time) WHERE (status = 'scheduled'::text);


--
-- Name: hdb_scheduled_event_status; Type: INDEX; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE INDEX hdb_scheduled_event_status ON hdb_catalog.hdb_scheduled_events USING btree (status);


--
-- Name: hdb_version_one_row; Type: INDEX; Schema: hdb_catalog; Owner: nhost_hasura
--

CREATE UNIQUE INDEX hdb_version_one_row ON hdb_catalog.hdb_version USING btree (((version IS NOT NULL)));


--
-- Name: user_providers set_auth_user_providers_updated_at; Type: TRIGGER; Schema: auth; Owner: nhost_auth_admin
--

CREATE TRIGGER set_auth_user_providers_updated_at BEFORE UPDATE ON auth.user_providers FOR EACH ROW EXECUTE FUNCTION auth.set_current_timestamp_updated_at();


--
-- Name: users set_auth_users_updated_at; Type: TRIGGER; Schema: auth; Owner: nhost_auth_admin
--

CREATE TRIGGER set_auth_users_updated_at BEFORE UPDATE ON auth.users FOR EACH ROW EXECUTE FUNCTION auth.set_current_timestamp_updated_at();


--
-- Name: buckets check_default_bucket_delete; Type: TRIGGER; Schema: storage; Owner: nhost_storage_admin
--

CREATE TRIGGER check_default_bucket_delete BEFORE DELETE ON storage.buckets FOR EACH ROW EXECUTE FUNCTION storage.protect_default_bucket_delete();


--
-- Name: buckets check_default_bucket_update; Type: TRIGGER; Schema: storage; Owner: nhost_storage_admin
--

CREATE TRIGGER check_default_bucket_update BEFORE UPDATE ON storage.buckets FOR EACH ROW EXECUTE FUNCTION storage.protect_default_bucket_update();


--
-- Name: buckets set_storage_buckets_updated_at; Type: TRIGGER; Schema: storage; Owner: nhost_storage_admin
--

CREATE TRIGGER set_storage_buckets_updated_at BEFORE UPDATE ON storage.buckets FOR EACH ROW EXECUTE FUNCTION storage.set_current_timestamp_updated_at();


--
-- Name: files set_storage_files_updated_at; Type: TRIGGER; Schema: storage; Owner: nhost_storage_admin
--

CREATE TRIGGER set_storage_files_updated_at BEFORE UPDATE ON storage.files FOR EACH ROW EXECUTE FUNCTION storage.set_current_timestamp_updated_at();


--
-- Name: users fk_default_role; Type: FK CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT fk_default_role FOREIGN KEY (default_role) REFERENCES auth.roles(role) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: user_providers fk_provider; Type: FK CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_providers
    ADD CONSTRAINT fk_provider FOREIGN KEY (provider_id) REFERENCES auth.providers(id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: user_roles fk_role; Type: FK CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_roles
    ADD CONSTRAINT fk_role FOREIGN KEY (role) REFERENCES auth.roles(role) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- Name: user_providers fk_user; Type: FK CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_providers
    ADD CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES auth.users(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: user_roles fk_user; Type: FK CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_roles
    ADD CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES auth.users(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: refresh_tokens fk_user; Type: FK CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.refresh_tokens
    ADD CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES auth.users(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: user_security_keys fk_user; Type: FK CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.user_security_keys
    ADD CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES auth.users(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: refresh_tokens refresh_tokens_types_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: nhost_auth_admin
--

ALTER TABLE ONLY auth.refresh_tokens
    ADD CONSTRAINT refresh_tokens_types_fkey FOREIGN KEY (type) REFERENCES auth.refresh_token_types(value) ON UPDATE RESTRICT ON DELETE RESTRICT;


--
-- Name: hdb_cron_event_invocation_logs hdb_cron_event_invocation_logs_event_id_fkey; Type: FK CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_cron_event_invocation_logs
    ADD CONSTRAINT hdb_cron_event_invocation_logs_event_id_fkey FOREIGN KEY (event_id) REFERENCES hdb_catalog.hdb_cron_events(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: hdb_scheduled_event_invocation_logs hdb_scheduled_event_invocation_logs_event_id_fkey; Type: FK CONSTRAINT; Schema: hdb_catalog; Owner: nhost_hasura
--

ALTER TABLE ONLY hdb_catalog.hdb_scheduled_event_invocation_logs
    ADD CONSTRAINT hdb_scheduled_event_invocation_logs_event_id_fkey FOREIGN KEY (event_id) REFERENCES hdb_catalog.hdb_scheduled_events(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: files fk_bucket; Type: FK CONSTRAINT; Schema: storage; Owner: nhost_storage_admin
--

ALTER TABLE ONLY storage.files
    ADD CONSTRAINT fk_bucket FOREIGN KEY (bucket_id) REFERENCES storage.buckets(id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: SCHEMA auth; Type: ACL; Schema: -; Owner: nhost_admin
--

GRANT ALL ON SCHEMA auth TO nhost_auth_admin;
GRANT USAGE ON SCHEMA auth TO nhost_hasura;


--
-- Name: SCHEMA pgbouncer; Type: ACL; Schema: -; Owner: nhost_admin
--

GRANT USAGE ON SCHEMA pgbouncer TO pgbouncer;


--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: postgres
--

REVOKE USAGE ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO PUBLIC;
GRANT USAGE ON SCHEMA public TO nhost_hasura;


--
-- Name: SCHEMA storage; Type: ACL; Schema: -; Owner: nhost_admin
--

GRANT ALL ON SCHEMA storage TO nhost_storage_admin;
GRANT USAGE ON SCHEMA storage TO nhost_hasura;


--
-- Name: FUNCTION user_lookup(i_username text, OUT uname text, OUT phash text); Type: ACL; Schema: pgbouncer; Owner: postgres
--

REVOKE ALL ON FUNCTION pgbouncer.user_lookup(i_username text, OUT uname text, OUT phash text) FROM PUBLIC;
GRANT ALL ON FUNCTION pgbouncer.user_lookup(i_username text, OUT uname text, OUT phash text) TO pgbouncer;


--
-- Name: TABLE migrations; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.migrations TO nhost_hasura;


--
-- Name: TABLE provider_requests; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.provider_requests TO nhost_hasura;


--
-- Name: TABLE providers; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.providers TO nhost_hasura;


--
-- Name: TABLE refresh_token_types; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.refresh_token_types TO nhost_hasura;


--
-- Name: TABLE refresh_tokens; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.refresh_tokens TO nhost_hasura;


--
-- Name: TABLE roles; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.roles TO nhost_hasura;


--
-- Name: TABLE user_providers; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.user_providers TO nhost_hasura;


--
-- Name: TABLE user_roles; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.user_roles TO nhost_hasura;


--
-- Name: TABLE user_security_keys; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.user_security_keys TO nhost_hasura;


--
-- Name: TABLE users; Type: ACL; Schema: auth; Owner: nhost_auth_admin
--

GRANT ALL ON TABLE auth.users TO nhost_hasura;


--
-- Name: TABLE pg_aggregate; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_aggregate TO nhost_hasura;


--
-- Name: TABLE pg_am; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_am TO nhost_hasura;


--
-- Name: TABLE pg_amop; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_amop TO nhost_hasura;


--
-- Name: TABLE pg_amproc; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_amproc TO nhost_hasura;


--
-- Name: TABLE pg_attrdef; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_attrdef TO nhost_hasura;


--
-- Name: TABLE pg_attribute; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_attribute TO nhost_hasura;


--
-- Name: TABLE pg_auth_members; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_auth_members TO nhost_hasura;


--
-- Name: TABLE pg_authid; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_authid TO nhost_hasura;


--
-- Name: TABLE pg_available_extension_versions; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_available_extension_versions TO nhost_hasura;


--
-- Name: TABLE pg_available_extensions; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_available_extensions TO nhost_hasura;


--
-- Name: TABLE pg_backend_memory_contexts; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_backend_memory_contexts TO nhost_hasura;


--
-- Name: TABLE pg_cast; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_cast TO nhost_hasura;


--
-- Name: TABLE pg_class; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_class TO nhost_hasura;


--
-- Name: TABLE pg_collation; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_collation TO nhost_hasura;


--
-- Name: TABLE pg_config; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_config TO nhost_hasura;


--
-- Name: TABLE pg_constraint; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_constraint TO nhost_hasura;


--
-- Name: TABLE pg_conversion; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_conversion TO nhost_hasura;


--
-- Name: TABLE pg_cursors; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_cursors TO nhost_hasura;


--
-- Name: TABLE pg_database; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_database TO nhost_hasura;


--
-- Name: TABLE pg_db_role_setting; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_db_role_setting TO nhost_hasura;


--
-- Name: TABLE pg_default_acl; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_default_acl TO nhost_hasura;


--
-- Name: TABLE pg_depend; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_depend TO nhost_hasura;


--
-- Name: TABLE pg_description; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_description TO nhost_hasura;


--
-- Name: TABLE pg_enum; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_enum TO nhost_hasura;


--
-- Name: TABLE pg_event_trigger; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_event_trigger TO nhost_hasura;


--
-- Name: TABLE pg_extension; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_extension TO nhost_hasura;


--
-- Name: TABLE pg_file_settings; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_file_settings TO nhost_hasura;


--
-- Name: TABLE pg_foreign_data_wrapper; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_foreign_data_wrapper TO nhost_hasura;


--
-- Name: TABLE pg_foreign_server; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_foreign_server TO nhost_hasura;


--
-- Name: TABLE pg_foreign_table; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_foreign_table TO nhost_hasura;


--
-- Name: TABLE pg_group; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_group TO nhost_hasura;


--
-- Name: TABLE pg_hba_file_rules; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_hba_file_rules TO nhost_hasura;


--
-- Name: TABLE pg_index; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_index TO nhost_hasura;


--
-- Name: TABLE pg_indexes; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_indexes TO nhost_hasura;


--
-- Name: TABLE pg_inherits; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_inherits TO nhost_hasura;


--
-- Name: TABLE pg_init_privs; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_init_privs TO nhost_hasura;


--
-- Name: TABLE pg_language; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_language TO nhost_hasura;


--
-- Name: TABLE pg_largeobject; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_largeobject TO nhost_hasura;


--
-- Name: TABLE pg_largeobject_metadata; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_largeobject_metadata TO nhost_hasura;


--
-- Name: TABLE pg_locks; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_locks TO nhost_hasura;


--
-- Name: TABLE pg_matviews; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_matviews TO nhost_hasura;


--
-- Name: TABLE pg_namespace; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_namespace TO nhost_hasura;


--
-- Name: TABLE pg_opclass; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_opclass TO nhost_hasura;


--
-- Name: TABLE pg_operator; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_operator TO nhost_hasura;


--
-- Name: TABLE pg_opfamily; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_opfamily TO nhost_hasura;


--
-- Name: TABLE pg_partitioned_table; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_partitioned_table TO nhost_hasura;


--
-- Name: TABLE pg_policies; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_policies TO nhost_hasura;


--
-- Name: TABLE pg_policy; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_policy TO nhost_hasura;


--
-- Name: TABLE pg_prepared_statements; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_prepared_statements TO nhost_hasura;


--
-- Name: TABLE pg_prepared_xacts; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_prepared_xacts TO nhost_hasura;


--
-- Name: TABLE pg_proc; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_proc TO nhost_hasura;


--
-- Name: TABLE pg_publication; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_publication TO nhost_hasura;


--
-- Name: TABLE pg_publication_rel; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_publication_rel TO nhost_hasura;


--
-- Name: TABLE pg_publication_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_publication_tables TO nhost_hasura;


--
-- Name: TABLE pg_range; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_range TO nhost_hasura;


--
-- Name: TABLE pg_replication_origin; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_replication_origin TO nhost_hasura;


--
-- Name: TABLE pg_replication_origin_status; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_replication_origin_status TO nhost_hasura;


--
-- Name: TABLE pg_replication_slots; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_replication_slots TO nhost_hasura;


--
-- Name: TABLE pg_rewrite; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_rewrite TO nhost_hasura;


--
-- Name: TABLE pg_roles; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_roles TO nhost_hasura;


--
-- Name: TABLE pg_rules; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_rules TO nhost_hasura;


--
-- Name: TABLE pg_seclabel; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_seclabel TO nhost_hasura;


--
-- Name: TABLE pg_seclabels; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_seclabels TO nhost_hasura;


--
-- Name: TABLE pg_sequence; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_sequence TO nhost_hasura;


--
-- Name: TABLE pg_sequences; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_sequences TO nhost_hasura;


--
-- Name: TABLE pg_settings; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_settings TO nhost_hasura;


--
-- Name: TABLE pg_shadow; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_shadow TO nhost_hasura;


--
-- Name: TABLE pg_shdepend; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_shdepend TO nhost_hasura;


--
-- Name: TABLE pg_shdescription; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_shdescription TO nhost_hasura;


--
-- Name: TABLE pg_shmem_allocations; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_shmem_allocations TO nhost_hasura;


--
-- Name: TABLE pg_shseclabel; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_shseclabel TO nhost_hasura;


--
-- Name: TABLE pg_stat_activity; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_activity TO nhost_hasura;


--
-- Name: TABLE pg_stat_all_indexes; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_all_indexes TO nhost_hasura;


--
-- Name: TABLE pg_stat_all_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_all_tables TO nhost_hasura;


--
-- Name: TABLE pg_stat_archiver; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_archiver TO nhost_hasura;


--
-- Name: TABLE pg_stat_bgwriter; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_bgwriter TO nhost_hasura;


--
-- Name: TABLE pg_stat_database; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_database TO nhost_hasura;


--
-- Name: TABLE pg_stat_database_conflicts; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_database_conflicts TO nhost_hasura;


--
-- Name: TABLE pg_stat_gssapi; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_gssapi TO nhost_hasura;


--
-- Name: TABLE pg_stat_progress_analyze; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_progress_analyze TO nhost_hasura;


--
-- Name: TABLE pg_stat_progress_basebackup; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_progress_basebackup TO nhost_hasura;


--
-- Name: TABLE pg_stat_progress_cluster; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_progress_cluster TO nhost_hasura;


--
-- Name: TABLE pg_stat_progress_copy; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_progress_copy TO nhost_hasura;


--
-- Name: TABLE pg_stat_progress_create_index; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_progress_create_index TO nhost_hasura;


--
-- Name: TABLE pg_stat_progress_vacuum; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_progress_vacuum TO nhost_hasura;


--
-- Name: TABLE pg_stat_replication; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_replication TO nhost_hasura;


--
-- Name: TABLE pg_stat_replication_slots; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_replication_slots TO nhost_hasura;


--
-- Name: TABLE pg_stat_slru; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_slru TO nhost_hasura;


--
-- Name: TABLE pg_stat_ssl; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_ssl TO nhost_hasura;


--
-- Name: TABLE pg_stat_subscription; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_subscription TO nhost_hasura;


--
-- Name: TABLE pg_stat_sys_indexes; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_sys_indexes TO nhost_hasura;


--
-- Name: TABLE pg_stat_sys_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_sys_tables TO nhost_hasura;


--
-- Name: TABLE pg_stat_user_functions; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_user_functions TO nhost_hasura;


--
-- Name: TABLE pg_stat_user_indexes; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_user_indexes TO nhost_hasura;


--
-- Name: TABLE pg_stat_user_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_user_tables TO nhost_hasura;


--
-- Name: TABLE pg_stat_wal; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_wal TO nhost_hasura;


--
-- Name: TABLE pg_stat_wal_receiver; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_wal_receiver TO nhost_hasura;


--
-- Name: TABLE pg_stat_xact_all_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_xact_all_tables TO nhost_hasura;


--
-- Name: TABLE pg_stat_xact_sys_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_xact_sys_tables TO nhost_hasura;


--
-- Name: TABLE pg_stat_xact_user_functions; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_xact_user_functions TO nhost_hasura;


--
-- Name: TABLE pg_stat_xact_user_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stat_xact_user_tables TO nhost_hasura;


--
-- Name: TABLE pg_statio_all_indexes; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statio_all_indexes TO nhost_hasura;


--
-- Name: TABLE pg_statio_all_sequences; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statio_all_sequences TO nhost_hasura;


--
-- Name: TABLE pg_statio_all_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statio_all_tables TO nhost_hasura;


--
-- Name: TABLE pg_statio_sys_indexes; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statio_sys_indexes TO nhost_hasura;


--
-- Name: TABLE pg_statio_sys_sequences; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statio_sys_sequences TO nhost_hasura;


--
-- Name: TABLE pg_statio_sys_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statio_sys_tables TO nhost_hasura;


--
-- Name: TABLE pg_statio_user_indexes; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statio_user_indexes TO nhost_hasura;


--
-- Name: TABLE pg_statio_user_sequences; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statio_user_sequences TO nhost_hasura;


--
-- Name: TABLE pg_statio_user_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statio_user_tables TO nhost_hasura;


--
-- Name: TABLE pg_statistic; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statistic TO nhost_hasura;


--
-- Name: TABLE pg_statistic_ext; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statistic_ext TO nhost_hasura;


--
-- Name: TABLE pg_statistic_ext_data; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_statistic_ext_data TO nhost_hasura;


--
-- Name: TABLE pg_stats; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stats TO nhost_hasura;


--
-- Name: TABLE pg_stats_ext; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stats_ext TO nhost_hasura;


--
-- Name: TABLE pg_stats_ext_exprs; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_stats_ext_exprs TO nhost_hasura;


--
-- Name: TABLE pg_subscription; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_subscription TO nhost_hasura;


--
-- Name: TABLE pg_subscription_rel; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_subscription_rel TO nhost_hasura;


--
-- Name: TABLE pg_tables; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_tables TO nhost_hasura;


--
-- Name: TABLE pg_tablespace; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_tablespace TO nhost_hasura;


--
-- Name: TABLE pg_timezone_abbrevs; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_timezone_abbrevs TO nhost_hasura;


--
-- Name: TABLE pg_timezone_names; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_timezone_names TO nhost_hasura;


--
-- Name: TABLE pg_transform; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_transform TO nhost_hasura;


--
-- Name: TABLE pg_trigger; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_trigger TO nhost_hasura;


--
-- Name: TABLE pg_ts_config; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_ts_config TO nhost_hasura;


--
-- Name: TABLE pg_ts_config_map; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_ts_config_map TO nhost_hasura;


--
-- Name: TABLE pg_ts_dict; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_ts_dict TO nhost_hasura;


--
-- Name: TABLE pg_ts_parser; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_ts_parser TO nhost_hasura;


--
-- Name: TABLE pg_ts_template; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_ts_template TO nhost_hasura;


--
-- Name: TABLE pg_type; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_type TO nhost_hasura;


--
-- Name: TABLE pg_user; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_user TO nhost_hasura;


--
-- Name: TABLE pg_user_mapping; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_user_mapping TO nhost_hasura;


--
-- Name: TABLE pg_user_mappings; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_user_mappings TO nhost_hasura;


--
-- Name: TABLE pg_views; Type: ACL; Schema: pg_catalog; Owner: postgres
--

GRANT SELECT ON TABLE pg_catalog.pg_views TO nhost_hasura;


--
-- Name: TABLE buckets; Type: ACL; Schema: storage; Owner: nhost_storage_admin
--

GRANT ALL ON TABLE storage.buckets TO nhost_hasura;


--
-- Name: TABLE files; Type: ACL; Schema: storage; Owner: nhost_storage_admin
--

GRANT ALL ON TABLE storage.files TO nhost_hasura;


--
-- Name: TABLE schema_migrations; Type: ACL; Schema: storage; Owner: nhost_storage_admin
--

GRANT ALL ON TABLE storage.schema_migrations TO nhost_hasura;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: auth; Owner: nhost_auth_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE nhost_auth_admin IN SCHEMA auth GRANT ALL ON TABLES  TO nhost_hasura;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: storage; Owner: nhost_storage_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE nhost_storage_admin IN SCHEMA storage GRANT ALL ON TABLES  TO nhost_hasura;


--
-- PostgreSQL database dump complete
--

