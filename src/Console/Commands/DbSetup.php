<?php

namespace BitWasp\Bitcoin\Node\Console\Commands;


use BitWasp\Bitcoin\Node\Config\ConfigLoader;
use BitWasp\Bitcoin\Node\Db\Db;
use Packaged\Config\ConfigProviderInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbSetup extends AbstractCommand
{
    const PARAM_DB = "dbname";
    const PARAM_SCHEMA = "schema";

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName('db:setup')
            //($name, $mode = null, $description = '', $default = null)
            ->addArgument(self::PARAM_DB, InputArgument::OPTIONAL, 'Database name', 'node')
            ->addOption(self::PARAM_SCHEMA, null, InputOption::VALUE_REQUIRED, 'Provide an SQL schema file to override the default', null)
            ->setDescription('Setup the provided database');
    }

    /**
     * @param InputInterface $input
     */
    protected function loadDbName(InputInterface $input)
    {
        $dbName = $input->getArgument(self::PARAM_DB);
        return $dbName;
    }

    /**
     * @param InputInterface $input
     * @return string
     */
    protected function loadSchemaFile(InputInterface $input)
    {
        $schemaFile = $input->getOption(self::PARAM_SCHEMA);
        if (null === $schemaFile) {
            $schemaFile = __DIR__ . "/../../../sql/schema.sql";
        }

        if (!file_exists($schemaFile)) {
            throw new \RuntimeException("Schema file doesn't exist");
        }

        if (is_dir($schemaFile)) {
            throw new \RuntimeException("Schema file input was a directory, a file is required");
        }

        if (!is_readable($schemaFile)) {
            throw new \RuntimeException("Schema file cannot be read");
        }

        return $schemaFile;
    }

    protected function checkDbExists(\PDO $pdo, $db) {
        // statement to execute
        $query = $pdo->prepare('SELECT COUNT(*) AS `exists` FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMATA.SCHEMA_NAME = :db');
        if ($query->execute(['db' => $db]) === false) {
            throw new \RuntimeException($pdo->errorInfo(), $pdo->errorCode());
        }

        $result = $query->fetchAll(\PDO::FETCH_ASSOC);
        return $result[0]['exists'] == 1;
    }

    protected function createDatabase(\PDO $pdo, $db) {
        // statement to execute
        $result = $pdo->exec("CREATE DATABASE `$db`;");
        return (bool) $result;
    }

    protected function createSchema(\PDO $pdo, $dbName, $schemaFile) {
        $schema = file_get_contents($schemaFile);
        $commands = explode("\n", $schema);
        $lines = [];
        foreach ($commands as $command) {
            if (substr($command, 0, 2) == "--") {
                continue;
            }
            if (substr($command, 0, 3) == "/*!") {
                continue;
            }
            if (substr($command, 0, 4) == "SET ") {
                continue;
            }
            $lines[] = $command;
        }
        $commands = explode(";", implode("\n", $lines));

        $pdo->exec("USE $dbName");
        $pdo->beginTransaction();

        try {
            foreach ($commands as $command) {
                $pdo->exec($command . ";");
            }
            $pdo->commit();
        } catch (\RuntimeException $e) {

            echo "rollback";
            echo $e->getMessage().PHP_EOL;
            $pdo->rollBack();
            throw $e;
        }
    }

    protected function mkDb(ConfigProviderInterface $config, $withDb = false) {
        $driver = $config->getItem('db', 'driver');
        $host = $config->getItem('db', 'host');
        $username = $config->getItem('db', 'username');
        $password = $config->getItem('db', 'password');

        $dsn = "$driver:host=$host";
        if ($withDb) {
            $dsn .= ";dbname=$withDb";
        }

        return new \PDO($dsn, $username, $password);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dbName = $this->loadDbName($input);
        $schemaFile = $this->loadSchemaFile($input);

        $config = (new ConfigLoader())->load();
        $pdo = $this->mkDB($config, false);

        if ($this->checkDbExists($pdo, $dbName)) {
            throw new \RuntimeException("This database already exists");
        }

        if (!$this->createDatabase($pdo, $dbName)) {
            throw new \RuntimeException("Failed to create database");
        }

        $pdo = $this->mkDB($config, $dbName);

        $this->createSchema($pdo, $dbName, $schemaFile);

        return 0;
    }
}
