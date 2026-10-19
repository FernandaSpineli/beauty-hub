-- The role the application connects as. It is neither a superuser nor the
-- owner of any table, so PostgreSQL row-level security always applies to it.
-- Migrations run as the owner role (POSTGRES_USER) and grant it table access.
CREATE ROLE beauty_app LOGIN PASSWORD 'beauty_app' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
