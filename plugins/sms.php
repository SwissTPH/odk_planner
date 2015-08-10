<?php # vim: set fdm=marker:
if (!defined('MAGIC')) die('!?');

# add menu item to show where we are only
function sms_augment_views($args) {
    global $self, $user, $show;
    $views =& $args['views'];
    if ($show === 'sms' && in_array('sms', $user['rights'])) array_push($views, 'sms');
}


function sms_dump_headers($args) {
    global $user, $config;
    if (in_array('sms', $user['rights']))
        echo '<script src="plugins/sms.js"></script>';

    if (isset($config->plugins['routo'])) {
        alert('update config.xls : "routo" sheet not used any longer; see help', 'warning');
    }
}


# formats "1.230000" to "1.23"
function sms_format_value($value) {
    if ($value === NULL) {
        return "NULL";
    }
    if (preg_match('/(\d+\.\d*?)0+/', $value, $m)) {
        return $m[1];
    }
    return $value;
}


# fills in (first) value of all {formid\field} specified in $message
function sms_interprete($id, $message) {
    global $forms, $conn;

    # remove leading number (see sms_get_number(...)
    if (preg_match('/\s*\+\d+\s*(.*)/', $message, $m) === 1) {
        $message = $m[1];
    }

    # iterate through all placeholders
    $formids_params = array();
    $tmp = $message;
    while (preg_match('|(.*?)\{([^\\\\}]+)\\\\([^\}]+)\}(.*)|', $tmp, $m) === 1) {
        $before = $m[1];
        $formid = strtoupper($m[2]);
        $field  = strtoupper($m[3]);
        $after  = $m[4];

        $tmp = $after;

        if (!isset($formids_params[$formid])) {
            $formids_params[$formid] = array();
        }
        array_push($formids_params[$formid], $field);
    }

    # query database form by form & fill in data
    $data = array();
    foreach($formids_params as $formid=>$params) {

        $ret = $forms->forms[$formid]->get_rlike($conn, "$id.*", $params, array('sms'));

        if (count($ret)) {
            # found data
            if (count($ret) > 1) {
                alert("found " . count($ret) . " occurrences of form '$formid' " .
                    "for '$id'", 'warning');
            }

            # fill in first
            $uris = array_keys($ret);
            $ret = $ret[$uris[0]];
            $data[$formid] = array();

            foreach($params as $param) {
                if (array_key_exists($param, $ret)) {
                    $data[$formid][$param] = sms_format_value($ret[$param]);
                } else {
                    $data[$formid][$param] = '(masked)';
                }
            }

        } else {
            # data not found
            alert("could not find form '$formid' for '$id'", 'warning');
            $data[$formid] = array();
            foreach($params as $param) {
                $data[$formid][$param] = '?';
            }
        }
    }

    # fill in values at placeholders
    while (preg_match('|(.*?)\{([^\\\\}]+)\\\\([^\}]+)\}(.*)|', $message, $m) === 1) {
        $before = $m[1];
        $formid = strtoupper($m[2]);
        $field  = strtoupper($m[3]);
        $after  = $m[4];

        $message = $before . $data[$formid][$field] . $after;
    }

    return $message;
}


# returns NULL unless $message starts with "+NNN" in which case NNN is returned
function sms_get_number($message) {
    if (preg_match('/\s*\+(\d+)\s*/', $message, $m) === 1) {
        return $m[1];
    }
    return NULL;
}


# actions {{{1

/**
 * @return <code>'success'</code> or error message
 */
