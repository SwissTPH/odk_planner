<?php # vim: set fdm=marker:
session_start();
define('MAGIC', '');

# pre-initialize {{{1
umask(002);
date_default_timezone_set('Europe/Zurich');

# for convenience, turn on error output while developing on localhost
if (file_exists('DEBUG') && !array_key_exists('nodebug', $_REQUEST)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

$version = trim(file_get_contents('VERSION'));
$gitversion = trim(@file_get_contents('GITVERSION'));

if (isset($_GET['ODK_PLANNER_VERSION'])) { die($version); }


# includes {{{1
require_once 'util.php';
require_once 'profile.php';
require_once 'log.php';
require_once 'config.php';
require_once 'odk_form.php';
require_once 'overview.php';
require_once 'plugins.php';

profile('all required');


# setup instance, set globals {{{1
$instance = @$_SESSION['instance'];
if (!$instance) {
    // before login instance is passed along as parameter
    $instance = @$_REQUEST['instance'];
}
if (!$instance) {
    // with mod_rewrite activated
    $instance = preg_replace('/.*\\/([a-z0-9_-]+).*/i', '$1', $_SERVER['REQUEST_URI']);
}
$instance = sanitize($instance);
$root = "instances/$instance";
if (!is_dir($root)) {
    if (isset($_SESSION['instance'])) {
        unset($_SESSION['instance']);
    }
    fatal_error("instance '$instance' not found");
}
assert_permissions($root);

$self = $_SERVER['PHP_SELF'];
$logdir = "$root/log";
$formdir = "$root/forms";
$config_xls = "$root/config/config.xls";
$config_ini = "$root/config/config.ini";
$configfile_tmp = "$root/config/config-uploaded-tmp.xls";
$tmp_pass_path = "$root/config/TMPPASS";
$tmp_pass = @file_get_contents($tmp_pass_path);


# load config {{{1
try {
    $config = new ExcelConfig($config_xls, $config_ini);
} catch(ExcelConfigException $e) {
    fatal_error('could not load config : '.$e->getMessage());
}
if (isset($config->settings['timezone_identifier'])) {
    date_default_timezone_set($config->settings['timezone_identifier']);
}
profile('loaded config');


# login {{{1

# do login (or fail)
if (isset($_POST['user']) && isset($_POST['password'])) {
    $ok = FALSE;
    if ($tmp_pass && $_POST['password'] === $tmp_pass) {
        log_add('user', 'login with temporary password');
        $ok = isset($config->users[$_POST['user']]); // accept all logins with TMPPASS
    } else {
        $user = @$config->users[$_POST['user']];
        # do not accept emtpy passwords (initial config)
        $ok = $user && $user['password'] && $user['password'] === $_POST['password'];
    }
    if ($ok) {
        $_SESSION['user'] = $_POST['user'];
        $_SESSION['instance'] = $instance;
        $_SESSION['timestamp'] = time();
        log_add('user', 'user "' . $_SESSION['user'] . '" logged in');
        # do redirect so page can be reloaded without browser suggesting
        # to post the data a second time
        header('Location: ' . $self);
    } else {
        log_add('user', 'user "' . $_POST['user'] . '" entered wrong password');
        sleep(2);
        alert('wrong password', 'error');
    }
}

# do logout
if (isset($_SESSION['user']) && isset($_REQUEST['logout'])) {
    log_add('user', 'user "' . $_SESSION['user'] . '" logged out');
    unset($_SESSION['user']);
    unset($_SESSION['instance']);
    alert('logged out');
}


# logout after inactivity
$delay_min = (time() - @$_SESSION['timestamp']) / 60;
if (isset($_SESSION['user']) && $delay_min > $config->settings['login_timeout']) {
    unset($_SESSION['user']);
    unset($_SESSION['instance']);
    $url = $self . "?instance=$instance";
    $params = urlencode(serialize(array_merge($_GET, $_POST)));
    if (count($params)) {
        $url .= "&params=$params";
    }
    header('Location: ' . $url);
    die;
}

# show login prompt if not logged in
if (!array_key_exists('user', $_SESSION)) {
    ?>
<!doctype html>
<head>
    <title>odk_planner <?php echo $version; ?> login</title><!-- <?php echo $gitversion; ?> -->
    <meta charset=utf-8>
    <link href=bootstrap/css/bootstrap.css rel=stylesheet media=screen>
    <link href=bootstrap/css/bootstrap-responsive.css rel=stylesheet media=screen>
    <link href=style.css rel=stylesheet media=screen>
</head>

<body>

    <div class=container>

    <?php render_alerts(); ?>

    <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method=POST class=form-signin>
        <h2 class=form-signin-heading>ODK planner <?php echo $version; ?></h2>
        <h4 class=form-signin-title><?php echo @$config->settings['title']; ?></h4>
        <input type=text name=user class=input-block-level placeholder=username autocorrect=off autocapitalize=off autocomplete=off>
        <input type=password name=password class=input-block-level placeholder=password autocorrect=off autocapitalize=off autocomplete=off>
        <input type=hidden name=instance value="<?php echo $instance; ?>" />
        <input type=hidden name=params value="<?php echo htmlentities(@$_REQUEST['params']); ?>" />
        <button class="btn btn-large btn-primary" type=submit>Sign in</button>
    </form>

    </div>

    </html>
    <?php
    die;
}

# update user globals
$_SESSION['timestamp'] = time();
$user = $config->users[$_SESSION['user']];


# open mysql connection, load odk forms {{{1
profile('initializing');

$conn = @mysql_connect(
    $config->settings['db_host'],
    $config->settings['db_user'],
    $config->settings['db_pass'])
    or fatal_error( "MySQL : could not connect : " . mysql_error() );
mysql_select_db($config->settings['db_database'])
    or fatal_error( "could not select db : " . mysql_error() );
profile('connected');

$use_cache = !array_key_exists('refresh', $_REQUEST);
$forms = new OdkDirectory($conn, $formdir,
    $config->settings['idfield'],
    $config->settings['datefield'], $use_cache);
profile('loaded .xls');


# early actions {{{1

if (array_key_exists('fake', $_REQUEST)) {
    die('faked.');
}

# test fixtures {{{2

if (isset($_GET['test_reset_mass_sms']) && in_array('admin', $user['rights'])) {
    $fh = fopen(get_log_path('mass-sms'), 'w');
    if ($fh !== FALSE)  {
        alert('reset mass-sms log', 'success');
        fclose($fh);
    }
}

# redirect to stored _REQUEST {{{2

if (@$_REQUEST['params']) {
    $request = unserialize(urldecode($_REQUEST['params']));
    foreach($request as $key=>$value) {
        if (in_array($key, array('instance', 'logout', 'session'))) {
            unset($request[$key]);
        }
    }
    $params = array();
    # even do redirect if no parameters (for landing page reloading)
    foreach($request as $name=>$value) {
        array_push($params, $name . '=' . urlencode($value));
    }
    $url = $self . '?' . implode('&', $params);
    header('Location: ' . $url);
    die();
}

# config/form download {{{2

function download_file($path, $mime='application/octet-stream') {
    header('Content-Description: File Transfer');
    header('Content-Type: '.$mime);
    header('Content-Disposition: attachment; filename='.basename($path));
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (array_key_exists('formdownload', $_REQUEST) && in_array('forms', $user['rights'])) {
    $formid = $_REQUEST['formdownload'];
    download_file($forms->forms[$formid]->fname, 'application/vnd.ms-excel');
}

if (array_key_exists('configdownload', $_REQUEST) && in_array('admin', $user['rights'])) {
    download_file($config_xls, 'application/vnd.ms-excel');
}
#
# config upload {{{2
if (array_key_exists('configupload', $_FILES) && in_array('admin', $user['rights'])) {
    if (substr($_FILES['configupload']['name'], -4) === '.xls') {

        if(move_uploaded_file($_FILES['configupload']['tmp_name'], $configfile_tmp)) {
            try {
                $config_tmp = new ExcelConfig($configfile_tmp, $config_ini, true);
                $config = $config_tmp;
                # no error -> accept new config
                unlink($config_xls);
                rename($configfile_tmp, $config_xls);
                # unlink temporary password file
                @unlink($tmp_pass_path);
                # redirect and force refresh to update cache
                header('Location: ' . $self . '?show=admin&refresh');
                die();
            } catch (ExcelConfigException $e) {
                alert('could not parse uploaded config : '.$e->getMessage(), 'error');
            }
        } else{
            alert('could not move uploaded file "'.basename($dst).'"', 'error');
        }

    } else {
        alert('config must be Microsoft Excel 97/2000/XP/2003 format (ending with ".xls")', 'error');
    }
}

# image download {{{2
if (array_key_exists('image', $_REQUEST)) {
    $formid = $_REQUEST['formid'];
    $rowid  = $_REQUEST['rowid' ];
    $path   = $_REQUEST['path'];

    validate_formid($formid);
    validate_rowid($rowid);
    validate_path($path);

    if ($formid && $rowid && $path) {
        if (array_key_exists($formid, $forms->forms)) {
            try {
                $form = $forms->forms[$formid];
                // (throws exception if user has no access rights)
                $form->send_image($conn, $rowid, $path, $user['access']);
            } catch (OdkException $e) {
                fatal_error('cannot show image : ' . $e->getMessage());
            }
        } else {
            fatal_error('unknown form');
        }
    } else {
        fatal_error('missing parameters for displaying image');
    }
    exit;
}

# plugin early actions {{{2

$hooks->run('early_action');


# html header {{{1
?>
<!doctype html>
<!-- odk_planner <?php echo $version; ?> <?php echo $gitversion; ?> -->
<head>
    <meta charset=utf-8>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="bootstrap/js/jq171.js"></script>
    <script src="bootstrap/js/bootstrap.js"></script>
    <script src="saveas.js"></script>
    <script src="overview.js"></script>

    <title>odk_planner <?php echo @$config->settings['title']; ?></title>

    <link href=bootstrap/css/bootstrap.min.css rel=stylesheet media=screen>
    <link href=bootstrap/css/bootstrap-responsive.min.css rel=stylesheet media=screen>
    <link href=style.css rel=stylesheet media=screen>

    <script type="text/javascript">

        var php_self = '<?php echo $self; ?>';

        function html_alert(html_content, div_class) {
            var $alert = $('<div class=alert></div>').html(
                html_content).appendTo($('.alerts'));
            $('<button type=button class=close data-dismiss=alert></button>').html(
                '&times;').appendTo($alert);
            if (div_class) {
                $alert.addClass('alert-' + div_class);
            }
        }
<?php if (in_array('admin', $user['rights'])): ?>
        $(function() {
            var url = window.location.href;
            if (!/\/$/.exec(url))
                url = /(.*\/)/.exec(url)[1];
            $.each(['instances', 'test'],
                function(i, part) {
                    $.get(url + part).done(function() {
                        html_alert(url + part + '" is not access restricted! ' +
                            'You MUST configure your web server to protect access ' +
                            'to this resource', 'error');
                    });
            });
            $.each(['lib', 'tools'],
                function(i, part) {
                    $.get(url + part).done(function() {
                        html_alert(url + part + '" is not access restricted! ' +
                            'You should configure your web server to protect access ' +
                            'to this resource', 'warning');
                    });
            });
        });
<?php endif; ?>
        $(function () {

            // activate bootstrap tooltip
            $("[data-toggle='tooltip']").tooltip();

            // filter tables
            $('.filter').keyup(function() {
                var t0 = new Date();
                var $this = $(this);
                var $table = $('table.filtered');
                var filter = $this.val();
                var columns = $this.data().columns;
                columns = typeof columns === 'string' ? columns.split(',') : [columns];
                $table.find('tr:gt(0)').each(function() {
                    var i, disp = 'hide', $this = $(this);
                    for(i = 0; disp === 'hide' && i < columns.length; i++) {
                        if ($this.find('td,th').eq(columns[i]).text().indexOf(filter)
                            !== -1) {
                            disp = 'show';
                        }
                    }
                    $(this)[disp]();
                });
                console.log('#filtering : ' + (new Date()-t0) + ' ms');
            });

            // select all
            $('table .select-all').change(function () {
                var $this = $(this);
                var idx = $this.closest('th,th').index();
                var val = $this.prop('checked');
                $this.closest('table').find('tr').find(
                    'td:eq(' + idx + ') ' + 'input[type="checkbox"]').prop('checked', val);
            });

            // form group display toggle
            $('.toggle-group').click(function () {
                var $link = $(this), $tds = $('[data-group="' + $link.data('name') + '"]');
                $tds.toggleClass('hidden');
                $link.text($tds.hasClass('hidden') ? 'show' : 'hide');
                return false;
            });

            // activate bootstrap popovers
            $('.popovered').popover({
                trigger: 'hover',
                html: true,
                placement: 'bottom'
            });

            // activate bootstrap dropdowns
            $('.dropdown-toggle').dropdown();

            // activate floating responsive page navigation
            //$('.group-nav').affix();
        });
    </script>
    <?php $hooks->run('dump_headers') ?>
</head>

<body>
<div class=container>
<?php


# menu {{{1
$show = 'overview';
if (array_key_exists('show', $_REQUEST)) $show = $_REQUEST['show'];

if ($show !== 'form') {
$show = preg_replace('/[^a-z_-]/i', '', $show);

    print '<div class="navbar navbar-inverse navbar-fixed-top"><div class=navbar-inner>';
    print '<ul class="nav">';
    if (isset($config->settings['title'])) {
        print '<li><h4 class=site-title>' . $config->settings['title'] . '</h4></li>';
    }

    $views = Array('overview');
    if (in_array('forms', $user['rights'])) array_push($views, 'forms');
    if (in_array('admin', $user['rights'])) array_push($views, 'admin');
    if (in_array('test', $user['rights'])) array_push($views, 'test');
    if (in_array('admin', $user['rights']) || in_array('forms', $user['rights'])) array_push($views, 'help');

    $plugin_menu_items = $hooks->run('augment_views', array('views' => &$views));

    foreach($views as $view) {

        $viewmore = $id = $class = $active = $target = $toggle = $ul = '';
        $liclasses = array();

        $link = $self . "?show=" . $view;

        if ($view === 'help') $link = 'docs/_build/html/index.html';
        if ($view === 'help') $target = 'target=odk_planner_help';
        if ($show === $view) array_push($liclasses, 'active');

        if ($view === 'overview') {
            $overview_name = @$_REQUEST['overview'];
            $overview_name = $overview_name ? $overview_name : $config->default_overview_table;
        }

        if ($view === 'overview' && count($config->overview_tables) > 1) {
            $viewmore = '';
            if ($show === 'overview')
                $viewmore.= " : $overview_name</b>";
            $viewmore.= " <b class=caret></b>";
            $id = 'id=overtoggle';
            $class = 'class=dropdown-toggle';
            $toggle = 'data-toggle=dropdown';
            $ul = "\n\t<ul class=dropdown-menu aria-labelledby=overtoggle role=menu>\n";

            foreach($config->overview_tables as $name=>$overview_table) {
                $sublink = $link . '&overview=' . urlencode($name);
                $subclass = '';
                $ul.= "\t\t<li><a role=menuitem href=\"$sublink\" $subclass>$name</a></li>\n";
            }
            $ul.= "\t</ul>";
            $link = '#';
            array_push($liclasses, 'dropdown');
            $liclass = 'class=dropdown';
        }

        print "\t<li class=\"" . implode(' ', $liclasses) . "\">";
        print "<a href=\"$link\" $id $class $target $toggle>$view$viewmore</a>";
        print "$ul</li>\n";
    }
    print '</ul>';

    print '<form class="navbar-form pull-right">';
    if ($show === 'overview')
        print '<input type=text class="filter search-query" data-columns="0" placeholder="filter ID">';
    if (in_array('admin', $user['rights']))
        print "<a href=\"$self?refresh&show=$show\" class=\"btn btn-inverse btn-small\">" .
        "<i class=\"icon-white icon-refresh\"></i></a>";
    print "<a href=\"$self?logout&instance=$instance\" class=\"btn btn-inverse btn-small\">" .
        "<i class=\"icon-off icon-white\"></i> logout</a>";
    print '</form>';

    print '</div></div>';


}

render_alerts();

# html utilities {{{1


$accordion_i = 0;
function accordion_start($title, $expanded=false) {
    global $accordion_i;
?>
    <div class="accordion" id="accordion-<?php echo $accordion_i; ?>">
        <div class="accordion-group">
            <div class="accordion-heading">
            <a class="accordion-toggle" data-toggle="collapse"
                data-parent="#accordion-<?php echo $accordion_i; ?>"
                href="#accordion-body-<?php echo $accordion_i; ?>"><?php echo $title; ?></a>
            </div>
        </div>
    </div>
    <div id="accordion-body-<?php echo $accordion_i; ?>" class="accordion-body <?php echo $expanded ? '' : 'collapse'; ?>">
        <div class="accordion-inner">
<?php
    $accordion_i++;
}
function accordion_end() {
?>
        </div>
    </div>
<?php
}
function accordion($title, $content) {
    accordion_start($title);
    echo $content;
    accordion_end();
}


# late actions {{{1
profile('start actions');

# form upload {{{2
if (array_key_exists('formupload', $_FILES) && in_array('forms', $user['rights'])) {
    $dst = $formdir .'/'. basename( $_FILES['formupload']['name']); 

    if (substr($dst, -4) === '.xls') {

        if(move_uploaded_file($_FILES['formupload']['tmp_name'], $dst)) {
            alert('successfully uploaded file "'.basename($dst).'"', 'success');
            # reload forms
            $forms = new OdkDirectory($conn, $formdir,
                $config->settings['idfield'],
                $config->settings['datefield']);
        } else{
            alert('could not upload file "'.basename($dst).'"', 'error');
        }

    } else {
        alert('form must be Microsoft Excel 97/2000/XP/2003 format (ending with ".xls")', 'error');
    }
}

# delete form {{{2
if (array_key_exists('rmform', $_REQUEST) && in_array('forms', $user['rights'])) {
    $fname = basename($_REQUEST['rmform']);
    if (unlink($formdir .'/'. $fname)) {
        @unlink($formdir .'/'. $fname + '.cache.json');
        alert('removed form "'.$fname.'"', 'success');
        # reload forms
        $forms = new OdkDirectory($conn, $formdir,
            $config->settings['idfield'],
            $config->settings['datefield']);
    } else
        alert('could not delete form "'.$fname.'"', 'error');
}

# displays {{{1
profile('start displays');

# view form content {{{2

if ($show === 'form' && in_array('data', $user['rights'])) {
    $formid = $_REQUEST['formid'];
    $rowid  = $_REQUEST['rowid' ];
    validate_formid($formid);
    validate_rowid($rowid);

    function dump_name_value_row($data, $path, $field, $group=null, $shown=true) {
        global $user, $self, $formid, $rowid, $hooks;

        if (array_key_exists($path, $data) ||
            ($field['type'] === 'geopoint' && array_key_exists($path . '_LAT', $data))) {
            # can access

            if ($field['type'] === 'geopoint') {
                $value = '<a target="odk_planner_map" href="http://maps.google.com/?q=';
                $value.= $data[$path . '_LAT'] . ',';
                $value.= $data[$path . '_LNG'];
                $value.= '">show on google maps</a>';

            } elseif ($field['type'] === 'image') {
                $href = "$self?image&formid=".rawurlencode($formid);
                $href.= "&path=".rawurlencode($path)."&rowid=$rowid";
                $value = '<a target="odk_planner_image" href="'.$href.'">'.$data[$path].'</a>';

            } else {
                $value = $data[$path];
                if ($value === NULL) {
                    $value = '<code>NULL</code>';
                } else {
                    if (gettype($value) === 'array') {
                        $value = '<code>' . implode('</code> <code>', $value) . '</code>';
                    } else {
                        $value = '<code>' . $value . '</code>';
                    }
                }
            }

        } else {
            # can not access
            $value = '<code>(masked)</code>';
        }

        $class = $shown ? '' : 'hidden';
        echo "<tr class=\"$class\" data-group=\"$group\">";
        if (in_array('admin', $user['rights'])) {
            $extra = " <input type=text class=input-small value=\"$formid\\$path\" readonly>";
        } else {
            $extra = "";
        }
        if ($group === null) {
            echo '<td>';
        } else {
            echo '<td class=in-group>';
        }

        $name = $field['name'];

        $hooks->run('render_form_data_row', array(
            'formid' => $formid,
            'rowid' => $rowid,
            'path' => $path,
            'field' => $field,
            'name'  => &$name,
            'extra' => &$extra,
            'value' => &$value));

        echo          $name  . '</td>';
        echo '<td>' . $extra . '</td>';
        echo '<td>' . $value . '</td>';
        echo '</tr>';
    }

    if(array_key_exists($formid, $forms->forms)) {
        $form = $forms->forms[$formid];

        try {
            $data = $form->get_values($conn, $rowid, $user['access']);

            # dump data into table
            ?>

            <div class="navbar navbar-inverse navbar-fixed-top">
                <div class="navbar-inner">
                    <span class="brand">
                        <?php echo $data[$form->id_column] . ' &mdash; ' . $formid; ?>
                    </span>
                </div>
            </div>

            <div class="row">

            <div class="span3">
                <ul class="nav nav-list group-nav" data-spy=affix data-offset-top=0>
                <li><h4>groups</h4></li>
                <?php foreach($form->groups as $group): ?>
                <?php if (gettype($group) === 'array'): ?>
                <li><a href="#<?php echo $group[0]?>"><?php echo $group[0]?></a></li>
                <?php endif; ?>
                <?php endforeach; ?>
                </ul>
            </div>

            <div class="offset3 span9">

            <table class="table form"> <!-- form content -->
            <?php

            $group_i = 0;
            foreach($form->groups as $group) {
                $group_i += 1;

                if (gettype($group) !== 'array') {
                    # field not within group
                    $path = $group;
                    $field = $form->fields[$path];
                    dump_name_value_row($data, $path, $field);

                } else {
                    # dump all fields from group
                    //accordion_start($group[0], count($group) < 5);
                    $shown = 1;
                    ?>
                    <tr id="<?php echo $group[0]; ?>"><th class=group-header colspan=3>
                        <?php echo $group[0]; ?>
                        <!-- <a href="#" class=toggle-group data-name="<?php echo $group[0]; ?>"><?php echo $shown ? 'hide' : 'show'; ?></a> -->
                    </th></tr>
                    <?php
                    for($i=1; $i<count($group); $i++) {
                        $path = $group[$i];
                        $field = $form->fields[$path];
                        dump_name_value_row($data, $path, $field, $group[0], $shown);
                    }
                    //accordion_end();
                }
            } # foreach($form->groups ...

            ?>
            </table>
            <?php
            //echo '<pre>'; print_r($form->groups); echo '</pre>';
            if (in_array('test', $user['rights']))
                accordion('print_r', '<pre>' . print_r($form->fields, true) . '</pre>');
            //echo '<pre>'; print_r($data); echo '</pre>';
            ?>
            </div>
            </div>
            <?php
        } catch (OdkException $e) {
            alert("cannot find rowid='$rowid' in form='$formid' : ".$e->getMessage(), 'error');
        }
    } else {
        alert("cannot find formid='$formid'", 'error');
    }
}

# overview {{{2

if ($show === 'overview' && in_array('overview', $user['rights'])) {

    $id_range = array($config->settings['idfield_start'],$config->settings['idfield_length']);

    # prepare overview

    $overview_tables = $config->overview_tables[$overview_name];

    foreach($overview_tables as $overview_table) {

        $id_rlike = $overview_table['id_rlike'];
        $condition = $overview_table['condition'];
        $formids = $overview_table['forms'];
        if (!$formids)
            $formids = array_keys($forms->forms);
        $overview = new OverviewTable($overview_name, $config->colors, $id_range, $condition);

        $overview->collect_data($conn, $forms, $formids, $id_rlike);


        # render overview {{3

        ?>
        <div class="row-fluid">
        <div class="span12">
        <?php

        if (isset($overview_table['subheading']))
            echo '<h4>'.$overview_table['subheading'].'</h4>';

        $hooks->run('before_overview', array(
            'id_rlike' => $id_rlike,
            'name' => $overview_name,
            'overview' => &$overview));

        # output table
        $cell_cb = function ($patid, $formid, $datas) {
            global $user, $self, $forms;
            $links = array();
            foreach($datas as $uri=>$data) {
                $timestamp = $data[$forms->forms[$formid]->date_column];
                $timestamp_short = 
                    (1 * substr($timestamp, 5, 2)) . '/' . // M(M)
                    (1 * substr($timestamp, 8, 2)) . '/' . // D(D)
                    substr($timestamp, 2, 2); // YY
                if (in_array('data', $user['rights'])) {
                    $href = $self."?show=form&formid=$formid&rowid=".rawurlencode($uri);
                    array_push($links, "<a " .targeting('data'). " href=\"$href\">".
                        "$timestamp_short</a>");
                } else {
                    array_push($links, $timestamp_short);
                }
            }
            return implode(', ', $links);
        };

        $row_cb = function ($patid) {
            global $id_rlike, $hooks;
            $hooks->run('render_row_header', array(
                'row_header' => &$patid,
                'patid' => $patid,
                'id_rlike' => $id_rlike));
            return $patid;
        };

        $column_cb = function ($formid) {
            global $forms;
            $form = $forms->forms[$formid];
            return '<span class=popovered ' .
                    'data-content="' . str_replace("'", '"', $form->title) . '" ' .
                    '>' . $formid . '</span>';
        };

        $overview->generate_html($cell_cb, $row_cb, $column_cb);

        ?>
        </div>
        </div>

        <?php

    } # foreach($overview_tables as $overview_table)

}

# forms {{{2

if ($show === 'forms' && in_array('forms', $user['rights'])) {
?>
    <div class="row-fluid">
    <div class="span10 offset1">
<?php

    # in db without forms (not in table below; show to remind to upload)
    if (count($forms->db_only)>0) {
?>
    <p>tables in database currently not matched with any .xls form:
        <code><?php echo implode('</code>, <code>', $forms->db_only); ?></code>
    </p>
<?php
    }

    # form upload
    print "<form enctype=\"multipart/form-data\" action=\"$self\" method=\"POST\">\n";
    print "Upload a new form / overwrite existing (.xls): <input name=formupload type=file />\n";
    print "<input type=hidden name=show value=forms>\n";
    print "<button type=submit File\" class=\"btn btn-primary\">Upload form</button>\n";
    print "</form><br />\n";

    # table with current forms -- also display infos about field matching
?>

    <table class=table>
        <tr><th>form_id</th><th>title</th><th>file</th><th>info</th><th>actions</th></tr>
        <?php foreach($forms->forms as $formid=>$form): ?>
        <tr data-formid="<?php echo $formid; ?>">
            <td><?php echo $formid ?></td>
            <td><?php echo $form->title ?></td>
            <td><a href="<?php echo $self.'?formdownload='.$formid; ?>" class="formdownload"
                ><?php echo basename($form->fname) ?></a>
                (<?php echo date('n/j/y', filemtime($form->fname)) ?>)</td>
            <td class="forminfo">
                <?php if (!$form->matched): ?>
                    <!-- .xls form not matched with tables in database -->
                    <a href="#" class="unmatched-form" data-toggle="tooltip" title="the .xls form could not be found in the database; make sure you uploaded the .xml form to ODK Aggregate">not matched</a>
                <?php else: ?>
                    <?php if (count($form->db_only)>0): ?>
                        <!-- columns only in database -->
                        <button class="btn popovered" data-db-only="<?php echo implode(',', $form->db_only); ?>" data-content="the following columns from the database were not matched to any field in the .xls form : <?php echo implode(', ', $form->db_only); ?>"><?php echo count($form->db_only); ?> unmatched columns</button>
                        &nbsp;
                    <?php endif; ?>
                    <?php if (count($form->xls_only)>0): ?>
                        <!-- fields only in .xls file -->
                        <button href="#" class="btn popovered" data-xls-only="<?php echo implode(',', $form->xls_only); ?>" data-content="the following fields form the .xls form could not be matched with columns in the database : <?php echo implode(', ', $form->xls_only); ?>"><?php echo count($form->xls_only); ?> unmatched fields</button>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <!-- controls : delete -->
            <td>
                <a href="<?php echo $self; ?>?rmform=<?php echo basename($form->fname); ?>&show=forms" class="formdelete">delete</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    </div>
<?php
}

# admin {{{2

if ($show === 'admin' && in_array('admin', $user['rights'])) {
    # config {{{3
?>
    <div class="row-fluid">
    <div class="span10 offset1">
    <h1>configuration</h1>
<?php
    # config upload
    print "<form enctype=\"multipart/form-data\" action=\"$self\" method=\"POST\">\n";
    print "Upload a new configuration (.xls): <input name=configupload type=file />\n";
    print "<button type=submit File\" class=\"btn btn-primary\">Upload config</button>\n";
    print "</form><br />\n";
?>

    <a href="<?php echo $self . '?configdownload'; ?>" class="btn btn-info">download current config</a>

    <br><br><br>

    <?php if (in_array('test', $user['rights'])): ?>
    <?php accordion('users', pre_print_r($config->users, true)); ?>
    <?php accordion('colors', pre_print_r($config->colors, true)); ?>
    <?php accordion('overview', pre_print_r($config->overview_tables, true)); ?>
    <?php accordion('settings', pre_print_r($config->settings, true)); ?>
    <?php accordion('other sheets', pre_print_r($config->dicts, true)); ?>
    <?php endif; ?>

    </div>
    </div>

    <?php # log files {{{3 ?>
    <div class="row-fluid">
    <div class="span10 offset1">
    <h1>log files</h1>

    <?php
    foreach(array('user', 'cron', 'sms', 'mass-sms') as $domain) {
        accordion_start('log "'.$domain.'"');
        log_render($domain, "$self?show=admin");
        accordion_end();
    }
    ?>

    </div>
    </div>

<?php
}


# test {{{2

if ($show === 'test' && in_array('test', $user['rights'])) {
?>

  <div class="row-fluid">
    <div class="span10 offset1">

    <?php // $forms->model->dump(); ?>
    <?php $forms->dump(); ?>

    </div>
  </div>
<?php
}


# run hooks {{{2

$hooks->run('display');


# footer {{{1
profile('display footer');
?>
<footer class=footer>
    <div class=row-fluid>
    <?php if (in_array('test', $user['rights'])): ?>
    <?php if ($show === 'form'): ?>
        <div class="span8 offset3">
    <?php else: ?>
        <div class="span10 offset1">
    <?php endif; ?>
    <pre><?php profile_dump(); ?></pre>
    <?php if ($mysql_queries): ?>
        <?php accordion_start('msyql queries'); ?>
        <pre><?php echo implode("\n\n", $mysql_queries); ?></pre>
        <?php accordion_end(); ?>
    <?php endif; ?>
    <?php if ($tmp_logs): ?>
        <?php accordion_start('tmp_log'); ?>
        <pre><?php tmp_dump(); ?></pre>
        <?php accordion_end(); ?>
    <?php endif; ?>
    </div>
    <?php endif; ?>
    </div>
</footer>

<?php render_alerts_delayed(); ?>

</div>
</body>

<?php
# {{{1
?>
