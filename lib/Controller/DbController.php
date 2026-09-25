<?php

namespace OCA\OCCWeb\Controller;

use OC;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class DbController extends Controller
{
    /** Maximum rows returned per SELECT (protection against OOM/DoS). */
    private const MAX_ROWS = 1000;

    /** Maximum size of submitted SQL text in bytes (protection against OOM/DoS). */
    private const MAX_SQL_BYTES = 1048576; // 1 MB

    /**
     * Constructs that give access to the server's filesystem or allow
     * launching external programs via SQL. These are blocked entirely,
     * with no way to confirm past them — unlike DELETE, this isn't
     * about data loss, it's about potential server takeover.
     */
    private const FORBIDDEN_PATTERNS = [
        '/\bCOPY\b[\s\S]*\bPROGRAM\b/i' => 'COPY ... PROGRAM (runs external commands)',
        '/\bCOPY\b[\s\S]*\b(FROM|TO)\b\s*\'/i' => "COPY ... FROM/TO 'file' (access to the server's filesystem)",
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

    private $db;
    private $groupManager;
    private $userSession;
    private $logger;

    public function __construct(
        $AppName,
        IRequest $request,
        IDBConnection $db,
        IGroupManager $groupManager,
        IUserSession $userSession
    ) {
        parent::__construct($AppName, $request);
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->userSession = $userSession;
        // Via OC::$server, same as in OccController — we don't add LoggerInterface
        // to the constructor so as not to change the signature resolved by the DI container.
        $this->logger = OC::$server->get(LoggerInterface::class);
    }

    /**
     * Strips leading single-line comments ("-- ...") before a query.
     * After splitting a batch, such a comment can end up stuck to the
     * next query and interfere with detecting its type (SELECT/SET/DELETE).
     */
    private function stripLeadingComments($query)
    {
        $query = ltrim($query);
        while (preg_match('/^--[^\n]*\n/', $query)) {
            $query = ltrim(preg_replace('/^--[^\n]*\n/', '', $query, 1));
        }
        return $query;
    }

    /**
     * Strips ALL single-line (-- ...) and block-style C comments from a
     * query, including ones sitting inside an expression (e.g. between a
     * function name and its opening parenthesis — otherwise a forbidden
     * construct could be hidden from findForbiddenConstruct that way).
     * Used only for checking against forbidden constructs — the query
     * that actually gets executed is left unmodified.
     */
    private function removeAllComments($query)
    {
        $query = preg_replace('/--[^\n]*/', '', $query);
        $query = preg_replace('/\/\*[\s\S]*?\*\//', '', $query);
        return $query;
    }

    /**
     * Splits a batch of queries on ";" while respecting single and double
     * quotes (strings and Postgres-escaped identifiers), so that a
     * semicolon inside a 'string' or "identifier" (including '' / "" as
     * an escaped quote) doesn't break up the split.
     */
    private function splitStatements($sql)
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $quoteChar = null;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($quoteChar !== null) {
                if ($ch === $quoteChar) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quoteChar) {
                        $current .= $quoteChar . $quoteChar;
                        $i++;
                        continue;
                    }
                    $quoteChar = null;
                }
                $current .= $ch;
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quoteChar = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === ';') {
                $statements[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return array_values(array_filter($statements, function ($s) {
            return $s !== '';
        }));
    }

    /**
     * Returns a description of the forbidden construct found (filesystem
     * access, running programs), or null if the query is safe in that
     * regard. The comment-stripped version of the query is checked —
     * otherwise the construct could be hidden by inserting a comment
     * between the function name and "(".
     */
    private function findForbiddenConstruct($query)
    {
        $clean = $this->removeAllComments($query);
        foreach (self::FORBIDDEN_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $clean)) {
                return $label;
            }
        }
        return null;
    }

    public function query()
    {
        // Check admin privileges
        $user = $this->userSession->getUser();
        if (!$user) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        if (!$this->groupManager->isAdmin($user->getUID())) {
            return new JSONResponse(['error' => 'Admin privileges required'], 403);
        }

        $sql = $this->request->getParam('sql', '');
        $confirmed = filter_var($this->request->getParam('confirm', false), FILTER_VALIDATE_BOOLEAN);

        if (empty(trim($sql))) {
            return new JSONResponse(['success' => false, 'error' => 'Empty query']);
        }

        if (strlen($sql) > self::MAX_SQL_BYTES) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Query too large (max ' . self::MAX_SQL_BYTES . ' bytes)'
            ], 413);
        }

        // Audit: log the fact that execution was attempted BEFORE any checks,
        // so blocked/rejected queries are recorded in the log too, not just
        // the ones that succeeded.
        $this->logger->warning('[occweb] SQL submitted by {user}: {sql}', [
            'app' => 'occweb',
            'user' => $user->getUID(),
            'sql' => $sql,
        ]);

        // Split the batch on ";" respecting quotes (see splitStatements).
        // All queries run sequentially on the same DB connection (within a
        // single HTTP request), so SET retains its value for
        // current_setting() in the following queries of the same batch.
        $queries = $this->splitStatements($sql);

        // Access to the server's filesystem / running programs via SQL is
        // blocked entirely — this isn't about data loss (like DELETE), it's
        // about potential server takeover, and confirmation doesn't bypass it.
        foreach ($queries as $query) {
            $forbidden = $this->findForbiddenConstruct($query);
            if ($forbidden !== null) {
                $this->logger->error('[occweb] Blocked forbidden construct ({construct}) from {user}: {sql}', [
                    'app' => 'occweb',
                    'construct' => $forbidden,
                    'user' => $user->getUID(),
                    'sql' => $sql,
                ]);
                return new JSONResponse([
                    'success' => false,
                    'error' => "Query blocked: contains a forbidden construct ({$forbidden}). File/program access via SQL is not allowed."
                ]);
            }
        }

        // DELETE and UPDATE are irreversible (or hard to reverse), so we
        // require explicit confirmation from the client (confirm=true) before
        // executing a single query from the batch.
        $deleteCount = 0;
        $updateCount = 0;
        foreach ($queries as $query) {
            $normalized = $this->stripLeadingComments($query);
            if (stripos($normalized, 'DELETE') === 0) {
                $deleteCount++;
            } elseif (stripos($normalized, 'UPDATE') === 0) {
                $updateCount++;
            }
        }

        if (($deleteCount > 0 || $updateCount > 0) && !$confirmed) {
            $parts = [];
            if ($deleteCount > 0) {
                $parts[] = "{$deleteCount} DELETE";
            }
            if ($updateCount > 0) {
                $parts[] = "{$updateCount} UPDATE";
            }
            return new JSONResponse([
                'success' => false,
                'requiresConfirmation' => true,
                'deleteCount' => $deleteCount,
                'updateCount' => $updateCount,
                'error' => 'Batch contains ' . implode(' and ', $parts) . ' statement(s) and was not executed. Resend with confirm=true to proceed.'
            ]);
        }

        // The batch runs inside a single transaction: if one of the queries
        // fails (e.g. a DELETE partway through a series due to an FK), all
        // changes already made within this same batch are rolled back instead
        // of staying partially applied. SET (without LOCAL) isn't
        // transactional in PostgreSQL, so the rollback doesn't affect
        // current_setting().
        //
        // About DDL: in PostgreSQL (our database) DDL — CREATE/ALTER/DROP
        // TABLE etc. — is fully transactional and rolls back together with
        // the rest of the batch's changes, so there's no problem here for
        // Postgres. But Nextcloud also works with MySQL/MariaDB through the
        // same IDBConnection, and there DDL causes an implicit COMMIT — if
        // this batch runs on a MySQL instance, commit()/rollBack() after such
        // a DDL statement will get a "no active transaction" exception. We
        // wrap begin/commit/rollBack in try/catch so this doesn't turn into
        // an unhandled exception and an HTTP 500 instead of a clean JSON
        // response.
        $results = [];
        $rolledBack = false;
        $rollbackFailed = false;
        $transactionStarted = false;

        try {
            $this->db->beginTransaction();
            $transactionStarted = true;
        } catch (\Exception $e) {
            $this->logger->warning('[occweb] beginTransaction() failed, proceeding without an explicit transaction: {error}', [
                'app' => 'occweb',
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($queries as $query) {
            $normalized = $this->stripLeadingComments($query);
            $isSelect = stripos($normalized, 'SELECT') === 0;
            $isSet = stripos($normalized, 'SET ') === 0;
            $isDelete = stripos($normalized, 'DELETE') === 0;
            $isUpdate = stripos($normalized, 'UPDATE') === 0;

            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute();

                if ($isSelect) {
                    // Read row by row and stop at MAX_ROWS, instead of
                    // fetchAll() + array_slice — otherwise a SELECT without
                    // LIMIT on a huge table would still pull everything into
                    // PHP memory.
                    $rows = [];
                    $truncated = false;
                    while (($row = $stmt->fetch()) !== false) {
                        if (count($rows) >= self::MAX_ROWS) {
                            $truncated = true;
                            break;
                        }
                        $rows[] = $row;
                    }
                    $results[] = [
                        'query' => $query,
                        'type' => 'select',
                        'count' => count($rows),
                        'truncated' => $truncated,
                        'data' => $rows
                    ];
                } else {
                    $affected = $stmt->rowCount();
                    if ($isSet) {
                        $type = 'set';
                    } elseif ($isDelete) {
                        $type = 'delete';
                    } elseif ($isUpdate) {
                        $type = 'update';
                    } else {
                        $type = 'write';
                    }
                    $results[] = [
                        'query' => $query,
                        'type' => $type,
                        'affected_rows' => $affected
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'query' => $query,
                    'type' => 'error',
                    'error' => $e->getMessage()
                ];
                // Roll back the whole batch and stop: don't keep executing
                // the remaining queries (e.g. a series of DELETEs) if one of
                // the preceding steps failed.
                if ($transactionStarted) {
                    try {
                        $this->db->rollBack();
                        $rolledBack = true;
                    } catch (\Exception $rollbackError) {
                        // The transaction was already closed by something
                        // other than us — e.g. an implicit COMMIT from a DDL
                        // statement on MySQL/MariaDB. Anything that executed
                        // BEFORE this point in the batch may remain
                        // permanently applied — we deliberately do NOT set
                        // rolledBack to true, since that would be untrue.
                        $rollbackFailed = true;
                        $this->logger->warning('[occweb] rollBack() failed after an error — earlier statements in this batch may already be permanently applied: {error}', [
                            'app' => 'occweb',
                            'error' => $rollbackError->getMessage(),
                        ]);
                    }
                }
                break;
            }
        }

        if (!$rolledBack && !$rollbackFailed && $transactionStarted) {
            try {
                $this->db->commit();
            } catch (\Exception $e) {
                // The transaction was already committed implicitly (e.g. by
                // a DDL statement on MySQL/MariaDB) — the effects are
                // already saved, this isn't an error in executing the batch
                // itself.
                $this->logger->info('[occweb] commit() had nothing to commit (likely auto-committed by a DDL statement): {error}', [
                    'app' => 'occweb',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $response = [
            'success' => true,
            'rolledBack' => $rolledBack,
            'results' => $results
        ];

        if ($rollbackFailed) {
            $response['rollbackFailed'] = true;
            $response['warning'] = 'The batch failed partway through and the rollback itself failed (this can happen if an earlier '
                . 'statement, e.g. a DDL statement, implicitly committed on this database backend). Statements executed before the '
                . 'failure may have been permanently applied — check the results above and verify manually.';
        }

        return new JSONResponse($response);
    }
}
