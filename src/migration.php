<?php
declare(strict_types=1);

const MIGRATION_FILE = 1;

unset($argv[0]);
parse_str(implode('&',$argv),$params);
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
$table = $config['table'];
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

// Проверяем существование таблиц
$sql = "SELECT EXISTS (
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA LIKE '$dbName' AND TABLE_TYPE LIKE 'BASE TABLE' AND TABLE_NAME = '$table'
) as result;";
$result = query($mysql, $sql);
$result = $result->fetch_assoc();
checkResult($result, $sql);

if ($result['result'] !== '1') {
    // Создаём таблицу
    $sql = "create table $dbName.$table (
    id       int unsigned auto_increment,
    `index`  int unsigned                         not null,
    filename varchar(255)                         not null,
    date_add datetime default current_timestamp() not null,
    constraint migration_log_pk
        primary key (id)
);";
    query($mysql, $sql);
}

$sql = "LOCK TABLES $table WRITE;";
query($mysql, $sql);

$sql = 'select max(`index`) max from ' . $table;
$result = query($mysql, $sql)->fetch_assoc();
checkResult($result, $sql);
$maxMigration = $result['max'] ?: -1;
echo 'Current migration: ', $maxMigration, PHP_EOL;

$files = scandir($migrationDir, SCANDIR_SORT_ASCENDING);
$migrationData = [];
foreach ($files as $file) {
    if (!is_file($migrationDir . $file)) {
        continue;
    }
    if (!preg_match('/^\d+(-\w+)?\.sql$/', $file)) {
        echo 'Strange file ' . $file, PHP_EOL;
        continue;
    }

    [$fileIndex,] = preg_split('/[.-]/', $file);
    $fileIndex = (int)$fileIndex;

    if ($maxMigration < $fileIndex) {
        $migrationData[] = [$fileIndex, $file];
    }
}
unset($files);
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
    $sql = file_get_contents($migrationDir . $file);
    if (empty($sql)) {
        echo 'Error read from file ' . $file . ' or file is empty', PHP_EOL;
        exit(3);
    }
    try {
        $result = $mysql->multi_query($sql);
        do {
            $result = $mysql->use_result();
            $result->free_result();
        } while ($mysql->next_result());
    } catch (\mysqli_sql_exception $ex) {
        echo 'Error to exec file: ' . $file, PHP_EOL;
        echo 'msg: ' . $ex->getMessage(), PHP_EOL;
        exit(4);
    }

    $stmt = $mysql->prepare('insert into ' . $table . '(`index`, filename) values(?, ?)');
    $stmt->bind_param("is", $index, $file);
    $stmt->execute();
    echo 'End ' . $file, PHP_EOL;
}

query($mysql, "UNLOCK TABLES");
$mysql->close();

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