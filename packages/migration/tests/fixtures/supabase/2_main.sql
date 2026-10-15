--
-- PostgreSQL database dump
--

-- Dumped from database version 15.1 (Ubuntu 15.1-1.pgdg20.04+1)
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


ALTER SCHEMA auth OWNER TO supabase_admin;

--
-- Name: extensions; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA extensions;


ALTER SCHEMA extensions OWNER TO postgres;

--
-- Name: graphql; Type: SCHEMA; Schema: -; Owner: supabase_admin
--

CREATE SCHEMA graphql;


ALTER SCHEMA graphql OWNER TO supabase_admin;

--
-- Name: graphql_public; Type: SCHEMA; Schema: -; Owner: supabase_admin
--

CREATE SCHEMA graphql_public;


ALTER SCHEMA graphql_public OWNER TO supabase_admin;

--
-- Name: pgsodium; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA pgsodium;


ALTER SCHEMA pgsodium OWNER TO postgres;

--
-- Name: pgsodium; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgsodium WITH SCHEMA pgsodium;


--
-- Name: EXTENSION pgsodium; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgsodium IS 'Pgsodium is a modern cryptography library for Postgres.';


--
-- Name: pgtle; Type: SCHEMA; Schema: -; Owner: supabase_admin
--

CREATE SCHEMA pgtle;


ALTER SCHEMA pgtle OWNER TO supabase_admin;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: postgres
--

-- *not* creating schema, since initdb creates it


ALTER SCHEMA public OWNER TO postgres;

--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: postgres
--

COMMENT ON SCHEMA public IS '';


--
-- Name: realtime; Type: SCHEMA; Schema: -; Owner: supabase_admin
--

CREATE SCHEMA realtime;


ALTER SCHEMA realtime OWNER TO supabase_admin;

--
-- Name: storage; Type: SCHEMA; Schema: -; Owner: supabase_admin
--

CREATE SCHEMA storage;


ALTER SCHEMA storage OWNER TO supabase_admin;

--
-- Name: vault; Type: SCHEMA; Schema: -; Owner: supabase_admin
--

CREATE SCHEMA vault;


ALTER SCHEMA vault OWNER TO supabase_admin;

--
-- Name: pg_graphql; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_graphql WITH SCHEMA graphql;


--
-- Name: EXTENSION pg_graphql; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pg_graphql IS 'pg_graphql: GraphQL support';


--
-- Name: pg_stat_statements; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_stat_statements WITH SCHEMA extensions;


--
-- Name: EXTENSION pg_stat_statements; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pg_stat_statements IS 'track planning and execution statistics of all SQL statements executed';


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA extensions;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: pgjwt; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgjwt WITH SCHEMA extensions;


--
-- Name: EXTENSION pgjwt; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pgjwt IS 'JSON Web Token API for Postgresql';


--
-- Name: supabase_vault; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS supabase_vault WITH SCHEMA vault;


--
-- Name: EXTENSION supabase_vault; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION supabase_vault IS 'Supabase Vault Extension';


--
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA extensions;


--
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- Name: aal_level; Type: TYPE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TYPE auth.aal_level AS ENUM (
    'aal1',
    'aal2',
    'aal3'
);


ALTER TYPE auth.aal_level OWNER TO supabase_auth_admin;

--
-- Name: code_challenge_method; Type: TYPE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TYPE auth.code_challenge_method AS ENUM (
    's256',
    'plain'
);


ALTER TYPE auth.code_challenge_method OWNER TO supabase_auth_admin;

--
-- Name: factor_status; Type: TYPE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TYPE auth.factor_status AS ENUM (
    'unverified',
    'verified'
);


ALTER TYPE auth.factor_status OWNER TO supabase_auth_admin;

--
-- Name: factor_type; Type: TYPE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TYPE auth.factor_type AS ENUM (
    'totp',
    'webauthn'
);


ALTER TYPE auth.factor_type OWNER TO supabase_auth_admin;

--
-- Name: email(); Type: FUNCTION; Schema: auth; Owner: supabase_auth_admin
--

CREATE FUNCTION auth.email() RETURNS text
    LANGUAGE sql STABLE
    AS $$
  select 
  	coalesce(
		nullif(current_setting('request.jwt.claim.email', true), ''),
		(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'email')
	)::text
$$;


ALTER FUNCTION auth.email() OWNER TO supabase_auth_admin;

--
-- Name: FUNCTION email(); Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON FUNCTION auth.email() IS 'Deprecated. Use auth.jwt() -> ''email'' instead.';


--
-- Name: jwt(); Type: FUNCTION; Schema: auth; Owner: supabase_auth_admin
--

CREATE FUNCTION auth.jwt() RETURNS jsonb
    LANGUAGE sql STABLE
    AS $$
  select 
    coalesce(
        nullif(current_setting('request.jwt.claim', true), ''),
        nullif(current_setting('request.jwt.claims', true), '')
    )::jsonb
$$;


ALTER FUNCTION auth.jwt() OWNER TO supabase_auth_admin;

--
-- Name: role(); Type: FUNCTION; Schema: auth; Owner: supabase_auth_admin
--

CREATE FUNCTION auth.role() RETURNS text
    LANGUAGE sql STABLE
    AS $$
  select 
  	coalesce(
		nullif(current_setting('request.jwt.claim.role', true), ''),
		(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'role')
	)::text
$$;


ALTER FUNCTION auth.role() OWNER TO supabase_auth_admin;

--
-- Name: FUNCTION role(); Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON FUNCTION auth.role() IS 'Deprecated. Use auth.jwt() -> ''role'' instead.';


--
-- Name: uid(); Type: FUNCTION; Schema: auth; Owner: supabase_auth_admin
--

CREATE FUNCTION auth.uid() RETURNS uuid
    LANGUAGE sql STABLE
    AS $$
  select 
  	coalesce(
		nullif(current_setting('request.jwt.claim.sub', true), ''),
		(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'sub')
	)::uuid
$$;


ALTER FUNCTION auth.uid() OWNER TO supabase_auth_admin;

--
-- Name: FUNCTION uid(); Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON FUNCTION auth.uid() IS 'Deprecated. Use auth.jwt() -> ''sub'' instead.';


--
-- Name: grant_pg_cron_access(); Type: FUNCTION; Schema: extensions; Owner: postgres
--

CREATE FUNCTION extensions.grant_pg_cron_access() RETURNS event_trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  schema_is_cron bool;
BEGIN
  schema_is_cron = (
    SELECT n.nspname = 'cron'
    FROM pg_event_trigger_ddl_commands() AS ev
    LEFT JOIN pg_catalog.pg_namespace AS n
      ON ev.objid = n.oid
  );

  IF schema_is_cron
  THEN
    grant usage on schema cron to postgres with grant option;

    alter default privileges in schema cron grant all on tables to postgres with grant option;
    alter default privileges in schema cron grant all on functions to postgres with grant option;
    alter default privileges in schema cron grant all on sequences to postgres with grant option;

    alter default privileges for user supabase_admin in schema cron grant all
        on sequences to postgres with grant option;
    alter default privileges for user supabase_admin in schema cron grant all
        on tables to postgres with grant option;
    alter default privileges for user supabase_admin in schema cron grant all
        on functions to postgres with grant option;

    grant all privileges on all tables in schema cron to postgres with grant option; 

  END IF;

END;
$$;


ALTER FUNCTION extensions.grant_pg_cron_access() OWNER TO postgres;

--
-- Name: FUNCTION grant_pg_cron_access(); Type: COMMENT; Schema: extensions; Owner: postgres
--

COMMENT ON FUNCTION extensions.grant_pg_cron_access() IS 'Grants access to pg_cron';


--
-- Name: grant_pg_graphql_access(); Type: FUNCTION; Schema: extensions; Owner: supabase_admin
--

CREATE FUNCTION extensions.grant_pg_graphql_access() RETURNS event_trigger
    LANGUAGE plpgsql
    AS $_$
DECLARE
    func_is_graphql_resolve bool;
BEGIN
    func_is_graphql_resolve = (
        SELECT n.proname = 'resolve'
        FROM pg_event_trigger_ddl_commands() AS ev
        LEFT JOIN pg_catalog.pg_proc AS n
        ON ev.objid = n.oid
    );

    IF func_is_graphql_resolve
    THEN
        -- Update public wrapper to pass all arguments through to the pg_graphql resolve func
        DROP FUNCTION IF EXISTS graphql_public.graphql;
        create or replace function graphql_public.graphql(
            "operationName" text default null,
            query text default null,
            variables jsonb default null,
            extensions jsonb default null
        )
            returns jsonb
            language sql
        as $$
            select graphql.resolve(
                query := query,
                variables := coalesce(variables, '{}'),
                "operationName" := "operationName",
                extensions := extensions
            );
        $$;

        -- This hook executes when `graphql.resolve` is created. That is not necessarily the last
        -- function in the extension so we need to grant permissions on existing entities AND
        -- update default permissions to any others that are created after `graphql.resolve`
        grant usage on schema graphql to postgres, anon, authenticated, service_role;
        grant select on all tables in schema graphql to postgres, anon, authenticated, service_role;
        grant execute on all functions in schema graphql to postgres, anon, authenticated, service_role;
        grant all on all sequences in schema graphql to postgres, anon, authenticated, service_role;
        alter default privileges in schema graphql grant all on tables to postgres, anon, authenticated, service_role;
        alter default privileges in schema graphql grant all on functions to postgres, anon, authenticated, service_role;
        alter default privileges in schema graphql grant all on sequences to postgres, anon, authenticated, service_role;
    END IF;

END;
$_$;


ALTER FUNCTION extensions.grant_pg_graphql_access() OWNER TO supabase_admin;

--
-- Name: FUNCTION grant_pg_graphql_access(); Type: COMMENT; Schema: extensions; Owner: supabase_admin
--

COMMENT ON FUNCTION extensions.grant_pg_graphql_access() IS 'Grants access to pg_graphql';


--
-- Name: grant_pg_net_access(); Type: FUNCTION; Schema: extensions; Owner: postgres
--

CREATE FUNCTION extensions.grant_pg_net_access() RETURNS event_trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  IF EXISTS (
    SELECT 1
    FROM pg_event_trigger_ddl_commands() AS ev
    JOIN pg_extension AS ext
    ON ev.objid = ext.oid
    WHERE ext.extname = 'pg_net'
  )
  THEN
    IF NOT EXISTS (
      SELECT 1
      FROM pg_roles
      WHERE rolname = 'supabase_functions_admin'
    )
    THEN
      CREATE USER supabase_functions_admin NOINHERIT CREATEROLE LOGIN NOREPLICATION;
    END IF;

    GRANT USAGE ON SCHEMA net TO supabase_functions_admin, postgres, anon, authenticated, service_role;

    ALTER function net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) SECURITY DEFINER;
    ALTER function net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) SECURITY DEFINER;
    ALTER function net.http_collect_response(request_id bigint, async boolean) SECURITY DEFINER;

    ALTER function net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) SET search_path = net;
    ALTER function net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) SET search_path = net;
    ALTER function net.http_collect_response(request_id bigint, async boolean) SET search_path = net;

    REVOKE ALL ON FUNCTION net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) FROM PUBLIC;
    REVOKE ALL ON FUNCTION net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) FROM PUBLIC;
    REVOKE ALL ON FUNCTION net.http_collect_response(request_id bigint, async boolean) FROM PUBLIC;

    GRANT EXECUTE ON FUNCTION net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) TO supabase_functions_admin, postgres, anon, authenticated, service_role;
    GRANT EXECUTE ON FUNCTION net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) TO supabase_functions_admin, postgres, anon, authenticated, service_role;
    GRANT EXECUTE ON FUNCTION net.http_collect_response(request_id bigint, async boolean) TO supabase_functions_admin, postgres, anon, authenticated, service_role;
  END IF;
END;
$$;


ALTER FUNCTION extensions.grant_pg_net_access() OWNER TO postgres;

--
-- Name: FUNCTION grant_pg_net_access(); Type: COMMENT; Schema: extensions; Owner: postgres
--

COMMENT ON FUNCTION extensions.grant_pg_net_access() IS 'Grants access to pg_net';


--
-- Name: pgrst_ddl_watch(); Type: FUNCTION; Schema: extensions; Owner: supabase_admin
--

CREATE FUNCTION extensions.pgrst_ddl_watch() RETURNS event_trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  cmd record;
BEGIN
  FOR cmd IN SELECT * FROM pg_event_trigger_ddl_commands()
  LOOP
    IF cmd.command_tag IN (
      'CREATE SCHEMA', 'ALTER SCHEMA'
    , 'CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO', 'ALTER TABLE'
    , 'CREATE FOREIGN TABLE', 'ALTER FOREIGN TABLE'
    , 'CREATE VIEW', 'ALTER VIEW'
    , 'CREATE MATERIALIZED VIEW', 'ALTER MATERIALIZED VIEW'
    , 'CREATE FUNCTION', 'ALTER FUNCTION'
    , 'CREATE TRIGGER'
    , 'CREATE TYPE', 'ALTER TYPE'
    , 'CREATE RULE'
    , 'COMMENT'
    )
    -- don't notify in case of CREATE TEMP table or other objects created on pg_temp
    AND cmd.schema_name is distinct from 'pg_temp'
    THEN
      NOTIFY pgrst, 'reload schema';
    END IF;
  END LOOP;
END; $$;


ALTER FUNCTION extensions.pgrst_ddl_watch() OWNER TO supabase_admin;

--
-- Name: pgrst_drop_watch(); Type: FUNCTION; Schema: extensions; Owner: supabase_admin
--

CREATE FUNCTION extensions.pgrst_drop_watch() RETURNS event_trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  obj record;
BEGIN
  FOR obj IN SELECT * FROM pg_event_trigger_dropped_objects()
  LOOP
    IF obj.object_type IN (
      'schema'
    , 'table'
    , 'foreign table'
    , 'view'
    , 'materialized view'
    , 'function'
    , 'trigger'
    , 'type'
    , 'rule'
    )
    AND obj.is_temporary IS false -- no pg_temp objects
    THEN
      NOTIFY pgrst, 'reload schema';
    END IF;
  END LOOP;
END; $$;


ALTER FUNCTION extensions.pgrst_drop_watch() OWNER TO supabase_admin;

--
-- Name: set_graphql_placeholder(); Type: FUNCTION; Schema: extensions; Owner: supabase_admin
--

CREATE FUNCTION extensions.set_graphql_placeholder() RETURNS event_trigger
    LANGUAGE plpgsql
    AS $_$
    DECLARE
    graphql_is_dropped bool;
    BEGIN
    graphql_is_dropped = (
        SELECT ev.schema_name = 'graphql_public'
        FROM pg_event_trigger_dropped_objects() AS ev
        WHERE ev.schema_name = 'graphql_public'
    );

    IF graphql_is_dropped
    THEN
        create or replace function graphql_public.graphql(
            "operationName" text default null,
            query text default null,
            variables jsonb default null,
            extensions jsonb default null
        )
            returns jsonb
            language plpgsql
        as $$
            DECLARE
                server_version float;
            BEGIN
                server_version = (SELECT (SPLIT_PART((select version()), ' ', 2))::float);

                IF server_version >= 14 THEN
                    RETURN jsonb_build_object(
                        'errors', jsonb_build_array(
                            jsonb_build_object(
                                'message', 'pg_graphql extension is not enabled.'
                            )
                        )
                    );
                ELSE
                    RETURN jsonb_build_object(
                        'errors', jsonb_build_array(
                            jsonb_build_object(
                                'message', 'pg_graphql is only available on projects running Postgres 14 onwards.'
                            )
                        )
                    );
                END IF;
            END;
        $$;
    END IF;

    END;
$_$;


ALTER FUNCTION extensions.set_graphql_placeholder() OWNER TO supabase_admin;

--
-- Name: FUNCTION set_graphql_placeholder(); Type: COMMENT; Schema: extensions; Owner: supabase_admin
--

COMMENT ON FUNCTION extensions.set_graphql_placeholder() IS 'Reintroduces placeholder function for graphql_public.graphql';

--
-- Name: can_insert_object(text, text, uuid, jsonb); Type: FUNCTION; Schema: storage; Owner: supabase_storage_admin
--

CREATE FUNCTION storage.can_insert_object(bucketid text, name text, owner uuid, metadata jsonb) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
  INSERT INTO "storage"."objects" ("bucket_id", "name", "owner", "metadata") VALUES (bucketid, name, owner, metadata);
  -- hack to rollback the successful insert
  RAISE sqlstate 'PT200' using
  message = 'ROLLBACK',
  detail = 'rollback successful insert';
END
$$;


ALTER FUNCTION storage.can_insert_object(bucketid text, name text, owner uuid, metadata jsonb) OWNER TO supabase_storage_admin;

--
-- Name: extension(text); Type: FUNCTION; Schema: storage; Owner: supabase_storage_admin
--

CREATE FUNCTION storage.extension(name text) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
_parts text[];
_filename text;
BEGIN
	select string_to_array(name, '/') into _parts;
	select _parts[array_length(_parts,1)] into _filename;
	-- @todo return the last part instead of 2
	return split_part(_filename, '.', 2);
END
$$;


ALTER FUNCTION storage.extension(name text) OWNER TO supabase_storage_admin;

--
-- Name: filename(text); Type: FUNCTION; Schema: storage; Owner: supabase_storage_admin
--

CREATE FUNCTION storage.filename(name text) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
_parts text[];
BEGIN
	select string_to_array(name, '/') into _parts;
	return _parts[array_length(_parts,1)];
END
$$;


ALTER FUNCTION storage.filename(name text) OWNER TO supabase_storage_admin;

--
-- Name: foldername(text); Type: FUNCTION; Schema: storage; Owner: supabase_storage_admin
--

CREATE FUNCTION storage.foldername(name text) RETURNS text[]
    LANGUAGE plpgsql
    AS $$
DECLARE
_parts text[];
BEGIN
	select string_to_array(name, '/') into _parts;
	return _parts[1:array_length(_parts,1)-1];
END
$$;


ALTER FUNCTION storage.foldername(name text) OWNER TO supabase_storage_admin;

--
-- Name: get_size_by_bucket(); Type: FUNCTION; Schema: storage; Owner: supabase_storage_admin
--

CREATE FUNCTION storage.get_size_by_bucket() RETURNS TABLE(size bigint, bucket_id text)
    LANGUAGE plpgsql
    AS $$
BEGIN
    return query
        select sum((metadata->>'size')::int) as size, obj.bucket_id
        from "storage".objects as obj
        group by obj.bucket_id;
END
$$;


ALTER FUNCTION storage.get_size_by_bucket() OWNER TO supabase_storage_admin;

--
-- Name: search(text, text, integer, integer, integer, text, text, text); Type: FUNCTION; Schema: storage; Owner: supabase_storage_admin
--

CREATE FUNCTION storage.search(prefix text, bucketname text, limits integer DEFAULT 100, levels integer DEFAULT 1, offsets integer DEFAULT 0, search text DEFAULT ''::text, sortcolumn text DEFAULT 'name'::text, sortorder text DEFAULT 'asc'::text) RETURNS TABLE(name text, id uuid, updated_at timestamp with time zone, created_at timestamp with time zone, last_accessed_at timestamp with time zone, metadata jsonb)
    LANGUAGE plpgsql STABLE
    AS $_$
declare
  v_order_by text;
  v_sort_order text;
begin
  case
    when sortcolumn = 'name' then
      v_order_by = 'name';
    when sortcolumn = 'updated_at' then
      v_order_by = 'updated_at';
    when sortcolumn = 'created_at' then
      v_order_by = 'created_at';
    when sortcolumn = 'last_accessed_at' then
      v_order_by = 'last_accessed_at';
    else
      v_order_by = 'name';
  end case;

  case
    when sortorder = 'asc' then
      v_sort_order = 'asc';
    when sortorder = 'desc' then
      v_sort_order = 'desc';
    else
      v_sort_order = 'asc';
  end case;

  v_order_by = v_order_by || ' ' || v_sort_order;

  return query execute
    'with folders as (
       select path_tokens[$1] as folder
       from storage.objects
         where objects.name ilike $2 || $3 || ''%''
           and bucket_id = $4
           and array_length(regexp_split_to_array(objects.name, ''/''), 1) <> $1
       group by folder
       order by folder ' || v_sort_order || '
     )
     (select folder as "name",
            null as id,
            null as updated_at,
            null as created_at,
            null as last_accessed_at,
            null as metadata from folders)
     union all
     (select path_tokens[$1] as "name",
            id,
            updated_at,
            created_at,
            last_accessed_at,
            metadata
     from storage.objects
     where objects.name ilike $2 || $3 || ''%''
       and bucket_id = $4
       and array_length(regexp_split_to_array(objects.name, ''/''), 1) = $1
     order by ' || v_order_by || ')
     limit $5
     offset $6' using levels, prefix, search, bucketname, limits, offsets;
end;
$_$;


ALTER FUNCTION storage.search(prefix text, bucketname text, limits integer, levels integer, offsets integer, search text, sortcolumn text, sortorder text) OWNER TO supabase_storage_admin;

--
-- Name: update_updated_at_column(); Type: FUNCTION; Schema: storage; Owner: supabase_storage_admin
--

CREATE FUNCTION storage.update_updated_at_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW; 
END;
$$;


ALTER FUNCTION storage.update_updated_at_column() OWNER TO supabase_storage_admin;

--
-- Name: secrets_encrypt_secret_secret(); Type: FUNCTION; Schema: vault; Owner: supabase_admin
--

ALTER FUNCTION vault.secrets_encrypt_secret_secret() OWNER TO supabase_admin;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: audit_log_entries; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.audit_log_entries (
    instance_id uuid,
    id uuid NOT NULL,
    payload json,
    created_at timestamp with time zone,
    ip_address character varying(64) DEFAULT ''::character varying NOT NULL
);


ALTER TABLE auth.audit_log_entries OWNER TO supabase_auth_admin;

--
-- Name: TABLE audit_log_entries; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.audit_log_entries IS 'Auth: Audit trail for user actions.';


--
-- Name: flow_state; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.flow_state (
    id uuid NOT NULL,
    user_id uuid,
    auth_code text NOT NULL,
    code_challenge_method auth.code_challenge_method NOT NULL,
    code_challenge text NOT NULL,
    provider_type text NOT NULL,
    provider_access_token text,
    provider_refresh_token text,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    authentication_method text NOT NULL
);


