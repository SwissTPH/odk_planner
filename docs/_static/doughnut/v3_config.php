<?php

function doughnut_dump_headers($args) {
    echo '<script src="plugins/doughnut_chart.js"></script>';
}

function doughnut_render($name, $config) {

    $id = 'doughnut_' . $name;
?>
    <div class="row">
        <br><br>
        <div class="span12">
            <center>
                <canvas id="<?php echo $id; ?>" style="width:100%"></canvas>
            </center>
            <h4 style="text-align:center"><?php echo $name; ?></h4>
            <script type="text/javascript">
                // Data copied from www.chartjs.org/docs/#doughnut-pie-chart
                new Chart(document.getElementById('<?php echo $id; ?>').getContext('2d'))
                    .Doughnut([{ value: 300, color:"#F7464A", highlight: "#FF5A5E", label: "Red" }, { value: 50, color: "#46BFBD", highlight: "#5AD3D1", label: "Green" }, { value: 100, color: "#FDB45C", highlight: "#FFC870", label: "Yellow" }]);
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

