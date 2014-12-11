<?php
if (!defined('MAGIC')) die('!?');

function get_log_names() {
    global $logdir;
    $names = array();
    foreach(glob($logdir . '/*.tsv') as $logpath) {
        $pi = pathinfo($logpath);
        array_push($names, $pi['filename']);
    }
    return $names;
}

function get_log_path($name) {
    global $logdir;
    return $logdir . '/' . $name . '.tsv';
}

function get_log_size($name) {
    $fpath = get_log_path($name);
    if (!is_file($fpath)) return 0;
    return filesize($fpath);
}

function get_log_pos($name) {
    global $logdir;
    # initialize positions to zero for all logs
    $logpos = array();
    foreach(get_log_names() as $log) {
        $logpos[$log] = 0;
    }
    # read from saved positions
    $jsonpath = $logdir . '/positions.json';
    if (file_exists($jsonpath)) {
        $data = json_decode(file_get_contents($jsonpath), TRUE);
        if (array_key_exists($name, $data)) {
            foreach($data[$name] as $log=>$pos) {
                $logpos[$log] = $pos;
            }
        }
    }
    return $logpos;
}

function update_log_pos($name, $logs, $positions = null) {
    global $logdir;
    $jsonpath = $logdir . '/positions.json';
    # read positions from .json if exists
    $data = array();
    if (file_exists($jsonpath)) {
        $data = json_decode(file_get_contents($jsonpath), TRUE);
    }
    if (!array_key_exists($name, $data))
        $data[$name] = array();
    # update position for specified logs
    foreach($logs as $logname) {
        if ($positions && array_key_exists($positions[$logname])) {
            $data[$name][$logname] = $positions[$logname];
        } else {
            # default to end-of-file
            $data[$name][$logname] = get_log_size($logname);
        }
    }
    # rewrite to .json
    $f = fopen($jsonpath, 'w');
    fwrite($f, json_encode($data));
    fclose($f);
}

function get_log_diffs($logpos, $maxdiff = 102400) {
    $ret = array();
    foreach($logpos as $name=>$pos) {
        $filesize = get_log_size($name);
        if ($filesize === 0) {
            $ret[$name] = '';
            continue;
        }
        if ($pos === $filesize)
            continue;
        $size = min($maxdiff, $filesize - $pos);

        $f = fopen(get_log_path($name), 'r');
        fseek($f, $filesize - $size); # positions should be at linebreak
        $ret[$name] = fread($f, $size);
        fclose($f);
    }
    return $ret;
}

# inefficient line based per file log

function log_add($name, $message) {
    global $user;
    $f = fopen(get_log_path($name), 'a');
    $who = '';
    if ($user && array_key_exists('name', $user)) {
        $who .= $user['name'];
    }
    if (array_key_exists('REMOTE_ADDR', $_SERVER)) {
        $who .= ' [' . $_SERVER['REMOTE_ADDR'] . ']';
    }
    $message = str_replace("\t", '    ', $message);
    $message = str_replace("\n", '\\n', $message);
    fwrite($f, implode("\t", array(
        strftime('%Y-%m-%d %H:%M:%S'),#1) time
        $who,                         #2) who (name [ip])
        $message)) . "\n");           #3) message
    fclose($f);
}

/**
 * get entries from the <code>.tsv</code> log file
 *
 * @param string $name what log file to read
 * @param integer $pos position of (beginning of buffer to) beginning of
 *        file if positive or (end of buffer to) end of file (+1) if negative
 * @param integer $size how many bytes to read; 0 means all
 *
 * @return array of <code>[$when, $who, $message]</code>
 */
function log_entries($name, $pos=-1, $size=0) {
    $fname = get_log_path($name);

    if (!is_readable($fname)) {
        # because not written to yet
        return array();
    }

    $fsize = get_log_size($name);
    if ($fsize == 0) {
        return array();
    }
    if (!$size)
        $size = $fsize;
    if ($pos < 0)
        $pos = max(0, $fsize + 1 + $pos - $size);

    $entries = array();
    $f = fopen($fname, 'r');
    fseek($f, $pos);

    $n = 0;
    foreach(explode("\n", fread($f, $size)) as $line) {
        if (!$n++ && $pos)
            continue; # most probably partial line
        if ($line)
            array_unshift($entries, explode("\t", $line));
    }
    fclose($f);

    return $entries;
}

# pagination starts from end
function log_render($name, $href, $size=4096) {

    if (strpos($href, '?') === FALSE) {
        $href .= '?log_pagination=';
    } else {
        $href .= '&log_pagination=';
    }

    # indexed by $name, 0=end
    $positions = array();
    if (array_key_exists('log_pagination', $_REQUEST)) {
        foreach(explode('_', $_REQUEST['log_pagination']) as $name_pos) {
            $name_pos = explode('-', $name_pos);
            $positions[$name_pos[0]] = (int) $name_pos[1];
        }
    }

    # our position
    if (array_key_exists($name, $positions)) {
        $pos = $positions[$name];
    } else {
        $pos = 0;
    }

    # generate pagination array
    $logsize = get_log_size($name);
    $n = (int) ($logsize / $size);
    $paginations = array();
    $paginations['&lt;'] = null;
    for($i = 0; $i < $n; $i++) {
        $positions[$name] = $n - 1 - $i;
        $ps = array();
        foreach($positions as $name=>$p) {
            array_push($ps, "$name-$p");
        }
        $paginations[$i] = $href . rawurlencode(implode('_', $ps));
    }
    if ($n - 1 - $pos > 0) {
        $paginations['&lt;'] = $paginations[$n - 1 - $pos - 1];
    } else {
        unset($paginations['&lt;']);
    }
    if ($n - 1 - $pos < $n - 1) {
        $paginations['&gt;'] = $paginations[$n - 1 - $pos + 1];
    }

    # display pagination / searchbar, then table
    ?>
    <form class="navbar-search pull-right">
        <div class=pagination>
        <ul>
            <?php if (count($paginations) > 2): ?>
            <?php foreach($paginations as $number=>$href): ?>
                <?php if ($number === $n - 1 - $pos): ?>
                <li><a href="<?php echo $href ?>"><u><?php echo $number ?></u></a></li>
                <?php else: ?>
                <li><a href="<?php echo $href ?>"><?php echo $number ?></a></li>
                <?php endif ?>
            <?php endforeach ?>
            <?php endif ?>
        </ul>
        </div>
        <input type="text" data-columns="1,2" class="search-query filter" placeholder="Filter" />
    </form>
    <table class="table log-rendered filtered" data-name="<?php echo $name; ?>">
    <tr><th>when</th><th>who</th><th>what</th></tr>
    <?php foreach(log_entries($name, -1 - $pos * $size, $size) as $line): ?>
    <tr>
        <td><?php echo htmlentities($line[0]); ?></td>
        <td><?php echo htmlentities($line[1]); ?></td>
        <td><?php echo htmlentities($line[2]); ?></td>
    </tr>
    <?php endforeach; ?>
    </table>
<?php
}

