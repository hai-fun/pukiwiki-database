<?php
// PukiWiki - Yet another WikiWikiWeb clone.
// database.php
// Copyright 2022 PukiWiki Development Team
// License: GPL v2 or (at your option) any later version
//
// DataBase related function

define("DATA_DB", $database_prefix . "page");
define("DIFF_DB", $database_prefix . "diff");
define("BACKUP_DB", $database_prefix . "backup");

/**
 * Setup database
 */
function db_init()
{
    global $database_dsn, $database_username, $database_password, $database_options, $database_timeout, $database_page_name_max_length;
    try {
        $pdo = new PDO($database_dsn, $database_username, $database_password, $database_options);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, $database_timeout);
        if (!exist_db_table(DATA_DB)) {
            $pdo->exec(
                "CREATE TABLE " . DATA_DB . " (
page_name VARCHAR(" . $database_page_name_max_length . ") PRIMARY KEY,
date DATETIME,
content TEXT
)"
            );
        }

        if (!exist_db_table(DIFF_DB)) {
            $pdo->exec(
                "CREATE TABLE " . DIFF_DB . " (
page_name VARCHAR(" . $database_page_name_max_length . ") PRIMARY KEY,
date DATETIME,
content TEXT
)"
            );
        }

        if (!exist_db_table(BACKUP_DB)) {
            $pdo->exec(
                "CREATE TABLE " . BACKUP_DB . " (
page_name VARCHAR(" . $database_page_name_max_length . ") PRIMARY KEY,
date DATETIME,
content TEXT
)"
            );
        }
    } catch (PDOException $e) {
        die_message("database.php: DataBase is not found or not readable.");
    }
}

/**
 * Get record
 *
 * @param $table table name
 * @param $column Column name to get
 * @param $where Search target column name 
 * @param $target Target column value
 * @return FALSE if error occurerd
 */
function db_read($table, $column, $where, $target)
{
    try {
        global $database_dsn, $database_username, $database_password, $database_options, $database_timeout;
        $pdo = new PDO($database_dsn, $database_username, $database_password, $database_options);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, $database_timeout);
        $stmt = $pdo->prepare("SELECT " . $column . " FROM " . $table . " WHERE " . $where . "=?");
        $stmt->execute(array($target));
        $r = $stmt->fetch();
        return $r;
    } catch (PDOException $e) {
        return FALSE;
    }
}

/**
 * Set record
 *
 * @param $table table name
 * @param $column Column name to set
 * @param $value Value to write
 * @param $where Search target column name 
 * @param $target Target column value
 * @param $mode h: head / f: foot / w: rewrite
 * @return FALSE if error occurerd
 */
function db_write($table, $column, $value, $where, $target, $mode = 'w')
{
    try {
        global $database_dsn, $database_username, $database_password, $database_options;
        $pdo = new PDO($database_dsn, $database_username, $database_password, $database_options);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!exist_db_record($table, $where, $target)) {
            $stmt = $pdo->prepare("INSERT INTO " . $table . " (" . $where . ", " . $column . ") VALUES(?, ?)");
            $stmt->execute(array($target, $value));
            $r = $stmt->fetch();
            return $r;
        }
        $stmt = $pdo->prepare("UPDATE " . $table . " SET " . $column . "=? WHERE " . $where . "=?");
        if ($mode === 'w') {
            $stmt->execute(array($value, $target));
        } else
        if ($mode === 'h') {
            $record = db_read($table, $column, $where, $target);
            $stmt->execute(array($value . $record[$column], $target));
        } else
        if ($mode === 'f') {
            $record = db_read($table, $column, $where, $target);
            $stmt->execute(array($record[$column] . $value, $target));
        } else {
            die_message("database.php: the mode is wrong");
        }
        $r = $stmt->fetch();
        return $r;
    } catch (PDOException $e) {
        return FALSE;
    }
}

// rename column
function db_rename($table, $old, $new) {
    global $database_dsn, $database_username, $database_password, $database_options;
    try {
        $pdo = new PDO($database_dsn, $database_username, $database_password, $database_options);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare(
            "UPDATE " . $table . " SET 
page_name = ?
WHERE page_name = ?
"
        );
        $stmt->execute(array($new, $old));
    } catch (Expection $e) {
        die_message('database.php: Error occurred');
    }
}

// delete record
function db_delete($table, $page)
{
    try {
        global $database_dsn, $database_username, $database_password, $database_options;
        $pdo = new PDO($database_dsn, $database_username, $database_password, $database_options);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("DELETE FROM " . $table . " WHERE page_name=?");
        $stmt->execute(array($page));
        return true;
    } catch (PDOException $e) {
        return FALSE;
    }
}

/**
 * Write to database
 */
