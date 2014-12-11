<?php
if (!defined('MAGIC')) die('!?');
/**
 * this file is included by <code>index.php</code> as well as <code>cron.php</code>
 * and loads all PHP files in the directory <code>plugins/</code>
 *
 * the plugin files must not produce any output and should register to hooks
 * (see HookRegistry)
 */

/**
 * the HookRegistry is used to register functions that are called at specific
 * points when the web page is generated or when the cron job executes
 * (prefix <code>cron_</code>).
 *
 * every hook is run with an associative array of parameters; refer to the
 * source documentation inside <code>$hooks</code> for a specification of
 * the different hooks. in some cases, references are passed to the hook
 * that can be used to modify non-global datastructures.
 *
 * @see HookRegistry::$hooks
 */
class HookRegistry {

    /**
     * an array (indexed by hook name) of arrays with function references;
     * hooks should always be added by the method <code>register</code>;
     * see the source for a description of the individual hooks
     */
    var $hooks = array(


        #### web page hooks

        # catch actions before any html output (e.g. for downloading files)
        'early_action' => array(),

        # output additional html into <header>...</header>
        'dump_headers' => array(),

        # additional items to put into menu
        # args: &views
        'augment_views' => array(),

        # show main page content
        'display' => array(),

        # called before the overview is output
        # args : &overview, name, id_rlike
        'before_overview' => array(),

        # called for every row header cell
        # args : field, &row_header, patid, id_rlike
        'render_row_header' => array(),

        # called for every name/value row in the form data display
        # args : formid, rowid, path, field, &name, &extra, &value
        'render_form_data_row' => array(),


        #### cron hooks

        # called after overview is generated
        # args : &overview, name, id_rlike
        'cron_overview' => array(),

        # called before the email is sent
        # args : &string_attachments, &subject, &message_plain
        'cron_notify_email' => array(),
    );

    var $hooks2file = array();
    var $current_file = '?';

    /**
     * register a function to a hook
     * @see HookRegistry::$hooks
     */
    function register($name, $funcref) {
        if (!in_array($funcref, $this->hooks[$name])) {
            array_push($this->hooks[$name], $funcref);
        }
        $this->hooks2file[$funcref] = $this->current_file;
    }

    /**
     * unregister a function from a hook
     * @see HookRegistry::$hooks
     */
    function unregister($name, $funcref) {
        $idx = array_search($funcref, $this->hooks[$name]);
        if ($idx !== FALSE) {
            array_splice($this->hooks[$name], $idx, 1);
        }
    }

    /**
     * run all functions registered to the specified hook
     * @see HookRegistry::$hooks
     */
    function run($name, $args=null) {
        foreach($this->hooks[$name] as $funcref) {
            profile_start('plugin:' . $this->hooks2file[$funcref]);
            call_user_func($funcref, $args);
            profile_stop('plugin:' . $this->hooks2file[$funcref]);
        }
    }
}


$hooks = new HookRegistry();

if ($handle = opendir('plugins/')) {
    while (false !== ($entry = readdir($handle))) {
        if (substr($entry, 0, 1) !== '_' &&
            pathinfo($entry, PATHINFO_EXTENSION) === 'php') {
                $hooks->current_file = pathinfo($entry, PATHINFO_FILENAME);
                include('plugins/' . $entry);
                $hooks->current_file = '?';
            }
    }
}