ALTER TABLE auth.flow_state OWNER TO supabase_auth_admin;

--
-- Name: TABLE flow_state; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.flow_state IS 'stores metadata for pkce logins';


--
-- Name: identities; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.identities (
    id text NOT NULL,
    user_id uuid NOT NULL,
    identity_data jsonb NOT NULL,
    provider text NOT NULL,
    last_sign_in_at timestamp with time zone,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    email text GENERATED ALWAYS AS (lower((identity_data ->> 'email'::text))) STORED
);


ALTER TABLE auth.identities OWNER TO supabase_auth_admin;

--
-- Name: TABLE identities; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.identities IS 'Auth: Stores identities associated to a user.';


--
-- Name: COLUMN identities.email; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON COLUMN auth.identities.email IS 'Auth: Email is a generated column that references the optional email property in the identity_data';


--
-- Name: instances; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.instances (
    id uuid NOT NULL,
    uuid uuid,
    raw_base_config text,
    created_at timestamp with time zone,
    updated_at timestamp with time zone
);


ALTER TABLE auth.instances OWNER TO supabase_auth_admin;

--
-- Name: TABLE instances; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.instances IS 'Auth: Manages users across multiple sites.';


--
-- Name: mfa_amr_claims; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.mfa_amr_claims (
    session_id uuid NOT NULL,
    created_at timestamp with time zone NOT NULL,
    updated_at timestamp with time zone NOT NULL,
    authentication_method text NOT NULL,
    id uuid NOT NULL
);


ALTER TABLE auth.mfa_amr_claims OWNER TO supabase_auth_admin;

--
-- Name: TABLE mfa_amr_claims; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.mfa_amr_claims IS 'auth: stores authenticator method reference claims for multi factor authentication';


--
-- Name: mfa_challenges; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.mfa_challenges (
    id uuid NOT NULL,
    factor_id uuid NOT NULL,
    created_at timestamp with time zone NOT NULL,
    verified_at timestamp with time zone,
    ip_address inet NOT NULL
);


ALTER TABLE auth.mfa_challenges OWNER TO supabase_auth_admin;

--
-- Name: TABLE mfa_challenges; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.mfa_challenges IS 'auth: stores metadata about challenge requests made';


--
-- Name: mfa_factors; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.mfa_factors (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    friendly_name text,
    factor_type auth.factor_type NOT NULL,
    status auth.factor_status NOT NULL,
    created_at timestamp with time zone NOT NULL,
    updated_at timestamp with time zone NOT NULL,
    secret text
);


ALTER TABLE auth.mfa_factors OWNER TO supabase_auth_admin;

--
-- Name: TABLE mfa_factors; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.mfa_factors IS 'auth: stores metadata about factors';


--
-- Name: refresh_tokens; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.refresh_tokens (
    instance_id uuid,
    id bigint NOT NULL,
    token character varying(255),
    user_id character varying(255),
    revoked boolean,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    parent character varying(255),
    session_id uuid
);


ALTER TABLE auth.refresh_tokens OWNER TO supabase_auth_admin;

--
-- Name: TABLE refresh_tokens; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.refresh_tokens IS 'Auth: Store of tokens used to refresh JWT tokens once they expire.';


--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE; Schema: auth; Owner: supabase_auth_admin
--

CREATE SEQUENCE auth.refresh_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE auth.refresh_tokens_id_seq OWNER TO supabase_auth_admin;

--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: auth; Owner: supabase_auth_admin
--

ALTER SEQUENCE auth.refresh_tokens_id_seq OWNED BY auth.refresh_tokens.id;


--
-- Name: saml_providers; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.saml_providers (
    id uuid NOT NULL,
    sso_provider_id uuid NOT NULL,
    entity_id text NOT NULL,
    metadata_xml text NOT NULL,
    metadata_url text,
    attribute_mapping jsonb,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    CONSTRAINT "entity_id not empty" CHECK ((char_length(entity_id) > 0)),
    CONSTRAINT "metadata_url not empty" CHECK (((metadata_url = NULL::text) OR (char_length(metadata_url) > 0))),
    CONSTRAINT "metadata_xml not empty" CHECK ((char_length(metadata_xml) > 0))
);


ALTER TABLE auth.saml_providers OWNER TO supabase_auth_admin;

--
-- Name: TABLE saml_providers; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.saml_providers IS 'Auth: Manages SAML Identity Provider connections.';


--
-- Name: saml_relay_states; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.saml_relay_states (
    id uuid NOT NULL,
    sso_provider_id uuid NOT NULL,
    request_id text NOT NULL,
    for_email text,
    redirect_to text,
    from_ip_address inet,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    CONSTRAINT "request_id not empty" CHECK ((char_length(request_id) > 0))
);


ALTER TABLE auth.saml_relay_states OWNER TO supabase_auth_admin;

--
-- Name: TABLE saml_relay_states; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.saml_relay_states IS 'Auth: Contains SAML Relay State information for each Service Provider initiated login.';


--
-- Name: schema_migrations; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.schema_migrations (
    version character varying(255) NOT NULL
);


ALTER TABLE auth.schema_migrations OWNER TO supabase_auth_admin;

--
-- Name: TABLE schema_migrations; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.schema_migrations IS 'Auth: Manages updates to the auth system.';


--
-- Name: sessions; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.sessions (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    factor_id uuid,
    aal auth.aal_level,
    not_after timestamp with time zone
);


ALTER TABLE auth.sessions OWNER TO supabase_auth_admin;

--
-- Name: TABLE sessions; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.sessions IS 'Auth: Stores session data associated to a user.';


--
-- Name: COLUMN sessions.not_after; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON COLUMN auth.sessions.not_after IS 'Auth: Not after is a nullable column that contains a timestamp after which the session should be regarded as expired.';


--
-- Name: sso_domains; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.sso_domains (
    id uuid NOT NULL,
    sso_provider_id uuid NOT NULL,
    domain text NOT NULL,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    CONSTRAINT "domain not empty" CHECK ((char_length(domain) > 0))
);


ALTER TABLE auth.sso_domains OWNER TO supabase_auth_admin;

--
-- Name: TABLE sso_domains; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.sso_domains IS 'Auth: Manages SSO email address domain mapping to an SSO Identity Provider.';


--
-- Name: sso_providers; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.sso_providers (
    id uuid NOT NULL,
    resource_id text,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    CONSTRAINT "resource_id not empty" CHECK (((resource_id = NULL::text) OR (char_length(resource_id) > 0)))
);


ALTER TABLE auth.sso_providers OWNER TO supabase_auth_admin;

--
-- Name: TABLE sso_providers; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.sso_providers IS 'Auth: Manages SSO identity provider information; see saml_providers for SAML.';


--
-- Name: COLUMN sso_providers.resource_id; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON COLUMN auth.sso_providers.resource_id IS 'Auth: Uniquely identifies a SSO provider according to a user-chosen resource ID (case insensitive), useful in infrastructure as code.';


--
-- Name: users; Type: TABLE; Schema: auth; Owner: supabase_auth_admin
--

CREATE TABLE auth.users (
    instance_id uuid,
    id uuid NOT NULL,
    aud character varying(255),
    role character varying(255),
    email character varying(255),
    encrypted_password character varying(255),
    email_confirmed_at timestamp with time zone,
    invited_at timestamp with time zone,
    confirmation_token character varying(255),
    confirmation_sent_at timestamp with time zone,
    recovery_token character varying(255),
    recovery_sent_at timestamp with time zone,
    email_change_token_new character varying(255),
    email_change character varying(255),
    email_change_sent_at timestamp with time zone,
    last_sign_in_at timestamp with time zone,
    raw_app_meta_data jsonb,
    raw_user_meta_data jsonb,
    is_super_admin boolean,
    created_at timestamp with time zone,
    updated_at timestamp with time zone,
    phone text DEFAULT NULL::character varying,
    phone_confirmed_at timestamp with time zone,
    phone_change text DEFAULT ''::character varying,
    phone_change_token character varying(255) DEFAULT ''::character varying,
    phone_change_sent_at timestamp with time zone,
    confirmed_at timestamp with time zone GENERATED ALWAYS AS (LEAST(email_confirmed_at, phone_confirmed_at)) STORED,
    email_change_token_current character varying(255) DEFAULT ''::character varying,
    email_change_confirm_status smallint DEFAULT 0,
    banned_until timestamp with time zone,
    reauthentication_token character varying(255) DEFAULT ''::character varying,
    reauthentication_sent_at timestamp with time zone,
    is_sso_user boolean DEFAULT false NOT NULL,
    deleted_at timestamp with time zone,
    CONSTRAINT users_email_change_confirm_status_check CHECK (((email_change_confirm_status >= 0) AND (email_change_confirm_status <= 2)))
);


ALTER TABLE auth.users OWNER TO supabase_auth_admin;

--
-- Name: TABLE users; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON TABLE auth.users IS 'Auth: Stores user login data within a secure schema.';


--
-- Name: COLUMN users.is_sso_user; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON COLUMN auth.users.is_sso_user IS 'Auth: Set this column to true when the account comes from SSO. These accounts can have duplicate emails.';


--
-- Name: test; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.test (
    id bigint NOT NULL,
    int2 smallint,
    int4 integer,
    int8 bigint,
    float4 real,
    float8 double precision,
    "numeric" numeric,
    json json,
    jsonb jsonb,
    text text[],
    "varchar" character varying[],
    uuid uuid,
    date date,
    timetz time with time zone,
    "timestamp" timestamp without time zone,
    timestamptz timestamp with time zone,
    bool boolean,
    boolarr boolean[]
);


ALTER TABLE public.test OWNER TO postgres;

--
-- Name: TABLE test; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.test IS 'test';

CREATE SEQUENCE IF NOT EXISTS public.test_data_id_seq;

-- Table Definition
CREATE TABLE public."FunctionalDefaultTestTable" (
    "id" int4 NOT NULL DEFAULT nextval('public.test_data_id_seq'::regclass),
    PRIMARY KEY ("id")
);

-- Indices
CREATE UNIQUE INDEX test_data_pkey ON public."FunctionalDefaultTestTable" USING btree (id);


--
-- Name: test2; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.test2 (
    id bigint NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    int4 smallint[],
    int5 integer[],
    int8 bigint[],
    float4 real[],
    float8 double precision[],
    "numeric" numeric[],
    json json[],
    jsonb jsonb[],
    text text[],
    "varchar" character varying[],
    uuid uuid[],
    date date[],
    "time" time without time zone[],
    timetz time with time zone[],
    "timestamp" timestamp without time zone[],
    timestamptz timestamp with time zone[],
    bool boolean[]
);


ALTER TABLE public.test2 OWNER TO postgres;

--
-- Name: test2_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

ALTER TABLE public.test2 ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.test2_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: test_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

ALTER TABLE public.test ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.test_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: buckets; Type: TABLE; Schema: storage; Owner: supabase_storage_admin
--

CREATE TABLE storage.buckets (
    id text NOT NULL,
    name text NOT NULL,
    owner uuid,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    public boolean DEFAULT false,
    avif_autodetection boolean DEFAULT false,
    file_size_limit bigint,
    allowed_mime_types text[]
);


ALTER TABLE storage.buckets OWNER TO supabase_storage_admin;

--
-- Name: migrations; Type: TABLE; Schema: storage; Owner: supabase_storage_admin
--

CREATE TABLE storage.migrations (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    hash character varying(40) NOT NULL,
    executed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE storage.migrations OWNER TO supabase_storage_admin;

--
-- Name: objects; Type: TABLE; Schema: storage; Owner: supabase_storage_admin
--

CREATE TABLE storage.objects (
    id uuid DEFAULT extensions.uuid_generate_v4() NOT NULL,
    bucket_id text,
    name text,
    owner uuid,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),
    last_accessed_at timestamp with time zone DEFAULT now(),
    metadata jsonb,
    path_tokens text[] GENERATED ALWAYS AS (string_to_array(name, '/'::text)) STORED,
    version text
);


ALTER TABLE storage.objects OWNER TO supabase_storage_admin;

ALTER TABLE vault.decrypted_secrets OWNER TO supabase_admin;

--
-- Name: refresh_tokens id; Type: DEFAULT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.refresh_tokens ALTER COLUMN id SET DEFAULT nextval('auth.refresh_tokens_id_seq'::regclass);


