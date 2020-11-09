<?php

namespace Amp\Sql\Common;

use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Sql\ConnectionConfig;
use Amp\Sql\Connector;
use Amp\Sql\FailureException;
use Amp\Sql\Link;
use Amp\Sql\Pool;
use Amp\Sql\Result;
use Amp\Sql\Statement;
use Amp\Sql\Transaction;
use function Amp\async;
use function Amp\await;

abstract class ConnectionPool implements Pool
{
    const DEFAULT_MAX_CONNECTIONS = 100;
    const DEFAULT_IDLE_TIMEOUT = 60;

    private Connector $connector;

    private ConnectionConfig $connectionConfig;

    private int $maxConnections;

    private \SplQueue $idle;

    private \SplObjectStorage $connections;

    /** @var Promise<Link>|null */
    private ?Promise $promise = null;

    private ?Deferred $deferred = null;

    private int $idleTimeout;

    private string $timeoutWatcher;

    private bool $closed = false;

    /**
     * Create a default connector object based on the library of the extending class.
     *
     * @return Connector
     */
    abstract protected function createDefaultConnector(): Connector;

    /**
     * Creates a Statement of the appropriate type using the Statement object returned by the Link object and the
     * given release callable.
     *
     * @param Statement $statement
     * @param callable  $release
     *
     * @return Statement
     */
    abstract protected function createStatement(Statement $statement, callable $release): Statement;

    /**
     * @param Pool     $pool
     * @param string   $sql
     * @param callable $prepare
     *
     * @return StatementPool
     */
    abstract protected function createStatementPool(Pool $pool, string $sql, callable $prepare): StatementPool;

    /**
     * Creates a Transaction of the appropriate type using the Transaction object returned by the Link object and the
     * given release callable.
     *
     * @param Transaction $transaction
     * @param callable    $release
     *
     * @return Transaction
     */
    abstract protected function createTransaction(Transaction $transaction, callable $release): Transaction;

    /**
     * @param ConnectionConfig $config
     * @param int              $maxConnections Maximum number of active connections in the pool.
     * @param int              $idleTimeout    Number of seconds until idle connections are removed from the pool.
     * @param Connector|null   $connector
     */
    public function __construct(
        ConnectionConfig $config,
        int $maxConnections = self::DEFAULT_MAX_CONNECTIONS,
        int $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
        Connector $connector = null
    ) {
        $this->connector = $connector ?? $this->createDefaultConnector();

        $this->connectionConfig = $config;

        $this->idleTimeout = $idleTimeout;
        if ($this->idleTimeout < 1) {
            throw new \Error("The idle timeout must be 1 or greater");
        }

        $this->maxConnections = $maxConnections;
        if ($this->maxConnections < 1) {
            throw new \Error("Pool must contain at least one connection");
        }

        $this->connections = $connections = new \SplObjectStorage;
        $this->idle = $idle = new \SplQueue;

        $idleTimeout = &$this->idleTimeout;

        $this->timeoutWatcher = Loop::repeat(1000, static function () use (&$idleTimeout, $connections, $idle) {
            $now = \time();
            while (!$idle->isEmpty()) {
                $connection = $idle->bottom();
                \assert($connection instanceof Link);

                if ($connection->getLastUsedAt() + $idleTimeout > $now) {
                    return;
                }

                // Close connection and remove it from the pool.
                $idle->shift();
                $connections->detach($connection);
                $connection->close();
            }
        });

        Loop::unreference($this->timeoutWatcher);
    }

    public function __destruct()
    {
        Loop::cancel($this->timeoutWatcher);
    }

    /**
     * Creates a ResultSet of the appropriate type using the ResultSet object returned by the Link object and the
     * given release callable.
     *
     * @param Result   $result
     * @param callable $release
     *
     * @return Result
     */
    protected function createResult(Result $result, callable $release): Result
    {
        return new PooledResult($result, $release);
    }

    public function getIdleTimeout(): int
    {
        return $this->idleTimeout;
    }

    public function getLastUsedAt(): int
    {
        // Simple implementation... can be improved if needed.

        $time = 0;

        foreach ($this->connections as $connection) {
            \assert($connection instanceof Link);
            if (($lastUsedAt = $connection->getLastUsedAt()) > $time) {
                $time = $lastUsedAt;
            }
        }

        return $time;
    }

    /**
     * @return bool
     */
    public function isAlive(): bool
    {
        return !$this->closed;
    }

