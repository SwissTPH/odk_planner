<?php
/**
 * `odk_planner` plugin generating doughnut plots from field values.
 *
 * This plugin is configured via the sheet "doughnut" in config.xls that has the
 * columns `key` and `value`. Every row will generate a doughnut plot that is
 * named with the cell value in the `key` column and is configured with the cell
 * value in the `value` column. This `value` column consists of the `FORM\FIELD`
 * from which data should be plotted (when you log into `odk_planner` as admin
 * you will see the `FORM\FIELD` displayed next to every field in the data view)
 * followed by the possible values. For categorical data these values are simply
 * the possible values of the field (values not listed will be aggregated into
 * "other") while for numerical data a value is a range in the form `12.3-15`.
 * 
 * Note that every user who has the `data` right will be able to see the plots
 * (independent of the access rights to the individual fields).
 * 
 * Please see [Developing a Plugin](http://odk-planner.readthedocs.org/en/latest/hacking.html#developing-a-plugin)
 * in the online documentation for a detailed explanation of this file.
 */

// This file must only be loaded via plugins.php
if (!defined('MAGIC')) die('!?');

/**
 * Generates JSON codified data from a MySQL query cursor.
 *
 * @param resource $curs MySQL that generates a table with the columns
 *    `value` and `count`.
 *
 * @return string A JSON encoded data array that can be used as input for
 *    Chart.js's Doughnut plot.
 */
function doughnut_json($curs) {
    $data = array();
    $colors = array(
        # from http://colrd.com/palette/19308/
        '#51574a', '#447c69', '#74c493', '#8e8c6d', '#e4bf80', '#e9d78e',
        '#e2975d', '#f19670', '#e16552', '#c94a53', '#be5168', '#a34974',
        '#993767', '#65387d', '#4e2472', '#9163b6', '#e279a3', '#e0598b',
        '#7c9fb0', '#5698c4', '#9abf88');
    $i = 0;
    while($row = mysql_fetch_assoc($curs)) {
        array_push($data, array(
            'value' => $row['count'],
            'label' => trim($row['value']),
            'color' => $colors[$i++ % count($colors)]
        ));
    }
    return json_encode($data);
}

/**
 * Generates the MySQL query to extract data from the ODK database.
 *
 * @param array $config Configuration parameters as returned by
 *    `doughnut_config`.
 *
 * @return string A MySQL query that will generate a table with the columns
 *    `value` and `count`.
 */
function doughnut_query($config) {
    // We aim to generate a SQL query in the following form:
    //
    // SELECT value, COUNT(value) AS count FROM (
    //   SELECT CASE
    //     WHEN column >= min AND column < max THEN 'min-max'
    //     WHEN column = 'value' THEN 'value'
    //     ...
    //     ELSE 'other'
    //   END AS value FROM LRF1_CORE
    //   FROM table
    // ) x GROUP BY value ORDER BY value;
    //
    $when = "CASE\n";
    $spaces = str_repeat(' ', count($config['values']));
    foreach ($config['values'] as $value) {
        if (preg_match('/^(\\d+\\.?\\d*)-(\\d+\\.?\\d*)$/', $value, $m) === 1) {
            $when .= "  WHEN \$column >= {$m[1]} AND \$column < {$m[2]} " .
                     "THEN '$spaces{$m[0]}'\n";
        } else {
            $value = mysql_real_escape_string($value);
            $when .= "  WHEN \$column = '$value' THEN '$spaces$value'\n";
        }
        $spaces = substr($spaces, 1);
    }
    $when .= "  ELSE 'other'\nEND";
    $inner = "SELECT $when AS value FROM \$table";
    $query = 'SELECT value, COUNT(value) AS count ' .
             'FROM (' . $inner . ') x GROUP BY value ORDER BY value';
    $query = str_replace('$table', $config['table'], $query);
    $query = str_replace('$column', $config['column'], $query);
    return $query;
}

$doughnut_i = 0; // Count doughnuts
/**
 * Render a single doughnut.
 *
 * @param string $name Name of the plot
 * @param array $config Configuration parameters as returned by
 *    `doughnut_config`.
 */
