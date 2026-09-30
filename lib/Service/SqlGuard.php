<?php

namespace OCA\OCCWeb\Service;

/**
 * Checks run on SQL submitted through the SQL mode before it is executed.
 *
 * This is defense in depth, not a sandbox: dynamic SQL (EXECUTE with a
 * built-up string, functions) can always get around a text-based check.
 * The real protection is a database role without superuser rights,
 * pg_execute_server_program or pg_read/write_server_files.
 */
class SqlGuard
{
    /**
     * Constructs that give access to the server's filesystem or allow
     * launching external programs via SQL. These are blocked entirely,
     * with no way to confirm past them.
     */
    private const FORBIDDEN_PATTERNS = [
        '/\bCOPY\b[\s\S]*\bPROGRAM\b/i' => 'COPY ... PROGRAM (runs external commands)',
        // A COPY file name is a string literal: 'x', E'x', U&'x' or $$x$$
        '/\bCOPY\b[\s\S]*\b(FROM|TO)\s+(E|U&)?(\'|\$)/i' => "COPY ... FROM/TO 'file' (access to the server's filesystem)",
        '/\bpg_read_binary_file\s*\(/i' => 'pg_read_binary_file()',
        '/\bpg_read_file\s*\(/i' => 'pg_read_file()',
        '/\bpg_ls_dir\s*\(/i' => 'pg_ls_dir()',
        '/\bpg_stat_file\s*\(/i' => 'pg_stat_file()',
        '/\blo_import\s*\(/i' => 'lo_import()',
        '/\blo_export\s*\(/i' => 'lo_export()',
        '/\bdblink(_connect)?\s*\(/i' => 'dblink() (connects to arbitrary databases)',
        '/\bLOAD_FILE\s*\(/i' => 'LOAD_FILE()',
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i' => 'INTO OUTFILE/DUMPFILE',
    ];

    /** Statements that start with one of these only read, unless WRITES_INSIDE_READ matches. */
    private const READ_ONLY_START = '/^(SELECT|WITH|SHOW|EXPLAIN|SET|VALUES|TABLE)\b/i';

    /**
     * What makes a statement that starts as a read change something:
     * a data-modifying CTE (WITH x AS (DELETE ...)), SELECT ... INTO,
     * SELECT ... FOR UPDATE, EXPLAIN ANALYZE (runs the statement),
     * SET ROLE / SET SESSION AUTHORIZATION, and functions with side
     * effects. Statements that don't start as a read always need
     * confirmation, so INSERT, DROP, CREATE etc. don't need to be here.
     */
    private const WRITES_INSIDE_READ = [
        '/\b(INSERT|UPDATE|DELETE|MERGE|INTO|ANALYZE)\b/i',
        '/^SET\s+(SESSION\s+|LOCAL\s+)?(ROLE|SESSION\s+AUTHORIZATION)\b/i',
        '/\b(pg_terminate_backend|pg_cancel_backend|pg_reload_conf|set_config|setval|lo_create|lo_put|lo_unlink|pg_switch_wal|pg_create_restore_point|pg_advisory_lock)\s*\(/i',
    ];

    /**
     * Replaces comments with a space. A comment separates tokens, so
     * dropping it without a space would glue "TO/ ** /PROGRAM" into one
     * word and hide it from the patterns above.
     */
    public static function removeComments(string $query): string
    {
        $query = preg_replace('/--[^\n]*/', ' ', $query);
        return preg_replace('/\/\*[\s\S]*?\*\//', ' ', $query);
    }

    /**
     * Replaces string literals and quoted identifiers with a placeholder,
     * so words inside them ('deleted', "update") don't count as keywords.
     */
    public static function removeLiterals(string $query): string
    {
        $query = preg_replace('/\$([A-Za-z_][A-Za-z0-9_]*|)\$[\s\S]*?\$\1\$/', "''", $query);
        $query = preg_replace("/[Ee]'(?:[^'\\\\]|\\\\.|'')*'/", "''", $query);
        $query = preg_replace("/'(?:[^']|'')*'/", "''", $query);
        return preg_replace('/"(?:[^"]|"")*"/', '""', $query);
    }

    /**
     * Returns a description of the forbidden construct found, or null.
     * Literals are kept here on purpose: a file name is a literal.
     */
    public static function findForbiddenConstruct(string $query): ?string
    {
        $clean = self::removeComments($query);
        foreach (self::FORBIDDEN_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $clean)) {
                return $label;
            }
        }
        return null;
    }

    /**
     * The first keyword of a statement, upper case, e.g. SELECT or DELETE.
     */
    public static function statementType(string $query): string
    {
        $clean = ltrim(self::removeComments($query), " \t\n\r\0\x0B(");
        return preg_match('/^([A-Za-z]+)/', $clean, $m) ? strtoupper($m[1]) : '';
    }

    /**
     * Whether a statement only reads. Anything else needs confirmation.
     */
    public static function isReadOnly(string $query): bool
    {
        $clean = ltrim(self::removeLiterals(self::removeComments($query)), " \t\n\r\0\x0B(");
        if (preg_match(self::READ_ONLY_START, $clean) !== 1) {
            return false;
        }
        foreach (self::WRITES_INSIDE_READ as $pattern) {
            if (preg_match($pattern, $clean) === 1) {
                return false;
            }
        }
        return true;
    }
}
