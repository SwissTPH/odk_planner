<?php
if (!defined('MAGIC')) die('!?');


/**
 * processes a part of rows across different tables and generate a
 * overview table where every original is reduced to a table cell and
 * all rows from the same table are arranged into one column. the
 * <code>id_column</code> is displayed in the table row header
 */ 

class OverviewTable {

    /**
     * table column headers
     */
    var $formids;

    /**
     * indexed by <code>[pat_id][form_id][uri]</code> -- every cell contains
     * a dictionary with extracted data (i.e. submission date, uid, and any
     * additional values used for cross referencing)
     */
    var $data;

    /**
     * sparse table indexed by <code>[pat_id][form_id]</code> that contains
     * ordered list of colorinfos (elements from <code>$colors</code> in the
     * constructor call).
     */
    var $colored;

    /**
     * creates a html overview table with links and background coloring
     * using the provided data
     *
     * @param name string what this table is called
     * @param colors mixed the coloring information (see <code>config.php</code>)
     *     also specifies what fields should be extracted from the different
     *     tables
     * @param id_range array character range (<code>[start, stop]</code>) to
     *     extract from <code>id_column</code> to join rows from tables
     */
    function OverviewTable($name, $colors, $id_range, $condition=null) {
        $this->name = $name;
        $this->colors = $colors;
        $this->id_range = $id_range;
        $this->condition = $condition;
    }

    /**
     * generates the overview for the specified subset of rows from every
     * table/forms
     *
     * @param conn resource MySQL connection
     * @param id_rlike string MySQL <code>RLIKE</code> expression for the field
     *     <code>id_column</code>
     * @param forms OdkDirectory available forms
     * @param formids mixed array of indexes of forms to be included in overview
     */
    function collect_data($conn, $forms, $formids, $id_rlike) {

        $this->formids = array();
        foreach($formids as $formid) {
            if ($forms->get($formid)) {
                array_push($this->formids, $formid);
            } else {
                alert("could not include $formid in overview : .xls not uploaded", 'error');
            }
        }

        # the overview table reads value from the database table by table
        # which corresponds to a column in the overview table -- if values
        # are referenced within a row of the overview table, these must
        # therefore be fetched from other tables and cached so they can be
        # accessed in the end to generate the overview.

        $params = array(); # cached values from table cross referencing
        foreach($formids as $formid) {
            $form = $forms->get($formid);
            if (!$form) {
                alert("form '$formid' not found", 'danger');
                continue;
            }
            $params[$formid] = array($form->id_column, $form->date_column);
        }
        $conditions = array();
        if ($this->condition) {
            array_push($conditions, $this->condition);
        }
        foreach($this->colors as $form2=>$colorinfos) {
            foreach($colorinfos as $colorinfo) {
                if ($colorinfo['condition']) {
                    array_push($conditions, $colorinfo['condition']);
                }
            }
        }
        foreach($conditions as $condition) {
            foreach($condition->get_params() as $formid=>$columns) {

                if (!array_key_exists($formid, $params)) {
                    $form = $forms->get($formid);
                    if (!$form) {
                        alert("form '$formid' not found", 'danger');
                        continue;
                    }
                    $id_column = $forms->get($formid)->id_column;
                    $date_column = $forms->get($formid)->date_column;
                    $params[$formid] = array($id_column, $date_column);
                }

                foreach($columns as $column) {
                    if (!in_array($column, $params[$formid])) {
                        array_push($params[$formid], $column);
                    }
                }
            }
        }
        #echo '<pre>', htmlentities(print_r($params, true)), '</pre>';

        # populate $this->data array form by form
        $this->data = array();
        foreach($params as $formid=>$columns) {

            $form = $forms->get($formid);

            if (!$form) {
                # alert already displayed above
                continue;
            }

            try {
                $ret = $form->get_rlike($conn, $id_rlike, $columns);
                foreach($ret as $uri=>$data) {

                    # there should not be more than one row -- if there is, show
                    # link to each of them; but which values are used for coloring
                    # dependent cells is random...

                    $patid = substr($data[$form->id_column],
                        $this->id_range[0],
                        $this->id_range[1]);
                    $timestamp = $data[$form->date_column];

                    if (!array_key_exists($patid, $this->data)) {
                        $this->data[$patid] = array();
                    }
                    if (!array_key_exists($formid, $this->data[$patid])) {
                        $this->data[$patid][$formid] = array();
                    }
                    $this->data[$patid][$formid][$uri] = $data;

                }
            } catch(OdkException $e) {
                alert("could not get entries for $formid : ".$e->getMessage(), 'error');
            }
        }
        # sort rows
        ksort($this->data);

        # filter based on $this->condition
        if ($this->condition) {
            $patids = array_keys($this->data);
            for($i=0; $i<count($patids); $i++)
                if (!$this->condition->evaluate($this->data[$patids[$i]]))
                    unset($this->data[$patids[$i]]);
        }

        # now loop through table again and populate $this->colored
        # of course, this will eventually be done with MySQL queries...
        $this->colored = array();
        $cols = $this->formids;
        array_unshift($cols, '*'); # that's the row header
        foreach($this->data as $patid=>$row) {

            foreach($cols as $formid) {
                if (!array_key_exists($formid, $this->colors)) {
                    continue;
                }

                if (array_key_exists($formid, $this->data[$patid])) {
                    $timestamp = 0;
                    foreach($this->data[$patid][$formid] as $uri=>$data) {
                        $date_column = $forms->get($formid)->date_column;
                        $timestamp = max($timestamp, $data[$date_column]);
                    }
                } else {
                    $timestamp = time();
                }

                # iterate through colors
                $matches = array();
                foreach($this->colors[$formid] as $colorinfo) {

                    # check conditions
                    $condition = $colorinfo['condition'];
                    if ($condition && !$condition->evaluate($row)) {
                        continue;
                    }

                    $form1 = $colorinfo['form1'];
                    $delay = $colorinfo['delay'];

                    // relative to other form : check delay
                    if ($form1) {
                        if (!array_key_exists($form1, $row)) {
                            continue;
                        }
                        $timestamp1 = 0;
                        foreach($this->data[$patid][$form1] as $uri=>$data) {
                            $timestamp1 = max($timestamp1, strtotime(
                                $data[$forms->get($form1)->date_column]));
                        }
                        $dt = intval(($timestamp-$timestamp1)/24/3600);
                        if ($dt < $delay) {
                            continue;
                        }
                    }

                    // all conditions met ==> attach colorinfo
                    array_push($matches, $colorinfo);
                }

                if ($matches) {
                    if (!array_key_exists($patid, $this->colored)) {
                        $this->colored[$patid] = array();
                    }
                    $this->colored[$patid][$formid] = $matches;
                }
            } # foreach($forms ...
        } # foreach($this->data ...

    }

