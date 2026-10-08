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

/** Database boundary adapter; concurrency and SQL writes remain real. */
final class Version_Database extends \wpdb
{
    public string $options = 'options';
    public string $last_error = '';
    private \PDO $connection;

    public function __construct(string $path = ':memory:')
    {
        $this->connection = new \PDO('sqlite:' . $path);
        $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->connection->exec('PRAGMA busy_timeout = 15000');
        $this->connection->exec('CREATE TABLE IF NOT EXISTS options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)');
    }

    public function prepare(string $sql, mixed ...$arguments): string
    {
        $quoted = array_map(fn(mixed $value): string => $this->connection->quote((string) $value), $arguments);
        return vsprintf(str_replace('%i', '%s', $sql), $quoted);
    }

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