--
-- Data for Name: audit_log_entries; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.audit_log_entries (instance_id, id, payload, created_at, ip_address) FROM stdin;
00000000-0000-0000-0000-000000000000	c1902f19-1b8c-4f4d-aed7-433edce6876e	{"action":"user_invited","actor_id":"00000000-0000-0000-0000-000000000000","actor_username":"supabase_admin","log_type":"team","traits":{"user_email":"ionicisere@gmail.com","user_id":"be55c6cf-94d2-44a4-a119-61297d68c0e8"}}	2022-08-30 12:59:35.98897+00	
00000000-0000-0000-0000-000000000000	27d0dfc7-35aa-49ff-bf42-cf94e85f01b2	{"action":"user_signedup","actor_id":"be55c6cf-94d2-44a4-a119-61297d68c0e8","actor_username":"ionicisere@gmail.com","log_type":"team"}	2022-08-30 12:59:49.901362+00	
00000000-0000-0000-0000-000000000000	4cb58f1d-eabd-4ac7-9731-7eb9aabb37e1	{"action":"user_invited","actor_id":"00000000-0000-0000-0000-000000000000","actor_username":"supabase_admin","log_type":"team","traits":{"user_email":"bradley@appwrite.io","user_id":"1b48e703-cb29-4b76-b804-82a53f074b93"}}	2022-09-14 10:09:18.311141+00	
00000000-0000-0000-0000-000000000000	59a6ff0f-2ae2-4674-a05a-ce889368762a	{"action":"user_signedup","actor_id":"1b48e703-cb29-4b76-b804-82a53f074b93","actor_username":"bradley@appwrite.io","log_type":"team"}	2022-09-14 10:19:09.440989+00	
00000000-0000-0000-0000-000000000000	7c93bde4-e3d6-4725-a251-dc37554f765d	{"action":"user_recovery_requested","actor_id":"1b48e703-cb29-4b76-b804-82a53f074b93","actor_username":"bradley@appwrite.io","log_type":"user"}	2022-09-20 09:05:38.36561+00	
00000000-0000-0000-0000-000000000000	c9ef1c90-0ac3-4ac7-9548-80b190bdb90d	{"action":"login","actor_id":"1b48e703-cb29-4b76-b804-82a53f074b93","actor_username":"bradley@appwrite.io","log_type":"account"}	2022-09-20 09:06:33.909469+00	
00000000-0000-0000-0000-000000000000	1067d0f5-59ed-4ee2-9c91-c4196e4167b5	{"action":"user_recovery_requested","actor_id":"1b48e703-cb29-4b76-b804-82a53f074b93","actor_username":"bradley@appwrite.io","log_type":"user"}	2022-09-20 09:23:34.466842+00	
00000000-0000-0000-0000-000000000000	2c794d09-7cb6-4c08-9e20-106c90389806	{"action":"login","actor_id":"1b48e703-cb29-4b76-b804-82a53f074b93","actor_username":"bradley@appwrite.io","log_type":"account"}	2022-09-20 09:23:51.627032+00	
00000000-0000-0000-0000-000000000000	a6598240-a79f-4778-b76e-0882f3534441	{"action":"user_modified","actor_id":"1b48e703-cb29-4b76-b804-82a53f074b93","actor_username":"bradley@appwrite.io","log_type":"user"}	2022-09-20 09:27:11.738268+00	
00000000-0000-0000-0000-000000000000	63009024-ef8c-42b6-822f-b1f6608f065d	{"action":"user_recovery_requested","actor_id":"1b48e703-cb29-4b76-b804-82a53f074b93","actor_username":"bradley@appwrite.io","log_type":"user"}	2022-09-20 09:32:37.016522+00	
00000000-0000-0000-0000-000000000000	fe9287eb-9306-4d92-97c7-ff8cfeaf6214	{"action":"login","actor_id":"1b48e703-cb29-4b76-b804-82a53f074b93","actor_username":"bradley@appwrite.io","log_type":"account"}	2022-09-20 09:32:43.909748+00	
00000000-0000-0000-0000-000000000000	c1abbcfc-4627-4755-a1ec-f512d8316440	{"action":"user_modified","actor_id":"1b48e703-cb29-4b76-b804-82a53f074b93","actor_username":"bradley@appwrite.io","log_type":"user"}	2022-09-20 09:33:47.027108+00	
00000000-0000-0000-0000-000000000000	2b65dfee-555c-4996-9411-d824a5858f92	{"action":"user_confirmation_requested","actor_id":"88043554-4aac-46de-8437-c02fdacfdc9c","actor_username":"misael.upton98@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:37.980112+00	
00000000-0000-0000-0000-000000000000	c87c281e-e62b-405c-92e4-5eadd49d88f0	{"action":"user_confirmation_requested","actor_id":"615357b8-c668-45ee-a749-c96db1aabc7a","actor_username":"albert.kihn95@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:37.986291+00	
00000000-0000-0000-0000-000000000000	988b85c7-b39a-4d0d-bd95-ee975f054aed	{"action":"user_confirmation_requested","actor_id":"369664ac-9358-4b51-91b5-79ddca7ef0b2","actor_username":"maida_walsh61@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:37.992667+00	
00000000-0000-0000-0000-000000000000	7f936ded-7ac9-4f10-baa1-ef7a0633fead	{"action":"user_confirmation_requested","actor_id":"e7faf866-0438-4b7c-8de3-1c134c707806","actor_username":"osvaldo.bogan15@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:37.997493+00	
00000000-0000-0000-0000-000000000000	44f1d5d8-acc5-4c64-bc0e-b73bf6f81bc4	{"action":"user_confirmation_requested","actor_id":"af00f659-028a-4268-b6b5-fa36e40e190e","actor_username":"wyman67@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.005148+00	
00000000-0000-0000-0000-000000000000	cf54912a-f78d-44c8-ad1b-1611e0bf65bd	{"action":"user_confirmation_requested","actor_id":"07616a1c-e8ce-489a-a74b-adab1f4e1b32","actor_username":"rylee68@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.021094+00	
00000000-0000-0000-0000-000000000000	f8ce7a46-e40d-487f-b44b-74649e73e358	{"action":"user_confirmation_requested","actor_id":"ab38ad48-7b50-4e66-85b1-7d78a61a04f0","actor_username":"devin.rath@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.030918+00	
00000000-0000-0000-0000-000000000000	dcfaa287-546a-49aa-b4c0-45bb8ff58b52	{"action":"user_confirmation_requested","actor_id":"23e19499-63e4-4fee-9718-bd24584fdca0","actor_username":"royce_hermiston@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.033558+00	
00000000-0000-0000-0000-000000000000	ee411931-e9c0-4c47-b2a0-ad5a224c97f8	{"action":"user_confirmation_requested","actor_id":"932ce7f7-e57d-45c3-bc7d-63362e2d67aa","actor_username":"eino_considine67@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.033417+00	
00000000-0000-0000-0000-000000000000	c2e9c5c8-9469-4a7d-931f-daf8b0386149	{"action":"user_confirmation_requested","actor_id":"b301a3c1-022f-49fa-a6fb-41c8cdd521a9","actor_username":"jerrod70@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.046936+00	
00000000-0000-0000-0000-000000000000	00a03660-31a1-4648-a06f-dd9e019f0633	{"action":"user_confirmation_requested","actor_id":"a7ca4ce7-7763-4edc-869a-dc3afb4dc1c2","actor_username":"ada.parker@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.627452+00	
00000000-0000-0000-0000-000000000000	fd3bbe3c-6b0b-418d-99a0-e521b3465133	{"action":"user_confirmation_requested","actor_id":"d977c288-485b-44f4-aa34-94c232decbed","actor_username":"aryanna_dickens47@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.798551+00	
00000000-0000-0000-0000-000000000000	95152226-2a61-4d46-97ab-e1ab5e559516	{"action":"user_confirmation_requested","actor_id":"53cd7be4-c374-492c-aa18-06febf196607","actor_username":"lonnie_huels69@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.817815+00	
00000000-0000-0000-0000-000000000000	7c0986f6-0310-4a94-9df2-6fc31d38b84c	{"action":"user_confirmation_requested","actor_id":"2b817ed6-e9b4-4ab6-a97c-8a5c5cca80b5","actor_username":"jennings.watsica71@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.845262+00	
00000000-0000-0000-0000-000000000000	8e392538-f1e9-4ece-8a7c-d1bf807d5e3a	{"action":"user_confirmation_requested","actor_id":"ba1d736c-18f9-4eca-8000-05bd223d097f","actor_username":"alia.emmerich42@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.884705+00	
00000000-0000-0000-0000-000000000000	88ad0d66-a780-4290-81ae-1425b86d10af	{"action":"user_confirmation_requested","actor_id":"1d1c4838-d7bf-4830-945e-7f1c31934340","actor_username":"mallory_kuhn@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.909651+00	
00000000-0000-0000-0000-000000000000	521e9c38-506a-4373-807e-11df4a6d62ec	{"action":"user_confirmation_requested","actor_id":"abb439a6-9812-4edb-8f54-8f99830a9d51","actor_username":"julianne.schinner98@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:38.944862+00	
00000000-0000-0000-0000-000000000000	feede8a4-1b06-4bbf-9210-4200b9df34a3	{"action":"user_confirmation_requested","actor_id":"acbbb7e0-44ff-42fa-a8aa-69ad98666e31","actor_username":"adonis_oconnell@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.011272+00	
00000000-0000-0000-0000-000000000000	8a039a1d-b856-4ed9-839f-e3922fed90ec	{"action":"user_confirmation_requested","actor_id":"67cb7f0e-c295-4d41-83a0-761568b7a13c","actor_username":"craig.dietrich31@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.034958+00	
00000000-0000-0000-0000-000000000000	3c328068-021f-4d85-8cde-a25dac7e23d0	{"action":"user_confirmation_requested","actor_id":"d1c2cd22-52db-4074-b0ba-e7152df4a27d","actor_username":"sunny.welch@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.039284+00	
00000000-0000-0000-0000-000000000000	8d9dfb38-5510-4540-afde-19e7210d3cba	{"action":"user_confirmation_requested","actor_id":"e6e80a3d-8435-4d09-bba3-e8f158ff93e4","actor_username":"elliott_goldner77@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.26905+00	
00000000-0000-0000-0000-000000000000	3d1d888b-99b0-4c72-a076-9e9ead3f79de	{"action":"user_confirmation_requested","actor_id":"fef209ad-b677-44a7-82a9-899c33490075","actor_username":"ana.nikolaus95@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:58.679176+00	
00000000-0000-0000-0000-000000000000	ce8b1ede-1f4e-47f3-b34a-958c1e3d707a	{"action":"user_confirmation_requested","actor_id":"7f08b69b-c277-4af8-a51e-9f80bad06430","actor_username":"kobe_bergnaum47@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.742489+00	
00000000-0000-0000-0000-000000000000	ab4f4854-0853-4214-805a-d1a1cdd7c5c1	{"action":"user_confirmation_requested","actor_id":"0b448f5c-7c26-498b-8d13-7049cf82037c","actor_username":"arne_bayer91@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.798011+00	
00000000-0000-0000-0000-000000000000	f8b9d51f-3e4c-4492-aa79-e6597db8707e	{"action":"user_confirmation_requested","actor_id":"8e40363f-20c0-4f58-ac8f-fecaec606923","actor_username":"cheyenne_cassin@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.346177+00	
00000000-0000-0000-0000-000000000000	ef6ed7c2-17e2-430b-80a9-77c52711298d	{"action":"user_confirmation_requested","actor_id":"5de1bfbc-4a77-4e6b-8adf-e5572a32e12e","actor_username":"coby91@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:58.835142+00	
00000000-0000-0000-0000-000000000000	f9509d5a-4501-4b3a-b4e0-d7e5b3fd0b30	{"action":"user_confirmation_requested","actor_id":"46867bb3-7c26-4ced-9ec0-268afda9ca10","actor_username":"lewis62@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.490841+00	
00000000-0000-0000-0000-000000000000	e0e19e16-1a57-49f0-8f3f-f08526126a23	{"action":"user_confirmation_requested","actor_id":"366b7862-08df-4b9e-8ba3-8e2d7fbe7a40","actor_username":"lue.dibbert26@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.342348+00	
00000000-0000-0000-0000-000000000000	eb4a832f-0f02-4433-9590-fc2089fb78b7	{"action":"user_confirmation_requested","actor_id":"5c6a9645-29e1-478e-9cba-d048ab579bd1","actor_username":"magdalena_metz@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.459499+00	
00000000-0000-0000-0000-000000000000	11b86e41-f5ed-4034-936a-3405666e7d86	{"action":"user_confirmation_requested","actor_id":"4535c79c-f973-447b-8692-6f2207f6efcb","actor_username":"madisen_harris32@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.505607+00	
00000000-0000-0000-0000-000000000000	8a2c5e3f-e192-43eb-b120-2681aca4dd93	{"action":"user_confirmation_requested","actor_id":"581483e9-b39a-477c-a712-a590a6bd2e8d","actor_username":"alexandre_rodriguez@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.508104+00	
00000000-0000-0000-0000-000000000000	2218eaf6-d590-4f4a-9abc-b54a0a89a2ea	{"action":"user_confirmation_requested","actor_id":"0d9fd639-9b14-49c8-a755-36565a345da5","actor_username":"pierre33@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.51246+00	
00000000-0000-0000-0000-000000000000	fccbf8d7-df33-4062-b60b-3c0c0d40906e	{"action":"user_confirmation_requested","actor_id":"af4d7654-d31d-40e9-8e31-8031b29651bf","actor_username":"laney.olson@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.61992+00	
00000000-0000-0000-0000-000000000000	f74d8518-8bb2-4d91-921a-3fa9e56c3ff7	{"action":"user_confirmation_requested","actor_id":"c7f6fb1f-a6d9-45b1-a732-4e80877266ea","actor_username":"jodie.wunsch60@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.637491+00	
00000000-0000-0000-0000-000000000000	b2a7a025-9dd9-4dc7-90dc-f05deb54cf7a	{"action":"user_confirmation_requested","actor_id":"bdc41348-d0ff-4716-b03e-505397255296","actor_username":"micheal.homenick@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.669904+00	
00000000-0000-0000-0000-000000000000	66f804ba-5420-456e-9905-ada2c35b9911	{"action":"user_confirmation_requested","actor_id":"b5aca4b6-67f6-47b1-b2f5-794f354e183a","actor_username":"pauline.moore@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 11:08:39.697472+00	
00000000-0000-0000-0000-000000000000	dcd63ad7-5648-40e9-af68-b185645f4ff8	{"action":"user_confirmation_requested","actor_id":"68fb3828-45a2-41ee-8c32-fd87525d955e","actor_username":"vivian_rogahn@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:58.906026+00	
00000000-0000-0000-0000-000000000000	44d7e676-d605-427a-8fb4-c77f51756325	{"action":"user_confirmation_requested","actor_id":"2d7cc448-9478-4c4b-b9c1-b6005b3b2517","actor_username":"casimir.williamson41@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.074583+00	
00000000-0000-0000-0000-000000000000	fe46709a-032f-43b4-8ce3-38a55e78db8b	{"action":"user_confirmation_requested","actor_id":"754e01ee-8bc6-42cf-875a-c596d4b5c108","actor_username":"rod_hoppe83@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.126499+00	
00000000-0000-0000-0000-000000000000	d7a8a31c-c1db-4d92-a1b0-b1e2756ec997	{"action":"user_confirmation_requested","actor_id":"fe8d52b3-9bb2-4063-95da-733f61763be0","actor_username":"demetris99@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.120626+00	
00000000-0000-0000-0000-000000000000	9ef5e545-5858-45d2-a4b0-b30cfd4ee0d9	{"action":"user_confirmation_requested","actor_id":"27b98fb2-2a63-4ea2-92da-845e45c1f530","actor_username":"marlon.torp45@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.175015+00	
00000000-0000-0000-0000-000000000000	9e4acd5e-bcf4-49b9-9f56-3b9eea2bb06f	{"action":"user_confirmation_requested","actor_id":"88847f43-2f69-4c26-b00b-6b59a38ef164","actor_username":"cali_orn71@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.196527+00	
00000000-0000-0000-0000-000000000000	ca7a089c-702a-4bbf-a87e-c354abae8d6b	{"action":"user_confirmation_requested","actor_id":"561e28e6-7ce5-4282-9f51-7f59722170ca","actor_username":"rey32@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.197341+00	
00000000-0000-0000-0000-000000000000	a17507f3-5447-4ac3-a6b5-3ebf9a16c79b	{"action":"user_confirmation_requested","actor_id":"a23c4ec2-eb31-420d-bd89-ec49065bed3f","actor_username":"ansel.kessler89@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.19806+00	
00000000-0000-0000-0000-000000000000	a498aa55-2d71-4299-9aad-df1628d66dab	{"action":"user_confirmation_requested","actor_id":"2e320c50-4640-43d4-8e82-5e66c430decd","actor_username":"anibal61@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.47539+00	
00000000-0000-0000-0000-000000000000	a3ed6e6c-12c7-4bc8-a75f-c604f4336370	{"action":"user_confirmation_requested","actor_id":"e42df43c-3603-45ea-a281-1c1c1b29965c","actor_username":"thora.renner@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.61507+00	
00000000-0000-0000-0000-000000000000	e7e6f7bb-eb45-4352-8b2a-677e5daa1e2a	{"action":"user_confirmation_requested","actor_id":"07ede456-d7f3-4840-a2b2-6394cb6e44c6","actor_username":"estel.kovacek68@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.655022+00	
00000000-0000-0000-0000-000000000000	632de9d3-c69c-4529-9694-e557670cbb0d	{"action":"user_confirmation_requested","actor_id":"8858520b-4d3e-4804-937c-e1fa8b355e1e","actor_username":"althea_dickens@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.75571+00	
00000000-0000-0000-0000-000000000000	75ab9005-95cc-43c4-98bc-6aa967b86c01	{"action":"user_confirmation_requested","actor_id":"26889fa5-94d3-4150-8b17-d7413e682652","actor_username":"francis_lockman21@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.809823+00	
00000000-0000-0000-0000-000000000000	e339fa91-ea17-4d6f-a0f9-2b06510b85a0	{"action":"user_confirmation_requested","actor_id":"9beabeb7-b51b-416c-9d3b-d23badeb7c5c","actor_username":"adonis_lemke@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.816807+00	
00000000-0000-0000-0000-000000000000	c4962a31-a422-4fde-ae3c-a117f0a70256	{"action":"user_confirmation_requested","actor_id":"b1262b76-e470-4f71-ad33-86919606391d","actor_username":"humberto_wolf@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.860563+00	
00000000-0000-0000-0000-000000000000	b2647794-d7b9-4bf6-b2ac-a94d41efee3c	{"action":"user_confirmation_requested","actor_id":"3298ca3a-400a-459e-b0a7-426fd6e9b716","actor_username":"buddy_hintz@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:40:59.889159+00	
00000000-0000-0000-0000-000000000000	946fb286-eebb-4e19-ac7e-dd13f6e04015	{"action":"user_confirmation_requested","actor_id":"a413cb80-834e-4d8a-b29a-a02ecf139f7c","actor_username":"brando_treutel@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.296428+00	
00000000-0000-0000-0000-000000000000	e45fe72b-6828-4634-9165-decdd88046b4	{"action":"user_confirmation_requested","actor_id":"ca9f8106-a060-4bd5-be47-d9f384e415ca","actor_username":"gavin33@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.352987+00	
00000000-0000-0000-0000-000000000000	8f7f9be0-e1ee-43c3-a4e0-bc6dcac9727f	{"action":"user_confirmation_requested","actor_id":"b096f178-9502-4837-bf3c-37acccf61eb9","actor_username":"ara_volkman7@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.381204+00	
00000000-0000-0000-0000-000000000000	ac7061d4-b248-488e-b31d-7c09097a2a63	{"action":"user_confirmation_requested","actor_id":"54b2da7d-4765-44f1-9e95-b6ebd5494530","actor_username":"horace_borer96@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.425255+00	
00000000-0000-0000-0000-000000000000	ca21b645-cf89-44d0-b70e-4af637b45548	{"action":"user_confirmation_requested","actor_id":"88e9b920-6bc9-4242-a245-2c2164c01092","actor_username":"princess24@gmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.728858+00	
00000000-0000-0000-0000-000000000000	f8e8223a-5e49-471a-a916-0863fa8c8f88	{"action":"user_confirmation_requested","actor_id":"7dff3d1f-c92e-45d8-ad4f-47976249728f","actor_username":"heather.corwin32@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.733868+00	
00000000-0000-0000-0000-000000000000	f30a282a-e7b0-419b-ad4c-64c923e03afc	{"action":"user_confirmation_requested","actor_id":"ec352166-6475-453f-825b-01bee04214d9","actor_username":"jamarcus94@hotmail.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.802217+00	
00000000-0000-0000-0000-000000000000	b67c4f21-d71b-4148-852a-6ca6c14031e3	{"action":"user_confirmation_requested","actor_id":"b576f0a4-3369-4e89-8221-72a89807dde9","actor_username":"efrain37@yahoo.com","log_type":"user","traits":{"provider":"email"}}	2023-01-19 14:41:00.825823+00	
\.


--
-- Data for Name: flow_state; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.flow_state (id, user_id, auth_code, code_challenge_method, code_challenge, provider_type, provider_access_token, provider_refresh_token, created_at, updated_at, authentication_method) FROM stdin;
\.


--
-- Data for Name: identities; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.identities (id, user_id, identity_data, provider, last_sign_in_at, created_at, updated_at) FROM stdin;
be55c6cf-94d2-44a4-a119-61297d68c0e8	be55c6cf-94d2-44a4-a119-61297d68c0e8	{"sub": "be55c6cf-94d2-44a4-a119-61297d68c0e8", "email": "ionicisere@gmail.com"}	email	2022-11-25 00:00:00+00	2022-11-25 00:00:00+00	2022-11-25 00:00:00+00
1b48e703-cb29-4b76-b804-82a53f074b93	1b48e703-cb29-4b76-b804-82a53f074b93	{"sub": "1b48e703-cb29-4b76-b804-82a53f074b93", "email": "bradley@appwrite.io"}	email	2022-11-25 00:00:00+00	2022-11-25 00:00:00+00	2022-11-25 00:00:00+00
88043554-4aac-46de-8437-c02fdacfdc9c	88043554-4aac-46de-8437-c02fdacfdc9c	{"sub": "88043554-4aac-46de-8437-c02fdacfdc9c", "email": "misael.upton98@hotmail.com"}	email	2023-01-19 11:08:37.978799+00	2023-01-19 11:08:37.97884+00	2023-01-19 11:08:37.97884+00
615357b8-c668-45ee-a749-c96db1aabc7a	615357b8-c668-45ee-a749-c96db1aabc7a	{"sub": "615357b8-c668-45ee-a749-c96db1aabc7a", "email": "albert.kihn95@yahoo.com"}	email	2023-01-19 11:08:37.985094+00	2023-01-19 11:08:37.98513+00	2023-01-19 11:08:37.98513+00
369664ac-9358-4b51-91b5-79ddca7ef0b2	369664ac-9358-4b51-91b5-79ddca7ef0b2	{"sub": "369664ac-9358-4b51-91b5-79ddca7ef0b2", "email": "maida_walsh61@yahoo.com"}	email	2023-01-19 11:08:37.991451+00	2023-01-19 11:08:37.991486+00	2023-01-19 11:08:37.991486+00
e7faf866-0438-4b7c-8de3-1c134c707806	e7faf866-0438-4b7c-8de3-1c134c707806	{"sub": "e7faf866-0438-4b7c-8de3-1c134c707806", "email": "osvaldo.bogan15@yahoo.com"}	email	2023-01-19 11:08:37.996337+00	2023-01-19 11:08:37.996377+00	2023-01-19 11:08:37.996377+00
af00f659-028a-4268-b6b5-fa36e40e190e	af00f659-028a-4268-b6b5-fa36e40e190e	{"sub": "af00f659-028a-4268-b6b5-fa36e40e190e", "email": "wyman67@gmail.com"}	email	2023-01-19 11:08:38.002463+00	2023-01-19 11:08:38.002505+00	2023-01-19 11:08:38.002505+00
07616a1c-e8ce-489a-a74b-adab1f4e1b32	07616a1c-e8ce-489a-a74b-adab1f4e1b32	{"sub": "07616a1c-e8ce-489a-a74b-adab1f4e1b32", "email": "rylee68@yahoo.com"}	email	2023-01-19 11:08:38.010583+00	2023-01-19 11:08:38.010667+00	2023-01-19 11:08:38.010667+00
ab38ad48-7b50-4e66-85b1-7d78a61a04f0	ab38ad48-7b50-4e66-85b1-7d78a61a04f0	{"sub": "ab38ad48-7b50-4e66-85b1-7d78a61a04f0", "email": "devin.rath@gmail.com"}	email	2023-01-19 11:08:38.018797+00	2023-01-19 11:08:38.018847+00	2023-01-19 11:08:38.018847+00
932ce7f7-e57d-45c3-bc7d-63362e2d67aa	932ce7f7-e57d-45c3-bc7d-63362e2d67aa	{"sub": "932ce7f7-e57d-45c3-bc7d-63362e2d67aa", "email": "eino_considine67@hotmail.com"}	email	2023-01-19 11:08:38.010854+00	2023-01-19 11:08:38.01089+00	2023-01-19 11:08:38.01089+00
23e19499-63e4-4fee-9718-bd24584fdca0	23e19499-63e4-4fee-9718-bd24584fdca0	{"sub": "23e19499-63e4-4fee-9718-bd24584fdca0", "email": "royce_hermiston@gmail.com"}	email	2023-01-19 11:08:38.021799+00	2023-01-19 11:08:38.02184+00	2023-01-19 11:08:38.02184+00
b301a3c1-022f-49fa-a6fb-41c8cdd521a9	b301a3c1-022f-49fa-a6fb-41c8cdd521a9	{"sub": "b301a3c1-022f-49fa-a6fb-41c8cdd521a9", "email": "jerrod70@yahoo.com"}	email	2023-01-19 11:08:38.04489+00	2023-01-19 11:08:38.044935+00	2023-01-19 11:08:38.044935+00
a7ca4ce7-7763-4edc-869a-dc3afb4dc1c2	a7ca4ce7-7763-4edc-869a-dc3afb4dc1c2	{"sub": "a7ca4ce7-7763-4edc-869a-dc3afb4dc1c2", "email": "ada.parker@gmail.com"}	email	2023-01-19 11:08:38.626499+00	2023-01-19 11:08:38.626538+00	2023-01-19 11:08:38.626538+00
d977c288-485b-44f4-aa34-94c232decbed	d977c288-485b-44f4-aa34-94c232decbed	{"sub": "d977c288-485b-44f4-aa34-94c232decbed", "email": "aryanna_dickens47@gmail.com"}	email	2023-01-19 11:08:38.797556+00	2023-01-19 11:08:38.797606+00	2023-01-19 11:08:38.797606+00
53cd7be4-c374-492c-aa18-06febf196607	53cd7be4-c374-492c-aa18-06febf196607	{"sub": "53cd7be4-c374-492c-aa18-06febf196607", "email": "lonnie_huels69@gmail.com"}	email	2023-01-19 11:08:38.816211+00	2023-01-19 11:08:38.816253+00	2023-01-19 11:08:38.816253+00
2b817ed6-e9b4-4ab6-a97c-8a5c5cca80b5	2b817ed6-e9b4-4ab6-a97c-8a5c5cca80b5	{"sub": "2b817ed6-e9b4-4ab6-a97c-8a5c5cca80b5", "email": "jennings.watsica71@hotmail.com"}	email	2023-01-19 11:08:38.844286+00	2023-01-19 11:08:38.844322+00	2023-01-19 11:08:38.844322+00
ba1d736c-18f9-4eca-8000-05bd223d097f	ba1d736c-18f9-4eca-8000-05bd223d097f	{"sub": "ba1d736c-18f9-4eca-8000-05bd223d097f", "email": "alia.emmerich42@hotmail.com"}	email	2023-01-19 11:08:38.883805+00	2023-01-19 11:08:38.883849+00	2023-01-19 11:08:38.883849+00
1d1c4838-d7bf-4830-945e-7f1c31934340	1d1c4838-d7bf-4830-945e-7f1c31934340	{"sub": "1d1c4838-d7bf-4830-945e-7f1c31934340", "email": "mallory_kuhn@yahoo.com"}	email	2023-01-19 11:08:38.908677+00	2023-01-19 11:08:38.908717+00	2023-01-19 11:08:38.908717+00
abb439a6-9812-4edb-8f54-8f99830a9d51	abb439a6-9812-4edb-8f54-8f99830a9d51	{"sub": "abb439a6-9812-4edb-8f54-8f99830a9d51", "email": "julianne.schinner98@gmail.com"}	email	2023-01-19 11:08:38.943988+00	2023-01-19 11:08:38.944025+00	2023-01-19 11:08:38.944025+00
acbbb7e0-44ff-42fa-a8aa-69ad98666e31	acbbb7e0-44ff-42fa-a8aa-69ad98666e31	{"sub": "acbbb7e0-44ff-42fa-a8aa-69ad98666e31", "email": "adonis_oconnell@yahoo.com"}	email	2023-01-19 11:08:39.009983+00	2023-01-19 11:08:39.010021+00	2023-01-19 11:08:39.010021+00
67cb7f0e-c295-4d41-83a0-761568b7a13c	67cb7f0e-c295-4d41-83a0-761568b7a13c	{"sub": "67cb7f0e-c295-4d41-83a0-761568b7a13c", "email": "craig.dietrich31@hotmail.com"}	email	2023-01-19 11:08:39.032835+00	2023-01-19 11:08:39.032875+00	2023-01-19 11:08:39.032875+00
d1c2cd22-52db-4074-b0ba-e7152df4a27d	d1c2cd22-52db-4074-b0ba-e7152df4a27d	{"sub": "d1c2cd22-52db-4074-b0ba-e7152df4a27d", "email": "sunny.welch@gmail.com"}	email	2023-01-19 11:08:39.03801+00	2023-01-19 11:08:39.038065+00	2023-01-19 11:08:39.038065+00
e6e80a3d-8435-4d09-bba3-e8f158ff93e4	e6e80a3d-8435-4d09-bba3-e8f158ff93e4	{"sub": "e6e80a3d-8435-4d09-bba3-e8f158ff93e4", "email": "elliott_goldner77@yahoo.com"}	email	2023-01-19 11:08:39.268192+00	2023-01-19 11:08:39.268229+00	2023-01-19 11:08:39.268229+00
8e40363f-20c0-4f58-ac8f-fecaec606923	8e40363f-20c0-4f58-ac8f-fecaec606923	{"sub": "8e40363f-20c0-4f58-ac8f-fecaec606923", "email": "cheyenne_cassin@hotmail.com"}	email	2023-01-19 11:08:39.345276+00	2023-01-19 11:08:39.345311+00	2023-01-19 11:08:39.345311+00
5c6a9645-29e1-478e-9cba-d048ab579bd1	5c6a9645-29e1-478e-9cba-d048ab579bd1	{"sub": "5c6a9645-29e1-478e-9cba-d048ab579bd1", "email": "magdalena_metz@gmail.com"}	email	2023-01-19 11:08:39.458571+00	2023-01-19 11:08:39.458609+00	2023-01-19 11:08:39.458609+00
4535c79c-f973-447b-8692-6f2207f6efcb	4535c79c-f973-447b-8692-6f2207f6efcb	{"sub": "4535c79c-f973-447b-8692-6f2207f6efcb", "email": "madisen_harris32@gmail.com"}	email	2023-01-19 11:08:39.50468+00	2023-01-19 11:08:39.504717+00	2023-01-19 11:08:39.504717+00
581483e9-b39a-477c-a712-a590a6bd2e8d	581483e9-b39a-477c-a712-a590a6bd2e8d	{"sub": "581483e9-b39a-477c-a712-a590a6bd2e8d", "email": "alexandre_rodriguez@gmail.com"}	email	2023-01-19 11:08:39.507102+00	2023-01-19 11:08:39.507136+00	2023-01-19 11:08:39.507136+00
0d9fd639-9b14-49c8-a755-36565a345da5	0d9fd639-9b14-49c8-a755-36565a345da5	{"sub": "0d9fd639-9b14-49c8-a755-36565a345da5", "email": "pierre33@gmail.com"}	email	2023-01-19 11:08:39.511573+00	2023-01-19 11:08:39.51161+00	2023-01-19 11:08:39.51161+00
af4d7654-d31d-40e9-8e31-8031b29651bf	af4d7654-d31d-40e9-8e31-8031b29651bf	{"sub": "af4d7654-d31d-40e9-8e31-8031b29651bf", "email": "laney.olson@gmail.com"}	email	2023-01-19 11:08:39.619015+00	2023-01-19 11:08:39.619079+00	2023-01-19 11:08:39.619079+00
c7f6fb1f-a6d9-45b1-a732-4e80877266ea	c7f6fb1f-a6d9-45b1-a732-4e80877266ea	{"sub": "c7f6fb1f-a6d9-45b1-a732-4e80877266ea", "email": "jodie.wunsch60@gmail.com"}	email	2023-01-19 11:08:39.636656+00	2023-01-19 11:08:39.636691+00	2023-01-19 11:08:39.636691+00
bdc41348-d0ff-4716-b03e-505397255296	bdc41348-d0ff-4716-b03e-505397255296	{"sub": "bdc41348-d0ff-4716-b03e-505397255296", "email": "micheal.homenick@yahoo.com"}	email	2023-01-19 11:08:39.669035+00	2023-01-19 11:08:39.669077+00	2023-01-19 11:08:39.669077+00
b5aca4b6-67f6-47b1-b2f5-794f354e183a	b5aca4b6-67f6-47b1-b2f5-794f354e183a	{"sub": "b5aca4b6-67f6-47b1-b2f5-794f354e183a", "email": "pauline.moore@hotmail.com"}	email	2023-01-19 11:08:39.696557+00	2023-01-19 11:08:39.696592+00	2023-01-19 11:08:39.696592+00
fef209ad-b677-44a7-82a9-899c33490075	fef209ad-b677-44a7-82a9-899c33490075	{"sub": "fef209ad-b677-44a7-82a9-899c33490075", "email": "ana.nikolaus95@yahoo.com"}	email	2023-01-19 14:40:58.677532+00	2023-01-19 14:40:58.67758+00	2023-01-19 14:40:58.67758+00
5de1bfbc-4a77-4e6b-8adf-e5572a32e12e	5de1bfbc-4a77-4e6b-8adf-e5572a32e12e	{"sub": "5de1bfbc-4a77-4e6b-8adf-e5572a32e12e", "email": "coby91@yahoo.com"}	email	2023-01-19 14:40:58.672691+00	2023-01-19 14:40:58.67274+00	2023-01-19 14:40:58.67274+00
68fb3828-45a2-41ee-8c32-fd87525d955e	68fb3828-45a2-41ee-8c32-fd87525d955e	{"sub": "68fb3828-45a2-41ee-8c32-fd87525d955e", "email": "vivian_rogahn@gmail.com"}	email	2023-01-19 14:40:58.904839+00	2023-01-19 14:40:58.904882+00	2023-01-19 14:40:58.904882+00
fe8d52b3-9bb2-4063-95da-733f61763be0	fe8d52b3-9bb2-4063-95da-733f61763be0	{"sub": "fe8d52b3-9bb2-4063-95da-733f61763be0", "email": "demetris99@yahoo.com"}	email	2023-01-19 14:40:59.04053+00	2023-01-19 14:40:59.040568+00	2023-01-19 14:40:59.040568+00
2d7cc448-9478-4c4b-b9c1-b6005b3b2517	2d7cc448-9478-4c4b-b9c1-b6005b3b2517	{"sub": "2d7cc448-9478-4c4b-b9c1-b6005b3b2517", "email": "casimir.williamson41@gmail.com"}	email	2023-01-19 14:40:59.073058+00	2023-01-19 14:40:59.073104+00	2023-01-19 14:40:59.073104+00
754e01ee-8bc6-42cf-875a-c596d4b5c108	754e01ee-8bc6-42cf-875a-c596d4b5c108	{"sub": "754e01ee-8bc6-42cf-875a-c596d4b5c108", "email": "rod_hoppe83@hotmail.com"}	email	2023-01-19 14:40:59.125133+00	2023-01-19 14:40:59.125176+00	2023-01-19 14:40:59.125176+00
27b98fb2-2a63-4ea2-92da-845e45c1f530	27b98fb2-2a63-4ea2-92da-845e45c1f530	{"sub": "27b98fb2-2a63-4ea2-92da-845e45c1f530", "email": "marlon.torp45@gmail.com"}	email	2023-01-19 14:40:59.164371+00	2023-01-19 14:40:59.164412+00	2023-01-19 14:40:59.164412+00
a23c4ec2-eb31-420d-bd89-ec49065bed3f	a23c4ec2-eb31-420d-bd89-ec49065bed3f	{"sub": "a23c4ec2-eb31-420d-bd89-ec49065bed3f", "email": "ansel.kessler89@yahoo.com"}	email	2023-01-19 14:40:59.194516+00	2023-01-19 14:40:59.194557+00	2023-01-19 14:40:59.194557+00
88847f43-2f69-4c26-b00b-6b59a38ef164	88847f43-2f69-4c26-b00b-6b59a38ef164	{"sub": "88847f43-2f69-4c26-b00b-6b59a38ef164", "email": "cali_orn71@yahoo.com"}	email	2023-01-19 14:40:59.195309+00	2023-01-19 14:40:59.195346+00	2023-01-19 14:40:59.195346+00
561e28e6-7ce5-4282-9f51-7f59722170ca	561e28e6-7ce5-4282-9f51-7f59722170ca	{"sub": "561e28e6-7ce5-4282-9f51-7f59722170ca", "email": "rey32@gmail.com"}	email	2023-01-19 14:40:59.192048+00	2023-01-19 14:40:59.192086+00	2023-01-19 14:40:59.192086+00
e42df43c-3603-45ea-a281-1c1c1b29965c	e42df43c-3603-45ea-a281-1c1c1b29965c	{"sub": "e42df43c-3603-45ea-a281-1c1c1b29965c", "email": "thora.renner@yahoo.com"}	email	2023-01-19 14:40:59.614062+00	2023-01-19 14:40:59.614098+00	2023-01-19 14:40:59.614098+00
07ede456-d7f3-4840-a2b2-6394cb6e44c6	07ede456-d7f3-4840-a2b2-6394cb6e44c6	{"sub": "07ede456-d7f3-4840-a2b2-6394cb6e44c6", "email": "estel.kovacek68@gmail.com"}	email	2023-01-19 14:40:59.653972+00	2023-01-19 14:40:59.654015+00	2023-01-19 14:40:59.654015+00
8858520b-4d3e-4804-937c-e1fa8b355e1e	8858520b-4d3e-4804-937c-e1fa8b355e1e	{"sub": "8858520b-4d3e-4804-937c-e1fa8b355e1e", "email": "althea_dickens@hotmail.com"}	email	2023-01-19 14:40:59.754789+00	2023-01-19 14:40:59.754826+00	2023-01-19 14:40:59.754826+00
26889fa5-94d3-4150-8b17-d7413e682652	26889fa5-94d3-4150-8b17-d7413e682652	{"sub": "26889fa5-94d3-4150-8b17-d7413e682652", "email": "francis_lockman21@yahoo.com"}	email	2023-01-19 14:40:59.808917+00	2023-01-19 14:40:59.808954+00	2023-01-19 14:40:59.808954+00
9beabeb7-b51b-416c-9d3b-d23badeb7c5c	9beabeb7-b51b-416c-9d3b-d23badeb7c5c	{"sub": "9beabeb7-b51b-416c-9d3b-d23badeb7c5c", "email": "adonis_lemke@gmail.com"}	email	2023-01-19 14:40:59.815894+00	2023-01-19 14:40:59.815928+00	2023-01-19 14:40:59.815928+00
b1262b76-e470-4f71-ad33-86919606391d	b1262b76-e470-4f71-ad33-86919606391d	{"sub": "b1262b76-e470-4f71-ad33-86919606391d", "email": "humberto_wolf@gmail.com"}	email	2023-01-19 14:40:59.859656+00	2023-01-19 14:40:59.859692+00	2023-01-19 14:40:59.859692+00
3298ca3a-400a-459e-b0a7-426fd6e9b716	3298ca3a-400a-459e-b0a7-426fd6e9b716	{"sub": "3298ca3a-400a-459e-b0a7-426fd6e9b716", "email": "buddy_hintz@gmail.com"}	email	2023-01-19 14:40:59.888234+00	2023-01-19 14:40:59.888268+00	2023-01-19 14:40:59.888268+00
a413cb80-834e-4d8a-b29a-a02ecf139f7c	a413cb80-834e-4d8a-b29a-a02ecf139f7c	{"sub": "a413cb80-834e-4d8a-b29a-a02ecf139f7c", "email": "brando_treutel@gmail.com"}	email	2023-01-19 14:41:00.294838+00	2023-01-19 14:41:00.294879+00	2023-01-19 14:41:00.294879+00
b096f178-9502-4837-bf3c-37acccf61eb9	b096f178-9502-4837-bf3c-37acccf61eb9	{"sub": "b096f178-9502-4837-bf3c-37acccf61eb9", "email": "ara_volkman7@gmail.com"}	email	2023-01-19 14:41:00.380085+00	2023-01-19 14:41:00.380125+00	2023-01-19 14:41:00.380125+00
54b2da7d-4765-44f1-9e95-b6ebd5494530	54b2da7d-4765-44f1-9e95-b6ebd5494530	{"sub": "54b2da7d-4765-44f1-9e95-b6ebd5494530", "email": "horace_borer96@yahoo.com"}	email	2023-01-19 14:41:00.424166+00	2023-01-19 14:41:00.424205+00	2023-01-19 14:41:00.424205+00
88e9b920-6bc9-4242-a245-2c2164c01092	88e9b920-6bc9-4242-a245-2c2164c01092	{"sub": "88e9b920-6bc9-4242-a245-2c2164c01092", "email": "princess24@gmail.com"}	email	2023-01-19 14:41:00.72796+00	2023-01-19 14:41:00.727998+00	2023-01-19 14:41:00.727998+00
7dff3d1f-c92e-45d8-ad4f-47976249728f	7dff3d1f-c92e-45d8-ad4f-47976249728f	{"sub": "7dff3d1f-c92e-45d8-ad4f-47976249728f", "email": "heather.corwin32@hotmail.com"}	email	2023-01-19 14:41:00.732996+00	2023-01-19 14:41:00.733031+00	2023-01-19 14:41:00.733031+00
ec352166-6475-453f-825b-01bee04214d9	ec352166-6475-453f-825b-01bee04214d9	{"sub": "ec352166-6475-453f-825b-01bee04214d9", "email": "jamarcus94@hotmail.com"}	email	2023-01-19 14:41:00.801386+00	2023-01-19 14:41:00.801419+00	2023-01-19 14:41:00.801419+00
b576f0a4-3369-4e89-8221-72a89807dde9	b576f0a4-3369-4e89-8221-72a89807dde9	{"sub": "b576f0a4-3369-4e89-8221-72a89807dde9", "email": "efrain37@yahoo.com"}	email	2023-01-19 14:41:00.824851+00	2023-01-19 14:41:00.824888+00	2023-01-19 14:41:00.824888+00
2e320c50-4640-43d4-8e82-5e66c430decd	2e320c50-4640-43d4-8e82-5e66c430decd	{"sub": "2e320c50-4640-43d4-8e82-5e66c430decd", "email": "anibal61@yahoo.com"}	email	2023-01-19 14:40:59.474355+00	2023-01-19 14:40:59.474391+00	2023-01-19 14:40:59.474391+00
ca9f8106-a060-4bd5-be47-d9f384e415ca	ca9f8106-a060-4bd5-be47-d9f384e415ca	{"sub": "ca9f8106-a060-4bd5-be47-d9f384e415ca", "email": "gavin33@hotmail.com"}	email	2023-01-19 14:41:00.351925+00	2023-01-19 14:41:00.351966+00	2023-01-19 14:41:00.351966+00
46867bb3-7c26-4ced-9ec0-268afda9ca10	46867bb3-7c26-4ced-9ec0-268afda9ca10	{"sub": "46867bb3-7c26-4ced-9ec0-268afda9ca10", "email": "lewis62@gmail.com"}	email	2023-01-19 14:40:59.489324+00	2023-01-19 14:40:59.489361+00	2023-01-19 14:40:59.489361+00
366b7862-08df-4b9e-8ba3-8e2d7fbe7a40	366b7862-08df-4b9e-8ba3-8e2d7fbe7a40	{"sub": "366b7862-08df-4b9e-8ba3-8e2d7fbe7a40", "email": "lue.dibbert26@hotmail.com"}	email	2023-01-19 14:41:00.341378+00	2023-01-19 14:41:00.341415+00	2023-01-19 14:41:00.341415+00
7f08b69b-c277-4af8-a51e-9f80bad06430	7f08b69b-c277-4af8-a51e-9f80bad06430	{"sub": "7f08b69b-c277-4af8-a51e-9f80bad06430", "email": "kobe_bergnaum47@yahoo.com"}	email	2023-01-19 14:40:59.74144+00	2023-01-19 14:40:59.741477+00	2023-01-19 14:40:59.741477+00
0b448f5c-7c26-498b-8d13-7049cf82037c	0b448f5c-7c26-498b-8d13-7049cf82037c	{"sub": "0b448f5c-7c26-498b-8d13-7049cf82037c", "email": "arne_bayer91@yahoo.com"}	email	2023-01-19 14:41:00.797067+00	2023-01-19 14:41:00.79713+00	2023-01-19 14:41:00.79713+00
\.


--
-- Data for Name: instances; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.instances (id, uuid, raw_base_config, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: mfa_amr_claims; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.mfa_amr_claims (session_id, created_at, updated_at, authentication_method, id) FROM stdin;
\.


--
-- Data for Name: mfa_challenges; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.mfa_challenges (id, factor_id, created_at, verified_at, ip_address) FROM stdin;
\.


--
-- Data for Name: mfa_factors; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.mfa_factors (id, user_id, friendly_name, factor_type, status, created_at, updated_at, secret) FROM stdin;
\.


--
-- Data for Name: refresh_tokens; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.refresh_tokens (instance_id, id, token, user_id, revoked, created_at, updated_at, parent, session_id) FROM stdin;
00000000-0000-0000-0000-000000000000	1	pBxe3O0yvpz4MqgUFS_wnQ	be55c6cf-94d2-44a4-a119-61297d68c0e8	f	2022-08-30 12:59:49.906193+00	2022-08-30 12:59:49.906197+00	\N	0511ce36-dba0-4a11-9b4c-0b68534c061f
00000000-0000-0000-0000-000000000000	2	bhzSsrlwilQCq_Wh50aOPw	1b48e703-cb29-4b76-b804-82a53f074b93	f	2022-09-14 10:19:09.444837+00	2022-09-14 10:19:09.444841+00	\N	2cd6bfe6-1132-4ff4-95b4-026db86e8bcf
00000000-0000-0000-0000-000000000000	3	zU3aesC_qCRQ9wchR_CpFQ	1b48e703-cb29-4b76-b804-82a53f074b93	f	2022-09-20 09:06:33.911565+00	2022-09-20 09:06:33.911568+00	\N	5f93a2d7-5712-4216-a6e7-4c7de40bafa4
00000000-0000-0000-0000-000000000000	4	kW81POOeB4Ts45afIog8WA	1b48e703-cb29-4b76-b804-82a53f074b93	f	2022-09-20 09:23:51.629517+00	2022-09-20 09:23:51.629521+00	\N	379572ef-778e-4c81-8f42-0b2b6961d004
00000000-0000-0000-0000-000000000000	5	r60ODOU2UxrvencX5E1U-g	1b48e703-cb29-4b76-b804-82a53f074b93	f	2022-09-20 09:32:43.911888+00	2022-09-20 09:32:43.911891+00	\N	b1d77e87-9a68-448d-9c6a-7580f1649f08
\.


--
-- Data for Name: saml_providers; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.saml_providers (id, sso_provider_id, entity_id, metadata_xml, metadata_url, attribute_mapping, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: saml_relay_states; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.saml_relay_states (id, sso_provider_id, request_id, for_email, redirect_to, from_ip_address, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: schema_migrations; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.schema_migrations (version) FROM stdin;
20171026211738
20171026211808
20171026211834
20180103212743
20180108183307
20180119214651
20180125194653
00
20210710035447
20210722035447
20210730183235
20210909172000
20210927181326
20211122151130
20211124214934
20211202183645
20220114185221
20220114185340
20220224000811
20220323170000
20220429102000
20220531120530
20220614074223
20220811173540
20221003041349
20221003041400
20221011041400
20221020193600
20221021073300
20221021082433
20221027105023
20221114143122
20221114143410
20221125140132
20221208132122
20221215195500
20221215195800
20221215195900
20230116124310
20230116124412
20230131181311
20230322519590
20230402418590
20230411005111
20230508135423
20230523124323
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.sessions (id, user_id, created_at, updated_at, factor_id, aal, not_after) FROM stdin;
0511ce36-dba0-4a11-9b4c-0b68534c061f	be55c6cf-94d2-44a4-a119-61297d68c0e8	2022-08-30 12:59:49.904128+00	2022-08-30 12:59:49.904133+00	\N	\N	\N
2cd6bfe6-1132-4ff4-95b4-026db86e8bcf	1b48e703-cb29-4b76-b804-82a53f074b93	2022-09-14 10:19:09.443186+00	2022-09-14 10:19:09.44319+00	\N	\N	\N
5f93a2d7-5712-4216-a6e7-4c7de40bafa4	1b48e703-cb29-4b76-b804-82a53f074b93	2022-09-20 09:06:33.910434+00	2022-09-20 09:06:33.910436+00	\N	\N	\N
379572ef-778e-4c81-8f42-0b2b6961d004	1b48e703-cb29-4b76-b804-82a53f074b93	2022-09-20 09:23:51.627974+00	2022-09-20 09:23:51.627976+00	\N	\N	\N
b1d77e87-9a68-448d-9c6a-7580f1649f08	1b48e703-cb29-4b76-b804-82a53f074b93	2022-09-20 09:32:43.910773+00	2022-09-20 09:32:43.910776+00	\N	\N	\N
\.


--
-- Data for Name: sso_domains; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.sso_domains (id, sso_provider_id, domain, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: sso_providers; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.sso_providers (id, resource_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: auth; Owner: supabase_auth_admin
--

COPY auth.users (instance_id, id, aud, role, email, encrypted_password, email_confirmed_at, invited_at, confirmation_token, confirmation_sent_at, recovery_token, recovery_sent_at, email_change_token_new, email_change, email_change_sent_at, last_sign_in_at, raw_app_meta_data, raw_user_meta_data, is_super_admin, created_at, updated_at, phone, phone_confirmed_at, phone_change, phone_change_token, phone_change_sent_at, email_change_token_current, email_change_confirm_status, banned_until, reauthentication_token, reauthentication_sent_at, is_sso_user, deleted_at) FROM stdin;
00000000-0000-0000-0000-000000000000	be55c6cf-94d2-44a4-a119-61297d68c0e8	authenticated	authenticated	ionicisere@gmail.com	$2a$10$r/w9qJ9Z3yr5hYHubUweZejnj7tqhnTkuiGPo5HJ/JDigTfEWbDxS	2022-08-30 12:59:49.902581+00	2022-08-30 12:59:35.990761+00		2022-08-30 12:59:35.990761+00		\N			\N	2022-08-30 12:59:49.904092+00	{"provider": "email", "providers": ["email"]}	{}	\N	2022-08-30 12:59:35.982779+00	2022-08-30 12:59:49.909025+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	1b48e703-cb29-4b76-b804-82a53f074b93	authenticated	authenticated	bradley@appwrite.io	$2a$10$MjBZgSYw.PbNwWogjjA44eFDi1Wxj3qR9xwL57r38iS1W/g7mqD1e	2022-09-14 10:19:09.44204+00	2022-09-14 10:09:18.313665+00		2022-09-14 10:09:18.313665+00		2022-09-20 09:32:37.017851+00			\N	2022-09-20 09:32:43.910738+00	{"provider": "email", "providers": ["email"]}	{}	\N	2022-09-14 10:09:18.30351+00	2022-09-20 09:33:47.026002+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	a7ca4ce7-7763-4edc-869a-dc3afb4dc1c2	authenticated	authenticated	ada.parker@gmail.com	$2a$10$GeeaVuNxnYP2iBpMmLl.oOWHCge9/ICG4OeduhRC9oh4WJ3bQ3E2O	\N	\N	f40c311041b24216ebe846312fe65dd159569a3472fa477e66650d02	2023-01-19 11:08:38.627948+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:38.621709+00	2023-01-19 11:08:39.171258+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	e6e80a3d-8435-4d09-bba3-e8f158ff93e4	authenticated	authenticated	elliott_goldner77@yahoo.com	$2a$10$K5FN9Y17r2M3vRnJjdBxxOc02akQY9JxN38Ps09cUuh/FdNUveoSq	\N	\N	e5e0df9fc30adeb96c73901e04ee086ee1f1045563f16705e6e00252	2023-01-19 11:08:39.294093+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.2656+00	2023-01-19 11:08:39.856707+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	615357b8-c668-45ee-a749-c96db1aabc7a	authenticated	authenticated	albert.kihn95@yahoo.com	$2a$10$NGZAAOfXeheUoH9V3dnRoeR.r3J5ynnSZ6KjvHxOUlV8XUrulJzQa	\N	\N	7c6d17d95f79959cb5c2e78bcfce0f3b4d79a64d6e944c8f1c17fd0b	2023-01-19 11:08:37.987925+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.961105+00	2023-01-19 11:08:38.533265+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	932ce7f7-e57d-45c3-bc7d-63362e2d67aa	authenticated	authenticated	eino_considine67@hotmail.com	$2a$10$UAtycuP68R6/s7BBXckGbeGVg26N/CuIBqvwdTPV.//yoCaaW9sNO	\N	\N	fe79c5e0ddc151b8d0b3d99a86b6f9c02f4fc4e3b5f8faae11ec5d4b	2023-01-19 11:08:38.04048+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.890482+00	2023-01-19 11:08:38.546294+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	e7faf866-0438-4b7c-8de3-1c134c707806	authenticated	authenticated	osvaldo.bogan15@yahoo.com	$2a$10$hK1ujHgvMcBgzLJ4FeKH6Omz7tX6voVKzFvIVoGpEvf0R7.0HfqLW	\N	\N	88fa60647707bca81b0dfdb171317655fe6258bca6967a3d8f5a7147	2023-01-19 11:08:37.99834+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.832183+00	2023-01-19 11:08:38.622417+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	b301a3c1-022f-49fa-a6fb-41c8cdd521a9	authenticated	authenticated	jerrod70@yahoo.com	$2a$10$OJSneRhPXRpWXn05ZkH6X..5/7iRVRbnU7sBtvFFhkLfSUTqAtEtu	\N	\N	f547effd9a91b0f30b777e9696f692dfb20d56a4254c8b1ec9fa67f5	2023-01-19 11:08:38.048714+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.976452+00	2023-01-19 11:08:38.622149+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	23e19499-63e4-4fee-9718-bd24584fdca0	authenticated	authenticated	royce_hermiston@gmail.com	$2a$10$5M2B42RfDcKhqgr9q5SoDO4OXhxYJ7i/E3XDnX8TMiGLH1U2LpDa2	\N	\N	077fa18a56afb21c750fc8398d9814d3bc0b9bf0a9b255c810594607	2023-01-19 11:08:38.040758+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.915266+00	2023-01-19 11:08:38.623762+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	07616a1c-e8ce-489a-a74b-adab1f4e1b32	authenticated	authenticated	rylee68@yahoo.com	$2a$10$ZpClmjACR4TcfxpZ1lVoWuisi5K.GCbALgFJwrOJQSJuvLF48um0K	\N	\N	892319f33d84c697a7f743de2afd667f39cf020eff19b0ac524257c0	2023-01-19 11:08:38.033036+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.998954+00	2023-01-19 11:08:38.641677+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	53cd7be4-c374-492c-aa18-06febf196607	authenticated	authenticated	lonnie_huels69@gmail.com	$2a$10$DOxK2zFxiouc5o1cPMnkcuTdM4g4FoJs3tMs49Xr1zuO/HwRTGebe	\N	\N	9826cf5f7c345b3af8ee746cd644d917a0ea83e235e09cb866429f9c	2023-01-19 11:08:38.818433+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:38.641261+00	2023-01-19 11:08:39.293169+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	4535c79c-f973-447b-8692-6f2207f6efcb	authenticated	authenticated	madisen_harris32@gmail.com	$2a$10$iDDzjps6TkD.ld6Ym5L.cuKBCoTTZPGXgTfebkoLNXRF8.svFfz5.	\N	\N	f326f2090e51ceab905155061f777c98c549cc95888cd7c1ba344d22	2023-01-19 11:08:39.506209+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.502072+00	2023-01-19 11:08:39.904436+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	ab38ad48-7b50-4e66-85b1-7d78a61a04f0	authenticated	authenticated	devin.rath@gmail.com	$2a$10$5kCdSNjRcHN8npUjzsQ7FeHhl3nlBh79iJvzrVK461yIYbJfaWOgG	\N	\N	989e63c456f64ef55f43796f35b57c57114ab81ca3d1675b765e98e5	2023-01-19 11:08:38.033607+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.984272+00	2023-01-19 11:08:38.724608+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	abb439a6-9812-4edb-8f54-8f99830a9d51	authenticated	authenticated	julianne.schinner98@gmail.com	$2a$10$vb3H97UZyGnxRnH.fFWj2ORQJsWAKAGIIg/VxVoj6H/vPrtMuGfEW	\N	\N	018c2302f9a0f2d411f075cfefd083d71e0f4a010341f4b68b529558	2023-01-19 11:08:38.945423+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:38.941342+00	2023-01-19 11:08:39.414274+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	bdc41348-d0ff-4716-b03e-505397255296	authenticated	authenticated	micheal.homenick@yahoo.com	$2a$10$3zaSVvuuXN1R8pDFUVcDPu7UdgwJDBMVZ8h7lF2ItZ1fOCfnP1FCa	\N	\N	b0ab2941b518793bc3dd6b78a8f7d22151a6034ea91cdfd350b76702	2023-01-19 11:08:39.670486+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.666419+00	2023-01-19 11:08:39.974713+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	369664ac-9358-4b51-91b5-79ddca7ef0b2	authenticated	authenticated	maida_walsh61@yahoo.com	$2a$10$Q7QA.QW6tPPtX86NRzxqcORC9wR58PWaS5ixYB.9tSz/Txm8RJkJ6	\N	\N	e8954d47bb74535ecdd509216fbf6b7bb2bc0d82f2d167c334d2f66e	2023-01-19 11:08:37.993574+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.92671+00	2023-01-19 11:08:38.726342+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	67cb7f0e-c295-4d41-83a0-761568b7a13c	authenticated	authenticated	craig.dietrich31@hotmail.com	$2a$10$wg.NDIAgvj0zVc0udeucgO0Boc/3x2UV1pE6tWqDonXNTR1jNBc6K	\N	\N	d477bd867d61c1ba94a4384eb406c9ab28d5cda8742cc6057fc639a1	2023-01-19 11:08:39.035758+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.015133+00	2023-01-19 11:08:39.413947+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	c7f6fb1f-a6d9-45b1-a732-4e80877266ea	authenticated	authenticated	jodie.wunsch60@gmail.com	$2a$10$Fo0K6NTjFcm5Aoq/xkhYBui0hjBNQIFlKY87aXZGImuTHrCVvn3.y	\N	\N	4ae77463d8a990faa1989907962d8449bf6866f196ff7ae35a1388a4	2023-01-19 11:08:39.638061+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.630447+00	2023-01-19 11:08:40.031006+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	88043554-4aac-46de-8437-c02fdacfdc9c	authenticated	authenticated	misael.upton98@hotmail.com	$2a$10$6nnhh/4pvc7ENxqSgxKeFu1k9K3/IWcXMLFngooQopnx7QRhl.xaG	\N	\N	12340b0d13e0b3b9ca1bcb93c8dea120c52c9d9c61cc04754b5efb73	2023-01-19 11:08:38.006529+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.952335+00	2023-01-19 11:08:38.725996+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	d1c2cd22-52db-4074-b0ba-e7152df4a27d	authenticated	authenticated	sunny.welch@gmail.com	$2a$10$MxZcZmD0RpqUVE2n.t.Vg.7/TMV2t7qPn8Jwh6Y1sK/1tgSARu3Py	\N	\N	dab91bd4244420789fe50b5cd065930ebcdf4dcf4e3acee439e40ae8	2023-01-19 11:08:39.039895+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.029386+00	2023-01-19 11:08:39.461112+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	af4d7654-d31d-40e9-8e31-8031b29651bf	authenticated	authenticated	laney.olson@gmail.com	$2a$10$73D85ZzhLD62lQnMog4K0uRZvMlcf0fcvs3fE7NAWNqD5znfoX6aa	\N	\N	bf0f017bc6c07aa07ac4386a62b78e09b9ca0a3060ca5052ee9fdbf9	2023-01-19 11:08:39.620535+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.595651+00	2023-01-19 11:08:39.948053+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	af00f659-028a-4268-b6b5-fa36e40e190e	authenticated	authenticated	wyman67@gmail.com	$2a$10$CtF/ECNwNRi.on9UvEmutO/5LgqlXlfLDjtI6Qqkd0tgWngfXCRP2	\N	\N	05bc7419506b52d336ea2d804f94e56f52660606dd129c4c5a6b378d	2023-01-19 11:08:38.006106+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:37.88958+00	2023-01-19 11:08:38.778102+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	acbbb7e0-44ff-42fa-a8aa-69ad98666e31	authenticated	authenticated	adonis_oconnell@yahoo.com	$2a$10$gYz/6D4ThCKCwIamrR/DQ.uJt8SM6vq5T3Jy2ux9V3t7o/nVkx2cC	\N	\N	308e4d2501a2cd6b1fd5c441d868580ad34b6637fb5a4d9362155a60	2023-01-19 11:08:39.011778+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:38.998524+00	2023-01-19 11:08:39.513701+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	b5aca4b6-67f6-47b1-b2f5-794f354e183a	authenticated	authenticated	pauline.moore@hotmail.com	$2a$10$8mzGxW07TrUW3YKfpjlvReoineG5PWWopHIR3hnER107vi4VjTaCq	\N	\N	0f8bc3154f92e7cd2d2c2af960662770cbc233a16699b372fe46e24a	2023-01-19 11:08:39.698148+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.693733+00	2023-01-19 11:08:40.046381+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	d977c288-485b-44f4-aa34-94c232decbed	authenticated	authenticated	aryanna_dickens47@gmail.com	$2a$10$acKiPRIGQc19DLIr2clTzesFMJ86HY0io3JnXIK9hHAna.G2Sh2J6	\N	\N	acec97cf6dee7adce3fd551a065839a78c2b389f880a285ea859838d	2023-01-19 11:08:38.799099+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:38.794485+00	2023-01-19 11:08:39.198817+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	8e40363f-20c0-4f58-ac8f-fecaec606923	authenticated	authenticated	cheyenne_cassin@hotmail.com	$2a$10$4vED.s6uCIYpS5oHN/Ge3uUVo4EJ7p47Io.E7x0JiU8H3B/DpsYaW	\N	\N	dc2e42e3c4b43bb7716cb2576e04b9b59ca7af22c43e5e27dcc24357	2023-01-19 11:08:39.346821+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.314765+00	2023-01-19 11:08:39.84668+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	2b817ed6-e9b4-4ab6-a97c-8a5c5cca80b5	authenticated	authenticated	jennings.watsica71@hotmail.com	$2a$10$agWJM753ThOSQdlZNkEBa.RnNUK4oFiXywyfo.5D8D8Cuohj1ZJRe	\N	\N	5571f3f9f2f5bcf33c0be925871401d3a83256865b77d350082b590d	2023-01-19 11:08:38.845784+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:38.825053+00	2023-01-19 11:08:39.239774+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	581483e9-b39a-477c-a712-a590a6bd2e8d	authenticated	authenticated	alexandre_rodriguez@gmail.com	$2a$10$JVPIgsLvTNqwQX73f724m.k0dwQ8GKvnPdkeech9sUuxb0pdik5Vm	\N	\N	9f09631bdf96f286f870d52b058d2bb7daa24d97704e971f7f7ba084	2023-01-19 11:08:39.508709+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.411206+00	2023-01-19 11:08:39.892652+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	1d1c4838-d7bf-4830-945e-7f1c31934340	authenticated	authenticated	mallory_kuhn@yahoo.com	$2a$10$fmrY.TdL/f.7YHnDyP3ZUODjglDno405Munb8K3yJ3Tw7TUpXMdCq	\N	\N	b6818ad2fa7592e2c6bd39e5cc608aa3b2ac8f920457732a2c07eb6d	2023-01-19 11:08:38.910173+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:38.840443+00	2023-01-19 11:08:39.280097+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	5c6a9645-29e1-478e-9cba-d048ab579bd1	authenticated	authenticated	magdalena_metz@gmail.com	$2a$10$14tT9gkqI2nTMWWQFaXT..qnSzbdTQGr5bnZQGkhcjgQT041sIxty	\N	\N	a7d1a730cf9c469069d36270bf16a8f7b719efbb35f498df854b11f4	2023-01-19 11:08:39.482243+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.455923+00	2023-01-19 11:08:39.925029+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	5de1bfbc-4a77-4e6b-8adf-e5572a32e12e	authenticated	authenticated	coby91@yahoo.com	$2a$10$ZarjL3uOinIDiJaqXXdQNuCj9Ol6E0pHmTwGYjnDtTV8fjvwLU4p2	\N	\N	e7cb8b0c7e3f84612f5b127411fc0431e8f2c3f146feb3e3660e8986	2023-01-19 14:40:58.835941+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:58.617251+00	2023-01-19 14:40:59.393384+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	ba1d736c-18f9-4eca-8000-05bd223d097f	authenticated	authenticated	alia.emmerich42@hotmail.com	$2a$10$DfXcVwodmMCPyUkkVJhpvOY2qPX1elMpnkmY5RmVfJ6/QYZlluPSG	\N	\N	49132c9b2f2495e94ffe7e5b51c37f1080ad244941c79ae0cd3ad107	2023-01-19 11:08:38.885235+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:38.880933+00	2023-01-19 11:08:39.260143+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	0d9fd639-9b14-49c8-a755-36565a345da5	authenticated	authenticated	pierre33@gmail.com	$2a$10$VzskcnSwVR3WJflIBh9foeP9zvSIAEs2DUgpl4YnEbZcFMW9O5mmC	\N	\N	48788ad0d702a5613ba8528faa6d8c5bfe747e1d53981fb37bc02d90	2023-01-19 11:08:39.513064+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 11:08:39.481569+00	2023-01-19 11:08:39.85781+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	07ede456-d7f3-4840-a2b2-6394cb6e44c6	authenticated	authenticated	estel.kovacek68@gmail.com	$2a$10$OkPbpEQ7cFTlJBZbAMLNWu0wvSKI3O11P56RqzN7h.8nwy6aSl2J2	\N	\N	e4eb7055f04178cd8cf9ece4bb45ba9ad57d1d6ff0eb75aefcb26c64	2023-01-19 14:40:59.655644+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.619794+00	2023-01-19 14:41:00.151257+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	a23c4ec2-eb31-420d-bd89-ec49065bed3f	authenticated	authenticated	ansel.kessler89@yahoo.com	$2a$10$90aSXQyDc.VfwmkQX9tFp.DGOx5lknqC/nA8M8yOqaufGCktFmVHW	\N	\N	795af0b942dd0fbf8860286b6072f592aa0dd21b64a264500468c540	2023-01-19 14:40:59.201093+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.144565+00	2023-01-19 14:40:59.616499+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	27b98fb2-2a63-4ea2-92da-845e45c1f530	authenticated	authenticated	marlon.torp45@gmail.com	$2a$10$ltqikNmlYh/bOgUnnUh.1OiUHTndQUpl9d856BHM7NmlrsJEhqPzy	\N	\N	a912390b874a3a88ac09aad04eb80b509392e427acfbce093e4d58a9	2023-01-19 14:40:59.179137+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.158889+00	2023-01-19 14:40:59.620199+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	88847f43-2f69-4c26-b00b-6b59a38ef164	authenticated	authenticated	cali_orn71@yahoo.com	$2a$10$i/VNgA5Q22gGQ0OCyvcUauCpwVbFUmGx5reFaFoX8ac7a34rTZyCS	\N	\N	a89d90f30b2859d523e8ee9702311dfadfadcd55f9c2e78aa3366149	2023-01-19 14:40:59.197369+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.172183+00	2023-01-19 14:40:59.725418+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	b1262b76-e470-4f71-ad33-86919606391d	authenticated	authenticated	humberto_wolf@gmail.com	$2a$10$UDK5nDmKCwix.PYQdpFiQ.KPRDn7cYaL1xuI7dpYKxS4FZtpnCuRO	\N	\N	2710337612b98c95e51b8bc7900323999363f5a96f163d6fabf73a97	2023-01-19 14:40:59.861071+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.856837+00	2023-01-19 14:41:00.641247+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	366b7862-08df-4b9e-8ba3-8e2d7fbe7a40	authenticated	authenticated	lue.dibbert26@hotmail.com	$2a$10$rH9sNLFro/A32zhE3KJ14eezpNV6axRauEUrvkVVHsiCdiw1QBnti	\N	\N	10e028c37d3e4ae9eb741c9da1a3615b8b381d81c70f7f71e1e420e4	2023-01-19 14:41:00.342917+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.297873+00	2023-01-19 14:41:00.80361+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	68fb3828-45a2-41ee-8c32-fd87525d955e	authenticated	authenticated	vivian_rogahn@gmail.com	$2a$10$L2qcapE93Sq35WR.lHeQ3eRN641Axr9F.QNM/5M6OeqnZdxCzaJIi	\N	\N	a9e8d2db7d93206e509b05a1dd7a3cb10e82ab0bc6465cbd171d634e	2023-01-19 14:40:58.906891+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:58.899728+00	2023-01-19 14:40:59.357833+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	2d7cc448-9478-4c4b-b9c1-b6005b3b2517	authenticated	authenticated	casimir.williamson41@gmail.com	$2a$10$ImEdz6ANAscNyInjDuYGHOCySiEWzV031mQElBoEUp9Q6B7V6EVom	\N	\N	1848d5557a10b20be09448fc4e8cbf35154ec4cd75f6df06616e113a	2023-01-19 14:40:59.076775+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.017546+00	2023-01-19 14:40:59.488707+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	754e01ee-8bc6-42cf-875a-c596d4b5c108	authenticated	authenticated	rod_hoppe83@hotmail.com	$2a$10$2Is3g0GMBSrY48CZt8OF6u6/tlhzX6OutrWnzUpHt9hZVpp2IQCt.	\N	\N	923b1d84244e5473b83d8d904e3ca4db70756a21556e85a324be07f1	2023-01-19 14:40:59.127685+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:58.989152+00	2023-01-19 14:40:59.50326+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	fe8d52b3-9bb2-4063-95da-733f61763be0	authenticated	authenticated	demetris99@yahoo.com	$2a$10$qH3xbDG8SP9m5tGLI.dRh.XW31lamljcDfPG0ygyGYV45FIrsVRyu	\N	\N	e99c1d4bdb036ab3073293832c8a78ca4381e6f75a033fa955cd8e41	2023-01-19 14:40:59.173555+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:58.917486+00	2023-01-19 14:40:59.621773+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	b576f0a4-3369-4e89-8221-72a89807dde9	authenticated	authenticated	efrain37@yahoo.com	$2a$10$86vlB0oTb9cdD96.qJ4IDe6pFw.GcjKdaVwwWBpapfGuTYvbEkT8O	\N	\N	7209056df97fcceaaae6e0782e945ff1ad4ce93cc308596a7a1d776c	2023-01-19 14:41:00.826379+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.821857+00	2023-01-19 14:41:01.163667+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	ca9f8106-a060-4bd5-be47-d9f384e415ca	authenticated	authenticated	gavin33@hotmail.com	$2a$10$zvkZ82K9xbd.0NUQpl2.JuAe4mAN9rsd1lMAASR9mRneM.8C530/K	\N	\N	82eab8491e8bb28564e3ce4e25b7f5e35b280d81a46faa6b14d827fa	2023-01-19 14:41:00.353569+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.338685+00	2023-01-19 14:41:01.164308+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	46867bb3-7c26-4ced-9ec0-268afda9ca10	authenticated	authenticated	lewis62@gmail.com	$2a$10$Ao8UXsOm.mYJx5Y5uxMIXeIySE8TxuS99tTLm2B3iOWwmgV/aCbQm	\N	\N	98912a145b10712750d9cca55eed5429d0a3b9833bdddaf82322e907	2023-01-19 14:40:59.491954+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.485996+00	2023-01-19 14:41:00.022215+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	2e320c50-4640-43d4-8e82-5e66c430decd	authenticated	authenticated	anibal61@yahoo.com	$2a$10$G8qYQ/rI8yteJwQ0hVuqVeaG63IbA.JpU/y19C6yida7Zsq47CVFG	\N	\N	2cad37b24957edc589b422386958fcb15d676a4339cce9dfbeacc9ea	2023-01-19 14:40:59.475874+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.471653+00	2023-01-19 14:41:00.039101+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	fef209ad-b677-44a7-82a9-899c33490075	authenticated	authenticated	ana.nikolaus95@yahoo.com	$2a$10$xL1HjHXBtGkD05wTk3cx6eefp8GQgb0gngGEhoRu0ipGntps7Kdqm	\N	\N	20d90286908d350ab9ce64d82bfc26f86abbd0619bebfc25c0c42d21	2023-01-19 14:40:58.725194+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:58.637887+00	2023-01-19 14:40:59.552296+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	7f08b69b-c277-4af8-a51e-9f80bad06430	authenticated	authenticated	kobe_bergnaum47@yahoo.com	$2a$10$FTYKtvyg3bUT6C17QrmGp.FWF91QYzkX5QMMj1YoyqUmtb4VmiWru	\N	\N	13b88b3e70e3d7f0be7d139a584f87a34673c26613744b532e6d09dc	2023-01-19 14:40:59.833432+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.738616+00	2023-01-19 14:41:00.620983+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	0b448f5c-7c26-498b-8d13-7049cf82037c	authenticated	authenticated	arne_bayer91@yahoo.com	$2a$10$FAGausswNagoC2.o5zAEaeGkqcyHYgbbYvM/NpSKki7IsD.Pf2d3C	\N	\N	5d02b72c31d4551b0e8206a8e2f99ac6a03637614ee91e9549df59ed	2023-01-19 14:41:00.798582+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.794406+00	2023-01-19 14:41:01.192666+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	561e28e6-7ce5-4282-9f51-7f59722170ca	authenticated	authenticated	rey32@gmail.com	$2a$10$n9whZrraPvrDrwDORkWUB.9QIZr/8ej10iQWfB5KS2eN3Vqki6S/W	\N	\N	a409dd4395aac24d5dbeff7bfec69b585815213f8b100f5f5a7d222b	2023-01-19 14:40:59.19846+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.150226+00	2023-01-19 14:40:59.574338+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	8858520b-4d3e-4804-937c-e1fa8b355e1e	authenticated	authenticated	althea_dickens@hotmail.com	$2a$10$f5rqFcHddSeTVflKLC94/u5x.a9VcISaLnzhussrHbvMlCf/.Gjda	\N	\N	dbc774b5a842c40dc355b06c28af7a610279eb4ee3c0200443a27d13	2023-01-19 14:40:59.756207+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.709773+00	2023-01-19 14:41:00.186285+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	54b2da7d-4765-44f1-9e95-b6ebd5494530	authenticated	authenticated	horace_borer96@yahoo.com	$2a$10$uiyeTSCdmPN639oB.jNXueIHvxlY0rPAhMR2vSg9QTLjW.5h2vL.u	\N	\N	e8c0615575cd86a33292106b3379d6a35798d1fb7c1a0288aa7ce15f	2023-01-19 14:41:00.425868+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.420619+00	2023-01-19 14:41:00.968277+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	e42df43c-3603-45ea-a281-1c1c1b29965c	authenticated	authenticated	thora.renner@yahoo.com	$2a$10$mk8kCvtTJUEvNAmpZiqel.F1CUQEefQTlay/ydx3iUcK4v5jG6TDi	\N	\N	6b02a01a66b56e26e56e7cdd6516ab062f17e162490873002135236e	2023-01-19 14:40:59.615636+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.597518+00	2023-01-19 14:41:00.036891+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	a413cb80-834e-4d8a-b29a-a02ecf139f7c	authenticated	authenticated	brando_treutel@gmail.com	$2a$10$EWlXNwEQ4emuyb.BKr2lNuayuBZs/pZ.Ho.KPCNf8wp7h4s/UNDYG	\N	\N	e22020c1d53d0768bbbf2663041053fc141da7a5f48ac3210bec12af	2023-01-19 14:41:00.354617+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.291317+00	2023-01-19 14:41:00.799496+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	b096f178-9502-4837-bf3c-37acccf61eb9	authenticated	authenticated	ara_volkman7@gmail.com	$2a$10$A6db028bqFS8JDrqyVuecOrB2Xq4qgGJgKi.wGhRVgLCSYSVa0jeS	\N	\N	b5fe693c8643381f1bf66e71ba5990e21d870001b638f6b46a5b853b	2023-01-19 14:41:00.382393+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.372818+00	2023-01-19 14:41:00.761849+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	9beabeb7-b51b-416c-9d3b-d23badeb7c5c	authenticated	authenticated	adonis_lemke@gmail.com	$2a$10$R5M4FJIYUkuycpYnHdj.oOKulZ2qx1ckeIwvk/.DQ3sFJjFVr7Ox6	\N	\N	fbbaf841ffa9496051531ca30065e4ff9b74fb8680fd03dccd07961c	2023-01-19 14:40:59.817341+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.813319+00	2023-01-19 14:41:00.560604+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	7dff3d1f-c92e-45d8-ad4f-47976249728f	authenticated	authenticated	heather.corwin32@hotmail.com	$2a$10$xwYuDq7WxW6XS3Y6QrRaDu041AzLwqQSc6E/dvlbpIz4U8MvVXsbe	\N	\N	3d48dacf155e6d9559b3db695c39306b78a39db07fbf6d016a497279	2023-01-19 14:41:00.734371+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.671941+00	2023-01-19 14:41:01.017625+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	3298ca3a-400a-459e-b0a7-426fd6e9b716	authenticated	authenticated	buddy_hintz@gmail.com	$2a$10$wfmwKnT/D3XfYNZo0kljv.wf5GkuUoDZuPOnC7gfc1mRlxtAxPW.i	\N	\N	9778ff31bdb0115f7448a51f8d3465e0d2bc27372c1785f22bd173f5	2023-01-19 14:40:59.889694+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.885268+00	2023-01-19 14:41:00.590039+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	88e9b920-6bc9-4242-a245-2c2164c01092	authenticated	authenticated	princess24@gmail.com	$2a$10$MKA4HJHa65fZszstcmJ/aOwrV1E.ZbzX4PmIvCi8LKClVgBvYtE4O	\N	\N	eca97274e1ff99f1c5492710315e4e6e00733bd7a47a37eb46d7e585	2023-01-19 14:41:00.729372+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.725169+00	2023-01-19 14:41:01.098846+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	26889fa5-94d3-4150-8b17-d7413e682652	authenticated	authenticated	francis_lockman21@yahoo.com	$2a$10$SzpahifmcMr6M21Hj50C1OQdxNZVDhR2K8uI0uvORHSMkSUQz5dN6	\N	\N	1cfa2c89ec0760892cd8b5c8e87bc869c5fc23bd3d85267b7f127d11	2023-01-19 14:40:59.810331+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:40:59.804253+00	2023-01-19 14:41:00.622202+00	\N	\N			\N		0	\N		\N	f	\N
00000000-0000-0000-0000-000000000000	ec352166-6475-453f-825b-01bee04214d9	authenticated	authenticated	jamarcus94@hotmail.com	$2a$10$7s5QCZDmZR2hBQim6SvCcuXfKFgSRczFq0HhDaKAgPd2005YfzGuG	\N	\N	11b9173d4ef911208f3a14d042c6dcd504d14026ef2654799ad53e5e	2023-01-19 14:41:00.802744+00		\N			\N	\N	{"provider": "email", "providers": ["email"]}	{}	\N	2023-01-19 14:41:00.798465+00	2023-01-19 14:41:01.173877+00	\N	\N			\N		0	\N		\N	f	\N
\.


--
-- Data for Name: key; Type: TABLE DATA; Schema: pgsodium; Owner: supabase_admin
--

COPY pgsodium.key (id, status, created, expires, key_type, key_id, key_context, name, associated_data, raw_key, raw_key_nonce, parent_key, comment, user_data) FROM stdin;
ed81f770-2c2e-4e89-a64b-a6c7b1dfac1b	default	2022-08-22 07:44:16.9012+00	\N	\N	1	\\x7067736f6469756d	\N	associated	\N	\N	\N	This is the default key used for vault.secrets	\N
\.


--
-- Data for Name: test; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.test (id, int2, int4, int8, float4, float8, "numeric", json, jsonb, text, "varchar", uuid, date, timetz, "timestamp", timestamptz, bool, boolarr) FROM stdin;
1	1	2	3	4.14	4.1239	100	{"hello":"world"}	{"hello": "world"}	{hello,world}	{hello,world}	2469ffd8-067a-47e1-b5fc-a9ac41a5fd45	2023-03-28	\N	2023-03-28 07:23:57.205926	2023-03-28 07:23:57.205926+00	t	{t,t}
\.


--
-- Data for Name: test2; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.test2 (id, created_at, int4, int5, int8, float4, float8, "numeric", json, jsonb, text, "varchar", uuid, date, "time", timetz, "timestamp", timestamptz, bool) FROM stdin;
1	2023-03-28 07:20:26.831742+00	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
\.


--
-- Data for Name: buckets; Type: TABLE DATA; Schema: storage; Owner: supabase_storage_admin
--

COPY storage.buckets (id, name, owner, created_at, updated_at, public, avif_autodetection, file_size_limit, allowed_mime_types) FROM stdin;
Test Bucket 1	Test Bucket 1	\N	2023-04-26 03:40:34.087782+00	2023-04-26 03:40:34.087782+00	f	f	\N	\N
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: storage; Owner: supabase_storage_admin
--

COPY storage.migrations (id, name, hash, executed_at) FROM stdin;
0	create-migrations-table	e18db593bcde2aca2a408c4d1100f6abba2195df	2022-08-30 09:34:04.331172
1	initialmigration	6ab16121fbaa08bbd11b712d05f358f9b555d777	2022-08-30 09:34:04.339817
2	pathtoken-column	49756be03be4c17bb85fe70d4a861f27de7e49ad	2022-08-30 09:34:04.345119
3	add-migrations-rls	bb5d124c53d68635a883e399426c6a5a25fc893d	2022-08-30 09:34:04.373099
4	add-size-functions	6d79007d04f5acd288c9c250c42d2d5fd286c54d	2022-08-30 09:34:04.392693
5	change-column-name-in-get-size	fd65688505d2ffa9fbdc58a944348dd8604d688c	2022-08-30 09:34:04.399423
6	add-rls-to-buckets	63e2bab75a2040fee8e3fb3f15a0d26f3380e9b6	2022-08-30 09:34:04.408055
7	add-public-to-buckets	82568934f8a4d9e0a85f126f6fb483ad8214c418	2022-08-30 09:34:04.414918
8	fix-search-function	1a43a40eddb525f2e2f26efd709e6c06e58e059c	2022-08-30 09:34:04.421464
9	search-files-search-function	34c096597eb8b9d077fdfdde9878c88501b2fafc	2022-08-30 09:34:04.427454
10	add-trigger-to-auto-update-updated_at-column	37d6bb964a70a822e6d37f22f457b9bca7885928	2022-08-30 09:34:04.434434
11	add-automatic-avif-detection-flag	bd76c53a9c564c80d98d119c1b3a28e16c8152db	2023-04-04 10:26:55.90276
12	add-bucket-custom-limits	cbe0a4c32a0e891554a21020433b7a4423c07ee7	2023-04-04 10:26:55.941199
13	use-bytes-for-max-size	7a158ebce8a0c2801c9c65b7e9b2f98f68b3874e	2023-04-04 10:26:55.950159
14	add-can-insert-object-function	273193826bca7e0990b458d1ba72f8aa27c0d825	2023-04-04 10:26:56.159325
15	add-version	e821a779d26612899b8c2dfe20245f904a327c4f	2023-04-04 10:26:56.173019
\.


--
-- Data for Name: objects; Type: TABLE DATA; Schema: storage; Owner: supabase_storage_admin
--

COPY storage.objects (id, bucket_id, name, owner, created_at, updated_at, last_accessed_at, metadata, version) FROM stdin;
808135d7-ee5b-4b7b-a5be-cfd007ae157d	Test Bucket 1	tulips.png	\N	2023-05-22 05:33:26.676802+00	2023-05-22 05:33:27.307468+00	2023-05-22 05:33:26.676802+00	{"eTag": "\\"2e57bf7a8a9bc49b3eacca90c921a4ae\\"", "size": 679233, "mimetype": "image/png", "cacheControl": "max-age=3600", "lastModified": "2023-05-22T05:33:28.000Z", "contentLength": 679233, "httpStatusCode": 200}	\N
\.


--
-- Data for Name: secrets; Type: TABLE DATA; Schema: vault; Owner: supabase_admin
--

COPY vault.secrets (id, name, description, secret, key_id, nonce, created_at, updated_at) FROM stdin;
\.


--
-- Name: refresh_tokens_id_seq; Type: SEQUENCE SET; Schema: auth; Owner: supabase_auth_admin
--

SELECT pg_catalog.setval('auth.refresh_tokens_id_seq', 5, true);


--
-- Name: key_key_id_seq; Type: SEQUENCE SET; Schema: pgsodium; Owner: supabase_admin
--

SELECT pg_catalog.setval('pgsodium.key_key_id_seq', 1, false);


--
-- Name: test2_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.test2_id_seq', 1, false);


--
-- Name: test_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.test_id_seq', 1, true);


--
-- Name: mfa_amr_claims amr_id_pk; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.mfa_amr_claims
    ADD CONSTRAINT amr_id_pk PRIMARY KEY (id);


--
-- Name: audit_log_entries audit_log_entries_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.audit_log_entries
    ADD CONSTRAINT audit_log_entries_pkey PRIMARY KEY (id);


--
-- Name: flow_state flow_state_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.flow_state
    ADD CONSTRAINT flow_state_pkey PRIMARY KEY (id);


--
-- Name: identities identities_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.identities
    ADD CONSTRAINT identities_pkey PRIMARY KEY (provider, id);


--
-- Name: instances instances_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.instances
    ADD CONSTRAINT instances_pkey PRIMARY KEY (id);


--
-- Name: mfa_amr_claims mfa_amr_claims_session_id_authentication_method_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.mfa_amr_claims
    ADD CONSTRAINT mfa_amr_claims_session_id_authentication_method_pkey UNIQUE (session_id, authentication_method);


--
-- Name: mfa_challenges mfa_challenges_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.mfa_challenges
    ADD CONSTRAINT mfa_challenges_pkey PRIMARY KEY (id);


--
-- Name: mfa_factors mfa_factors_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.mfa_factors
    ADD CONSTRAINT mfa_factors_pkey PRIMARY KEY (id);


--
-- Name: refresh_tokens refresh_tokens_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.refresh_tokens
    ADD CONSTRAINT refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: refresh_tokens refresh_tokens_token_unique; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.refresh_tokens
    ADD CONSTRAINT refresh_tokens_token_unique UNIQUE (token);


--
-- Name: saml_providers saml_providers_entity_id_key; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.saml_providers
    ADD CONSTRAINT saml_providers_entity_id_key UNIQUE (entity_id);


--
-- Name: saml_providers saml_providers_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.saml_providers
    ADD CONSTRAINT saml_providers_pkey PRIMARY KEY (id);


--
-- Name: saml_relay_states saml_relay_states_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.saml_relay_states
    ADD CONSTRAINT saml_relay_states_pkey PRIMARY KEY (id);


--
-- Name: schema_migrations schema_migrations_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.schema_migrations
    ADD CONSTRAINT schema_migrations_pkey PRIMARY KEY (version);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: sso_domains sso_domains_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.sso_domains
    ADD CONSTRAINT sso_domains_pkey PRIMARY KEY (id);


--
-- Name: sso_providers sso_providers_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.sso_providers
    ADD CONSTRAINT sso_providers_pkey PRIMARY KEY (id);


--
-- Name: users users_phone_key; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT users_phone_key UNIQUE (phone);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: test2 test2_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.test2
    ADD CONSTRAINT test2_pkey PRIMARY KEY (id);


--
-- Name: test test_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.test
    ADD CONSTRAINT test_pkey PRIMARY KEY (id);


--
-- Name: buckets buckets_pkey; Type: CONSTRAINT; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE ONLY storage.buckets
    ADD CONSTRAINT buckets_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_name_key; Type: CONSTRAINT; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE ONLY storage.migrations
    ADD CONSTRAINT migrations_name_key UNIQUE (name);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE ONLY storage.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: objects objects_pkey; Type: CONSTRAINT; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE ONLY storage.objects
    ADD CONSTRAINT objects_pkey PRIMARY KEY (id);


--
-- Name: audit_logs_instance_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX audit_logs_instance_id_idx ON auth.audit_log_entries USING btree (instance_id);


--
-- Name: confirmation_token_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE UNIQUE INDEX confirmation_token_idx ON auth.users USING btree (confirmation_token) WHERE ((confirmation_token)::text !~ '^[0-9 ]*$'::text);


--
-- Name: email_change_token_current_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE UNIQUE INDEX email_change_token_current_idx ON auth.users USING btree (email_change_token_current) WHERE ((email_change_token_current)::text !~ '^[0-9 ]*$'::text);


--
-- Name: email_change_token_new_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE UNIQUE INDEX email_change_token_new_idx ON auth.users USING btree (email_change_token_new) WHERE ((email_change_token_new)::text !~ '^[0-9 ]*$'::text);


--
-- Name: factor_id_created_at_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX factor_id_created_at_idx ON auth.mfa_factors USING btree (user_id, created_at);


--
-- Name: flow_state_created_at_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX flow_state_created_at_idx ON auth.flow_state USING btree (created_at DESC);


--
-- Name: identities_email_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX identities_email_idx ON auth.identities USING btree (email text_pattern_ops);


--
-- Name: INDEX identities_email_idx; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON INDEX auth.identities_email_idx IS 'Auth: Ensures indexed queries on the email column';


--
-- Name: identities_user_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX identities_user_id_idx ON auth.identities USING btree (user_id);


--
-- Name: idx_auth_code; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX idx_auth_code ON auth.flow_state USING btree (auth_code);


--
-- Name: idx_user_id_auth_method; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX idx_user_id_auth_method ON auth.flow_state USING btree (user_id, authentication_method);


--
-- Name: mfa_challenge_created_at_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX mfa_challenge_created_at_idx ON auth.mfa_challenges USING btree (created_at DESC);


--
-- Name: mfa_factors_user_friendly_name_unique; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE UNIQUE INDEX mfa_factors_user_friendly_name_unique ON auth.mfa_factors USING btree (friendly_name, user_id) WHERE (TRIM(BOTH FROM friendly_name) <> ''::text);


--
-- Name: reauthentication_token_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE UNIQUE INDEX reauthentication_token_idx ON auth.users USING btree (reauthentication_token) WHERE ((reauthentication_token)::text !~ '^[0-9 ]*$'::text);


--
-- Name: recovery_token_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE UNIQUE INDEX recovery_token_idx ON auth.users USING btree (recovery_token) WHERE ((recovery_token)::text !~ '^[0-9 ]*$'::text);


--
-- Name: refresh_tokens_instance_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX refresh_tokens_instance_id_idx ON auth.refresh_tokens USING btree (instance_id);


--
-- Name: refresh_tokens_instance_id_user_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX refresh_tokens_instance_id_user_id_idx ON auth.refresh_tokens USING btree (instance_id, user_id);


--
-- Name: refresh_tokens_parent_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX refresh_tokens_parent_idx ON auth.refresh_tokens USING btree (parent);


--
-- Name: refresh_tokens_session_id_revoked_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX refresh_tokens_session_id_revoked_idx ON auth.refresh_tokens USING btree (session_id, revoked);


--
-- Name: refresh_tokens_updated_at_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX refresh_tokens_updated_at_idx ON auth.refresh_tokens USING btree (updated_at DESC);


--
-- Name: saml_providers_sso_provider_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX saml_providers_sso_provider_id_idx ON auth.saml_providers USING btree (sso_provider_id);


--
-- Name: saml_relay_states_created_at_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX saml_relay_states_created_at_idx ON auth.saml_relay_states USING btree (created_at DESC);


--
-- Name: saml_relay_states_for_email_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX saml_relay_states_for_email_idx ON auth.saml_relay_states USING btree (for_email);


--
-- Name: saml_relay_states_sso_provider_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX saml_relay_states_sso_provider_id_idx ON auth.saml_relay_states USING btree (sso_provider_id);


--
-- Name: sessions_not_after_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX sessions_not_after_idx ON auth.sessions USING btree (not_after DESC);


--
-- Name: sessions_user_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX sessions_user_id_idx ON auth.sessions USING btree (user_id);


--
-- Name: sso_domains_domain_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE UNIQUE INDEX sso_domains_domain_idx ON auth.sso_domains USING btree (lower(domain));


--
-- Name: sso_domains_sso_provider_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX sso_domains_sso_provider_id_idx ON auth.sso_domains USING btree (sso_provider_id);


--
-- Name: sso_providers_resource_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE UNIQUE INDEX sso_providers_resource_id_idx ON auth.sso_providers USING btree (lower(resource_id));


--
-- Name: user_id_created_at_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX user_id_created_at_idx ON auth.sessions USING btree (user_id, created_at);


--
-- Name: users_email_partial_key; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE UNIQUE INDEX users_email_partial_key ON auth.users USING btree (email) WHERE (is_sso_user = false);


--
-- Name: INDEX users_email_partial_key; Type: COMMENT; Schema: auth; Owner: supabase_auth_admin
--

COMMENT ON INDEX auth.users_email_partial_key IS 'Auth: A partial unique index that applies only when is_sso_user is false';


--
-- Name: users_instance_id_email_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX users_instance_id_email_idx ON auth.users USING btree (instance_id, lower((email)::text));


--
-- Name: users_instance_id_idx; Type: INDEX; Schema: auth; Owner: supabase_auth_admin
--

CREATE INDEX users_instance_id_idx ON auth.users USING btree (instance_id);


--
-- Name: bname; Type: INDEX; Schema: storage; Owner: supabase_storage_admin
--

CREATE UNIQUE INDEX bname ON storage.buckets USING btree (name);


--
-- Name: bucketid_objname; Type: INDEX; Schema: storage; Owner: supabase_storage_admin
--

CREATE UNIQUE INDEX bucketid_objname ON storage.objects USING btree (bucket_id, name);


--
-- Name: name_prefix_search; Type: INDEX; Schema: storage; Owner: supabase_storage_admin
--

CREATE INDEX name_prefix_search ON storage.objects USING btree (name text_pattern_ops);


--
-- Name: objects update_objects_updated_at; Type: TRIGGER; Schema: storage; Owner: supabase_storage_admin
--

CREATE TRIGGER update_objects_updated_at BEFORE UPDATE ON storage.objects FOR EACH ROW EXECUTE FUNCTION storage.update_updated_at_column();


--
-- Name: identities identities_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.identities
    ADD CONSTRAINT identities_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE CASCADE;


--
-- Name: mfa_amr_claims mfa_amr_claims_session_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.mfa_amr_claims
    ADD CONSTRAINT mfa_amr_claims_session_id_fkey FOREIGN KEY (session_id) REFERENCES auth.sessions(id) ON DELETE CASCADE;


--
-- Name: mfa_challenges mfa_challenges_auth_factor_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.mfa_challenges
    ADD CONSTRAINT mfa_challenges_auth_factor_id_fkey FOREIGN KEY (factor_id) REFERENCES auth.mfa_factors(id) ON DELETE CASCADE;


--
-- Name: mfa_factors mfa_factors_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.mfa_factors
    ADD CONSTRAINT mfa_factors_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE CASCADE;


--
-- Name: refresh_tokens refresh_tokens_session_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.refresh_tokens
    ADD CONSTRAINT refresh_tokens_session_id_fkey FOREIGN KEY (session_id) REFERENCES auth.sessions(id) ON DELETE CASCADE;


--
-- Name: saml_providers saml_providers_sso_provider_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.saml_providers
    ADD CONSTRAINT saml_providers_sso_provider_id_fkey FOREIGN KEY (sso_provider_id) REFERENCES auth.sso_providers(id) ON DELETE CASCADE;


--
-- Name: saml_relay_states saml_relay_states_sso_provider_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.saml_relay_states
    ADD CONSTRAINT saml_relay_states_sso_provider_id_fkey FOREIGN KEY (sso_provider_id) REFERENCES auth.sso_providers(id) ON DELETE CASCADE;


--
-- Name: sessions sessions_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.sessions
    ADD CONSTRAINT sessions_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE CASCADE;


--
-- Name: sso_domains sso_domains_sso_provider_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: supabase_auth_admin
--

ALTER TABLE ONLY auth.sso_domains
    ADD CONSTRAINT sso_domains_sso_provider_id_fkey FOREIGN KEY (sso_provider_id) REFERENCES auth.sso_providers(id) ON DELETE CASCADE;


--
-- Name: buckets buckets_owner_fkey; Type: FK CONSTRAINT; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE ONLY storage.buckets
    ADD CONSTRAINT buckets_owner_fkey FOREIGN KEY (owner) REFERENCES auth.users(id);


--
-- Name: objects objects_bucketId_fkey; Type: FK CONSTRAINT; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE ONLY storage.objects
    ADD CONSTRAINT "objects_bucketId_fkey" FOREIGN KEY (bucket_id) REFERENCES storage.buckets(id);


--
-- Name: objects objects_owner_fkey; Type: FK CONSTRAINT; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE ONLY storage.objects
    ADD CONSTRAINT objects_owner_fkey FOREIGN KEY (owner) REFERENCES auth.users(id);


--
-- Name: test; Type: ROW SECURITY; Schema: public; Owner: postgres
--

ALTER TABLE public.test ENABLE ROW LEVEL SECURITY;

--
-- Name: test2; Type: ROW SECURITY; Schema: public; Owner: postgres
--

ALTER TABLE public.test2 ENABLE ROW LEVEL SECURITY;

--
-- Name: buckets; Type: ROW SECURITY; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE storage.buckets ENABLE ROW LEVEL SECURITY;

--
-- Name: migrations; Type: ROW SECURITY; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE storage.migrations ENABLE ROW LEVEL SECURITY;

--
-- Name: objects; Type: ROW SECURITY; Schema: storage; Owner: supabase_storage_admin
--

ALTER TABLE storage.objects ENABLE ROW LEVEL SECURITY;

--
-- Name: supabase_realtime; Type: PUBLICATION; Schema: -; Owner: postgres
--

CREATE PUBLICATION supabase_realtime WITH (publish = 'insert, update, delete, truncate');


ALTER PUBLICATION supabase_realtime OWNER TO postgres;

--
-- Name: SCHEMA auth; Type: ACL; Schema: -; Owner: supabase_admin
--

GRANT USAGE ON SCHEMA auth TO anon;
GRANT USAGE ON SCHEMA auth TO authenticated;
GRANT USAGE ON SCHEMA auth TO service_role;
GRANT ALL ON SCHEMA auth TO supabase_auth_admin;
GRANT ALL ON SCHEMA auth TO dashboard_user;
GRANT ALL ON SCHEMA auth TO postgres;


--
-- Name: SCHEMA extensions; Type: ACL; Schema: -; Owner: postgres
--

GRANT USAGE ON SCHEMA extensions TO anon;
GRANT USAGE ON SCHEMA extensions TO authenticated;
GRANT USAGE ON SCHEMA extensions TO service_role;
GRANT ALL ON SCHEMA extensions TO dashboard_user;


--
-- Name: SCHEMA graphql_public; Type: ACL; Schema: -; Owner: supabase_admin
--

GRANT USAGE ON SCHEMA graphql_public TO postgres;
GRANT USAGE ON SCHEMA graphql_public TO anon;
GRANT USAGE ON SCHEMA graphql_public TO authenticated;
GRANT USAGE ON SCHEMA graphql_public TO service_role;


--
-- Name: SCHEMA pgsodium_masks; Type: ACL; Schema: -; Owner: supabase_admin
--

REVOKE ALL ON SCHEMA pgsodium_masks FROM supabase_admin;
GRANT ALL ON SCHEMA pgsodium_masks TO postgres;


--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: postgres
--

REVOKE USAGE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO anon;
GRANT USAGE ON SCHEMA public TO authenticated;
GRANT USAGE ON SCHEMA public TO service_role;


--
-- Name: SCHEMA realtime; Type: ACL; Schema: -; Owner: supabase_admin
--

GRANT USAGE ON SCHEMA realtime TO postgres;


--
-- Name: SCHEMA storage; Type: ACL; Schema: -; Owner: supabase_admin
--

GRANT ALL ON SCHEMA storage TO postgres;
GRANT USAGE ON SCHEMA storage TO anon;
GRANT USAGE ON SCHEMA storage TO authenticated;
GRANT USAGE ON SCHEMA storage TO service_role;
GRANT ALL ON SCHEMA storage TO supabase_storage_admin;
GRANT ALL ON SCHEMA storage TO dashboard_user;


--
-- Name: FUNCTION email(); Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON FUNCTION auth.email() TO dashboard_user;
GRANT ALL ON FUNCTION auth.email() TO postgres;


--
-- Name: FUNCTION jwt(); Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON FUNCTION auth.jwt() TO postgres;
GRANT ALL ON FUNCTION auth.jwt() TO dashboard_user;


--
-- Name: FUNCTION role(); Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON FUNCTION auth.role() TO dashboard_user;
GRANT ALL ON FUNCTION auth.role() TO postgres;


--
-- Name: FUNCTION uid(); Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON FUNCTION auth.uid() TO dashboard_user;
GRANT ALL ON FUNCTION auth.uid() TO postgres;


--
-- Name: FUNCTION algorithm_sign(signables text, secret text, algorithm text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.algorithm_sign(signables text, secret text, algorithm text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.algorithm_sign(signables text, secret text, algorithm text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION armor(bytea); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.armor(bytea) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.armor(bytea) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION armor(bytea, text[], text[]); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.armor(bytea, text[], text[]) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.armor(bytea, text[], text[]) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION crypt(text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.crypt(text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.crypt(text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION dearmor(text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.dearmor(text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.dearmor(text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION decrypt(bytea, bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.decrypt(bytea, bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.decrypt(bytea, bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION decrypt_iv(bytea, bytea, bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.decrypt_iv(bytea, bytea, bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.decrypt_iv(bytea, bytea, bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION digest(bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.digest(bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.digest(bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION digest(text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.digest(text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.digest(text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION encrypt(bytea, bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.encrypt(bytea, bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.encrypt(bytea, bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION encrypt_iv(bytea, bytea, bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.encrypt_iv(bytea, bytea, bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.encrypt_iv(bytea, bytea, bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION gen_random_bytes(integer); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.gen_random_bytes(integer) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.gen_random_bytes(integer) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION gen_random_uuid(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.gen_random_uuid() TO dashboard_user;
GRANT ALL ON FUNCTION extensions.gen_random_uuid() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION gen_salt(text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.gen_salt(text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.gen_salt(text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION gen_salt(text, integer); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.gen_salt(text, integer) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.gen_salt(text, integer) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION grant_pg_cron_access(); Type: ACL; Schema: extensions; Owner: postgres
--

REVOKE ALL ON FUNCTION extensions.grant_pg_cron_access() FROM postgres;
GRANT ALL ON FUNCTION extensions.grant_pg_cron_access() TO postgres WITH GRANT OPTION;
GRANT ALL ON FUNCTION extensions.grant_pg_cron_access() TO dashboard_user;


--
-- Name: FUNCTION grant_pg_graphql_access(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.grant_pg_graphql_access() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION grant_pg_net_access(); Type: ACL; Schema: extensions; Owner: postgres
--

REVOKE ALL ON FUNCTION extensions.grant_pg_net_access() FROM postgres;
GRANT ALL ON FUNCTION extensions.grant_pg_net_access() TO postgres WITH GRANT OPTION;
GRANT ALL ON FUNCTION extensions.grant_pg_net_access() TO dashboard_user;


--
-- Name: FUNCTION hmac(bytea, bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.hmac(bytea, bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.hmac(bytea, bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION hmac(text, text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.hmac(text, text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.hmac(text, text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pg_stat_statements(showtext boolean, OUT userid oid, OUT dbid oid, OUT toplevel boolean, OUT queryid bigint, OUT query text, OUT plans bigint, OUT total_plan_time double precision, OUT min_plan_time double precision, OUT max_plan_time double precision, OUT mean_plan_time double precision, OUT stddev_plan_time double precision, OUT calls bigint, OUT total_exec_time double precision, OUT min_exec_time double precision, OUT max_exec_time double precision, OUT mean_exec_time double precision, OUT stddev_exec_time double precision, OUT rows bigint, OUT shared_blks_hit bigint, OUT shared_blks_read bigint, OUT shared_blks_dirtied bigint, OUT shared_blks_written bigint, OUT local_blks_hit bigint, OUT local_blks_read bigint, OUT local_blks_dirtied bigint, OUT local_blks_written bigint, OUT temp_blks_read bigint, OUT temp_blks_written bigint, OUT blk_read_time double precision, OUT blk_write_time double precision, OUT temp_blk_read_time double precision, OUT temp_blk_write_time double precision, OUT wal_records bigint, OUT wal_fpi bigint, OUT wal_bytes numeric, OUT jit_functions bigint, OUT jit_generation_time double precision, OUT jit_inlining_count bigint, OUT jit_inlining_time double precision, OUT jit_optimization_count bigint, OUT jit_optimization_time double precision, OUT jit_emission_count bigint, OUT jit_emission_time double precision); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pg_stat_statements(showtext boolean, OUT userid oid, OUT dbid oid, OUT toplevel boolean, OUT queryid bigint, OUT query text, OUT plans bigint, OUT total_plan_time double precision, OUT min_plan_time double precision, OUT max_plan_time double precision, OUT mean_plan_time double precision, OUT stddev_plan_time double precision, OUT calls bigint, OUT total_exec_time double precision, OUT min_exec_time double precision, OUT max_exec_time double precision, OUT mean_exec_time double precision, OUT stddev_exec_time double precision, OUT rows bigint, OUT shared_blks_hit bigint, OUT shared_blks_read bigint, OUT shared_blks_dirtied bigint, OUT shared_blks_written bigint, OUT local_blks_hit bigint, OUT local_blks_read bigint, OUT local_blks_dirtied bigint, OUT local_blks_written bigint, OUT temp_blks_read bigint, OUT temp_blks_written bigint, OUT blk_read_time double precision, OUT blk_write_time double precision, OUT temp_blk_read_time double precision, OUT temp_blk_write_time double precision, OUT wal_records bigint, OUT wal_fpi bigint, OUT wal_bytes numeric, OUT jit_functions bigint, OUT jit_generation_time double precision, OUT jit_inlining_count bigint, OUT jit_inlining_time double precision, OUT jit_optimization_count bigint, OUT jit_optimization_time double precision, OUT jit_emission_count bigint, OUT jit_emission_time double precision) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pg_stat_statements(showtext boolean, OUT userid oid, OUT dbid oid, OUT toplevel boolean, OUT queryid bigint, OUT query text, OUT plans bigint, OUT total_plan_time double precision, OUT min_plan_time double precision, OUT max_plan_time double precision, OUT mean_plan_time double precision, OUT stddev_plan_time double precision, OUT calls bigint, OUT total_exec_time double precision, OUT min_exec_time double precision, OUT max_exec_time double precision, OUT mean_exec_time double precision, OUT stddev_exec_time double precision, OUT rows bigint, OUT shared_blks_hit bigint, OUT shared_blks_read bigint, OUT shared_blks_dirtied bigint, OUT shared_blks_written bigint, OUT local_blks_hit bigint, OUT local_blks_read bigint, OUT local_blks_dirtied bigint, OUT local_blks_written bigint, OUT temp_blks_read bigint, OUT temp_blks_written bigint, OUT blk_read_time double precision, OUT blk_write_time double precision, OUT temp_blk_read_time double precision, OUT temp_blk_write_time double precision, OUT wal_records bigint, OUT wal_fpi bigint, OUT wal_bytes numeric, OUT jit_functions bigint, OUT jit_generation_time double precision, OUT jit_inlining_count bigint, OUT jit_inlining_time double precision, OUT jit_optimization_count bigint, OUT jit_optimization_time double precision, OUT jit_emission_count bigint, OUT jit_emission_time double precision) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pg_stat_statements_info(OUT dealloc bigint, OUT stats_reset timestamp with time zone); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pg_stat_statements_info(OUT dealloc bigint, OUT stats_reset timestamp with time zone) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pg_stat_statements_info(OUT dealloc bigint, OUT stats_reset timestamp with time zone) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pg_stat_statements_reset(userid oid, dbid oid, queryid bigint); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pg_stat_statements_reset(userid oid, dbid oid, queryid bigint) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pg_stat_statements_reset(userid oid, dbid oid, queryid bigint) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_armor_headers(text, OUT key text, OUT value text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_armor_headers(text, OUT key text, OUT value text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_armor_headers(text, OUT key text, OUT value text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_key_id(bytea); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_key_id(bytea) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_key_id(bytea) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_decrypt(bytea, bytea); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt(bytea, bytea) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt(bytea, bytea) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_decrypt(bytea, bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt(bytea, bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt(bytea, bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_decrypt(bytea, bytea, text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt(bytea, bytea, text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt(bytea, bytea, text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_decrypt_bytea(bytea, bytea); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt_bytea(bytea, bytea) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt_bytea(bytea, bytea) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_decrypt_bytea(bytea, bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt_bytea(bytea, bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt_bytea(bytea, bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_decrypt_bytea(bytea, bytea, text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt_bytea(bytea, bytea, text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_decrypt_bytea(bytea, bytea, text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_encrypt(text, bytea); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_encrypt(text, bytea) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_encrypt(text, bytea) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_encrypt(text, bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_encrypt(text, bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_encrypt(text, bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_encrypt_bytea(bytea, bytea); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_encrypt_bytea(bytea, bytea) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_encrypt_bytea(bytea, bytea) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_pub_encrypt_bytea(bytea, bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_pub_encrypt_bytea(bytea, bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_pub_encrypt_bytea(bytea, bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_sym_decrypt(bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_sym_decrypt(bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_sym_decrypt(bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_sym_decrypt(bytea, text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_sym_decrypt(bytea, text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_sym_decrypt(bytea, text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_sym_decrypt_bytea(bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_sym_decrypt_bytea(bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_sym_decrypt_bytea(bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_sym_decrypt_bytea(bytea, text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_sym_decrypt_bytea(bytea, text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_sym_decrypt_bytea(bytea, text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_sym_encrypt(text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_sym_encrypt(text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_sym_encrypt(text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_sym_encrypt(text, text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_sym_encrypt(text, text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_sym_encrypt(text, text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_sym_encrypt_bytea(bytea, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_sym_encrypt_bytea(bytea, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_sym_encrypt_bytea(bytea, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgp_sym_encrypt_bytea(bytea, text, text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgp_sym_encrypt_bytea(bytea, text, text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.pgp_sym_encrypt_bytea(bytea, text, text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgrst_ddl_watch(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgrst_ddl_watch() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION pgrst_drop_watch(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.pgrst_drop_watch() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION set_graphql_placeholder(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.set_graphql_placeholder() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION sign(payload json, secret text, algorithm text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.sign(payload json, secret text, algorithm text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.sign(payload json, secret text, algorithm text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION try_cast_double(inp text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.try_cast_double(inp text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.try_cast_double(inp text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION url_decode(data text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.url_decode(data text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.url_decode(data text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION url_encode(data bytea); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.url_encode(data bytea) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.url_encode(data bytea) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_generate_v1(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_generate_v1() TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_generate_v1() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_generate_v1mc(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_generate_v1mc() TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_generate_v1mc() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_generate_v3(namespace uuid, name text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_generate_v3(namespace uuid, name text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_generate_v3(namespace uuid, name text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_generate_v4(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_generate_v4() TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_generate_v4() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_generate_v5(namespace uuid, name text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_generate_v5(namespace uuid, name text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_generate_v5(namespace uuid, name text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_nil(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_nil() TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_nil() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_ns_dns(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_ns_dns() TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_ns_dns() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_ns_oid(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_ns_oid() TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_ns_oid() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_ns_url(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_ns_url() TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_ns_url() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION uuid_ns_x500(); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.uuid_ns_x500() TO dashboard_user;
GRANT ALL ON FUNCTION extensions.uuid_ns_x500() TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION verify(token text, secret text, algorithm text); Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON FUNCTION extensions.verify(token text, secret text, algorithm text) TO dashboard_user;
GRANT ALL ON FUNCTION extensions.verify(token text, secret text, algorithm text) TO postgres WITH GRANT OPTION;


--
-- Name: FUNCTION comment_directive(comment_ text); Type: ACL; Schema: graphql; Owner: supabase_admin
--

GRANT ALL ON FUNCTION graphql.comment_directive(comment_ text) TO postgres;
GRANT ALL ON FUNCTION graphql.comment_directive(comment_ text) TO anon;
GRANT ALL ON FUNCTION graphql.comment_directive(comment_ text) TO authenticated;
GRANT ALL ON FUNCTION graphql.comment_directive(comment_ text) TO service_role;


--
-- Name: FUNCTION exception(message text); Type: ACL; Schema: graphql; Owner: supabase_admin
--

GRANT ALL ON FUNCTION graphql.exception(message text) TO postgres;
GRANT ALL ON FUNCTION graphql.exception(message text) TO anon;
GRANT ALL ON FUNCTION graphql.exception(message text) TO authenticated;
GRANT ALL ON FUNCTION graphql.exception(message text) TO service_role;


--
-- Name: FUNCTION get_schema_version(); Type: ACL; Schema: graphql; Owner: supabase_admin
--

GRANT ALL ON FUNCTION graphql.get_schema_version() TO postgres;
GRANT ALL ON FUNCTION graphql.get_schema_version() TO anon;
GRANT ALL ON FUNCTION graphql.get_schema_version() TO authenticated;
GRANT ALL ON FUNCTION graphql.get_schema_version() TO service_role;


--
-- Name: FUNCTION increment_schema_version(); Type: ACL; Schema: graphql; Owner: supabase_admin
--

GRANT ALL ON FUNCTION graphql.increment_schema_version() TO postgres;
GRANT ALL ON FUNCTION graphql.increment_schema_version() TO anon;
GRANT ALL ON FUNCTION graphql.increment_schema_version() TO authenticated;
GRANT ALL ON FUNCTION graphql.increment_schema_version() TO service_role;
--
-- Name: TABLE key; Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON TABLE pgsodium.key FROM supabase_admin;
GRANT ALL ON TABLE pgsodium.key TO postgres;


--
-- Name: TABLE valid_key; Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON TABLE pgsodium.valid_key FROM supabase_admin;
REVOKE SELECT ON TABLE pgsodium.valid_key FROM pgsodium_keyiduser;
GRANT ALL ON TABLE pgsodium.valid_key TO postgres;
GRANT ALL ON TABLE pgsodium.valid_key TO pgsodium_keyiduser;


--
-- Name: FUNCTION crypto_aead_det_decrypt(ciphertext bytea, additional bytea, key bytea, nonce bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_det_decrypt(ciphertext bytea, additional bytea, key bytea, nonce bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_det_decrypt(ciphertext bytea, additional bytea, key bytea, nonce bytea) TO postgres;


--
-- Name: FUNCTION crypto_aead_det_decrypt(message bytea, additional bytea, key_uuid uuid, nonce bytea); Type: ACL; Schema: pgsodium; Owner: pgsodium_keymaker
--

GRANT ALL ON FUNCTION pgsodium.crypto_aead_det_decrypt(message bytea, additional bytea, key_uuid uuid, nonce bytea) TO service_role;


--
-- Name: FUNCTION crypto_aead_det_decrypt(message bytea, additional bytea, key_id bigint, context bytea, nonce bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_det_decrypt(message bytea, additional bytea, key_id bigint, context bytea, nonce bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_det_decrypt(message bytea, additional bytea, key_id bigint, context bytea, nonce bytea) TO postgres;


--
-- Name: FUNCTION crypto_aead_det_encrypt(message bytea, additional bytea, key bytea, nonce bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_det_encrypt(message bytea, additional bytea, key bytea, nonce bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_det_encrypt(message bytea, additional bytea, key bytea, nonce bytea) TO postgres;


--
-- Name: FUNCTION crypto_aead_det_encrypt(message bytea, additional bytea, key_uuid uuid, nonce bytea); Type: ACL; Schema: pgsodium; Owner: pgsodium_keymaker
--

GRANT ALL ON FUNCTION pgsodium.crypto_aead_det_encrypt(message bytea, additional bytea, key_uuid uuid, nonce bytea) TO service_role;


--
-- Name: FUNCTION crypto_aead_det_encrypt(message bytea, additional bytea, key_id bigint, context bytea, nonce bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_det_encrypt(message bytea, additional bytea, key_id bigint, context bytea, nonce bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_det_encrypt(message bytea, additional bytea, key_id bigint, context bytea, nonce bytea) TO postgres;


--
-- Name: FUNCTION crypto_aead_det_keygen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_det_keygen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_det_keygen() TO service_role;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_det_keygen() TO postgres;


--
-- Name: FUNCTION crypto_aead_det_noncegen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_det_noncegen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_det_noncegen() TO postgres;


--
-- Name: FUNCTION crypto_aead_ietf_decrypt(message bytea, additional bytea, nonce bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_ietf_decrypt(message bytea, additional bytea, nonce bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_ietf_decrypt(message bytea, additional bytea, nonce bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_aead_ietf_decrypt(message bytea, additional bytea, nonce bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_ietf_decrypt(message bytea, additional bytea, nonce bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_ietf_decrypt(message bytea, additional bytea, nonce bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_aead_ietf_encrypt(message bytea, additional bytea, nonce bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_ietf_encrypt(message bytea, additional bytea, nonce bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_ietf_encrypt(message bytea, additional bytea, nonce bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_aead_ietf_encrypt(message bytea, additional bytea, nonce bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_ietf_encrypt(message bytea, additional bytea, nonce bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_ietf_encrypt(message bytea, additional bytea, nonce bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_aead_ietf_keygen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_ietf_keygen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_ietf_keygen() TO postgres;


--
-- Name: FUNCTION crypto_aead_ietf_noncegen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_aead_ietf_noncegen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_aead_ietf_noncegen() TO postgres;


--
-- Name: FUNCTION crypto_auth(message bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth(message bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth(message bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth(message bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth(message bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth(message bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_hmacsha256(message bytea, secret bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256(message bytea, secret bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256(message bytea, secret bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_hmacsha256(message bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256(message bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256(message bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_hmacsha256_keygen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256_keygen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256_keygen() TO postgres;


--
-- Name: FUNCTION crypto_auth_hmacsha256_verify(hash bytea, message bytea, secret bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256_verify(hash bytea, message bytea, secret bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256_verify(hash bytea, message bytea, secret bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_hmacsha256_verify(hash bytea, message bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256_verify(hash bytea, message bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_hmacsha256_verify(hash bytea, message bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_hmacsha512(message bytea, secret bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_hmacsha512(message bytea, secret bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_hmacsha512(message bytea, secret bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_hmacsha512(message bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_hmacsha512(message bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_hmacsha512(message bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_hmacsha512_verify(hash bytea, message bytea, secret bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_hmacsha512_verify(hash bytea, message bytea, secret bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_hmacsha512_verify(hash bytea, message bytea, secret bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_hmacsha512_verify(hash bytea, message bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_hmacsha512_verify(hash bytea, message bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_hmacsha512_verify(hash bytea, message bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_keygen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_keygen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_keygen() TO postgres;


--
-- Name: FUNCTION crypto_auth_verify(mac bytea, message bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_verify(mac bytea, message bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_verify(mac bytea, message bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_auth_verify(mac bytea, message bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_auth_verify(mac bytea, message bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_auth_verify(mac bytea, message bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_box(message bytea, nonce bytea, public bytea, secret bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_box(message bytea, nonce bytea, public bytea, secret bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_box(message bytea, nonce bytea, public bytea, secret bytea) TO postgres;


--
-- Name: FUNCTION crypto_box_new_keypair(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_box_new_keypair() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_box_new_keypair() TO postgres;


--
-- Name: FUNCTION crypto_box_noncegen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_box_noncegen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_box_noncegen() TO postgres;


--
-- Name: FUNCTION crypto_box_open(ciphertext bytea, nonce bytea, public bytea, secret bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_box_open(ciphertext bytea, nonce bytea, public bytea, secret bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_box_open(ciphertext bytea, nonce bytea, public bytea, secret bytea) TO postgres;


--
-- Name: FUNCTION crypto_box_seed_new_keypair(seed bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_box_seed_new_keypair(seed bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_box_seed_new_keypair(seed bytea) TO postgres;


--
-- Name: FUNCTION crypto_generichash(message bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_generichash(message bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_generichash(message bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_generichash_keygen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_generichash_keygen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_generichash_keygen() TO postgres;


--
-- Name: FUNCTION crypto_kdf_derive_from_key(subkey_size bigint, subkey_id bigint, context bytea, primary_key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_kdf_derive_from_key(subkey_size bigint, subkey_id bigint, context bytea, primary_key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_kdf_derive_from_key(subkey_size bigint, subkey_id bigint, context bytea, primary_key bytea) TO postgres;


--
-- Name: FUNCTION crypto_kdf_keygen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_kdf_keygen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_kdf_keygen() TO postgres;


--
-- Name: FUNCTION crypto_kx_new_keypair(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_kx_new_keypair() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_kx_new_keypair() TO postgres;


--
-- Name: FUNCTION crypto_kx_new_seed(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_kx_new_seed() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_kx_new_seed() TO postgres;


--
-- Name: FUNCTION crypto_kx_seed_new_keypair(seed bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_kx_seed_new_keypair(seed bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_kx_seed_new_keypair(seed bytea) TO postgres;


--
-- Name: FUNCTION crypto_secretbox(message bytea, nonce bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_secretbox(message bytea, nonce bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_secretbox(message bytea, nonce bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_secretbox(message bytea, nonce bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_secretbox(message bytea, nonce bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_secretbox(message bytea, nonce bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_secretbox_keygen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_secretbox_keygen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_secretbox_keygen() TO postgres;


--
-- Name: FUNCTION crypto_secretbox_noncegen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_secretbox_noncegen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_secretbox_noncegen() TO postgres;


--
-- Name: FUNCTION crypto_secretbox_open(ciphertext bytea, nonce bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_secretbox_open(ciphertext bytea, nonce bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_secretbox_open(ciphertext bytea, nonce bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_secretbox_open(message bytea, nonce bytea, key_id bigint, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_secretbox_open(message bytea, nonce bytea, key_id bigint, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_secretbox_open(message bytea, nonce bytea, key_id bigint, context bytea) TO postgres;


--
-- Name: FUNCTION crypto_shorthash(message bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_shorthash(message bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_shorthash(message bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_shorthash_keygen(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_shorthash_keygen() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_shorthash_keygen() TO postgres;


--
-- Name: FUNCTION crypto_sign_final_create(state bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_sign_final_create(state bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_sign_final_create(state bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_sign_final_verify(state bytea, signature bytea, key bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_sign_final_verify(state bytea, signature bytea, key bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_sign_final_verify(state bytea, signature bytea, key bytea) TO postgres;


--
-- Name: FUNCTION crypto_sign_init(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_sign_init() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_sign_init() TO postgres;


--
-- Name: FUNCTION crypto_sign_new_keypair(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_sign_new_keypair() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_sign_new_keypair() TO postgres;


--
-- Name: FUNCTION crypto_sign_update(state bytea, message bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_sign_update(state bytea, message bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_sign_update(state bytea, message bytea) TO postgres;


--
-- Name: FUNCTION crypto_sign_update_agg1(state bytea, message bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_sign_update_agg1(state bytea, message bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_sign_update_agg1(state bytea, message bytea) TO postgres;


--
-- Name: FUNCTION crypto_sign_update_agg2(cur_state bytea, initial_state bytea, message bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_sign_update_agg2(cur_state bytea, initial_state bytea, message bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_sign_update_agg2(cur_state bytea, initial_state bytea, message bytea) TO postgres;


--
-- Name: FUNCTION crypto_signcrypt_new_keypair(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_signcrypt_new_keypair() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_signcrypt_new_keypair() TO postgres;


--
-- Name: FUNCTION crypto_signcrypt_sign_after(state bytea, sender_sk bytea, ciphertext bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_signcrypt_sign_after(state bytea, sender_sk bytea, ciphertext bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_signcrypt_sign_after(state bytea, sender_sk bytea, ciphertext bytea) TO postgres;


--
-- Name: FUNCTION crypto_signcrypt_sign_before(sender bytea, recipient bytea, sender_sk bytea, recipient_pk bytea, additional bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_signcrypt_sign_before(sender bytea, recipient bytea, sender_sk bytea, recipient_pk bytea, additional bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_signcrypt_sign_before(sender bytea, recipient bytea, sender_sk bytea, recipient_pk bytea, additional bytea) TO postgres;


--
-- Name: FUNCTION crypto_signcrypt_verify_after(state bytea, signature bytea, sender_pk bytea, ciphertext bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_signcrypt_verify_after(state bytea, signature bytea, sender_pk bytea, ciphertext bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_signcrypt_verify_after(state bytea, signature bytea, sender_pk bytea, ciphertext bytea) TO postgres;


--
-- Name: FUNCTION crypto_signcrypt_verify_before(signature bytea, sender bytea, recipient bytea, additional bytea, sender_pk bytea, recipient_sk bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_signcrypt_verify_before(signature bytea, sender bytea, recipient bytea, additional bytea, sender_pk bytea, recipient_sk bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_signcrypt_verify_before(signature bytea, sender bytea, recipient bytea, additional bytea, sender_pk bytea, recipient_sk bytea) TO postgres;


--
-- Name: FUNCTION crypto_signcrypt_verify_public(signature bytea, sender bytea, recipient bytea, additional bytea, sender_pk bytea, ciphertext bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.crypto_signcrypt_verify_public(signature bytea, sender bytea, recipient bytea, additional bytea, sender_pk bytea, ciphertext bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.crypto_signcrypt_verify_public(signature bytea, sender bytea, recipient bytea, additional bytea, sender_pk bytea, ciphertext bytea) TO postgres;


--
-- Name: FUNCTION derive_key(key_id bigint, key_len integer, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.derive_key(key_id bigint, key_len integer, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.derive_key(key_id bigint, key_len integer, context bytea) TO postgres;


--
-- Name: FUNCTION pgsodium_derive(key_id bigint, key_len integer, context bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.pgsodium_derive(key_id bigint, key_len integer, context bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.pgsodium_derive(key_id bigint, key_len integer, context bytea) TO postgres;


--
-- Name: FUNCTION randombytes_buf(size integer); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.randombytes_buf(size integer) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.randombytes_buf(size integer) TO postgres;


--
-- Name: FUNCTION randombytes_buf_deterministic(size integer, seed bytea); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.randombytes_buf_deterministic(size integer, seed bytea) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.randombytes_buf_deterministic(size integer, seed bytea) TO postgres;


--
-- Name: FUNCTION randombytes_new_seed(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.randombytes_new_seed() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.randombytes_new_seed() TO postgres;


--
-- Name: FUNCTION randombytes_random(); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.randombytes_random() FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.randombytes_random() TO postgres;


--
-- Name: FUNCTION randombytes_uniform(upper_bound integer); Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON FUNCTION pgsodium.randombytes_uniform(upper_bound integer) FROM supabase_admin;
GRANT ALL ON FUNCTION pgsodium.randombytes_uniform(upper_bound integer) TO postgres;


--
-- Name: FUNCTION can_insert_object(bucketid text, name text, owner uuid, metadata jsonb); Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON FUNCTION storage.can_insert_object(bucketid text, name text, owner uuid, metadata jsonb) TO postgres;


--
-- Name: FUNCTION extension(name text); Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON FUNCTION storage.extension(name text) TO anon;
GRANT ALL ON FUNCTION storage.extension(name text) TO authenticated;
GRANT ALL ON FUNCTION storage.extension(name text) TO service_role;
GRANT ALL ON FUNCTION storage.extension(name text) TO dashboard_user;
GRANT ALL ON FUNCTION storage.extension(name text) TO postgres;


--
-- Name: FUNCTION filename(name text); Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON FUNCTION storage.filename(name text) TO anon;
GRANT ALL ON FUNCTION storage.filename(name text) TO authenticated;
GRANT ALL ON FUNCTION storage.filename(name text) TO service_role;
GRANT ALL ON FUNCTION storage.filename(name text) TO dashboard_user;
GRANT ALL ON FUNCTION storage.filename(name text) TO postgres;


--
-- Name: FUNCTION foldername(name text); Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON FUNCTION storage.foldername(name text) TO anon;
GRANT ALL ON FUNCTION storage.foldername(name text) TO authenticated;
GRANT ALL ON FUNCTION storage.foldername(name text) TO service_role;
GRANT ALL ON FUNCTION storage.foldername(name text) TO dashboard_user;
GRANT ALL ON FUNCTION storage.foldername(name text) TO postgres;


--
-- Name: FUNCTION get_size_by_bucket(); Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON FUNCTION storage.get_size_by_bucket() TO postgres;


--
-- Name: FUNCTION search(prefix text, bucketname text, limits integer, levels integer, offsets integer, search text, sortcolumn text, sortorder text); Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON FUNCTION storage.search(prefix text, bucketname text, limits integer, levels integer, offsets integer, search text, sortcolumn text, sortorder text) TO postgres;


--
-- Name: FUNCTION update_updated_at_column(); Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON FUNCTION storage.update_updated_at_column() TO postgres;


--
-- Name: TABLE audit_log_entries; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.audit_log_entries TO dashboard_user;
GRANT ALL ON TABLE auth.audit_log_entries TO postgres;


--
-- Name: TABLE flow_state; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.flow_state TO postgres;
GRANT ALL ON TABLE auth.flow_state TO dashboard_user;


--
-- Name: TABLE identities; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.identities TO postgres;
GRANT ALL ON TABLE auth.identities TO dashboard_user;


--
-- Name: TABLE instances; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.instances TO dashboard_user;
GRANT ALL ON TABLE auth.instances TO postgres;


--
-- Name: TABLE mfa_amr_claims; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.mfa_amr_claims TO postgres;
GRANT ALL ON TABLE auth.mfa_amr_claims TO dashboard_user;


--
-- Name: TABLE mfa_challenges; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.mfa_challenges TO postgres;
GRANT ALL ON TABLE auth.mfa_challenges TO dashboard_user;


--
-- Name: TABLE mfa_factors; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.mfa_factors TO postgres;
GRANT ALL ON TABLE auth.mfa_factors TO dashboard_user;


--
-- Name: TABLE refresh_tokens; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.refresh_tokens TO dashboard_user;
GRANT ALL ON TABLE auth.refresh_tokens TO postgres;


--
-- Name: SEQUENCE refresh_tokens_id_seq; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON SEQUENCE auth.refresh_tokens_id_seq TO dashboard_user;
GRANT ALL ON SEQUENCE auth.refresh_tokens_id_seq TO postgres;


--
-- Name: TABLE saml_providers; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.saml_providers TO postgres;
GRANT ALL ON TABLE auth.saml_providers TO dashboard_user;


--
-- Name: TABLE saml_relay_states; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.saml_relay_states TO postgres;
GRANT ALL ON TABLE auth.saml_relay_states TO dashboard_user;


--
-- Name: TABLE schema_migrations; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.schema_migrations TO dashboard_user;
GRANT ALL ON TABLE auth.schema_migrations TO postgres;


--
-- Name: TABLE sessions; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.sessions TO postgres;
GRANT ALL ON TABLE auth.sessions TO dashboard_user;


--
-- Name: TABLE sso_domains; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.sso_domains TO postgres;
GRANT ALL ON TABLE auth.sso_domains TO dashboard_user;


--
-- Name: TABLE sso_providers; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.sso_providers TO postgres;
GRANT ALL ON TABLE auth.sso_providers TO dashboard_user;


--
-- Name: TABLE users; Type: ACL; Schema: auth; Owner: supabase_auth_admin
--

GRANT ALL ON TABLE auth.users TO dashboard_user;
GRANT ALL ON TABLE auth.users TO postgres;


--
-- Name: TABLE pg_stat_statements; Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON TABLE extensions.pg_stat_statements TO dashboard_user;
GRANT ALL ON TABLE extensions.pg_stat_statements TO postgres WITH GRANT OPTION;


--
-- Name: TABLE pg_stat_statements_info; Type: ACL; Schema: extensions; Owner: supabase_admin
--

GRANT ALL ON TABLE extensions.pg_stat_statements_info TO dashboard_user;
GRANT ALL ON TABLE extensions.pg_stat_statements_info TO postgres WITH GRANT OPTION;


--
-- Name: SEQUENCE seq_schema_version; Type: ACL; Schema: graphql; Owner: supabase_admin
--

GRANT ALL ON SEQUENCE graphql.seq_schema_version TO postgres;
GRANT ALL ON SEQUENCE graphql.seq_schema_version TO anon;
GRANT ALL ON SEQUENCE graphql.seq_schema_version TO authenticated;
GRANT ALL ON SEQUENCE graphql.seq_schema_version TO service_role;


--
-- Name: TABLE decrypted_key; Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

GRANT ALL ON TABLE pgsodium.decrypted_key TO pgsodium_keyholder;


--
-- Name: SEQUENCE key_key_id_seq; Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

REVOKE ALL ON SEQUENCE pgsodium.key_key_id_seq FROM supabase_admin;
GRANT ALL ON SEQUENCE pgsodium.key_key_id_seq TO postgres;
GRANT ALL ON SEQUENCE pgsodium.key_key_id_seq TO pgsodium_keyiduser;


--
-- Name: TABLE masking_rule; Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

GRANT ALL ON TABLE pgsodium.masking_rule TO pgsodium_keyholder;


--
-- Name: TABLE mask_columns; Type: ACL; Schema: pgsodium; Owner: supabase_admin
--

GRANT ALL ON TABLE pgsodium.mask_columns TO pgsodium_keyholder;


--
-- Name: TABLE test; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.test TO anon;
GRANT ALL ON TABLE public.test TO authenticated;
GRANT ALL ON TABLE public.test TO service_role;


--
-- Name: TABLE test2; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.test2 TO anon;
GRANT ALL ON TABLE public.test2 TO authenticated;
GRANT ALL ON TABLE public.test2 TO service_role;


--
-- Name: SEQUENCE test2_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.test2_id_seq TO anon;
GRANT ALL ON SEQUENCE public.test2_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.test2_id_seq TO service_role;


--
-- Name: SEQUENCE test_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.test_id_seq TO anon;
GRANT ALL ON SEQUENCE public.test_id_seq TO authenticated;
GRANT ALL ON SEQUENCE public.test_id_seq TO service_role;


--
-- Name: TABLE buckets; Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON TABLE storage.buckets TO anon;
GRANT ALL ON TABLE storage.buckets TO authenticated;
GRANT ALL ON TABLE storage.buckets TO service_role;
GRANT ALL ON TABLE storage.buckets TO postgres;


--
-- Name: TABLE migrations; Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON TABLE storage.migrations TO anon;
GRANT ALL ON TABLE storage.migrations TO authenticated;
GRANT ALL ON TABLE storage.migrations TO service_role;
GRANT ALL ON TABLE storage.migrations TO postgres;


--
-- Name: TABLE objects; Type: ACL; Schema: storage; Owner: supabase_storage_admin
--

GRANT ALL ON TABLE storage.objects TO anon;
GRANT ALL ON TABLE storage.objects TO authenticated;
GRANT ALL ON TABLE storage.objects TO service_role;
GRANT ALL ON TABLE storage.objects TO postgres;


--
-- Name: TABLE decrypted_secrets; Type: ACL; Schema: vault; Owner: supabase_admin
--

GRANT ALL ON TABLE vault.decrypted_secrets TO pgsodium_keyiduser;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: auth; Owner: supabase_auth_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_auth_admin IN SCHEMA auth GRANT ALL ON SEQUENCES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_auth_admin IN SCHEMA auth GRANT ALL ON SEQUENCES  TO dashboard_user;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: auth; Owner: supabase_auth_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_auth_admin IN SCHEMA auth GRANT ALL ON FUNCTIONS  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_auth_admin IN SCHEMA auth GRANT ALL ON FUNCTIONS  TO dashboard_user;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: auth; Owner: supabase_auth_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_auth_admin IN SCHEMA auth GRANT ALL ON TABLES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_auth_admin IN SCHEMA auth GRANT ALL ON TABLES  TO dashboard_user;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: extensions; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA extensions GRANT ALL ON SEQUENCES  TO postgres WITH GRANT OPTION;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: extensions; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA extensions GRANT ALL ON FUNCTIONS  TO postgres WITH GRANT OPTION;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: extensions; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA extensions GRANT ALL ON TABLES  TO postgres WITH GRANT OPTION;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: graphql; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON SEQUENCES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON SEQUENCES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON SEQUENCES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON SEQUENCES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: graphql; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON FUNCTIONS  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON FUNCTIONS  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON FUNCTIONS  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON FUNCTIONS  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: graphql; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON TABLES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON TABLES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON TABLES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql GRANT ALL ON TABLES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: graphql_public; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON SEQUENCES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON SEQUENCES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON SEQUENCES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON SEQUENCES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: graphql_public; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON FUNCTIONS  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON FUNCTIONS  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON FUNCTIONS  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON FUNCTIONS  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: graphql_public; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON TABLES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON TABLES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON TABLES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA graphql_public GRANT ALL ON TABLES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: pgsodium; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA pgsodium GRANT ALL ON SEQUENCES  TO pgsodium_keyholder;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: pgsodium; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA pgsodium GRANT ALL ON SEQUENCES  TO pgsodium_keyiduser;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: pgsodium; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA pgsodium GRANT ALL ON TABLES  TO pgsodium_keyholder;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: pgsodium; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA pgsodium GRANT ALL ON TABLES  TO pgsodium_keyiduser;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: pgsodium_masks; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA pgsodium_masks GRANT ALL ON SEQUENCES  TO pgsodium_keyiduser;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: pgsodium_masks; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA pgsodium_masks GRANT ALL ON FUNCTIONS  TO pgsodium_keyiduser;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: pgsodium_masks; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA pgsodium_masks GRANT ALL ON TABLES  TO pgsodium_keyiduser;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: public; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON SEQUENCES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON SEQUENCES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON SEQUENCES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON SEQUENCES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: public; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON SEQUENCES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON SEQUENCES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON SEQUENCES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON SEQUENCES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: public; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON FUNCTIONS  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON FUNCTIONS  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON FUNCTIONS  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON FUNCTIONS  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: public; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON FUNCTIONS  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON FUNCTIONS  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON FUNCTIONS  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON FUNCTIONS  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: public; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON TABLES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON TABLES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON TABLES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON TABLES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: public; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON TABLES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON TABLES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON TABLES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA public GRANT ALL ON TABLES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: realtime; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA realtime GRANT ALL ON SEQUENCES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA realtime GRANT ALL ON SEQUENCES  TO dashboard_user;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: realtime; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA realtime GRANT ALL ON FUNCTIONS  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA realtime GRANT ALL ON FUNCTIONS  TO dashboard_user;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: realtime; Owner: supabase_admin
--

ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA realtime GRANT ALL ON TABLES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE supabase_admin IN SCHEMA realtime GRANT ALL ON TABLES  TO dashboard_user;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: storage; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON SEQUENCES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON SEQUENCES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON SEQUENCES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON SEQUENCES  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR FUNCTIONS; Type: DEFAULT ACL; Schema: storage; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON FUNCTIONS  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON FUNCTIONS  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON FUNCTIONS  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON FUNCTIONS  TO service_role;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: storage; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON TABLES  TO postgres;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON TABLES  TO anon;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON TABLES  TO authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA storage GRANT ALL ON TABLES  TO service_role;


--
-- Name: issue_graphql_placeholder; Type: EVENT TRIGGER; Schema: -; Owner: supabase_admin
--

CREATE EVENT TRIGGER issue_graphql_placeholder ON sql_drop
         WHEN TAG IN ('DROP EXTENSION')
   EXECUTE FUNCTION extensions.set_graphql_placeholder();


ALTER EVENT TRIGGER issue_graphql_placeholder OWNER TO supabase_admin;

--
-- Name: issue_pg_cron_access; Type: EVENT TRIGGER; Schema: -; Owner: supabase_admin
--

CREATE EVENT TRIGGER issue_pg_cron_access ON ddl_command_end
         WHEN TAG IN ('CREATE SCHEMA')
   EXECUTE FUNCTION extensions.grant_pg_cron_access();


ALTER EVENT TRIGGER issue_pg_cron_access OWNER TO supabase_admin;

--
-- Name: issue_pg_graphql_access; Type: EVENT TRIGGER; Schema: -; Owner: supabase_admin
--

CREATE EVENT TRIGGER issue_pg_graphql_access ON ddl_command_end
         WHEN TAG IN ('CREATE FUNCTION')
   EXECUTE FUNCTION extensions.grant_pg_graphql_access();


ALTER EVENT TRIGGER issue_pg_graphql_access OWNER TO supabase_admin;

--
-- Name: issue_pg_net_access; Type: EVENT TRIGGER; Schema: -; Owner: supabase_admin
--

CREATE EVENT TRIGGER issue_pg_net_access ON ddl_command_end
         WHEN TAG IN ('CREATE EXTENSION')
   EXECUTE FUNCTION extensions.grant_pg_net_access();


ALTER EVENT TRIGGER issue_pg_net_access OWNER TO supabase_admin;

--
-- Name: pgrst_ddl_watch; Type: EVENT TRIGGER; Schema: -; Owner: supabase_admin
--

CREATE EVENT TRIGGER pgrst_ddl_watch ON ddl_command_end
   EXECUTE FUNCTION extensions.pgrst_ddl_watch();


ALTER EVENT TRIGGER pgrst_ddl_watch OWNER TO supabase_admin;

--
-- Name: pgrst_drop_watch; Type: EVENT TRIGGER; Schema: -; Owner: supabase_admin
--

CREATE EVENT TRIGGER pgrst_drop_watch ON sql_drop
   EXECUTE FUNCTION extensions.pgrst_drop_watch();


ALTER EVENT TRIGGER pgrst_drop_watch OWNER TO supabase_admin;

--
-- PostgreSQL database dump complete
--

