<?php
/**
 * Executes the option SQL against an actual isolated SQLite database.
 *
 * @package Tests\Helpers
 * @since 0.5.2
 */

declare(strict_types=1);

namespace Tests\Helpers;

require_once __DIR__ . '/Database_Boundary.php';

/**
 * Database boundary adapter; concurrency and SQL writes remain real.
 *
 * @since 0.5.2
 */
final class Version_Database extends \wpdb
{
    /**
     * The actual isolated options-table name.
     *
     * @since 0.5.2
     */
    public string $options = 'options';
    /**
     * The last controlled database diagnostic.
     *
     * @since 0.5.2
     */
    public string $last_error = '';
    /**
     * The caller-owned WordPress diagnostic policy.
     *
     * @since 0.5.2
     */
    public bool $suppress_errors = false;
    /**
     * The real SQLite connection used by the adapter.
     *
     * @since 0.5.2
     */
    private \PDO $connection;

    /**
     * Opens an isolated database and creates its structural options table.
     *
     * @since 0.5.2
     */
    public function __construct(string $path = ':memory:')
    {
        $this->connection = new \PDO('sqlite:' . $path);
        $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->connection->exec('PRAGMA busy_timeout = 15000');
        $this->connection->exec('CREATE TABLE IF NOT EXISTS options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)');
    }

    /**
     * Quotes placeholders through the actual database boundary.
     *
     * @since 0.5.2
     */
    public function prepare(string $sql, mixed ...$arguments): string
    {
        $quoted = array_map(fn(mixed $value): string => $this->connection->quote((string) $value), $arguments);
        return vsprintf(str_replace('%i', '%s', $sql), $quoted);
    }

    /**
     * Returns the exact previous policy while selecting temporary suppression.
     *
     * @since 0.5.2
     */
    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppress_errors;
        $this->suppress_errors = $suppress;
        return $previous;
    }

    /**
     * Executes real writes and retains errors for silent production handling.
     *
     * @since 0.5.2
     */
    public function query(string $sql): int|false
    {
        $this->last_error = '';
        try {
            return $this->connection->exec(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql));
        } catch (\PDOException $failure) {
            $this->last_error = $failure->getMessage();
            return false;
        }
    }

    /**
     * Reads one value without emitting visitor-facing database diagnostics.
     *
     * @since 0.5.2
     */
    public function get_var(string $sql): mixed
    {
        $this->last_error = '';
        try {
            $value = $this->connection->query($sql)->fetchColumn();
            return $value === false ? null : $value;
        } catch (\PDOException $failure) {
            $this->last_error = $failure->getMessage();
            return null;
        }
    }
}
