<?php # vim: set fdm=marker:
if (!defined('MAGIC')) die('!?');


function cxrv_render_form_data_row($args) {

    global $self;

    $formid = $args['formid'];
    $path = $args['path'];
    $rowid = $args['rowid'];
    $field = $args['field'];
    $value =& $args['value'];

    if ($field['type'] === 'image') {
        $href = "$self?show=cxrv";
        $href.= "&formid=".rawurlencode($formid);
        $href.= "&path=".rawurlencode($path);
        $href.= "&rowid=".rawurlencode($rowid);
        $label = "<i class=icon-camera></i> view as CXR";
        $link = "<a href=\"$href\" class=\"btn btn-mini\" target=_cxrv>$label</a>";
        $value = "$value $link";
    }
}


function cxrv_dump_headers($args) {
    global $_REQUEST;

    if(@$_REQUEST['show'] !== 'cxrv')
        return;
?>

<?php
}

function cxrv_early_action($args) {

    global $_REQUEST, $user, $config, $self, $conn, $forms;

    if(@$_REQUEST['show'] !== 'cxrv')
        return;

    $formid = $_REQUEST['formid'];
    $rowid  = $_REQUEST['rowid' ];
    $path   = $_REQUEST['path'];

    $href = "$self?image";
    $href.= "&formid=".rawurlencode($formid);
    $href.= "&path=".rawurlencode($path);
    $href.= "&rowid=".rawurlencode($rowid);

    try {
        $form = $forms->forms[$formid];
        $data = $form->get_values($conn, $rowid, $user['access']);
        $id = $data[$form->id_column];
    } catch (OdkException $e) {
        $id = '(error)';
    }

?>
<head>

    <title>odk_planner CXR viewer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="bootstrap/js/jq171.js"></script>
    <script src="plugins/cxrv.js"></script>

    <script type="text/javascript">
        $(function() {

            $('#cxrv-cxr').cxr({
                $overlay: $('#cxrv-controls'),
                bind_keys: false
            });

            $('#cxrv-controls .bar').click(function() {
                $('#cxrv-cxr').cxr('reset_window');
            });
            $('#cxrv-window-left').click(function() {
                $('#cxrv-cxr').cxr('window_left');
            });
            $('#cxrv-window-right').click(function() {
                $('#cxrv-cxr').cxr('window_right');
            });
            $('#cxrv-window-narrow').click(function() {
                $('#cxrv-cxr').cxr('window_narrow');
            });
            $('#cxrv-window-widen').click(function() {
                $('#cxrv-cxr').cxr('window_widen');
            });

            $('#cxrv-zoom-in').click(function() {
                $('#cxrv-cxr').cxr('zoom_in');
            });
            $('#cxrv-zoom-out').click(function() {
                $('#cxrv-cxr').cxr('zoom_out');
            });
            $('#cxrv-turn-left').click(function() {
                $('#cxrv-cxr').cxr('turn_left');
            });
            $('#cxrv-turn-right').click(function() {
                $('#cxrv-cxr').cxr('turn_right');
            });

        });
    </script>

    <style type="text/css">

        body {
            background: black;
            font-family: Helvetica, Arial, sans;
        }

        #cxrv-controls {
            color: black;
            background-color: rgba(255, 255, 255, 0.6);
            box-sizing: border-box;
            padding: 1rem;
            margin-bottom: 2rem;
            position:fixed;
            top:20px;
            left:20px;
            width:200px;
        }

        .cxrv-control {
            clear:both;
            margin-top: 1rem;
            width: 100%;
            text-align: center;
        }

        .cxrv-help {
            margin-top: 1rem;
            color:#444;
        }

        #cxrv-controls h4 {
            text-align:center;
        }

        #cxrv-controls .bar {
            margin-top: 1rem;
            border-radius: 0.5rem;
            border: #776be0 solid 1px;
            cursor: pointer;
            position: relative;
            width: 100%;
            height: 30px;
        }

        #cxrv-controls .bar .window {
            border-radius: 0.5rem;
            border: #776be0 solid 1px;
            margin-top: -1px;
            position: absolute;
            height: 30px;
            width: 30%;
            left: 20%;
        }

        #cxrv-controls button {
            text-decoration: none;
            color: black;
            margin: 0;
            padding: 0 10px;
            border: 0;
            background-color: rgba(0, 0, 0, 0);
            outline: 0;
            user-select: none;
            box-sizing: border-box;
            vertical-align: middle;
            border: 1px solid #333;
            border-radius: 30px;
            font-size: 1rem;
            width: 2rem;
            height: 2rem;
        }

    </style>
</head>

<body>

    <img src="<?php echo $href; ?>" id=cxrv-cxr />

    <div id=cxrv-controls>

        <h4><?php echo $id; ?></h4>

        <div class=cxrv-control>

            <button class=bar-button id=cxrv-window-left>&#8592;</button>
            <button class=bar-button id=cxrv-window-widen>&#8593;</button>
            <button class=bar-button id=cxrv-window-narrow>&#8595;</button>
            <button class=bar-button id=cxrv-window-right>&#8594;</button>

            <div class=bar>
                <div class=window>&nbsp;</div>
            </div>
        </div>

        <div class=cxrv-control>
            <button id=cxrv-zoom-out>&ndash;</button>
            zoom: <span class=zoom>100%</span>
            <button id=cxrv-zoom-in>+</button>
        </div>

        <div class=cxrv-control>
            <button id=cxrv-turn-left>L</button>
            angle: <span class=angle>0</span>&deg;
            <button id=cxrv-turn-right>R</button>
        </div>

        <p class=cxrv-help>
            Drag image to change contrast/brigtness. Shift-scroll to zoom.
        </p>

    </div>

</body>
<?php
    exit;
}


$hooks->register('render_form_data_row', 'cxrv_render_form_data_row');
$hooks->register('early_action', 'cxrv_early_action');

?>
