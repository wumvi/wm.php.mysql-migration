<?php
declare(strict_types=1);

const MIGRATION_INDEX = 0;
const MIGRATION_FILE = 1;

unset($argv[0]);
parse_str(implode('&', $argv), $params);
$fileConfig = $params['config'] ?? 'config/migration.ini';
$section = $params['section'] ?? 'dev';

if (!is_readable($fileConfig)) {
    echo 'config file "' . $fileConfig . '" not found', PHP_EOL;
    exit(3);
}

$data = pathinfo($fileConfig);
$ext = $data['extension'] ?? '';
switch ($ext) {
    case 'ini':
        $ini = parse_ini_file($fileConfig, true);
        break;
    case 'php':
        $ini = include($fileConfig);
        break;
    default:
        echo 'Unsupported file config ' . $fileConfig, PHP_EOL;
        exit(1);
}

if (!array_key_exists($section, $ini)) {
    echo 'Section ' . $section . ' not found', PHP_EOL;
    exit(2);
}

$config = $ini[$section];
$host = $config['host'];
$user = $config['user'];
$password = $config['pwd'];
$dbName = $config['db'];
$port = (int)$config['port'];
$migrationTable = $config['table'];
$migrationDir = $config['dir'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = @new \mysqli($host, $user, $password, $dbName, $port);
} catch (\mysqli_sql_exception $ex) {
    echo 'Error to connection to mysql: ' . $ex->getMessage(), PHP_EOL;
    $msg = sprintf(
        '%s@%s:%s',
        $user,
        $host,
        $port,
    );
    echo $msg, PHP_EOL;
    exit(2);
} catch (\Throwable $ex) {
    echo 'Unknown error: ' . $ex->getMessage(), PHP_EOL;
    exit(2);
}

// Check migration table
$sql = "SELECT EXISTS (
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA LIKE '$dbName' AND TABLE_TYPE LIKE 'BASE TABLE' AND TABLE_NAME = '$migrationTable'
) as result;";
$result = query($mysql, $sql);
$result = $result->fetch_assoc();
checkResult($result, $sql);

// Creat table if no exists
if ($result['result'] !== '1') {
    $sql = "CREATE TABLE $dbName.$migrationTable (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `index` int unsigned NOT NULL,
          `filename` varchar(255) NOT NULL,
          `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `migration_log_pk` (`filename`)
        ) ENGINE=InnoDB
    ";
    query($mysql, $sql);
}
// lock table for write
$sql = "SELECT * FROM `$migrationTable` FOR UPDATE";
query($mysql, $sql);

$sql = "SELECT max(`index`) max FROM `$migrationTable`";
$result = query($mysql, $sql)->fetch_assoc();
checkResult($result, $sql);
$maxMigration = (int)($result['max'] ?: -1);
query($mysql, 'COMMIT');
echo 'Current migration: ', $maxMigration, PHP_EOL;

$files = scandir($migrationDir);
$migrationData = [];
foreach ($files as $file) {
    if (!is_file($migrationDir . $file)) {
        continue;
    }
    $flag = preg_match('/^(?<index>\d+)(-\w+)?\.sql$/', $file, $match);
    if (!$flag) {
        echo 'Strange file ' . $file, PHP_EOL;
        continue;
    }
    $migrationData[] = [(int)$match['index'], $file];
}

usort($migrationData, function ($a, $b) {
    $ai = $a[MIGRATION_INDEX];
    $bi = $b[MIGRATION_INDEX];

    return $ai === $bi ? 0 : (($ai < $bi) ? -1 : 1);
});

$migrationData = array_filter($migrationData, function ($item) use ($maxMigration) {
    return $maxMigration < $item[MIGRATION_INDEX];
});

if (count($migrationData) === 0) {
    echo 'Up to date', PHP_EOL;
    exit;
}

foreach ($migrationData as $data) {
    echo 'Found file: ' . $data[MIGRATION_FILE] . PHP_EOL;
}

foreach ($migrationData as $data) {
    list($index, $file) = $data;
    echo '----------------------', PHP_EOL;
    echo 'Start ' . $file, PHP_EOL;

    $stmt = $mysql->prepare("INSERT INTO `$migrationTable` (`index`, filename) values(?, ?)");
    $stmt->bind_param("is", $index, $file);
    $result = $stmt->execute();
    $rowId = $stmt->insert_id;

    $sql = file_get_contents($migrationDir . $file);
    if (empty($sql)) {
        deleteMigrationRow($mysql, $migrationTable, $rowId);
        echo 'Error read from file ' . $file . ' or file is empty', PHP_EOL;
        exit(3);
    }
    try {
        $result = $mysql->multi_query($sql);
        do {
            $result = $mysql->use_result();
            if (!is_bool($result)) {
                $result->free_result();
            }
        } while ($mysql->next_result());
    } catch (\mysqli_sql_exception $ex) {
        deleteMigrationRow($mysql, $migrationTable, $rowId);
        echo 'Error to exec file: ' . $file, PHP_EOL;
        echo 'msg: ' . $ex->getMessage(), PHP_EOL;
        exit(4);
    }

    echo 'End ' . $file, PHP_EOL;
}

$mysql->close();


function deleteMigrationRow(\mysqli $mysqli, string $migrationTable, int $id)
{
    query($mysqli, "DELETE FROM `$migrationTable` WHERE ID = $id");
}

function checkResult($result, string $sql)
{
    if (!is_array($result)) {
        echo 'Error to get data from result. Sql: ' . $sql, PHP_EOL;
        exit(4);
    }
}

function query(\mysqli $mysqli, string $sql)
{
    try {
        return $mysqli->query($sql);
    } catch (\mysqli_sql_exception $ex) {
        echo 'Error to exec sql: ' . $sql, PHP_EOL;
        echo 'msg: ' . $ex->getMessage(), PHP_EOL;
        exit(4);
    }
}
