<?php
define('MAGIC', '');
error_reporting(E_ALL);
ini_set('display_errors', '1');

umask(002);
chdir(__DIR__);

# includes {{{1
require_once 'profile.php';
require_once 'log.php';
require_once 'util.php';
require_once 'config.php';
require_once 'odk_form.php';
require_once 'overview.php';
require_once 'mail.php';
require_once 'plugins.php';

# parse & check command line options {{{1

$args = getopt('i:hd', array('instance:', 'help'));
if (isset($args['h']) || isset($args['help'])) {
    $me = basename(__FILE__);
    die("usage:\n\t$me [-d] [-i instance]\n\n" .
        "instance specifies a odk_planner instance (sub-directory of 'instances/')\n\n");
}
$debug = isset($args['d']);

# set 'instance' in $_REQUEST for instance.php...
$instance = @$args['i'];
if (!$instance) $instance = @$args['instance'];
//if ($instance) $_SESSION['instance'] = $instance; XXX
if (!$instance) {
    die("\nmust specify instance with -i command line switch!\n\n");
}
$instance = sanitize($instance);
$root = "instances/$instance";
if (!is_dir($root)) {
    die("\ninstance '$instance' not found!\n\n");
}
assert_permissions($root);

$self = $_SERVER['PHP_SELF'];
$logdir = "$root/log";
$formdir = "$root/forms";
$config_xls = "$root/config/config.xls";
$config_ini = "$root/config/config.ini";


# initialize {{{1

$cron_logsize = get_log_size('cron');
profile('reading config');

$config = new ExcelConfig($config_xls, $config_ini);
date_default_timezone_set($config->settings['timezone_identifier']);

# fake user -- see config.php
$user = array(
    'name' => 'cron',
    'rights' => array('overview', 'data', 'forms', 'sms', 'admin', 'test'),
    'access' => array('default'),
);

profile('connecting');

$conn = @mysql_connect(
    $config->settings['db_host'],
    $config->settings['db_user'],
    $config->settings['db_pass'])
    or fatal_error( "MySQL : could not connect : " . mysql_error() );
mysql_select_db($config->settings['db_database'])
    or fatal_error( "could not select db : " . mysql_error() );

log_add('cron', 'started cron.php');

profile('loading forms');

$use_cache = true;
$forms = new OdkDirectory($conn, $formdir,
    $config->settings['idfield'],
    $config->settings['datefield'], $use_cache);


# generate overviews {{{1

profile('overviews');

$id_range = array($config->settings['idfield_start'],$config->settings['idfield_length']);

foreach($config->overview_tables as $name=>$overview_tables)
{
    foreach($overview_tables as $overview_table) {

        $id_rlike = $overview_table['id_rlike'];
        $formids = $overview_table['forms'];
        $condition = $overview_table['condition'];
        $overview = new OverviewTable($name, $config->colors, $id_range, $condition);

        if (!$formids)
            $formids = array_keys($forms->forms);

        $overview->collect_data($conn, $forms, $formids, $id_rlike);

        $name_subheading = $name;
        if (isset($overview_table['subheading']))
            $name_subheading .= $overview_table['subheading'];

        $hooks->run('cron_overview', array(
            'id_rlike' => $id_rlike,
            'name' => $name_subheading,
            'overview' => &$overview));
    }
}


# send email {{{1

profile('send emails');

$notify_email = $config->dicts['cron']['notify_email'];
$notify_logs = $config->dicts['cron']['notify_logs'];

if ($notify_email && $notify_logs) {

    # get last positions for 'cron' for specified logs
    $all_logpos = get_log_pos('cron');
    $logpos = array();
    foreach(explode(',', $notify_logs) as $name) {
        if (isset($all_logpos[$name])) {
            $logpos[$name] = $all_logpos[$name];
        }
    }
    $logdiffs = get_log_diffs($logpos);
    # create .csv attachments with diffs
    $string_attachments = array();
    foreach($logdiffs as $name=>$content) {
        $content = "when\twho\tmessage\n" . $content;
        # windows friendly output
        $content = str_replace("\t", ',', str_replace(',', ';', $content));
        $string_attachments[$name . '.csv'] = $content;
    }

    $subject = 'odk_planner cron output';
    if (isset($config->settings['title'])) {
        $subject .= ' [' . $config->settings['title'] . ']';
    }
    $message = 'please see attached the log files since last email';
    $hooks->run('cron_notify_email', array(
        'string_attachments' => &$string_attachments,
        'subject' => &$subject,
        'message_plain' => &$message
    ));

    $options = array(
        'recipient' => $notify_email,
        'subject' => $subject,
        'message_plain' => $message,
        'string_attachments' => &$string_attachments,
    );
    $status = send_mail($options, $debug);
    if ($status === "success") {
        log_add('cron', 'sent email to ' . $notify_email);
        # only update log position for 'cron' if email sent successfully
        update_log_pos('cron', array_keys($logpos));
    } else {
        log_add('cron', 'could not send email : ' . $status);
    }
}


# render alerts, dump profile {{{1

alerts_to_log('cron');

profile('done');

if ($config->dicts['cron']['profile'] === 'yes') {

    ob_start();
    profile_dump();
    $dump = ob_get_contents();
    ob_end_clean();

    foreach(explode("\n", $dump) as $line) {
        if ($line)
            log_add('cron', $line);
    }
}

