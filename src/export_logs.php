<?php

function sqliteDateRangeFilter($fromDate, $toDate, $db)
{
    $fromDateSqlite = DateTime::createFromFormat('Y-m-d H:i:s', $fromDate, new DateTimeZone("Europe/Berlin"))->format('Y-m-d H:i:s');
    $toDateSqlite = DateTime::createFromFormat('Y-m-d H:i:s', $toDate, new DateTimeZone("Europe/Berlin"))->format('Y-m-d H:i:s');
    return ' (date BETWEEN ' . $db->quote($fromDateSqlite) . ' AND ' . $db->quote($toDateSqlite) . ') ';
}

function sqliteVhostFilter($vhosts, $db)
{
    $vhostArray = explode(',', $vhosts);
    $vhostArray = array_map(function ($vhost) use ($db) {
        return $db->quote(trim($vhost));
    }, $vhostArray);
    return 'vhost IN (' . implode(',', $vhostArray) . ')';
}

function sqliteCustomConditionFilter($condition)
{
    return $condition;
}

function exportLogs($dbPath, $fromDate, $toDate, $vhosts, $customCondition)
{
    $db = new PDO('sqlite:' . $dbPath);
    $query = 'SELECT ip, ident, authuser, date, request, status, bytes, referer, useragent, vhost FROM logs ';
    $conditions = [];

    if ($fromDate && $toDate) {
        $conditions[] = sqliteDateRangeFilter($fromDate, $toDate, $db);
    }

    if ($vhosts) {
        $conditions[] = sqliteVhostFilter($vhosts, $db);
    }

    if ($customCondition) {
        $conditions[] = sqliteCustomConditionFilter($customCondition);
    }

    if (!empty($conditions)) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $query .= ' ORDER BY date ASC';

    echo $query . "\n";

    return $db->query($query);
}

function createLogEntry($row)
{
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $row['date'], new DateTimeZone("UTC"));
    $date->setTimezone(new DateTimeZone("Europe/Berlin"));
    return sprintf(
        "%s %s %s %s [%s] \"%s\" %s %s \"%s\" \"%s\"\n",
        $row['vhost'] . ':80',
        $row['ip'],
        $row['ident'],
        $row['authuser'],
        $date->format('d/M/Y:H:i:s O'),
        $row['request'],
        $row['status'],
        $row['bytes'],
        $row['referer'],
        $row['useragent']
    );
}

function exportLogsToFile($result, $filename, $gzip)
{
    if ($result) {
        if ($gzip) {
            $file = gzopen($filename, 'w');
        } else {
            $file = fopen($filename, 'w');
        }

        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $logEntry = createLogEntry($row);
            if ($gzip) {
                gzwrite($file, $logEntry);
            } else {
                fwrite($file, $logEntry);
            }
        }

        if ($gzip) {
            gzclose($file);
        } else {
            fclose($file);
        }
    } else {
        echo "No logs found.";
    }
}

function exportLogsToReport($result, $report_file)
{
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w']   // stderr
    ];

    $process = proc_open('goaccess --log-format=VCOMBINED -a -o ' . escapeshellarg($report_file), $descriptorspec, $pipes);

    if (is_resource($process)) {
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $logEntry = createLogEntry($row);
            fwrite($pipes[0], $logEntry);
        }
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $return_value = proc_close($process);

        if ($return_value != 0) {
            echo "Error generating report: $errors\n";
        } else {
            echo "Report generated successfully.\n";
        }
    } else {
        echo "Failed to open process for goaccess.\n";
    }
}

$options = getopt("", ["db::", "fromDate::", "toDate::", "output_logfile::", "gzip::", "vhosts::", "where::", "report::", "help"]);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "php export_logs.php --db=<database_file> --fromDate=<start_date> --toDate=<end_date> --output_logfile=<logfile> [--gzip] [--vhosts=<vhost1,vhost2,...>] [--where=<custom_condition>] [--report]\n\n";
    echo "Parameters:\n";
    echo "--db (optional): The path to the SQLite database file containing the logs. Defaults to 'logs.db'.\n";
    echo "--fromDate (optional): The start date for the log export in 'YYYY-MM-DD HH:MM:SS' format. Must be used together with --toDate.\n";
    echo "--toDate (optional): The end date for the log export in 'YYYY-MM-DD HH:MM:SS' format. Must be used together with --fromDate.\n";
    echo "--output_logfile (optional): The path to the output logfile. Defaults to 'access_log.log'.\n";
    echo "--gzip (optional): If specified, the output logfile will be gzipped.\n";
    echo "--vhosts (optional): A comma-separated list of vhosts (folder names of the logs archives) to filter the logs by.\n";
    echo "--where (optional): A custom SQL condition to filter the logs by.\n";
    echo "--report (optional): If specified, the output will be piped to goaccess (needs to be installed on the system) to generate a report. Defaults to 'report.html'\n";
    echo "--help: Displays this help message.\n\n";
    echo "Notes:\n";
    echo "- If the specified output logfile already exists, the script will terminate with an error message.\n";
    echo "- The directory containing the output logfile must be writable.\n";
    echo "- If either --fromDate or --toDate is provided, both must be specified.\n";
    exit;
}

$output_logfile = $options['output_logfile'] ?? 'access_log.log';
$gzip = isset($options['gzip']);
$vhosts = $options['vhosts'] ?? null;
$customCondition = $options['where'] ?? null;
$report_file = empty(trim($options['report'])) ? 'report.html' : $options['report'];
$report = isset($options['report']) ?? false;

if ($gzip && substr($output_logfile, -3) !== '.gz') {
    $output_logfile .= '.gz';
}

if (!$report) {
    if (is_file($output_logfile)) {
        die("The output_logfile '" . $output_logfile . "' exists! Remove the file.\n");
    }
    if (!is_writable(dirname($output_logfile))) {
        die("The output_logfile '" . $output_logfile . "' is not writable!\n");
    }
} else {
    if (is_file($report_file)) {
        die("The report file '" . $report_file . "' exists! Remove the file.\n");
    }
    if (!is_writable(dirname($report_file))) {
        die("The report file '" . $report_file . "' is not writable!\n");
    }
}

$logDatabase = $options['db'] ?? 'logs.db';
$fromDate = $options['fromDate'] ?? null;
$toDate = $options['toDate'] ?? null;

if (($fromDate && !$toDate) || (!$fromDate && $toDate)) {
    die("Both --fromDate and --toDate parameters are required if one is provided.\n");
}

$result = exportLogs($logDatabase, $fromDate, $toDate, $vhosts, $customCondition);

if ($report) {
    exportLogsToReport($result, $report_file);
} else {
    exportLogsToFile($result, $output_logfile, $gzip);
}