function sms_send($number, $text) {
    global $config, $self;

    if ($config->plugins['sms']['mode'] === 'test') {
        //if (rand(1, 5) == 1)
            //return 'failed randomly';
        return 'success';
    }

    $url  = $config->plugins['sms']['url'];
    $opts = $config->plugins['sms']['params'];
    $opts.= '&' . $config->plugins['sms']['param_number']  . '=' . rawurlencode($number);
    $opts.= '&' . $config->plugins['sms']['param_message'] . '=' . rawurlencode($text);

    $req = curl_init();
    curl_setopt($req, CURLOPT_URL, $url);
    curl_setopt($req, CURLOPT_POST, 1);
    curl_setopt($req, CURLOPT_POSTFIELDS, $opts);
    curl_setopt($req, CURLOPT_HEADER, 1);
    curl_setopt($req, CURLOPT_USERAGENT, 'odk_planner');
    curl_setopt($req, CURLOPT_TIMEOUT, 3);
    curl_setopt($req, CURLOPT_RETURNTRANSFER, 1);
    $proxy = @$config->settings['proxy'];
    if ($proxy) {
        curl_setopt($req, CURLOPT_PROXY, $config->settings['proxy']);
    }
    $page = curl_exec($req);
    curl_close($req);

    if (!$page)
        return "ERROR: did not receive answer -- $url unreachable?";

    preg_match("/^HTTP\\/\\d+\\.\\d+\\s+(\\d+).*?\r\n\r\n(.*)/s", $page, $m);
    $status = $m[1];
    $content = $m[2];

    if ($status === '200') {
        $regexp = $config->plugins['sms']['response_regexp'];
        if (preg_match('/' . $regexp . '/', $content)) {
            return 'success';
        } else {
            return $content;
        }
    }

    return "ERROR: status=$status content=$content proxy=$proxy";
}

function sms_die_json($data) {
    header('Content-type: application/json');
    die(json_encode($data));
}

function sms_early_action($args) {
    global $user, $_REQUEST, $_SESSION, $config;

    # send a message using CURL
    # log message & status to 'sms.tsv'
    # additionally log sent message if success to 'mass-sms.tsv' if id_title is set
    if (array_key_exists('sendsms', $_REQUEST) && in_array('sms', $user['rights'])) {
        $number = $message = null;
        if (array_key_exists('number', $_REQUEST))
            $number = $_REQUEST['number'];
        if (array_key_exists('message', $_REQUEST))
            $message = $_REQUEST['message'];

        $test_suffix = $config->plugins['sms']['mode'] === 'test' ?
            ' [test]' : '';


        if (!preg_match('/^\\d+$/', $number))
            sms_die_json(array('status'=>'error', 'error'=>'malformatted number'));
        if (!$message)
            sms_die_json(array('status'=>'error', 'error'=>'empty message'));

        $status = sms_send($number, $message);
        log_add('sms', "$number $message -- $status$test_suffix");

        if ($status === 'success' && array_key_exists('id_title', $_REQUEST)) {
            $id_title = $_REQUEST['id_title'];
            log_add('mass-sms', $id_title);
        }

        sms_die_json(array('status' => $status));
    }
}


# display {{{1

function &sms_templates() {
    global $config;
    $ret = array();
    foreach($config->plugins['sms'] as $key=>$value) {
        if (substr($key, 0, 9) === 'template_') {
            $ret[substr($key, 9)] = $value;
        }
    }
    return $ret;
}

function sms_internationalize($number) {
    global $config;
    $number = preg_replace('/[^0-9]/', '', $number);
    if (substr($number, 0, 2) === '00') {
        $number = substr($number, 2);
    } else if (substr($number, 0, 1) === '0') {
        $number = $config->plugins['sms']['default_country_prefix']
            . substr($number, 1);
    }
    return $number;
}

function sms_numbers($patient_id) {
    global $config, $forms, $conn;

    $ret = array();

    foreach(preg_split('/\s/', $config->plugins['sms']['phone_numbers']) as $formfield) {
        $form_field = explode('\\', $formfield);

        if ($forms->exists($form_field[0])) {
            $form = $forms->get($form_field[0]);
            $column = $form->find_path($form_field[1]);

            $uris = $form->get_uris($conn, $patient_id . '.*');
            for($i = 0; $i < count($uris); $i++) {
                $row = $form->get_values($conn, $uris[0]);
                $number = sms_internationalize($row[$column]);
                array_push($ret, array(
                    "$formfield : +$number", $number));
            }
        }
    }

    return $ret;
}

