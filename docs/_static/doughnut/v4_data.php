<?php

function doughnut_dump_headers($args) {
    echo '<script src="plugins/doughnut_chart.js"></script>';
}

function doughnut_query($config) {
    $query = 'SELECT $column AS value, COUNT($column) AS count ' .
             'FROM $table GROUP BY $column ORDER BY $column';
    $query = str_replace('$table', $config['table'], $query);
    $query = str_replace('$column', $config['column'], $query);
    return $query;
}

function doughnut_json($curs) {
    $data = array();
    $colors = ['red', 'green', 'blue', 'yellow', 'magenta', 'purple', 'orange'];
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

function doughnut_render($name, $config) {
    global $conn;

    $id = 'doughnut_' . $name;

    $query = doughnut_query($config);
    $curs = mysql_query_($query, $conn);
    if ($curs === FALSE) {
        alert('MySQL error : ' . mysql_error(), 'error');
        return;
    }
    $json = doughnut_json($curs);
?>
    <div class="row">
        <br><br>
        <div class="span12">
            <center>
                <canvas id="<?php echo $id; ?>" style="width:100%"></canvas>
            </center>
            <h4 style="text-align:center"><?php echo $name; ?></h4>
            <script type="text/javascript">
                // See www.chartjs.org/docs/#doughnut-pie-chart
                new Chart(document.getElementById('<?php echo $id; ?>').getContext('2d'))
                    .Doughnut(<?php echo $json; ?>);
            </script>
        </div>
    </div>
<?php
}

function doughnut_config($config) {
    global $forms;
    // We only expect one part but allow more values for later versions.
    $parts = explode(' ', $config);
    if (count($parts) < 1) return 'expected "FORM\\FIELD ..."';
    $form_field = explode('\\', $parts[0]);
    if (count($form_field) !== 2) return 'expected "FORM\\FIELD ..."';

    // $forms->forms is array that maps formid to OdkForm (see odk_form.php).
    $form = @$forms->forms[$form_field[0]];
    if (!$form) return 'unknown form "' . $form_field[0] . '"';
    // OdkForm::mapping maps form field to table/column (see odk_form.php).
    $table_column = @$form->mapping[$form_field[1]];
    if (!$table_column) return 'unknown field "' . implode('\\', $form_field) . '"';

    return array(
        'table' => $table_column[0],
        'column' => $table_column[1],
    );
}

function doughnut_display($args) {
    global $show, $user, $config;
    if (!in_array('data', $user['rights'])) return;
    if ($show !== 'doughnut') return;
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
}

function doughnut_augment_views($args) {
    global $user;
    if (!in_array('data', $user['rights'])) return;
    $views =& $args['views'];
    array_push($views, 'doughnut');
}

$hooks->register('dump_headers', 'doughnut_dump_headers');
$hooks->register('augment_views', 'doughnut_augment_views');
$hooks->register('display', 'doughnut_display');


