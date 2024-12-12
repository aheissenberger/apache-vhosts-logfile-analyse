<?php
function getLogFolders($path)
{
    $folders = [];
    if (is_dir($path)) {
        $dir = new DirectoryIterator($path);
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                $folders[] = $fileinfo->getFilename();
            }
        }
    }
    return $folders;
}

function getLogFiles($path)
{
    $files = [];
    if (is_dir($path)) {
        $dir = new DirectoryIterator($path);
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isFile()) {
                $files[] = $fileinfo->getFilename();
            }
        }
    }
    return $files;
}

function parseLogLine($line)
{
    $pattern = '/^(\S+) (\S+) (\S+) \[(.*?)\] "(.*?)" (\d{3}) (\d+|-) "(.*?)" "(.*?)"$/';
    if (preg_match($pattern, $line, $matches)) {
        $date = DateTime::createFromFormat('d/M/Y:H:i:s O', $matches[4]);
        $date->setTimezone(new DateTimeZone("UTC"));
        return [
            'ip' => $matches[1],
            'ident' => $matches[2],
            'authuser' => $matches[3],
            'date' => $date ? $date->format('Y-m-d H:i:s') : null,
            'request' => $matches[5],
            'status' => $matches[6],
            'bytes' => $matches[7],
            'referer' => $matches[8],
            'useragent' => $matches[9],
        ];
    }
    return false;
}

function handleLogFile($filePath, $folderName, $db)
{
    $handle = fopen($filePath, 'r');
    if ($handle) {
        $db->beginTransaction();
        while (($line = fgets($handle)) !== false) {
            $logData = parseLogLine($line);
            if ($logData) {
                $logData['vhost'] = $folderName;
                $stmt = $db->prepare("INSERT INTO logs (ip, ident, authuser, date, request, status, bytes, referer, useragent, vhost) VALUES (:ip, :ident, :authuser, :date, :request, :status, :bytes, :referer, :useragent, :vhost)");
                $stmt->execute($logData);
            }
        }
        $db->commit();
        fclose($handle);
    }
}

function processLogFiles($logPath, $logFolders, $logDatabase, $dbreset, $verbose)
{
    $db = new PDO("sqlite:" . $logDatabase);
    if ($dbreset) {
        $db->exec("DROP TABLE IF EXISTS logs");
        $db->exec("VACUUM");
        if ($verbose) {
            echo "Database reseted.\n";
        }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY, ip TEXT, ident TEXT, authuser TEXT, date DATETIME, request TEXT, status INTEGER, bytes INTEGER, referer TEXT, useragent TEXT, vhost TEXT)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_date ON logs (date)");

    foreach ($logFolders as $folder) {
        $folderPath = $logPath . '/' . $folder;
        $logFiles = getLogFiles($folderPath);
        foreach ($logFiles as $file) {
            if (strpos($file, 'access_') === 0) {
                $filePath = $folderPath . '/' . $file;
                if ($verbose) {
                    echo "Processing $filePath\n";
                }
                if (substr($file, -3) === '.gz') {
                    $filePath = 'compress.zlib://' . $filePath;
                }
                handleLogFile($filePath, $folder, $db);
            }
        }
    }
}

$options = getopt("", ["db::", "db-reset", "logdir:", "verbose::", "help"]);

if (isset($options['help'])) {
    echo "Usage: php merge_logs.php --logdir=<log_directory> [--db=<database_file>]\n";
    echo "Options:\n";
    echo "  --logdir              The directory containing the log folders.\n";
    echo "  --db (optional)       The SQLite database file to store the logs (default: logs.db).\n";
    echo "  --db-reset (optional) Reset the SQLite database.\n";
    echo "  --verbose (optional)  Log the processed log files.\n";
    echo "  --help                Display this help message.\n";
    exit(0);
}

if (!isset($options['logdir'])) {
    die("Error: Missing log directory.\n");
}
$logPath = $options['logdir'];
if (str_ends_with($logPath, '/')) {
    $logPath = substr($logPath, 0, -1);
}
if (!is_dir($logPath)) {
    die("Error: Invalid log path.\n");
}

$logDatabase = $options['db'] ?? 'logs.db';
$dbreset = isset($options['db-reset']) ?? false;

$verbose = isset($options['verbose']) ?? false;

$logFolders = getLogFolders($logPath);

processLogFiles($logPath, $logFolders, $logDatabase, $dbreset, $verbose);