# generate option with phone numbers for given patient
function sms_dump_tel_select($patient_id) {
    ?>
    <select class=number>
    <?php foreach(sms_numbers($patient_id) as $label_number): ?>
        <option data-number="<?php echo $label_number[1]; ?>">
            <?php echo htmlspecialchars($label_number[0]); ?>
        </option>
    <?php endforeach; ?>
    </select>
    <?php
}

function sms_display_mass($args) {

    global $_REQUEST, $user, $show, $config;

    if ($show !== 'sms' || !in_array('sms', $user['rights'])) return;
    if (!array_key_exists('ids_titels', $_REQUEST)) return;

    if ($config->plugins['sms']['mode'] === 'test')
        alert('will not actually send messages ("test" mode)', 'info');

    $templates = sms_templates();

?>
    <div class=row-fluid>
    <div class="span10 offset1">
    <table class="send-list table table-bordered sms-mass">
    <tr>
        <th>ID</th>
        <th>Phone Number</th>
        <th>message</th>
        <th><input type="checkbox" class="select-all"></th>
        <th><a href=# class="btn btn-primary send-selected">send</a></th>
    </tr>
<?php

    $i = 0;
    foreach(explode(';', $_REQUEST['ids_titels']) as $id_title) {
        $id_title = explode('=', $id_title); # (patientid is escaped in odk_form)
        $message = $templates[$id_title[1]];
        $number = sms_get_number($message);
        $message = sms_interprete($id_title[0], $message);
        echo '<tr>';
        echo '<td class=patient_id>' . htmlentities($id_title[0]) . '</td>';
        echo '<td>';
        if ($number === NULL) {
            sms_dump_tel_select($id_title[0]);
        } else {
            echo "<select class=number><option data-number=\"$number\">+$number</option></select>";
        }
        echo '</td>';
        echo '<td><code class=title>' . htmlentities($id_title[1]) . '</code> <span class=message>' . htmlentities($message) . '</span></td>';
        echo '<td><input type="checkbox"></td>';
        echo '<td><span class=send-status></span></td>';
        echo '</tr>';
        $i++;
    }
    echo '</table></div></div>';
}

function sms_display_single($args) {

    global $_REQUEST, $user, $show, $config;

    if ($show !== 'sms' || !in_array('sms', $user['rights'])) return;
    if (!array_key_exists('id', $_REQUEST)) return;

    if ($config->plugins['sms']['mode'] === 'test')
        alert('will not actually send messages ("test" mode)', 'info');

    $templates = sms_templates();

    $patient_id = $_REQUEST['id']; # (patientid is escaped in odk_form)

    # display sms form
    ?>
    <div class="sms-single">
        <h4>send SMS to <?php echo htmlentities($patient_id); ?></h4>
        <p>
            <label for="number">Phone-Number:</label>
            <?php sms_dump_tel_select($patient_id); ?>
        </p>
        <?php if($templates): ?>
        <p>
            <label>Choose from Templates:</label>
            <select class="template">
                <option>(free text)</option>
            <?php foreach($templates as $title=>$content): ?>
                <option data-content="<?php echo $content; ?>">
                    <?php echo $title; ?>
                </option>
            <?php endforeach; ?>
            </select>
        </p>
        <?php endif ?>
        <p>
            <label>Message:</label>
            <textarea class="message" cols="60" rows="8"></textarea>
        </p>
        <p class="controls">
            please enter phone number &amp; message
        </p>
    </div>
    <?php
}


# overview {{{1

