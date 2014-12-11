<?php

$t0 = microtime(true);
$profile_ts = array();
$profile_i  = 0;

function profile($label = NULL) {
    global $profile_ts, $profile_i;
    if ($label === NULL)
        $label = $profile_i++;
    array_push($profile_ts, array($label, microtime(true)));
}

$profile_things = array();

function profile_start($thing) {
    global $profile_things;
    if(!array_key_exists($thing, $profile_things))
        $profile_things[$thing] = array('total'=>0, 'count'=>0);
    $profile_things[$thing]['t0'] = microtime(true);
}
function profile_stop($thing) {
    global $profile_things;
    $profile_things[$thing]['count']++;
    $t0 = $profile_things[$thing]['t0'];
    $profile_things[$thing]['total'] += microtime(true) - $t0;
}

function profile_dump() {
    global $profile_ts, $profile_i, $t0, $profile_things;
    $lt = NULL;
    foreach($profile_ts as $name_t) {
        $d = $lt === NULL ? $name_t[1]-$t0 : $name_t[1]-$lt;
        printf('%20s : %7.2f ms', $name_t[0], 1000*($d));
        printf("\n");
        $lt = $name_t[1];
    }
    foreach($profile_things as $name=>$thing) {
        printf("%20s : %7.2f ms (%dx)\n", $name, 1000*$thing['total'], $thing['count']);
    }
}

$tmp_logs = array();
function tmp_log($msg) {
    global $tmp_logs;
    array_push($tmp_logs, $msg);
}

function tmp_dump() {
    global $tmp_logs;
    print implode("\n", $tmp_logs);
}

$mysql_queries = array();
function mysql_query_($sql, $conn = NULL) {
    global $mysql_queries;
    array_push($mysql_queries, $sql);
    if ($conn !== NULL)
        return mysql_query($sql, $conn);
    else
        return mysql_query($sql);
}

?>
