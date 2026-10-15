--
-- PostgreSQL database cluster dump
--

SET default_transaction_read_only = off;

SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;

--
-- Roles
--

CREATE ROLE anon;
ALTER ROLE anon WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB NOLOGIN NOREPLICATION NOBYPASSRLS;
CREATE ROLE authenticated;
ALTER ROLE authenticated WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB NOLOGIN NOREPLICATION NOBYPASSRLS;
CREATE ROLE authenticator;
ALTER ROLE authenticator WITH NOSUPERUSER NOINHERIT NOCREATEROLE NOCREATEDB LOGIN NOREPLICATION NOBYPASSRLS PASSWORD 'SCRAM-SHA-256$4096:szx/7w+4m/hR2n9MfVITFQ==$GGxciglaM3HdLL1ahHSCqmxHm7Qz4vKrte4Lnb7N0jo=:XoV9G2pR9vra3HWwv1amgqqPS6jAmq6DnSlN6NN+oP8=';
CREATE ROLE dashboard_user;
ALTER ROLE dashboard_user WITH NOSUPERUSER INHERIT CREATEROLE CREATEDB NOLOGIN REPLICATION NOBYPASSRLS;
CREATE ROLE pgbouncer;
ALTER ROLE pgbouncer WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB LOGIN NOREPLICATION NOBYPASSRLS PASSWORD 'SCRAM-SHA-256$4096:G45ht6arMDEwvdkoYVf0nA==$Zmxb/SeY3OQGehZwKl1Z+cPItFhUxAAfXmnD3pYxWns=:qV03RcTGmWGNI15aucpO2gnS0B/VORXIPlNKI762z3Q=';
CREATE ROLE pgsodium_keyholder;
ALTER ROLE pgsodium_keyholder WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB NOLOGIN NOREPLICATION NOBYPASSRLS;
CREATE ROLE pgsodium_keyiduser;
ALTER ROLE pgsodium_keyiduser WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB NOLOGIN NOREPLICATION NOBYPASSRLS;
CREATE ROLE pgsodium_keymaker;
ALTER ROLE pgsodium_keymaker WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB NOLOGIN NOREPLICATION NOBYPASSRLS;
CREATE ROLE pgtle_admin;
ALTER ROLE pgtle_admin WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB NOLOGIN NOREPLICATION NOBYPASSRLS;
CREATE ROLE service_role;
ALTER ROLE service_role WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB NOLOGIN NOREPLICATION BYPASSRLS;
CREATE ROLE supabase_admin;
ALTER ROLE supabase_admin WITH SUPERUSER INHERIT CREATEROLE CREATEDB LOGIN REPLICATION BYPASSRLS PASSWORD 'SCRAM-SHA-256$4096:c+sBcrIXDNbQrZzLMsu1IQ==$hfowwoASBk25DiYF0qJOEg7w1gI+ymoJx8a5+vwO0eE=:L/CN+wuruMEejJ/LuAOS3We5kW3fQ4y8NGd28t7FaL4=';
CREATE ROLE supabase_auth_admin;
ALTER ROLE supabase_auth_admin WITH NOSUPERUSER NOINHERIT CREATEROLE NOCREATEDB LOGIN NOREPLICATION NOBYPASSRLS PASSWORD 'SCRAM-SHA-256$4096:9Qqu8Ll/V1X12FSamb0QZw==$/ZtigsHl2PkOSEZOkipQt+rEAItTXxO8qaG93mIB36Q=:8EsySWfNFoTAlu60O3L/PS3XX2GQBGfitZwli2yL6JI=';
CREATE ROLE supabase_read_only_user;
ALTER ROLE supabase_read_only_user WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB LOGIN NOREPLICATION BYPASSRLS PASSWORD 'SCRAM-SHA-256$4096:HDZLOG6Y5BTRO9bZ86fhMg==$LpcVbWDXFn9PzSV6dRoAwi/C0ze8cTOErUmIw1f5qps=:eB7dElN0V1+DSDK5TcngUXcGAu1n8wT+d/tkTcsuJyg=';
CREATE ROLE supabase_replication_admin;
ALTER ROLE supabase_replication_admin WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB LOGIN REPLICATION NOBYPASSRLS PASSWORD 'SCRAM-SHA-256$4096:A5RAYfnsNlNpFjDzHTB/8g==$rnnZHZ/77r8r37RglM56Br/25jLoUDFVDwgu2/fOATI=:0wLlbnAC7hzLhYwFSQVVfFBHiugU9+e25+IWkonLDeg=';
CREATE ROLE supabase_storage_admin;
ALTER ROLE supabase_storage_admin WITH NOSUPERUSER NOINHERIT CREATEROLE NOCREATEDB LOGIN NOREPLICATION NOBYPASSRLS PASSWORD 'SCRAM-SHA-256$4096:xf0jcoRsFxNrSY7GdGxW6Q==$nuEuY584hOb0eZHfRfXa9Z7mB8rNnn3AB/X+Kd2jXOE=:cByY2VNQTEILEjw3qtwK0Xydk/4n+Te7uEOdMl8LQk8=';