function db_page_write($table, $page, $str, $notimestamp = FALSE, $is_delete = FALSE)
{
    global $database_dsn, $database_username, $database_password, $database_options;
    global $whatsdeleted, $maxshow_deleted, $notify, $notify_diff_only, $notify_subject;

    // Delete
    if ($table == DATA_DB && $is_delete) {
        // check
        if (!exist_db_page($table, $page)) {
            return;
        }

        add_recent($page, $whatsdeleted, '', $maxshow_deleted);

        db_delete($table, $page);
        lastmodified_add($whatsdeleted, $page);

        // Clear is_page() cache
        is_page($page, TRUE);

        return;
    } else if ($table == DIFF_DB && $str === " \n") {
        return;
    }

    // check
    $file_exists = false;
    if (exist_db_page($table, $page)) {
        $file_exists = true;
    }

    $timestamp = ($file_exists && $notimestamp) ? get_db_recordtime($page) : false;

    // Update
    try {
        $pdo = new PDO($database_dsn, $database_username, $database_password, $database_options);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (!$file_exists) {
            // create
            $date = date("y-m-d H:i:s");
            $put_stmt = $pdo->prepare("INSERT INTO " . $table . " (page_name, date, content) VALUES(?, ?, ?)");
            $put_stmt->execute(array($page, $date, $str));
        } else {
            $date = $notimestamp ? NULL : date("y-m-d H:i:s");
            $put_stmt = $pdo->prepare(
                "UPDATE " . $table . " SET 
" . ($notimestamp ? "" : "date = ?,") . "
content = ?
WHERE page_name = ?
"
            );
            if ($notimestamp) {
                $put_stmt->execute(array($str, $page));
            } else {
                $put_stmt->execute(array($date, $str, $page));
            }
        }
    } catch (Expection $e) {
        die_message('database.php: Error occurred');
    }

    // Optional actions
    if ($table == DATA_DB) {
        if ($timestamp === false) {
            lastmodified_add($page);
        }

        // Command execution per update
        if (defined('PKWK_UPDATE_EXEC') && PKWK_UPDATE_EXEC)
            system(PKWK_UPDATE_EXEC . ' > /dev/null &');
    } else if ($table == DIFF_DB && $notify) {
        if ($notify_diff_only) $str = preg_replace('/^[^-+].*\n/m', '', $str);
        $footer['ACTION'] = 'Page update';
        $footer['PAGE']   = $page;
        $footer['URI']    = get_page_uri($page, PKWK_URI_ABSOLUTE);
        $footer['USER_AGENT']  = TRUE;
        $footer['REMOTE_ADDR'] = TRUE;
        pkwk_mail_notify($notify_subject, $str, $footer) or
            die_message('pkwk_mail_notify(): Failed');
    }
    if ($table == DIFF_DB) {
        pkwk_log_updates($page, $str);
    }

    // Clear is_page() cache
    is_page($page, TRUE);
}

function get_db_recordtime($page, $table = DATA_DB)
{
    if (exist_db_page($table, $page)) {
        return db_recordmtime($page, $table) - LOCALZONE;
    }
    return 0;
}

function db_recordmtime($page, $table = DATA_DB)
{
    if (exist_db_page($table, $page)) {
        $date = db_read($table, "date", "page_name", $page)['date'];
        return strtotime($date);
    }
    return 0;
}

function exist_db_table($name)
{
    global $database_dsn, $database_username, $database_password, $database_options;
    $pdo = new PDO($database_dsn, $database_username, $database_password, $database_options);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try {
        $pdo->query("SELECT 1 FROM " . $name . " LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function exist_db_page($table, $page)
{
    return exist_db_record($table, "page_name", $page);
}

function exist_db_record($table, $column, $value)
{
    if (db_read($table, $column, $column, $value) === false) {
        return false;
    }
    return true;
}

// Output like backup gz
function db_backup_output($table = BACKUP_DB, $dir = CACHE_DIR . "db/", $ext = BACKUP_EXT) {
    if (!file_exists($dir)) mkdir($dir);
    if (!file_exists($dir . db2dir($table, false))) mkdir($dir . db2dir($table, false));
    $pages = create_db_existpages_list($table);
    foreach ($pages as $dummy => $page) {
        $r = db_read($table, "content, date", "page_name", $page);
        $path = $dir . db2dir($table, false) . encode($page) . $ext;
        $fp = gzopen($path, 'wb')
            or die_message('Cannot open ' . htmlsc($path . encode($page) . BACKUP_EXT) .
                '<br />Maybe permission is not writable or filename is too long');
        _backup_fputs($fp, $r['content']);
        _backup_fclose($fp);
    }
}

// Output like wiki data
function db_output($table = DATA_DB, $dir = CACHE_DIR . "db/", $ext = ".txt") {
    if (!file_exists($dir)) mkdir($dir);
    if (!file_exists($dir . db2dir($table, false))) mkdir($dir . db2dir($table, false));
    $pages = create_db_existpages_list($table);
    foreach ($pages as $dummy => $page) {
        $r = db_read($table, "content, date", "page_name", $page);
        $path = $dir . db2dir($table, false) . encode($page) . $ext;
        file_put_contents($path, $r['content']);
        touch($path, strtotime($r['date']));
    }
}

// Create exist page list of data base
function create_db_existpages_list($table = DATA_DB)
{
    global $database_dsn, $database_username, $database_password, $database_options;

    if ($table == false)
        return array();

    $aryret = array();
    $pdo = new PDO($database_dsn, $database_username, $database_password, $database_options);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT page_name FROM " . $table);
    $stmt->execute();
    $r = $stmt->fetchAll();

    // データベースから受け取った配列を整理
    $aryret = array();
    foreach ($r as $val) {
        $name = $val['page_name'];
        $aryret["db_" . $name] = $name;
    }

    return $aryret;
}

function db_record_count($table, $column_name = "*")
{
    global $database_dsn, $database_username, $database_password, $database_options;
    $pdo = new PDO($database_dsn, $database_username, $database_password, $database_options);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT COUNT(" . $column_name . ") FROM " . $table);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function dir2db($dir)
{
    switch ($dir) {
        case DATA_DIR:
            return DATA_DB;
        case DIFF_DIR:
            return DIFF_DB;
        case BACKUP_DIR:
            return BACKUP_DB;
        case 'wiki/':
            return DATA_DB;
        case 'diff/':
            return DIFF_DB;
        case 'backup/':
            return BACKUP_DB;
    }
    return false;
}

function db2dir($db, $path = true)
{
    switch ($db) {
        case DATA_DB:
            return $path ? DATA_DIR : "wiki/";
        case DIFF_DB:
            return $path ? DIFF_DIR : "diff/";
        case BACKUP_DB:
            return $path ? BACKUP_DIR : "backup/";
    }
    return false;
}