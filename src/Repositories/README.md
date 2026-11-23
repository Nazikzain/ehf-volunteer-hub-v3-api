# Repositories layer

**Status:** reserved; first repositories arrive with the database in Phase 3 and with
authentication in Phase 4 (API V1.3.1 §18).

**Responsibility (API §2.11):** prepared SQL only, fetching only the columns of the field
sets the policy allows. Connections come from `Infrastructure\Database\ConnectionFactory`
(real prepared statements, utf8mb4, UTC, strict SQL mode). The schema is defined by the
MySQL Database Architecture V1.4 and created by Phinx migrations in Phase 3.

**Must never:** decide permissions.

Phase 2 creates no tables and no migrations, and opens no database connection.