--
-- User Configurations
--

--
-- User Config "anon"
--

ALTER ROLE anon SET statement_timeout TO '3s';

--
-- User Config "authenticated"
--

ALTER ROLE authenticated SET statement_timeout TO '8s';

--
-- User Config "authenticator"
--

ALTER ROLE authenticator SET session_preload_libraries TO 'supautils', 'safeupdate';
ALTER ROLE authenticator SET statement_timeout TO '8s';

--
-- User Config "postgres"
--

ALTER ROLE postgres SET search_path TO E'\\$user', 'public', 'extensions';

--
-- User Config "supabase_admin"
--

ALTER ROLE supabase_admin SET search_path TO '$user', 'public', 'auth', 'extensions';

--
-- User Config "supabase_auth_admin"
--

ALTER ROLE supabase_auth_admin SET search_path TO 'auth';
ALTER ROLE supabase_auth_admin SET idle_in_transaction_session_timeout TO '60000';

--
-- User Config "supabase_storage_admin"
--

ALTER ROLE supabase_storage_admin SET search_path TO 'storage';


--
-- Role memberships
--

GRANT anon TO authenticator GRANTED BY postgres;
GRANT anon TO postgres GRANTED BY supabase_admin;
GRANT anon TO supabase_storage_admin GRANTED BY supabase_admin;
GRANT authenticated TO authenticator GRANTED BY postgres;
GRANT authenticated TO postgres GRANTED BY supabase_admin;
GRANT authenticated TO supabase_storage_admin GRANTED BY supabase_admin;
GRANT pg_monitor TO postgres GRANTED BY supabase_admin;
GRANT pg_read_all_data TO supabase_read_only_user GRANTED BY postgres;
GRANT pgsodium_keyholder TO pgsodium_keymaker GRANTED BY supabase_admin;
GRANT pgsodium_keyholder TO postgres WITH ADMIN OPTION GRANTED BY supabase_admin;
GRANT pgsodium_keyholder TO service_role GRANTED BY supabase_admin;
GRANT pgsodium_keyiduser TO pgsodium_keyholder GRANTED BY supabase_admin;
GRANT pgsodium_keyiduser TO pgsodium_keymaker GRANTED BY supabase_admin;
GRANT pgsodium_keyiduser TO postgres WITH ADMIN OPTION GRANTED BY supabase_admin;
GRANT pgsodium_keymaker TO postgres WITH ADMIN OPTION GRANTED BY supabase_admin;
GRANT pgtle_admin TO postgres GRANTED BY supabase_admin;
GRANT service_role TO authenticator GRANTED BY postgres;
GRANT service_role TO postgres GRANTED BY supabase_admin;
GRANT service_role TO supabase_storage_admin GRANTED BY supabase_admin;
GRANT supabase_auth_admin TO postgres GRANTED BY supabase_admin;
GRANT supabase_storage_admin TO postgres GRANTED BY supabase_admin;






--
-- PostgreSQL database cluster dump complete
--

