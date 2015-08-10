<?php

function doughnut_dump_headers($args) {
    echo '<script src="plugins/doughnut_chart.js"></script>';
}

function doughnut_display($args) {
    global $show;
    if ($show !== 'doughnut') return;
?>
    <div class="row">
        <br><br>
        <div class="span12">
            <center>
                <canvas id="doughnut" style="width:100%"></canvas>
            </center>
            <h4 style="text-align:center">doughnut</h4>
            <script type="text/javascript">
                // Data copied from www.chartjs.org/docs/#doughnut-pie-chart
                new Chart(document.getElementById('doughnut').getContext('2d'))
                    .Doughnut([{ value: 300, color:"#F7464A", highlight: "#FF5A5E", label: "Red" }, { value: 50, color: "#46BFBD", highlight: "#5AD3D1", label: "Green" }, { value: 100, color: "#FDB45C", highlight: "#FFC870", label: "Yellow" }]);
            </script>
        </div>
    </div>
<?php
}

function doughnut_augment_views($args) {
    $views =& $args['views'];
    array_push($views, 'doughnut');
}

$hooks->register('dump_headers', 'doughnut_dump_headers');
$hooks->register('augment_views', 'doughnut_augment_views');
$hooks->register('display', 'doughnut_display');
