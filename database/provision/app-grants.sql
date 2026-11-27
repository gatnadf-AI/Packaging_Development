-- TSD-PDS-003 §11, TSD-1102 — audit immutability at the database layer.
--
-- Application code can be bypassed; a missing grant cannot.
--
-- The shape of this script is decided by what MySQL will and will not do. The
-- application account holds DML at SCHEMA level (GRANT ... ON `pds`.*), and a
-- schema-level grant cannot be subtracted from below: REVOKE UPDATE, DELETE ON
-- `pds`.`audit_entries` answers ERROR 1147 (no such grant defined) and the
-- UPDATE that follows still takes effect. Nor can the guard be a trigger — the
-- app account holds no TRIGGER privilege, and a trigger the application cannot
-- create is a trigger the migrations cannot ship. So the grant is rebuilt rather
-- than carved: DML is given back table by table first, and only then is the
-- schema-level grant taken away, which leaves the two append-only tables with
-- SELECT and INSERT and every other base table with its four.
--
-- The order is not decoration. information_schema answers from the grants that
-- exist at the moment it is read, so a script that revoked first and then looked
-- up whose tables to grant would find no account, grant no table, and exit
-- without an error over a schema the application could no longer write. For the
-- same reason the account list below is read from whichever level the privileges
-- are still held at, so that running this script twice is a repair rather than a
-- no-op that reports success.
--
-- DDL stays at schema level (CREATE, DROP, ALTER, INDEX, REFERENCES) because
-- `php artisan migrate` runs as the application account. A table that does not
-- exist yet therefore has no DML grant until this script is run again, which is
-- why it belongs in the migration step and why `php artisan pds:doctor` names the
-- tables that are still waiting for one.
--
-- Run as an administrative account (root locally), with the target schema
-- selected, after every migrate that creates a table:
--
--     mysql -u root pds < database/provision/app-grants.sql
--
-- pds_test is deliberately left out of this. RefreshDatabase rebuilds that schema
-- and its grants from nothing on every suite run, so the database-level answer
-- cannot live there; the suite proves the model-level refusal instead
-- (Tests\Feature\Audit\AuditImmutabilityTest), and this script is what the
-- deployed schema is verified with.

DELIMITER $$

DROP PROCEDURE IF EXISTS pds_apply_dml_grants$$

CREATE PROCEDURE pds_apply_dml_grants()
BEGIN
    DECLARE v_grantee VARCHAR(129);
    DECLARE v_table VARCHAR(64);
    DECLARE v_done TINYINT DEFAULT 0;

    -- Every account the application could connect as, taken from whichever level
    -- it currently holds privileges at: schema level on a first run, table level
    -- on a re-run, because by then the schema-level row is gone. Reading only
    -- schema_privileges here would make a second run find no account, grant no
    -- table, revoke nothing, and report success over a schema the application
    -- cannot write.
    DECLARE writings CURSOR FOR
        SELECT DISTINCT held.grantee, named.table_name
          FROM (SELECT grantee
                  FROM information_schema.table_privileges
                 WHERE table_schema = DATABASE()
                 UNION
                SELECT grantee
                  FROM information_schema.schema_privileges
                 WHERE table_schema = DATABASE()) AS held
         CROSS JOIN (SELECT table_name
                  FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_type = 'BASE TABLE') AS named;

    -- The accounts whose schema-level DML is about to be taken away. This is read
    -- while the grants are still there, and it is what makes the revoke below
    -- conditional rather than merely quiet: an account that has already been
    -- stripped is simply not in the list, so there is no grant to subtract twice.
    DECLARE accounts CURSOR FOR
        SELECT DISTINCT grantee
          FROM information_schema.schema_privileges
         WHERE table_schema = DATABASE()
           AND privilege_type = 'INSERT';

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'pds_apply_dml_grants: select the target schema first (mysql -u root pds < app-grants.sql)';
    END IF;

    OPEN writings;

    grant_table_dml: LOOP
        FETCH writings INTO v_grantee, v_table;

        IF v_done THEN
            LEAVE grant_table_dml;
        END IF;

        SET @privileges = CASE
            WHEN v_table IN ('audit_entries', 'access_logs') THEN 'SELECT, INSERT'
            ELSE 'SELECT, INSERT, UPDATE, DELETE'
        END;

        SET @statement = CONCAT(
            'GRANT ', @privileges,
            ' ON `', DATABASE(), '`.`', v_table, '` TO ', v_grantee
        );

        PREPARE guard FROM @statement;
        EXECUTE guard;
        DEALLOCATE PREPARE guard;
    END LOOP;

    CLOSE writings;

    -- The table-level grants above are the floor; this is the ceiling. Schema-level
    -- DML is additive, so while it stands the per-table rows are not the answer the
    -- server gives — which is why the first version of this script, which tried to
    -- revoke UPDATE and DELETE at table level, provably changed nothing.
    OPEN accounts;

    strip_schema_dml: LOOP
        FETCH accounts INTO v_grantee;

        IF v_done THEN
            LEAVE strip_schema_dml;
        END IF;

        SET @statement = CONCAT(
            'REVOKE SELECT, INSERT, UPDATE, DELETE ON `', DATABASE(), '`.* FROM ', v_grantee
        );

        PREPARE guard FROM @statement;
        EXECUTE guard;
        DEALLOCATE PREPARE guard;
    END LOOP;

    CLOSE accounts;
END$$

DELIMITER ;

CALL pds_apply_dml_grants();

DROP PROCEDURE IF EXISTS pds_apply_dml_grants;

FLUSH PRIVILEGES;

-- Expected result afterwards, for the application account:
--
--   SHOW GRANTS FOR CURRENT_USER();
--
-- One line of DDL over the schema, one line of SELECT and INSERT on each of
-- audit_entries and access_logs, and one line of the four DML verbs on every
-- other base table. The schema line must not mention SELECT, INSERT, UPDATE or
-- DELETE, because that grant is the one no table-level row can argue with.
--
-- The same reading, in the form `php artisan pds:doctor` prints it, is the row
-- labelled "Audit grants" — and that row is the deployed-schema proof of
-- TSD-1102, so it is worth checking after a deploy rather than after a migrate.
