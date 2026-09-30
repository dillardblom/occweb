<?php

namespace OCA\OCCWeb\Tests\Unit\Service;

use OCA\OCCWeb\Service\SqlGuard;
use PHPUnit\Framework\TestCase;

class SqlGuardTest extends TestCase
{
    public static function forbiddenProvider(): array
    {
        return [
            ["COPY (SELECT 1) TO PROGRAM 'sh'"],
            ["COPY (SELECT 'id') TO/**/PROGRAM 'sh'"],
            ["COPY (SELECT 1) TO--x\nPROGRAM 'sh'"],
            ["COPY (SELECT 1) TO '/tmp/x'"],
            ["COPY (SELECT current_user) TO/**/'/tmp/x'"],
            ["COPY (SELECT 1) TO E'/tmp/x'"],
            ["COPY (SELECT 1) TO \$\$/tmp/x\$\$"],
            ["COPY (SELECT 1) TO \$f\$/tmp/x\$f\$"],
            ["COPY oc_users FROM '/etc/passwd'"],
            ["SELECT pg_read_file/**/('/etc/passwd')"],
            ["SELECT pg_ls_dir('/')"],
            ["SELECT lo_import('/etc/passwd')"],
            ["SELECT dblink_connect('host=x')"],
            ["SELECT * FROM t INTO OUTFILE '/tmp/x'"],
        ];
    }

    /**
     * @dataProvider forbiddenProvider
     */
    public function testForbidden(string $sql): void
    {
        $this->assertNotNull(SqlGuard::findForbiddenConstruct($sql));
    }

    public static function allowedProvider(): array
    {
        return [
            ['SELECT * FROM oc_users'],
            ['COPY (SELECT 1) TO STDOUT'],
        ];
    }

    /**
     * @dataProvider allowedProvider
     */
    public function testAllowed(string $sql): void
    {
        $this->assertNull(SqlGuard::findForbiddenConstruct($sql));
    }

    public static function readOnlyProvider(): array
    {
        return [
            ['SELECT * FROM oc_users'],
            ['  -- comment' . "\n" . 'SELECT 1'],
            ['/* comment */ SELECT 1'],
            ['(SELECT 1) UNION (SELECT 2)'],
            ['WITH x AS (SELECT 1) SELECT * FROM x'],
            ["SELECT * FROM oc_jobs WHERE class = 'OCA\\\\Mail\\\\DeleteJob'"],
            ["SELECT * FROM t WHERE status = 'deleted' OR note = 'update into'"],
            ['SELECT "update" FROM t'],
            ["SELECT replace(name, 'a', 'b') FROM t"],
            ['SHOW search_path'],
            ['EXPLAIN SELECT 1'],
            ["SET myvar.uid = 'alice'"],
            ["SELECT \$\$DELETE FROM x\$\$"],
            ["SELECT E'it\\'s delete' FROM t"],
            ['VALUES (1)'],
        ];
    }

    /**
     * @dataProvider readOnlyProvider
     */
    public function testReadOnly(string $sql): void
    {
        $this->assertTrue(SqlGuard::isReadOnly($sql), $sql);
    }

    public static function writeProvider(): array
    {
        return [
            ['DELETE FROM oc_preferences'],
            ['/* not a leading -- comment */ DELETE FROM oc_preferences'],
            ["-- x\nUPDATE t SET a = 1"],
            ['WITH removed AS (DELETE FROM oc_preferences RETURNING *) SELECT count(*) FROM removed'],
            ['WITH x AS (UPDATE t SET a = 1 RETURNING *) SELECT 1'],
            ['INSERT INTO t VALUES (1)'],
            ['TRUNCATE oc_preferences'],
            ['DROP TABLE t'],
            ['ALTER TABLE t ADD c int'],
            ['CREATE TABLE t (a int)'],
            ['GRANT ALL ON t TO x'],
            ["DO \$\$BEGIN PERFORM 1; END\$\$"],
            ['CALL p()'],
            ['SELECT * INTO newtable FROM t'],
            ['SELECT * FROM t FOR UPDATE'],
            ['EXPLAIN ANALYZE DELETE FROM t'],
            ['SET ROLE postgres'],
            ['SET SESSION AUTHORIZATION postgres'],
            ['SELECT pg_terminate_backend(123)'],
            ["SELECT set_config('x', 'y', false)"],
            ["SELECT setval('seq', 1)"],
            ['MERGE INTO t USING s ON true WHEN MATCHED THEN DELETE'],
            ['VACUUM'],
            ['', ],
        ];
    }

    /**
     * @dataProvider writeProvider
     */
    public function testNeedsConfirmation(string $sql): void
    {
        $this->assertFalse(SqlGuard::isReadOnly($sql), $sql);
    }

    public function testStatementType(): void
    {
        $this->assertSame('DELETE', SqlGuard::statementType('/* x */ delete from t'));
        $this->assertSame('WITH', SqlGuard::statementType("-- x\nWITH a AS (SELECT 1) SELECT 1"));
        $this->assertSame('SELECT', SqlGuard::statementType('(SELECT 1)'));
        $this->assertSame('', SqlGuard::statementType(''));
    }

    public static function splitProvider(): array
    {
        return [
            ['SELECT 1; SELECT 2', ['SELECT 1', 'SELECT 2']],
            ["SELECT ';'; SELECT 2", ["SELECT ';'", 'SELECT 2']],
            ["SELECT 'it''s; x'", ["SELECT 'it''s; x'"]],
            ['SELECT "a;b" FROM t', ['SELECT "a;b" FROM t']],
            ["SELECT E'it\\'s; x'; SELECT 2", ["SELECT E'it\\'s; x'", 'SELECT 2']],
            ["DO \$\$BEGIN PERFORM 1; PERFORM 2; END\$\$; SELECT 3", ["DO \$\$BEGIN PERFORM 1; PERFORM 2; END\$\$", 'SELECT 3']],
            ["SELECT \$f\$a;\$\$;b\$f\$; SELECT 2", ["SELECT \$f\$a;\$\$;b\$f\$", 'SELECT 2']],
            ["-- don't; split\nSELECT 1; SELECT 2", ["-- don't; split\nSELECT 1", 'SELECT 2']],
            ["/* it's; here */ SELECT 1; SELECT 2", ["/* it's; here */ SELECT 1", 'SELECT 2']],
            ['SELECT \$1; SELECT 2', ['SELECT \$1', 'SELECT 2']],
            [' ; ;SELECT 1;', ['SELECT 1']],
        ];
    }

    /**
     * @dataProvider splitProvider
     */
    public function testSplitStatements(string $sql, array $expected): void
    {
        $this->assertSame($expected, SqlGuard::splitStatements($sql));
    }
}
