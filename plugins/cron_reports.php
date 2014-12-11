<?php
if (!defined('MAGIC')) die('!?');

$cron_report_attachments = array();

function cron_report_overview($args) {
    global $config, $cron_report_attachments;
    $name = $args['name'];
    $overview =& $args['overview'];

    $date = getdate();
    $doit = false;
    foreach(explode(',', $config->dicts['cron']['report_mdays']) as $mday) {
        if ($date['mday'] == trim($mday))
            $doit = true;
    }
    if (!$doit) return;

    $csv = "id\tform\tremark\n";

    foreach($overview->colored as $patid=>$colored_row) {
        foreach($colored_row as $formid=>$colorinfos) {
            foreach($colorinfos as $colorinfo) {

                $remark = $colorinfo['list'];
                if ($remark) {
                    $csv .= "$patid\t$formid\t$remark\n";
                }

            }
        }
    }

    # windows friendly output
    $csv = str_replace("\t", ',', str_replace(',', ';', $csv));

    $fname = 'missing_forms_' . $name . '_' . strftime('%Y%m%d') . '.csv';
    $cron_report_attachments[$fname] = $csv;
}

function cron_report_email($args) {
    global $cron_report_attachments;
    $string_attachments =& $args['string_attachments'];
    $subject =& $args['subject'];

    if (!$cron_report_attachments) return;

    $subject .= ' -- with reports';

    foreach($cron_report_attachments as $file => &$content) {
        $string_attachments[$file] = $content;
    }
}

$hooks->register('cron_overview', 'cron_report_overview');
$hooks->register('cron_notify_email', 'cron_report_email');