    /**
     * Close all connections in the pool. No further queries may be made after a pool is closed.
     */
    public function close(): void
    {
        $this->closed = true;
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->idle = new \SplQueue;

        if ($this->deferred instanceof Deferred) {
            $deferred = $this->deferred;
            $this->deferred = null;
            $deferred->fail(new FailureException("Connection pool closed"));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function extractConnection(): Link
    {
        $connection = $this->pop();
        $this->connections->detach($connection);
        return $connection;
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionCount(): int
    {
        return $this->connections->count();
    }

    /**
     * {@inheritdoc}
     */
    public function getIdleConnectionCount(): int
    {
        return $this->idle->count();
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionLimit(): int
    {
        return $this->maxConnections;
    }

    /**
     * @throws FailureException If creating a new connection fails.
     * @throws \Error If the pool has been closed.
     */
    protected function pop(): Link
    {
        if ($this->closed) {
            throw new \Error("The pool has been closed");
        }

        while ($this->promise !== null) {
            await($this->promise); // Prevent simultaneous connection creation or waiting.
        }

        do {
            // While loop to ensure an idle connection is available after promises below are resolved.
            while ($this->idle->isEmpty()) {
                if ($this->connections->count() < $this->getConnectionLimit()) {
                    // Max connection count has not been reached, so open another connection.
                    try {
                        $connection = await(
                            $this->promise = async(fn() => $this->connector->connect($this->connectionConfig))
                        );
                        /** @psalm-suppress DocblockTypeContradiction */
                        if (!$connection instanceof Link) {
                            throw new \Error(\sprintf(
                                "%s::connect() must resolve to an instance of %s",
                                \get_class($this->connector),
                                Link::class
                            ));
                        }
                    } finally {
                        $this->promise = null;
                    }

                    $this->connections->attach($connection);
                    return $connection;
                }

                // All possible connections busy, so wait until one becomes available.
                try {
                    $this->deferred = new Deferred;
                    // Connection will be pulled from $this->idle when promise is resolved.
                    await($this->promise = $this->deferred->promise());
                } finally {
                    $this->deferred = null;
                    $this->promise = null;
                }
            }

            $connection = $this->idle->pop();
            \assert($connection instanceof Link);

            if ($connection->isAlive()) {
                return $connection;
            }

            $this->connections->detach($connection);
        } while (!$this->closed);

        throw new FailureException("Pool closed before an active connection could be obtained");
    }

    /**
     * @param Link $connection
     *
     * @throws \Error If the connection is not part of this pool.
     */
    protected function push(Link $connection): void
    {
        \assert(isset($this->connections[$connection]), 'Connection is not part of this pool');

        if ($connection->isAlive()) {
            $this->idle->unshift($connection);
        } else {
            $this->connections->detach($connection);
        }

        if ($this->deferred instanceof Deferred) {
            $this->deferred->resolve($connection);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $sql): Result
    {
        $connection = $this->pop();

        try {
            $result = $connection->query($sql);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        return $this->createResult($result, function () use ($connection): void {
            $this->push($connection);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $sql, array $params = []): Result
    {
        $connection = $this->pop();

        try {
            $result = $connection->execute($sql, $params);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        return $this->createResult($result, function () use ($connection): void {
            $this->push($connection);
        });
    }

    /**
     * {@inheritdoc}
     *
     * Prepared statements returned by this method will stay alive as long as the pool remains open.
     */
    public function prepare(string $sql): Statement
    {
        return $this->createStatementPool($this, $sql, \Closure::fromCallable([$this, "prepareStatement"]));
    }

    /**
     * Prepares a new statement on an available connection.
     *
     * @param string $sql
     *
     * @return Statement
     *
     * @throws FailureException
     */
    private function prepareStatement(string $sql): Statement
    {
        $connection = $this->pop();

        try {
            $statement = $connection->prepare($sql);
            \assert($statement instanceof Statement);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        return $this->createStatement($statement, function () use ($connection): void {
            $this->push($connection);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(int $isolation = Transaction::ISOLATION_COMMITTED): Transaction
    {
        $connection = $this->pop();

        try {
            $transaction = $connection->beginTransaction($isolation);
            \assert($transaction instanceof Transaction);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        return $this->createTransaction($transaction, function () use ($connection): void {
            $this->push($connection);
        });
    }
}
