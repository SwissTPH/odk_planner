<?php
// src: https://github.com/Kicksend/mailcheck/wiki/String-Distance-Algorithms

if (!defined('MAGIC')) die('!?');

function levenshteinDistance($s, $t) {
    // Determine the Levenshtein distance between s and t
    if (!$s || !$t) {
        return 99;
    }
    $m = strlen($s);
    $n = strlen($t);

    /* For all i and j, d[i][j] holds the Levenshtein distance between
     * the first i characters of s and the first j characters of t.
     * Note that the array has (m+1)x(n+1) values.
     */
    $d = Array();
    for ($i = 0; $i <= $m; $i++) {
        $d[$i] = Array();
        $d[$i][0] = $i;
    }
    for ($j = 0; $j <= $n; $j++) {
        $d[0][$j] = $j;
    }

    // Determine substring distances
    for ($j = 1; $j <= $n; $j++) {
        for ($i = 1; $i <= $m; $i++) {
            // Subtract one to start at strings' index zero instead of index one
            $cost = (substr($s, $i-1, 1) == substr($t, $j-1, 1)) ? 0 : 1;  
            $d[$i][$j] = min($d[$i][$j-1] + 1, // insertion
                min($d[$i-1][$j] + 1, // deletion
                $d[$i-1][$j-1] + $cost)); // substitution                              
        }
    }

    // Return the strings' distance
    return $d[$m][$n];
}

function pre_print_r($x, $return = false) {
    if ($return) {
        return '<pre>' . htmlentities(print_r($x, true)) . '</pre>';
    } else {
        echo '<pre>', htmlentities(print_r($x, true)), '</pre>';
    }
}

### file system {{{1

function assert_permissions($instancedir) {
    foreach(array('forms', 'config', 'log') as $dir) {
        $dir = "$instancedir/$dir";
        $good = is_dir($dir) && is_writable($dir);
        if ($good) {
            $dh = opendir($dir);
            while ($good === true && ($file = readdir($dh)) !== false) {
                $good = substr($file, 0, 1) === '.' || is_writable($dir . '/' . $file) ||
                    ' as well as the file "' . $file;
            }
            closedir($dh);
        }
        if ($good !== true) {
            fatal_error('directory "' . $dir . '" ' . $good . 
                ' must be writeable by the user running this PHP script');
        }
    }
}

function sanitize($x) {
   return preg_replace('/[^a-z0-9_-]/i', '', $x);
}

### html generation {{{1

function targeting($domain) {
    global $config;
    if ($config->get_setting('opentabs', 'no') == 'yes') {
        return " target=\"odk_planner_$domain\" ";
    } else {
        return '';
    }
}

### alerts {{{1

$alerts = array();

# classes : success, info, danger, error
function render_alert($html, $class, $times = 1) {
    if ($class === 'warning') $class = 'danger';
    if ($times > 1) {
        $html .= " ($times times)";
    }
    print "<div class=\"alert alert-$class\"><button type=\"button\" class=\"close\" data-dismiss=\"alert\">&times;</button>$html</div>\n";
}

function render_alerts() {
    global $alerts;
    print "<div class=alerts>";
    foreach($alerts as $alert) {
        render_alert($alert[0], $alert[1], $alert[2]);
    }
    print "</div>\n";
    $alerts = array();
}

function alerts_to_log($logname) {
    global $alerts;
    foreach($alerts as $alert) {
        $msg = strtoupper($alert[1]) . " : " . $alert[0];
        if ($alert[2] > 1) {
            $msg .= " ({$alert[2]} times)";
        }
        log_add($logname, $msg);
    }
}

function render_alerts_delayed() {
    global $alerts;
    if (count($alerts) === 0) {
        return;
    }
    print "<script>\n";
    foreach($alerts as $alert) {
        $html = $alert[0];
        $times = $alert[2];
        if ($times > 1) {
            $html .= " ($times times)";
        }
        $html_j = json_encode($html);
        $class = htmlentities($alert[1]);
        print "$('.alerts').append($('<div class=\"alert alert-$class\">' + $html_j ".
            "+ '<button type=button class=close data-dismiss=alert>&times;</button></div>'));\n";
    }
    print "</script>\n";
}

function alert($html, $class = 'info', $delayed = true, $admin = false) {
    global $alerts, $user;
    if ($admin && !in_array('admin', $user['rights'])) {
        return;
    }
    if ($delayed) {
        $found = false;
        foreach($alerts as $i=>$alert) {
            if ($alert[0] === $html && $alert[1] === $class) {
                $alerts[$i][2]++;
                $found = true;
                break;
            }
        }
        if (!$found) {
            array_push($alerts, array($html, $class, 1));
        }
    } else {
        render_alert($html, $class);
    }
}

function fatal_error($html) {
    echo '<style>.alert-error { width: 60%; left: 20%; background: red; padding: 1rem; color: white; } .alert-error button { display: none; }</style>';
    alert('fatal error : ' . $html, 'error');
    render_alerts();
    die;
}


?>
