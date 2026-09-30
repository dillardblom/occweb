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

    /**
     * Statements that start with one of these only read, unless
     * WRITES_INSIDE_READ matches. SQLite's PRAGMA is left out on purpose:
     * it can also change settings, so it asks for confirmation.
     */
    private const READ_ONLY_START = '/^(SELECT|WITH|SHOW|EXPLAIN|DESCRIBE|DESC|SET|VALUES|TABLE)\b/i';

    /**
     * What makes a statement that starts as a read change something:
     * a data-modifying CTE (WITH x AS (DELETE ...)), SELECT ... INTO,
     * SELECT ... FOR UPDATE, EXPLAIN ANALYZE (runs the statement),
     * SET ROLE / SET SESSION AUTHORIZATION, MySQL's SET PASSWORD /
     * GLOBAL / PERSIST, and functions with side
     * effects. Statements that don't start as a read always need
     * confirmation, so INSERT, DROP, CREATE etc. don't need to be here.
     */
    private const WRITES_INSIDE_READ = [
        '/\b(INSERT|UPDATE|DELETE|MERGE|INTO|ANALYZE)\b/i',
        '/^SET\s+(SESSION\s+|LOCAL\s+)?(ROLE|SESSION\s+AUTHORIZATION)\b/i',
        '/^SET\s+(PASSWORD|GLOBAL|PERSIST|PERSIST_ONLY)\b|^SET\s+@@(GLOBAL|PERSIST|PERSIST_ONLY)\./i',
        '/\b(pg_terminate_backend|pg_cancel_backend|pg_reload_conf|set_config|setval|nextval|lo_create|lo_put|lo_unlink|pg_switch_wal|pg_create_restore_point|pg_advisory_lock)\s*\(/i',
    ];

    /**
     * Splits a batch on ";" outside of 'strings', "identifiers",
     * $tag$dollar-quoted bodies$tag$ and comments, so a semicolon in a
     * DO block or a quote in a comment doesn't break up the split.
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $end = self::literalEnd($sql, $i) ?? self::commentEnd($sql, $i);
            if ($end !== null) {
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($sql[$i] === ';') {
                $statements[] = trim($current);
                $current = '';
            } else {
                $current .= $sql[$i];
            }
            $i++;
        }

        $statements[] = trim($current);

        return array_values(array_filter($statements, function ($s) {
            return $s !== '';
        }));
    }

    /**
     * Replaces comments with a space, outside of literals only. A comment
     * separates tokens, so dropping it without a space would glue
     * "TO/ ** /PROGRAM" into one word; and a comment marker inside a
     * literal ('/*') must not swallow the code after it.
     */
    public static function removeComments(string $query): string
    {
        return self::scan($query, false);
    }

    /**
     * Also replaces string literals and quoted identifiers with a
     * placeholder, so words inside them ('deleted', "update") don't count
     * as keywords.
     */
    public static function removeLiterals(string $query): string
    {
        return self::scan($query, true);
    }

    private static function scan(string $sql, bool $replaceLiterals): string
    {
        $result = '';
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $end = self::literalEnd($sql, $i);
            if ($end !== null) {
                $result .= $replaceLiterals ? ($sql[$i] === '"' ? '""' : "''") : substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            $end = self::commentEnd($sql, $i);
            if ($end !== null) {
                $result .= ' ';
                $i = $end;
                continue;
            }
            $result .= $sql[$i];
            $i++;
        }

        return $result;
    }

    /**
     * End offset of the 'string', E'string', "identifier" or $tag$body$tag$
     * starting at $i, or null if none starts there. An unclosed literal
     * runs to the end.
     */
    private static function literalEnd(string $sql, int $i): ?int
    {
        $len = strlen($sql);
        $ch = $sql[$i];

        if ($ch === "'" || $ch === '"') {
            $backslashEscapes = $ch === "'" && $i > 0 && ($sql[$i - 1] === 'E' || $sql[$i - 1] === 'e')
                && ($i === 1 || !preg_match('/[A-Za-z0-9_]/', $sql[$i - 2]));
            $end = $i + 1;
            while ($end < $len) {
                if ($backslashEscapes && $sql[$end] === '\\') {
                    $end += 2;
                    continue;
                }
                if ($sql[$end] === $ch) {
                    if ($end + 1 < $len && $sql[$end + 1] === $ch) {
                        $end += 2;
                        continue;
                    }
                    return $end + 1;
                }
                $end++;
            }
            return $len;
        }

        if ($ch === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
            $close = strpos($sql, $m[0], $i + strlen($m[0]));
            return $close === false ? $len : $close + strlen($m[0]);
        }

        return null;
    }

    /**
     * End offset of the -- or block comment starting at $i, or null.
     */
    private static function commentEnd(string $sql, int $i): ?int
    {
        if (substr($sql, $i, 2) === '--') {
            $newline = strpos($sql, "\n", $i);
            return $newline === false ? strlen($sql) : $newline;
        }
        if (substr($sql, $i, 2) === '/*') {
            $close = strpos($sql, '*/', $i + 2);
            return $close === false ? strlen($sql) : $close + 2;
        }
        return null;
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