# generate list of messages to be sent based on coloring information &
# log file 'mass-sms'
function sms_overview_list(&$overview) {

    $sent_previously = 0;
    $ids_titles = array();
    $ids_titles_auto = array();

    $entries = log_entries('mass-sms');
    $prev_ids_titles = array_map(function ($x) { return $x[count($x)-1]; }, $entries);

    foreach($overview->colored as $patid=>$colored_row) {
        foreach($colored_row as $formid=>$colorinfos) {
            foreach($colorinfos as $colorinfo) {

                foreach(explode(',', $colorinfo['more']) as $option) {
                    # sending sms is defined als additional colorinfo int he form
                    # sms:title where title refers to templates in the sms tab
                    # a trailing '!' indicates that the corresponding message should
                    # also be sent in an atonomous fashion (via cron.php)
                    $option = array_map('trim', explode(':', $option));
                    if ($option[0] === 'sms') {

                        $title = $option[1];
                        $auto = preg_match('/(.*)!$/', $title, $m);
                        if ($auto) $title = $m[1];
                        $id_title = implode('=', array($patid, $title));

                        if (in_array($id_title, $prev_ids_titles)) {
                            $sent_previously++;
                        } else {
                            array_push($ids_titles, $id_title);
                            if ($auto)
                                array_push($ids_titles_auto, $id_title);
                        }
                    }
                }
            } # foreach =>$colorinfo
        } # foreach $formid=>
    } # foreach $patid=>

    return array(
        'ids_titles' => &$ids_titles,
        'ids_titles_auto' => &$ids_titles_auto,
        'sent_previously' => $sent_previously
    );
}

# autonomously send sms depending on overview
function sms_cron_overview($args) {
    global $config;
    $overview =& $args['overview'];

    $templates =& sms_templates();
    $overview_list = sms_overview_list($overview);
    $ids_titles =& $overview_list['ids_titles_auto'];

    foreach($ids_titles as $id_title) {

        $id_title = explode('=', $id_title);

        if (!array_key_exists($id_title[1], $templates)) {
            log_add('cron', 'sms ERROR : unknown template name : ' . $id_title[1]);
            continue;
        }

        $message = $templates[$id_title[1]];

        $number = sms_get_number($message);
        if ($number === NULL) {
            $number = $numbers[0][1];
            $numbers = sms_numbers($id_title[0]);
            if (!$numbers) {
                log_add('cron', 'sms ERROR : no number found for id=' . $id_title[0]);
                continue;
            }
        }

        $message = sms_interprete($id_title[0], $message);

        $test_suffix = $config->plugins['sms']['mode'] === 'test' ?
            ' [test]' : '';

        $status = sms_send($number, $message);
        log_add('sms', "$number $message -- $status$test_suffix");

        if ($status === 'success') {
            log_add('mass-sms', implode('=', $id_title));
        }
    }
}

# generate list of mass sms to be sent & display corresponding control
function sms_before_overview($args) {
    global $config, $user, $self;
    $overview =& $args['overview'];

    if (!in_array('sms', $user['rights'])) return;

    $overview_list = sms_overview_list($overview);
    $sms_already_sent = $overview_list['sent_previously'];
    $ids_titles =& $overview_list['ids_titles'];
    $sms_to_be_sent = count($ids_titles);

    # output sms well
    $href = "$self?show=sms";
    $href.= '&ids_titels=' . rawurlencode(implode(';', $ids_titles));

    if ($sms_to_be_sent + $sms_already_sent == 0)
        return;

    echo "<div class=well>";
    echo "autogenerated messages : ";
    if ($sms_to_be_sent) {
        echo "<a " .targeting('sms'). " href=\"$href\" class=\"btn btn-primary sms-send\">send $sms_to_be_sent messages</a> ";
    }
    if ($sms_already_sent) {
        echo "($sms_already_sent messages already sent)";
    }
    echo "</div>";
} 

function sms_render_row_header($args) {
    global $self, $user;
    $patid = $args['patid'];
    $row_header =& $args['row_header'];
    if (in_array('sms', $user['rights'])) {
        $href = $self.'?show=sms&id='.$patid;
        $row_header = "$row_header <a " .targeting('sms'). " href=\"$href\">".
            "<i class=icon-envelope></i></a>";
    }
}

# register hooks {{{1

$hooks->register('dump_headers', 'sms_dump_headers');
$hooks->register('early_action', 'sms_early_action');
$hooks->register('augment_views', 'sms_augment_views');
$hooks->register('display', 'sms_display_single');
$hooks->register('display', 'sms_display_mass');
$hooks->register('before_overview', 'sms_before_overview');
$hooks->register('render_row_header', 'sms_render_row_header');

$hooks->register('cron_overview', 'sms_cron_overview');

?>
