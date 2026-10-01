<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Database;

use InvalidArgumentException;
use PDO;

final class DatabaseConnection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function driver(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function identifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }
        return $this->driver() === 'pgsql' ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function fetch(string $sql, array $parameters = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    public function insert(string $table, array $data): void
    {
        $columns = array_keys($data);
        $quoted = array_map(fn(string $column): string => $this->identifier($column), $columns);
        $placeholders = array_map(fn(string $column): string => ':insert_' . $column, $columns);
        $parameters = [];
        foreach ($data as $column => $value) {
            $parameters['insert_' . $column] = $value;
        }
        $this->execute(
            'INSERT INTO ' . $this->identifier($table) . ' (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $parameters
        );
    }

    public function update(string $table, array $data, string $where, array $whereParameters = []): int
    {
        $sets = [];
        $parameters = $whereParameters;
        foreach ($data as $column => $value) {
            $parameter = 'set_' . $column;
            $sets[] = $this->identifier((string)$column) . ' = :' . $parameter;
            $parameters[$parameter] = $value;
        }
        return $this->execute(
            'UPDATE ' . $this->identifier($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $parameters
        );
    }

    /** @return string[] */
    public function columns(string $table): array
    {
        if ($this->driver() === 'mysql') {
            return array_map('strval', array_column($this->fetchAll('SHOW COLUMNS FROM ' . $this->identifier($table)), 'Field'));
        }
        if ($this->driver() === 'pgsql') {
            $rows = $this->fetchAll(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table ORDER BY ordinal_position',
                ['table' => $table]
            );
            return array_map('strval', array_column($rows, 'column_name'));
        }
        return array_map('strval', array_column($this->fetchAll('PRAGMA table_info(' . $this->identifier($table) . ')'), 'name'));
    }
}