    /**
     * generates the HTML output of the overview. callbacks should return
     * content of table cell
     *
     * @param cell_cb function will be called with arguments
     *     <code>$row_header, $column_header, [$data1, ...]</code>
     * @param row_cb function will be called with arguments
     *     <code>$row_header</code>
     * @param column_cb function will be called with arguments
     *     <code>$column_header</code>
     * @param header_repeat_n every how many rows the header should be
     *     repeated
     */
    function generate_html($cell_cb = null, $row_cb = null, $column_cb = null, $header_repeat_n = 10) {
        global $user;

        $null_cb = function ($x) {
            return $x;
        };
        if ($cell_cb   === null) $cell_cb   = $null_cb;
        if ($row_cb    === null) $row_cb    = $null_cb;
        if ($column_cb === null) $column_cb = $null_cb;

        # output table header
        echo '<table class="table overview-table filtered" data-name="' . 
            htmlentities($this->name). '">' . "\n";
        echo "<tr>";
        echo "<td class=topleft></td>";
        $header = "<tr><td></td>";
        foreach($this->formids as $formid) {
            $cell = "<th>" . $column_cb($formid) . "</th>";
            $header .= $cell;
            echo $cell;
        }
        echo "</tr>\n";
        $header .= "</tr>\n";

        $i = 0;
        $cols = $this->formids;
        array_unshift($cols, '*'); # that's the row header
        foreach($this->data as $patid=>$row) {
            echo "<tr>\n";

            foreach($cols as $formid) {

                # gather data from colorinfo
                $styles = array();
                $lists = array();
                $rows = array();
                if (array_key_exists($patid, $this->colored) &&
                    array_key_exists($formid, $this->colored[$patid])) {
                        # last overrides
                        foreach($this->colored[$patid][$formid] as $colorinfo) {
                            if ($colorinfo['style']) {
                                array_push($styles, $colorinfo['style']);
                            }
                            if ($colorinfo['list']) {
                                array_push($lists, $colorinfo['list']);
                            }

                            if ($colorinfo['config_xls_row']) {
                                array_push($rows, $colorinfo['config_xls_row']);
                            }
                        }
                    }
                $style = '';
                if ($styles) {
                    $style = 'style="' . implode(';', $styles) . '"';
                }

                # start tag
                if ($formid === '*') {
                    echo "\t<th class=column-header";
                } else {
                    echo "\t<td $style";
                }

                # output data attributes
                echo " " . $style;
                if ($lists) {
                    echo ' data-list="' . implode(',', $lists) . '"';
                }
                if ($rows) {
                    echo ' data-row="' . implode(',', $rows) . '"';
                }
                echo ">";

                # output colored content div & popover if colored
                if ($lists) {
                    echo '<div class=popovered data-content="<div class=popover-reset>';
                    for($j = 0; $j<count($lists); $j++) {
                        echo str_replace("\n", ';', str_replace('"', "'", $lists[$j]));
                        if (in_array('admin', $user['rights'])) {
                            echo ' (config.xls:' . $rows[$j] . ')';
                        }
                        echo '<br />';
                    }
                    echo '</div>">';
                }

                # output actual content
                if ($formid === '*') {
                    echo $row_cb($patid);
                } else {
                    if (array_key_exists($formid, $row)) {
                        echo $cell_cb($patid, $formid, $row[$formid]);
                    }
                }

                # close content div if colored
                if ($lists) {
                    echo '&nbsp;</div>';
                }

                # end tag
                if ($formid === '*') {
                    echo "</th>\n";
                } else {
                    echo "</td>\n";
                }
            }
            echo "</tr>\n";

            $i++;
            if ($header_repeat_n !== 0 && $i % $header_repeat_n === 0) {
                echo $header;
            }
        }
        echo "</table>\n";
    }

}

?>