function doughnut_render($name, $config) {
    global $conn, $doughnut_i;

    $id = 'doughnut_' . $name;

    $query = doughnut_query($config);
    // We use mysql_query_ from profile.php to record the query. The recorded
    // queries are shown in the footer when '&test' is added to the URL and
    // the user has the "test" right.
    $curs = mysql_query_($query, $conn);
    if ($curs === FALSE) {
        alert('MySQL error : ' . mysql_error(), 'error');
        return;
    }
    $json = doughnut_json($curs);

    // Pack three doughnuts into a row (desktop display).
    if ($doughnut_i % 3 === 0) echo '<div class="row"><br><br>';
?>
    <div class="span4">
        <center>
            <canvas id="<?php echo $id; ?>" style="width:100%"></canvas>
        </center>
        <h4 style="text-align:center"><?php echo $name; ?>
        <script type="text/javascript">
            // See www.chartjs.org/docs/#doughnut-pie-chart
            new Chart(document.getElementById('<?php echo $id; ?>').getContext('2d'))
                .Doughnut(<?php echo $json; ?>);
        </script>
    </div>
<?php
    $doughnut_i++;
    if ($doughnut_i % 3 === 0) echo '</div>';
}

/**
 * Helper function that parses the configuration
 *
 * @param string $config A value from the `value` column in the configuration
 *    sheet. Should have the form `FORM\FIELD value1 value2 value3 ...` where
 *    a value can also be a range for numerical data.
 *
 * @return mixed Either an array with the keys `table`, `column` and `values`,
 *    or a error string.
 */
function doughnut_config($config) {
    global $forms;
    $parts = explode(' ', $config);
    if (count($parts) < 2) return 'expected "FORM\\FIELD value1 ..."';
    $values = array_splice($parts, 1);
    $form_field = explode('\\', $parts[0]);
    if (count($form_field) !== 2) return 'expected "FORM\\FIELD value1 ..."';

    // $forms->forms is array that maps formid to OdkForm (see odk_form.php).
    $form = @$forms->forms[$form_field[0]];
    if (!$form) return 'unknown form "' . $form_field[0] . '"';
    // OdkForm::mapping maps form field to table/column (see odk_form.php).
    $table_column = @$form->mapping[$form_field[1]];
    if (!$table_column) return 'unknown field "' . implode('\\', $form_field) . '"';

    return array(
        'table' => $table_column[0],
        'column' => $table_column[1],
        'values' => $values
    );
}

/**
 * Gets called in the "display" part of index.php and renders the doughnuts
 * if the view "doughnut" is selected and the user has the "data" access right.
 */
function doughnut_display($args) {
    global $user, $config, $show, $doughnut_i;
    // Show doughnut view?
    if (!in_array('data', $user['rights'])) return;
    if ($show !== 'doughnut') return;
    if (!array_key_exists('doughnut', $config->plugins)) {
        alert('Missing sheet "doughnut" in configuration', 'warning');
        return;
    }

    // Parse doughnut config lines.
    foreach($config->plugins['doughnut'] as $name=>$config_string) {
        $config_or_error = doughnut_config($config_string);
        if (gettype($config_or_error) === 'string') {
            // Function returns string in case of parse error.
            alert('Error config doughnut ' . $name . ' : ' .
                  $config_or_error, 'error');
        } else {
            doughnut_render($name, $config_or_error);
        }
    }
    // Close row if necessary -- see doughnut_render
    if ($doughnut_i % 3 !== 0) echo '</div>';
}

/**
 * Includes the Chart.js code into the current page if the view "doughnut"
 * is selected (see `doughnut_display`).
 */
function doughnut_dump_headers($args) {
    global $show;
    if ($show !== 'doughnut') return;
    echo '<script src="plugins/doughnut_chart.js"></script>';
}

/**
 * Adds a "doughnut" menu item if the user has the "data" access right and
 * the configuration sheet "doughnut" exists.
 */
function doughnut_augment_views($args) {
    global $self, $user, $config;
    $views =& $args['views'];
    if (!in_array('data', $user['rights'])) return;
    if (!array_key_exists('doughnut', $config->plugins)) return;
    array_push($views, 'doughnut');
}

// Install hooks -- see plugins.php
$hooks->register('dump_headers', 'doughnut_dump_headers');
$hooks->register('augment_views', 'doughnut_augment_views');
$hooks->register('display', 'doughnut_display');

