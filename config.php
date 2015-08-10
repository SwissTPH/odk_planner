<?php
if (!defined('MAGIC')) die('!?');

require_once('conditions.php');


class ExcelConfigException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}


class ExcelConfig
{

    /**
     * dictionary mapping username to dictionary with keys <code>'password',
     * 'rights', 'access'</code>
     */
    var $users;

    /**
     * array of dictionaries with keys <code>'subheading', 'id_rlike', 'forms',
     * 'condition'</code>, one entry for every row in the "overview" sheet.
     */
    var $overview_tables;

    /**
     * points to the default entry in the array 
     * {@link ExcelConfig::$overview_tables}
     */
    var $default_overview_table;

    /**
     * dictionary of dictionaries specifying how empty cells should be colored.
     * the first index is the ID of the form whose cells should be colored.
     * the second level dictionary describes how the cell should be colored and
     * depending on what other conditions.  every row in the config file "colors"
     * sheet yields one dictionary.
     */
    var $colors;

    /**
     * these dictionaries map keys to values, each for the corresponding
     * sheet name in the config.
     */
    var $settings, $cron;

    /**
     * this dictionary contains one dictionary of keys -&gt; values (same as
     * {@link ExcelConfig::$settings}) per additional sheet. these sheets hsould
     * have the same name as the plugin files (without extension).
     */
    var $plugins;

    function ExcelConfig($config_xls, $config_ini, $uploaded=false) {
        $wb = new Spreadsheet_Excel_Reader();
        $wb->read($config_xls);

        // each of these is parsed from a separate excel sheet
        $this->users = array();
        $this->colors = array();
        $this->overview_tables = array();
        $this->settings = array();

        $this->check_sheets($wb, array('settings', 'users', 'overview', 'colors'));

        foreach($wb->boundsheets as $i=>$x) {
            $name = $x['name'];
            $ws = $wb->sheets[$i];

            if (strcasecmp($name, 'users') === 0) {
                $this->check_columns($ws, $name, array('name', 'password', 'rights', 'access'));
                $row = 2;
                while(($name = $this->lookup($ws, $row, 'name'))) {
                    $password = $this->lookup($ws, $row, 'password');
                    if ($uploaded && !$password) {
                        throw new ExcelConfigException("empty password for user $name");
                    }
                    $this->users[$name]['name'] = $name;
                    $this->users[$name]['password'] = $password;
                    $this->users[$name]['rights'] = $this->lookup($ws, $row, 'rights', ',');
                    $this->users[$name]['access'] = $this->lookup($ws, $row, 'access', ',');
                    $row++;
                }
            }

            else if (strcasecmp($name, 'colors') === 0) {
                $this->check_columns($ws, $name, array('form2', 'form1', 'delay', 'style', 'list', 'more', 'condition'));
                $row = 2;
                while(($form2 = strtoupper($this->lookup($ws, $row, 'form2')))) {
                    $form1 = strtoupper($this->lookup($ws, $row, 'form1'));
                    $delay = $this->lookup($ws, $row, 'delay');
                    $style = $this->lookup($ws, $row, 'style');
                    $list = $this->lookup($ws, $row, 'list');
                    $more = $this->lookup($ws, $row, 'more');

                    if (strstr($style, ':') === FALSE) {
                        $style = 'background-color:' . $style;
                    }

                    $expression = $this->lookup($ws, $row, 'condition');
                    if ($expression) {
                        try {
                            $condition = new Condition($form2, $expression);
                        } catch (ParserException $e) {
                            throw new ExcelConfigException(
                                "cannot parse condition '$expression' : " . 
                                $e->getMessage());
                        }
                    } else {
                        $condition = null;
                    }

                    if (!array_key_exists($form2, $this->colors)) {
                        $this->colors[$form2] = array();
                    }

                    array_push($this->colors[$form2],array(
                        'style' => $style,
                        'list' => $list,
                        'form1' => strtoupper($form1),
                        'delay' => strtoupper($delay),
                        'condition' => $condition,
                        'more' => $more,
                        'config_xls_row' => $row
                    ));

                    $row++;
                }
            }

            else if (strcasecmp($name, 'overview') === 0) {
                $this->check_columns($ws, $name, array('id_rlike', 'name', 'forms'));
                $row = 2;
                while(($overview_name = $this->lookup($ws, $row, 'name'))) {

                    $expression = $this->lookup($ws, $row, 'condition');
                    if ($expression) {
                        try {
                            $condition = new Condition(null, $expression);
                        } catch (ParserException $e) {
                            throw new ExcelConfigException(
                                "cannot parse condition '$expression' : " . 
                                $e->getMessage());
                        }
                    } else {
                        $condition = null;
                    }

                    if (!isset($this->overview_tables[$overview_name]))
                        $this->overview_tables[$overview_name] = array();

                    array_push($this->overview_tables[$overview_name], array(
                        'subheading' => $this->lookup($ws, $row, 'subheading'),
                        'id_rlike' => $this->lookup($ws, $row, 'id_rlike'),
                        'forms' => $this->lookup($ws, $row, 'forms', ','),
                        'condition' => $condition
                    ));
                    if (!$this->default_overview_table)
                        $this->default_overview_table = $overview_name;
                    $row++;
                }
            }

            // key-value sheet
            else {

                // fill in key -> values
                $this->check_columns($ws, $name, array('key', 'value'));
                $dict = array();
                $row = 2;
                while(($key = $this->lookup($ws, $row, 'key'))) {
                    if (preg_match('/\s/', $key))
                        throw new ExcelConfigException(
                            "key '$key' in sheet '$name' contains spaces!");
                    $dict[$key] = $this->lookup($ws, $row, 'value');
                    $row++;
                }

                // special post-processing for some settings
                if ($name === 'settings') {
                    if (array_key_exists('db_pass', $dict))
                        alert('database settings no longer saved in ' .
                            'confix.xls (>=v0.3)', 'warning');
                    // default settings (backward compatibility)
                    if (!array_key_exists('datefield', $dict)) {
                        $dict['datefield'] = 'COMPLETION_DATE';
                    $this->settings = $dict;
                    }
                }

                // store sheet to config
                if (in_array($name, ['settings', 'cron'])) {
                    $this->$name = $dict;
                } else {
                    $this->plugins[$name] = $dict;
                }
            }
        }

        if (!$this->users)
            throw new ExcelConfigException("cannot find sheet 'users'");
        if (!$this->settings)
            throw new ExcelConfigException("cannot find sheet 'settings'");

        // default : show all forms for all IDs
        if (!$this->overview_tables) {
            $this->overview_tables = array(
                'all forms' => array(
                    'id_rlike' => '.*',
                    'forms' => null,
                    'condition' => null
                )
            );
            $this->default_overview_table = 'all forms';
        }

        // read INI last to overwrite any values specified in xls
        $settings = parse_ini_file($config_ini, false);
        foreach($settings as $k=>$v) {
            $this->settings[$k] = $v;
        }
    }

