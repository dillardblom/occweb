<?php

namespace OCA\OCCWeb\Controller;

use OC;
use OCA\OCCWeb\Service\SqlGuard;
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

    public function query()
    {
        // Deliberate defense-in-depth (see the equivalent check in
        // OccController::requireAdmin() for why it's kept even though
        // Nextcloud's SecurityMiddleware already enforces this by default
        // absent @NoAdminRequired) - this one is also the direct fix for
        // this controller having shipped with @PublicPage originally,
        // which did bypass that default protection entirely.
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
        $this->logger->warning('[extended_occweb] SQL submitted by {user}: {sql}', [
            'app' => 'extended_occweb',
            'user' => $user->getUID(),
            'sql' => $sql,
        ]);

        // Split the batch on ";" respecting quotes (see splitStatements).
        // All queries run sequentially on the same DB connection (within a
        // single HTTP request), so SET retains its value for
        // current_setting() in the following queries of the same batch.
        $queries = SqlGuard::splitStatements($sql);

        // Access to the server's filesystem / running programs via SQL is
        // blocked entirely — this isn't about data loss (like DELETE), it's
        // about potential server takeover, and confirmation doesn't bypass it.
        foreach ($queries as $query) {
            $forbidden = SqlGuard::findForbiddenConstruct($query);
            if ($forbidden !== null) {
                $this->logger->error('[extended_occweb] Blocked forbidden construct ({construct}) from {user}: {sql}', [
                    'app' => 'extended_occweb',
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

        // Anything that isn't a plain read (DELETE, UPDATE, INSERT, DROP,
        // a data-modifying CTE, ...) needs explicit confirmation from the
        // client (confirm=true) before a single query of the batch runs.
        $writeTypes = [];
        foreach ($queries as $query) {
            if (!SqlGuard::isReadOnly($query)) {
                $type = SqlGuard::statementType($query) ?: 'UNKNOWN';
                $writeTypes[$type] = ($writeTypes[$type] ?? 0) + 1;
            }
        }

        if ($writeTypes !== [] && !$confirmed) {
            $parts = [];
            foreach ($writeTypes as $type => $count) {
                $parts[] = "{$count} {$type}";
            }
            return new JSONResponse([
                'success' => false,
                'requiresConfirmation' => true,
                'deleteCount' => $writeTypes['DELETE'] ?? 0,
                'updateCount' => $writeTypes['UPDATE'] ?? 0,
                'writeCount' => array_sum($writeTypes),
                'error' => 'Batch contains statement(s) that change data or the schema (' . implode(', ', $parts) . ') and was not executed. Resend with confirm=true to proceed.'
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
            $this->logger->warning('[extended_occweb] beginTransaction() failed, proceeding without an explicit transaction: {error}', [
                'app' => 'extended_occweb',
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($queries as $query) {
            $statementType = SqlGuard::statementType($query);
            $isSet = $statementType === 'SET';
            $isDelete = $statementType === 'DELETE';
            $isUpdate = $statementType === 'UPDATE';

            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute();

                if ($statementType === 'SELECT' || ($statementType === 'WITH' && SqlGuard::isReadOnly($query))) {
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
                        $this->logger->warning('[extended_occweb] rollBack() failed after an error — earlier statements in this batch may already be permanently applied: {error}', [
                            'app' => 'extended_occweb',
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
                $this->logger->info('[extended_occweb] commit() had nothing to commit (likely auto-committed by a DDL statement): {error}', [
                    'app' => 'extended_occweb',
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
