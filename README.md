# README

This repository provides two php scrips to combine apache log files (NSCA Combined Log Format) which are stored in a separate folder per domain into a SQLite database and export all logs ordered by timestamp in the (NSCA Combined Log Format with Virtual Host).

The export tool has multiple options to filter the log data and can directly feed the output to [GoAccess](https://goaccess.io) to provide a HTML based visual report.

## Usage

1. merge all logs (database is created in the current working directory)

   ```sh
   php merge_logs.php --logdir="/var/logs/http"
   ```

2. create a report

   ```sh
   php export_logs.php --report
   ```

3. view the report in a browser

open the file `report.html` in a web browser

**Tipp:** call each tool with `--help` to get all posible options

**manual report creation based on the exported logfile:**

```sh
goaccess access_log.log --log-format=VCOMBINED -a -o report.html
```

## Requirements

- php 7.x (with SQLite)
- [Download GoAccess](https://goaccess.io/download) - open source real-time web log analyzer and interactive viewer that runs in a terminal in \*nix systems or through your browser.