    /**
     * returns trimmed cell value in given row, column specified by heading
     * <code>$name</code> -- returns <code>NULL</code> if not found
     */
    function lookup($ws, $row, $name, $explode = NULL) {
        if (array_key_exists(1, $ws['cells']) &&
            array_key_exists($row, $ws['cells']))

            for($col=1; $col<=$ws['numCols']; $col++)
                if (array_key_exists($col, $ws['cells'][1]) &&
                    strcasecmp($ws['cells'][1][$col], $name) == 0)

                    if (array_key_exists($col, $ws['cells'][$row])) {

                        if ($explode === NULL)
                            return trim($ws['cells'][$row][$col]);

                        $ret = array();
                        foreach(explode($explode, $ws['cells'][$row][$col]) as $part)
                            array_push($ret, trim($part));
                        return $ret;
                    }

        return NULL;
    }

    function check_sheets($wb, $sheet_names) {
        foreach($sheet_names as $sheet_name) {
            $found = false;
            foreach($wb->boundsheets as $i=>$x)
                if ($x['name'] === $sheet_name) {
                    $found = true;
                    break;
                }
            if (!$found)
                alert("expected to find sheet named '$sheet_name' in config", 'error');
        }
    }

    function check_columns($ws, $sheet_name, $column_names) {
        foreach($column_names as $column_name) {
            for($col=1, $found=false; $col<=$ws['numCols'] && !$found; $col++)
                if (array_key_exists($col, $ws['cells'][1]) &&
                    strcasecmp($ws['cells'][1][$col], $column_name) == 0)
                    $found = true;
            if (!$found)
                alert("expected to find column named '$column_name' in sheet '$sheet_name' in config", 'error');
        }
    }

    /**
     * returns the value corresponding to the given <code>$key</code> on the
     * settings page, or <code>$default</code> if no value is given
     */
    function get_setting($name, $default) {
        if (isset($this->settings[$name])) {
            return $this->settings[$name];
        } else {
            return $default;
        }
    }
}

