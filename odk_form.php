<?php
if (!defined('MAGIC')) die('!?');

require_once 'lib/phpexcelreader/Excel/reader.php';

class ValidationException extends Exception
{
}

function validate_formid($formid) {
    if (!preg_match("/^[0-9a-z_]+$/i", $formid))
        throw new ValidationException('invalid format for formid');
}

function validate_path($path) {
    if (!preg_match("/^[0-9a-z_]+$/i", $path))
        throw new ValidationException('invalid format for path');
}

function validate_rowid($rowid) {
    if (!preg_match("/^uuid:".
        "[0-9a-f]{8}-".
        "[0-9a-f]{4}-".
        "[0-9a-f]{4}-".
        "[0-9a-f]{4}-".
        "[0-9a-f]{12}$/i", $rowid))
        throw new ValidationException('invalid format for rowid');
}

/**
 * Exception risen in various data-returning methods of OdkForm
 */
class OdkException extends Exception
{
    /**
     * if <code>$mysql_error=true</code> the output of function
     * <code>mysql_error()</code> is appended to the exception message
     */
    public function __construct($message, $mysql_error = false, $code = 0, Exception $previous = null) {
        if ($mysql_error)
            $message .= ' -- mysql_error="'.mysql_error().'"';
        parent::__construct($message, $code, $previous);
    }
}

/**
 * a simple file based cache that saves/restores multiple subsets of
 * properties of an object
 */

class JsonCache {

    /**
     * @param fname source file name (its mtime is used to determine whether
     *        values in the cache are to be used or not) -- even if no "source file"
     *        exists, this name is prepended to <code>'.cache.json'</code> to generate
     *        the name of the cache file (but then the <code>$mtime</code> parameter
     *        should be used when calling <code>newer()</code>)
     * @param obj the object that provides the properties to be cached
     * @param use_cache setting this to false will make the properties be only
     *        saved to disk and never restored (use e.g. to force cache refresh)
     */
    function JsonCache($fname, $obj, $use_cache=true) {
        $this->fname = $fname;
        $this->cfname= $fname . '.cache.json';
        $this->obj = $obj;
        $this->use_cache = $use_cache;
        $this->cache = null;

        profile_start('json_cache_load');
        if (is_file($this->cfname)) {
            $fh = fopen($this->cfname, 'r');
            $this->cache = json_decode(fgets($fh), true);
            fclose($fh);
        } else {
            $this->cache = array();
        }
        profile_stop('json_cache_load');
    }

    /**
     * @return <code>true</code> if values under <code>$prefix</code>
     *         are newer in cache than in source (determined by either
     *         <code>$mtime</code> provided or mtime of <code>$this-&gt;fname</code>
     */
    function newer($prefix, $mtime=null) {
        if(!$this->use_cache || !array_key_exists($prefix, $this->cache))
            return false;
        if ($mtime === null) {
            if ($this->fname and file_exists($this->fname)) {
                $mtime = filemtime($this->fname);
            } else {
                $mtime = 0;
            }
        }
        /*
        if (!array_key_exists('$mtime', $this->cache[$prefix])) {
            echo $this->fname, "\n";
            echo $prefix, "\n";
            print_r($this->cache);
            exit;
        }
         */
        return $mtime < $this->cache[$prefix]['$mtime'];
    }

    /**
     * writes cache to disk; normally called in every <code>save()</code>
     */
    function flush() {
        profile_start('json_cache_flush');
        $fh = fopen($this->cfname, 'w');
        fwrite($fh, json_encode($this->cache));
        fclose($fh);
        profile_stop('json_cache_flush');
    }

    /**
     * saves all properties with names in <code>$names</code> of object
     * <code>$this-&gt;obj</code> into cache under prefix <code>$prefix</code>
     */
    function save($prefix, $names, $flush=true) {
        $this->cache[$prefix] = array();
        foreach($names as $name) {
            $this->cache[$prefix][$name] = $this->obj->$name;
        }
        $this->cache[$prefix]['$mtime'] = time();
        if ($flush) $this->flush();
    }

    /**
     * loads all properties with names in <code>$names</code> under prefix
     * <code>$prefix</code> from cache into properties of <code>$this-&gtobj</code>
     */
    function load($prefix) {
        foreach($this->cache[$prefix] as $key=>$value) {
            $this->obj->$key = $value;
        }
    }

}


/**
 * helper class that represents a node in the tree of the FormDataModel
 *
 * a node is identified by its "path" that is an array of enclosing
 * group names, ending with the element's name
 * (e.g. <code>[outer_group, inner_group, field_name]</code>)
 */
class FormDataNode {

    /**
     * create new node
     *
     * @param string uri identifying this node
     * @param string parent_uri <code>uri</code> of node of which this node is a child
     * @param array data node data
     */
    function FormDataNode($uri, $parent_uri, $data, $children) {
        $this->uri = $uri;
        $this->parent_uri = $parent_uri;
        $this->data = $data;
        if (!$children) $children = array();
        $this->children = $children;
        $this->parent = null;
    }

    /**
     * adds <code>$child</code> to the children of this node
     */
    function add($child) {
        if (!in_array($child, $this->children))
            array_push($this->children, $child);
    }

    /**
     * tries to add <code>$node</code> as a child node to this node or any of its
     * descendants
     *
     * @return <code>true</code> if node could be attached
     */
    function try_add_rek($node) {
        if ($this->uri === $node->parent_uri) {
            $this->add($node);
            return true;
        }
        foreach($this->children as $child)
            if ($child->try_add_rek($node))
                return true;
        return false;
    }

    /**
     * returns the node corresponding to the specified field or
     * <code>NULL</code> if not found.
     *
     * @param array path path of a field (i.e. <code>[group1, ..., field_name]</code>)
     */
    function get($path) {
        if (!$path) return $this;
        $name = array_shift($path);
        foreach($this->children as $child)
            if ($child->data['name'] === $name)
                return $child->get($path);
        return NULL;
    }

    /**
     * get array of paths (<code>[group1, ..., field_name]</code>) under this
     * node
     */
    function paths(&$ret=null, $path=null) {
        if ($ret === null) $ret = array();
        if ($path === null) {
            // don't include name of root element
            $path = array();
        } else {
            array_push($path, $this->data['name']);
        }
        if ($this->data['type'] === 'GROUP') {
            // descend only into groups (and not images, geopoints, ...)
            foreach($this->children as $child)
                $child->paths($ret, $path);
        } else {
            if ($this->data['column'] !== 'META_INSTANCE_ID' &&
                strpos($this->data['name'], 'generated_table_list_label_') !== 0 &&
                strpos($this->data['name'], 'reserved_name_for_field_list_labels_') !== 0)
                array_push($ret, $path);
        }
        return $ret;
    }
}

/**
 * represents data contained in the `_form_data_model` table
 *
 * the table `_form_data_model` describes the hierarchical structure of
 * the fields from the different forms contained in the database. every form
 * is the root node of one of these trees (<code>$roots</code>).
 */
class FormDataModel {

    /**
     * array of root nodes (<code>FormDataNode</code>) of the different forms.
     * every node contains a data array with indices <code>type</code>,
     * <code>name</code>, <code>table</code>, <code>column</code>.
     *
     * remarks about structure:
     *
     * <ul>
     *
     * <li>
     * the root nodes have the forms filename as <code>name</code> and
     * <code>type="GROUP"</code>
     * </li>
     *
     * <li>
     * groups also have <code>type="GROUP"</code> and <code>column=NULL</code>
     * </li>
     *
     * <li>
     * <code>type="GEOPOINT"</code> also have <code>column=NULL</code> and
     * contain four child nodes with the location specification
     * (with <code>type=DECIMAL</code>).
     * </li>
     *
     * <li>
     * select_one have <code>type="STRING"</code>
     * </li>
     *
     * <li>
     * select_multiple have <code>type="SELECTN"</code>, specify the additional
     * table in <code>table</code> and have <code>column=NULL</code>
     * </li>
     *
     * <li>
     * image have <code>type="BINARY"</code>, specify the additional table in
     * in <code>table</code>, have <code>column=NULL</code>, and contain a
     * child-node <code>type="BINARY_CONTENT_REF_BLOB"</code> and finally a
     * grandchild-node <code>type="REF_BLOB"</code> with each a new
     * <code>table</code> and <code>column=NULL</code>
     * </li>
     *
     * </ul>
     */
    var $roots = array();

    /**
     * reads out the table `_form_data_model` and fills the array
     * <code>$this-&gt;roots</code> accordingly
     */
    function FormDataModel($conn) {

        $ret = mysql_query("SELECT _URI, PARENT_URI_FORM_DATA_MODEL, ".
            "ELEMENT_TYPE, ELEMENT_NAME, PERSIST_AS_TABLE_NAME, PERSIST_AS_COLUMN_NAME ".
            "FROM _form_data_model");

        $hash = array();
        $waiting = array();

        while($row = mysql_fetch_row($ret)) {
            $uri = $row[0];
            $parent_uri = $row[1];
            $data = array(
                'type'   => $row[2],
                'name'   => $row[3],
                'table'  => $row[4],
                'column' => $row[5]
            );

            profile_start('FormDataModel:add');

            $node = new FormDataNode($uri, $parent_uri, $data, @$waiting[$uri]);
            unset($waiting[$uri]);
            $hash[$uri] = $node;

            if (substr($parent_uri, 0, 5) === 'uuid:') {
                array_push($this->roots, $node);
            } else {
                if (isset($hash[$parent_uri])) {
                    $hash[$parent_uri]->add($node);
                } else {
                    if (!isset($waiting[$parent_uri]))
                        $waiting[$parent_uri] = array();
                    array_push($waiting[$parent_uri], $node);
                }
            }

            profile_stop('FormDataModel:add');
        }
    }

    /**
     * returns the root path with the specified form name; optionally
     * a field can be specified, in which case the node corresponding to
     * the field is returned (equivalent to calling <code>get()</code> of the
     * root node). if the form (/field) is not found, <code>NULL</code> is
     * returned
     *
     * @param string name name of the form
     * @param array path path of a field (i.e. <code>[group1, ..., field_name]</code>)
     */
    function get($name, $path=null) {
        foreach($this->roots as $root) {
            if ($name === $root->data['name']) {
                if ($path !== null) {
                    return $root->get($path);
                } else {
                    return $root;
                }
            }
        }
        return NULL;
    }

    /**
     * get array of root form names
     */
    function names() {
        $f = function($root) { return $root->data['name']; };
        return array_map($f, $this->roots);
    }

    /**
     * helper function for method <code>dump()</code>
     */
    function dump_rek($node, $prefix='') {
        $uri = $node->uri;
        $name = $node->data['name'];
        $type = $node->data['type'];
        $table = $node->data['table'];
        $column = $node->data['column'];
        echo "$prefix$uri : $name ($type) $table.$column\n";
        foreach($node->children as $child)
            $this->dump_rek($child, $prefix . '  ');
    }

    /**
     * dump information about all data found
     */
    function dump() {
        echo '<pre>';
        foreach($this->roots as $root) {
            $this->dump_rek($root);
            echo "----\n";
        }
        echo '</pre>';
    }
}


/**
 * Parse an Excel file that was used (using {@link http://opendatakit.org/use/xlsform/
 * XLSForm}) to create the .xml file as uploaded to Aggregate. The Description of the
 * Excel file can be used to structure/display data contained in the database.
 *
 * some remarks about the database structure :
 *
 * <ul>
 * <li>long forms spread across <code>FORM_CORE, FORM_CORE2, ...</code></li>
 * <li>column name is <code>GROUPNAME_FIELDNAME</code> and unique across
 *     all cores</li>
 * <li><code>_URI</code> is unique key in every core</li>
 * <li>cores are linked with via <code>FORM_COREx._TOP_LEVEL_AURI=FORM_CORE._URI</code></li>
 * </ul>
 */

class OdkForm {

    /**
     * the FORM_ID as taken from the .xls settings sheet (and UPPERCASED)
     */
    var $id;

    /**
     * the title as taken from the .xls settings sheet
     */
    var $title;

    /**
     * .xls file name
     */
    var $fname;

    /**
     * the name of the form -- i.e. <code>$fname</code> without extension. this
     * name is used the root nodes in FormDataModel
     */
    var $name;

    /**
     * character that is used to connect the group to the field name to construct
     * the <code>PATH</code>
     */
    var $lig = '_';

    /**
     * dictionary of fields, indexed by <code>PATH</code>
     *
     * every field is itself is a dictionary with some attributes
     * (such as <code>name, type, label, hint</code> from the .xls
     * file)
     */
    var $fields;

    /**
     * array of groups as found in .xls file; every group itself consists of
     * array starting with group name then COLUMNNAMES (as keys in <code>$columns</code>)
     *
     * fields not within a group are storead as string in the <code>$groups</code>
     * array
     */
    var $groups;

    /**
     * (UPPERCASED) column name of field identifying record
     *
     * this is not neccessarily a unique identifier for a row, because ODK lets
     * users upload a form with the same "id" several times...
     */
    var $id_column;

    /**
     * (UPPERCASED) column name of field containing submission date
     *
     * odk_planner will use this field to identify when a form was created;
     * if the field specified by its name is not found it will automatically
     * fall back on the submission date (_SUBMISSION_DATE, generated by ODK)
     */
    var $date_column = '_SUBMISSION_DATE';

    /**
     * indicates whether <code>match()</code> has already been called
     */
    var $matched;

    /**
     * associative array that maps a <code>PATH</code> to <code>[TABLE, COLUMN]</code>.
     *
     * <ul>
     * <li>for select_multiple, COLUMN is NULL</li>
     * <li>for images, only the common part of TABLE is saved (i.e. without 
     *     <code>_{BN|BLOB|REF}</code>)</li>
     * <li>for geopoints, only the common part of COLUMN is saved (i.e. without
     *     <code>_{LNG|LAT|ALT|ACC}</code>)</li>
     * </ul>
     *
     * (filled when <code>match()</code> is called)
     */
    var $mapping;

    /**
     * list of columns that were found in database but not listed in .xls file
     */
    var $db_only;

    /**
     * list of fields that are listed in .xls file but were not found in database
     */
    var $xls_only;

    /**
     * initialize new form from .xls file
     *
     * @param string fname where to find Excel file describing form
     * @param string id_name name of field to be used as ID
     * @param boolean use_cache whether to read values from cache
     *        (specifying <code>false</code> will force a cache refresh
     *        regardless of age of cache file -- do this e.g. after the
     *        configuration changed and invalidated the cache)
     */
    function OdkForm($fname, $id_name, $date_name, $use_cache=true) {

        // properties not set in constructor
        $this->fname = $fname;
        $this->name = basename($fname, '.xls');
        $this->matched = false;
        $this->mapping = array();

        // load from cache if newer than .xls
        $this->cache = new JsonCache($fname, $this, $use_cache);
        if ($this->cache->newer('constructor')) {
            $this->cache->load('constructor');
            return;
        }

        // read from .xls
        $wb = new Spreadsheet_Excel_Reader();
        $wb->read($fname);

        $settings = $this->sheet_by_name($wb, 'settings');
        if ($settings) {
            $this->id = strtoupper($this->lookup($settings, 2, 'form_id'));
            $this->title = $this->lookup($settings, 2, 'form_title');
        }

        $survey = $this->sheet_by_name($wb, 'survey');
        if ($survey) {
            $parts = array();
            $this->fields = array();
            $this->groups = array();
            $current_group = NULL;

            for($row=2; $row<=$survey['numRows']; $row++) {

                $type = $this->lookup($survey, $row, 'type');
                $name = $this->lookup($survey, $row, 'name');
                $label = $this->lookup($survey, $row, 'label');
                $hint = $this->lookup($survey, $row, 'hint');

                $access = $this->lookup($survey, $row, 'access');
                $access = array_map('trim', explode(',', $access));
                if (count($access) == 1 && strlen($access[0])==0)
                    $access[0] = 'default';

                if ($type === 'begin group') {
                    array_push($parts, $name);
                    $current_group = array($name);
                }
                else if ($type === 'end group') {
                    array_pop($parts);
                    array_push($this->groups, $current_group);
                    $current_group = NULL;
                }
                else if ($type) {

                    $group = implode('/', $parts);

                    array_push($parts, $name);
                    $path = strtoupper(implode($this->lig, $parts));
                    array_pop($parts);

                    $field = array(
                            'name' => $name,
                            'type' => $type,
                            'label' => $label,
                            'hint' => $hint,
                            'group' => $group,
                            'access' => $access,
                        );

                    $this->fields[$path] = $field;

                    if ($current_group === NULL)
                        array_push($this->groups, $path);
                    else
                        array_push($current_group, $path);

                    if (strtoupper($name) == strtoupper($id_name))
                        $this->id_column = strtoupper($path);
                    if (strtoupper($name) == strtoupper($date_name)) {
                        $date_column = strtoupper($path);
                        if ($date_column) {
                            $this->date_column = $date_column;
                        }
                    }
                }
            } // for($row ...
        } // if ($survey ...

        // save to cache
        $this->cache->save('constructor', array(
            'id', 'title', 'fields', 'groups', 'id_column', 'date_column'));
    }

    /**
     * returns sheet named <code>$name</code> in the excel working book
     * <code>$wb</code> (phpexcelreader object)
     */
    function sheet_by_name($wb, $name) {
        foreach($wb->boundsheets as $i=>$ws)
            if (strcasecmp($ws['name'], $name) == 0)
                return $wb->sheets[$i];
        return NULL;
    }

    /**
     * returns trimmed cell value
     */
    function lookup($ws, $row, $name) {
        if (array_key_exists(1, $ws['cells']) &&
            array_key_exists($row, $ws['cells']))

            for($col=1; $col<=$ws['numCols']; $col++)
                if (array_key_exists($col, $ws['cells'][1]) &&
                    strcasecmp($ws['cells'][1][$col], $name) == 0)

                    if (array_key_exists($col, $ws['cells'][$row]))
                        return ltrim(rtrim($ws['cells'][$row][$col]));
        return NULL;
    }

    /**
     * match columns in database described by <code>FormDataNode</code>
     * with fields in <code>.xls</code> form.  all fields that could not be
     * matched are saved in <code>$xls_only</code> and all database
     * columns that were not found in the excel form are saved in
     * <code>$db_only</code>
     *
     * call to this method fills in the object properties
     * <code>mapping</code>, <code>db_only</code>, <code>xls_only</code>
     *
     * values will be loaded from and stored to <code>$this=&gt;cache</code>
     *
     * @param resource $conn database connection
     * @param object $root root <code>FormDataNode</code>
     */
    function match($conn, $root) {

        profile_start('match_form');

        // load from cache if newer than .xls
        if ($this->cache->newer('match')) {
            $this->cache->load('match');
            return;
        }

        $paths = $root->paths();
        $imploded_paths = array();
        foreach($paths as $path)
            array_push($imploded_paths, strtoupper(implode($this->lig, $path)));

        $xls_only = array();
        foreach($this->fields as $path=>$field) {
            // search database column description matching xls field
            $found = false;
            foreach($paths as $i=>$db_path) {
                if ($imploded_paths[$i] === $path) {

                    $node = $root->get($paths[$i]);
                    $table = $node->data['table'];
                    $column = $node->data['column'];

                    if ($field['type'] === 'image')
                        $table = substr($table, 0, strrpos($table, '_'));
                    if ($field['type'] === 'geopoint') {
                        $column = $node->children[0]->data['column'];
                        $column = substr($column, 0, strrpos($column, '_'));
                    }

                    $this->mapping[$path] = array($table, $column);

                    unset($paths[$i]);
                    unset($imploded_paths[$i]);
                    $found = true;
                    break;
                }
            }
            if (!$found)
                array_push($xls_only, $path);
        }

        $db_only = array();

        $this->db_only = $imploded_paths;
        $this->xls_only = $xls_only;

        $this->matched = true;

        // save to cache
        $this->cache->save('match', array(
            'db_only', 'xls_only', 'matched', 'mapping'));

        profile_stop('match_form');
    }

    /**
     * finds a path; input <code>$name</code> can be the actual
     * column name or a ODK fieldname as specified in the .xls file
     * (i.e. without its group; first match is returned then)
     */
    function find_path($name) {
        $name = strtoupper($name);
        if (array_key_exists($name, $this->fields))
            return $name;
        foreach($this->fields as $path=>$field)
            if ($name === strtoupper($field['name']))
                return $path;
    }

    /**
     * get an array of URIs (mysql RLIKE)
     *
     * raises OdkException if error
     */
    function get_uris($conn, $id_rlike) {
        if (!$this->id_column)
            throw new OdkException("cannot get_uris because no id column found");
        if (!array_key_exists($this->id_column, $this->mapping))
            throw new OdkException("cannot get_uris because id column '".$this->id_column."' not found");
        $id_table = $this->mapping[$this->id_column][0];

        $sql = "SELECT _URI FROM $id_table WHERE `" .
            mysql_real_escape_string($this->id_column) . "` RLIKE '" .
            mysql_real_escape_string($id_rlike) . "'";

        $ret = mysql_query_($sql, $conn);
        if ($ret === FALSE)
            throw new OdkException("cannot get_uris", true);

        $uris = array();
        while($row = mysql_fetch_row($ret))
            array_push($uris, $row[0]);

        return $uris;
    }

    /**
     * get values limited by MySQL <code>WHERE</code> clause
     *
     * @param conn resource MySQL connection
     * @param where MySQL <code>WHERE</code> clause
     * @param mapping array like <code>$this-&gt;mapping</code>
     * @param acls mixed result is filtered following using given permissions
     *
     * @return associative array indexed by <code>_URI</code>
     *
     * raises OdkException if error
     */
    function get_where($conn, $where, $mapping=null, $acls=null) {

         if (!array_key_exists($this->id_column, $this->mapping))
             throw new OdkException("cannot get_where because id column '$this->id_column' not found");
         $id_table = $this->mapping[$this->id_column][0];

         $bt = function($table, $column) {
             return '`'.$table.'`.'.'`'.$column.'`';
         };

         $select_multiple = array(); # paths that will be processed later

         $select = array($bt($id_table, '_URI'));
         $keys = array(); # i.e. indexes in the returned $data
         $paths = array(); # i.e. indexes into $mapping
         $tables = array(); # table for JOIN

         foreach($mapping as $path=>$table_column) {
             $table = $table_column[0];
             $column = $table_column[1];
             if (array_key_exists($path, $this->fields)) {
                 $type = $this->fields[$path]['type'];
             } else {
                 $type = "";
             }

             if (strpos($type, 'select_multiple') === 0) {
                array_push($select_multiple, $path);
                continue; # we don't want to JOIN these tables

             } else if ($type === 'geopoint') {
                 # is actually two values
                 array_push($select, $bt($table, $column.'_LAT'));
                 array_push($keys, $path . '_LAT');
                 array_push($paths, $path);
                 array_push($select, $bt($table, $column.'_LNG'));
                 array_push($keys, $path . '_LNG');
                 array_push($paths, $path);

             } else if ($type === 'image') {
                 # in this case we want the filename
                 $table = $table . '_BN'; #FIXME should be done in match()
                 array_push($select, $bt($table, 'UNROOTED_FILE_PATH'));
                 array_push($keys, $path);
                 array_push($paths, $path);

             } else {
                 # default
                 array_push($select, $bt($table, $column));
                 array_push($keys, $path);
                 array_push($paths, $path);
             }

             # add to unique list of tables
             if ($table !== $id_table && !in_array($table, $tables))
                 array_push($tables, $table);
         }

         $sql = 'SELECT ' . implode(', ', $select) . ' ';
         $sql.= "FROM `$id_table` ";
         foreach($tables as $table) {
             $sql.= "JOIN `$table` ON `$table`._TOP_LEVEL_AURI=`$id_table`._URI ";
         }
         $sql.= "WHERE $where ";

         $curs = mysql_query_($sql, $conn);
         if ($curs === FALSE) {
             throw new OdkException("cannot perform get_where " .
                 "(joined table $id_table)", true);
         }

         $ret = array();
         while($row = mysql_fetch_row($curs)) {
             # every row accessed by its uri
             $uri = array_shift($row);
             # map results from row
             $ret[$uri] = array_combine($keys, $row);
         }

         foreach(array_keys($ret) as $uri) {

             # every select_multiple creates array
             foreach($select_multiple as $path) {
                 $table = $this->mapping[$path][0];
                 $sql = "SELECT VALUE FROM `$table` WHERE _PARENT_AURI='$uri'";
                 $curs = mysql_query_($sql, $conn);
                 if ($curs === FALSE) {
                     throw new OdkException("cannot perform get_where " .
                         "(select_multiple $table)", true);
                 }
                 $ret[$uri][$path] = array();
                 while($row = mysql_fetch_row($curs)) {
                     array_push($ret[$uri][$path], $row[0]);
                 }
             }

             # filter based on access column in .xls sheet
             if ($acls != NULL) {
                 foreach($paths as $i=>$path) {

                     $access = $this->fields[$path]['access'];

                     $ok = $path === $this->id_column;
                     foreach($access as $a) {
                         foreach($acls as $b)
                             if (strcasecmp($a, $b) == 0) {
                                 $ok = true;
                                 break;
                             }
                         if ($ok) break;
                     }
                     if (!$ok) {
                         unset($ret[$uri][$keys[$i]]);
                     }
                 }
             }
         }

         return $ret;
    }

    /**
     * returns a escaped version of the specified <code>$path</code> from
     * the <code>$this-&gt;mapping</code>
     */
    function escape($path) {
         if (!array_key_exists($path, $this->mapping)) {
             throw new OdkException("cannot escape '$path' : ".
                 "not in mapping of '{$this->id}'");
         }

         return '`' . implode('`.`', array_map('mysql_real_escape_string',
             $this->mapping[$path])) . '`';
    }

    /**
     * returns <code>{ uri =&gt; { param1=&gt;value1, ... } }</code> -- mysql RLIKE
     *
     * @param conn resource MySQL connection
     * @param string id_rlike to match <code>$this-&gt;id_column</code>
     * @param array params data that should be returned to caller; if no form field
     *        with specified name is found, param is expected to be database column name
     *
     * raises OdkException if error
     */
    function get_rlike($conn, $id_rlike, $params=array(), $acls=NULL) {
         if (!array_key_exists($this->id_column, $this->mapping))
             throw new OdkException("cannot select because id column '$this->id_column' not found");
         $id_table = $this->mapping[$this->id_column][0];

         $mapping = array();
         foreach($params as $param) {
             if (array_key_exists($param, $this->mapping)) {
                 $mapping[$param] = $this->mapping[$param];
             } else {
                 $mapping[$param] = array($id_table, $param);
             }
         }

         $where = $this->escape($this->id_column) . " RLIKE '".
             mysql_real_escape_string($id_rlike) . "'";

         return $this->get_where($conn, $where, $mapping, $acls);

    }

    /**
     * get associative array <code>$column_name =&gt; $value</code> for specified
     * <code>$uri</code> (can be retrieved by <code>get_uris()</code>
     *
     * if the optional parameter <code>$acls</code> (array) is specified, the data is
     * filtered to columns with matching <code>access</code>
     *
     * raises OdkException if error
     */
    function get_values($conn, $uri, $acls=NULL) {
         if (!array_key_exists($this->id_column, $this->mapping))
             throw new OdkException("cannot get_values because id column '$this->id_column' not found");
         $id_table = $this->mapping[$this->id_column][0];

         $where = '`'.mysql_real_escape_string($id_table).'`.`_URI`='.
             "'".mysql_real_escape_string($uri)."'";
         $ret = $this->get_where($conn, $where, $this->mapping, $acls);
         return $ret[$uri];
    }

    /**
     * sends image specified by <code>$uri</code> and <code>$path</code> to user
     *
     * raises OdkException if error or <code>$acls</code> don't allow retrieval
     * of data
     *
     * <strong>stops program</strong> on success
     */
    function send_image($conn, $uri, $path, $acls=NULL) {
         if (!array_key_exists($path, $this->mapping))
             throw new OdkException("path '$path' not mapped");
         if ($this->fields[$path]['type'] !== 'image')
             throw new OdkException("path '$path' not of type 'image'");
         $table = mysql_real_escape_string($this->mapping[$path][0]);

         // filter based on access column from .xls file
         if ($acls != NULL) {

             $access = $this->fields[$path]['access'];

             $ok = false;
             foreach($access as $a) {
                 foreach($acls as $b)
                     if (strcasecmp($a, $b) == 0) {
                         $ok = true;
                         break;
                     }
                 if ($ok) break;
             }
             if (!$ok)
                 throw new OdkException('insufficient access rights');
         }

         // get mime type from table_BN
         $sql = 'SELECT CONTENT_TYPE FROM `' . $table . '_BN` ';
         $sql.= 'WHERE _TOP_LEVEL_AURI=\'' . mysql_real_escape_string($uri) . '\'';

         $curs = mysql_query_($sql, $conn);
         if ($curs === FALSE)
             throw new OdkException("cannot select CONTENT_TYPE");

         if(($row = mysql_fetch_row($curs)) === FALSE)
             throw new OdkException("cannot find CONTENT_TYPE-row with uri=$uri");

         $mimetype = $row[0];

         // get blob from table_BLB
         $sql = 'SELECT VALUE, OCTET_LENGTH(VALUE) FROM `' . $table . '_BLB` ';
         $sql.= 'WHERE _TOP_LEVEL_AURI=\'' . mysql_real_escape_string($uri) . '\'';

         $curs = mysql_query_($sql, $conn);
         if ($curs === FALSE)
             throw new OdkException("cannot select VALUE");

         if(($row = mysql_fetch_row($curs)) === FALSE)
             throw new OdkException("cannot find VALUE-row with uri=$uri");

         $data = $row[0];
         $size = $row[1];

         // send content
         header('Content-Type: ' . $mimetype);
         header('Content-Transfer-Encoding: binary');
         header('Expires: 0');
         header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
         header('Pragma: public');
         header('Content-Length: ' . $size);
         echo $data;
         exit;
    }

    /**
     * prints information about form (& matching) as html
     */
    function dump() {
        print '<pre>';
        print "id=$this->id\n";
        print "title=$this->title\n";
        print "id_column=$this->id_column\n";
        if ($this->matched) {
            print "<span style=\"color:orange\">";
            if ($this->db_only) print "db_only=".implode(',', $this->db_only)."\n";
            if ($this->xls_only) print "xls_only=".implode(',', $this->xls_only)."\n";
            print "</span>";
        } else {
            print "<span style=\"color:red\">";
            print "-- NOT MATCHED --\n";
            print "</span>";
        }
        print 'form_id : ' . $this->id . "\n";
        foreach($this->groups as $group) {
            if (gettype($group) === 'string') {
                $tmp = Array();
                array_push($tmp, $group);
                array_push($tmp, $group);
                $group = $tmp;
            } else {
                print "$group[0] : ";
            }
            for($i=1; $i<count($group); $i++) {
                $path = $group[$i];
                $field = $this->fields[$path];
                $name = $field['name'];
                $type = $field['type'];
                if ($this->mapping && array_key_exists($path, $this->mapping))
                    $table_column = $this->mapping[$path];
                else
                    $table_column = array('?', '?');
                print $table_column[0].'.'.$table_column[1]."(name=$name,type=$type) ";
            }
            print "\n";
        }
        print '</pre>';
    }
}


/**
 * Parse all .xls files contained in a directory (using {@link OdkForm})
 */

class OdkDirectory {

    /**
     * a FormDataModel instance
     */
    var $model;

    /**
     * associative array mapping FORM_ID to <code>OdkForm</code>
     */
    var $forms;

    /**
     * array of form names that could not be matched with xls files
     */
    var $db_only;

    /**
     * array of FORM_IDs that could not be matched database
     */
    var $xls_only;

    /**
     * parse all .xls files in given directory and try to match tables in
     * database with .xls forms
     *
     * @param resource $conn database connection
     * @param string $path directory containing .xls files
     * @param string $id identity field that connects different forms
     */
    function OdkDirectory($conn, $path, $id, $date, $use_cache=true) {

        profile_start('OdkDirectory');

        $this->path = $path;
        $this->forms = array();

        // create OdkForm for every .xls in directory
        $this->mtime = 0;
        if ($handle = opendir($path)) {
            while (false !== ($entry = readdir($handle))) {
                if (strcasecmp(substr($entry, strlen($entry)-4), '.xls') == 0) {
                    $this->mtime = max($this->mtime, filemtime($path .'/'. $entry));
                    $form = new OdkForm($path .'/'. $entry, $id, $date, $use_cache);
                    $this->forms[strtoupper($form->id)] = $form;
                }
            }
            closedir($handle);
        }

        ksort($this->forms);

        $this->model = new FormDataModel($conn);
        $this->match($conn);

        profile_stop('OdkDirectory');
    }

    /**
     * match tables in database with forms loaded from directory. all tables that
     * could not be matched with any form will be saved in array
     * <code>$db_only_tables</code> and all forms that are found in directory
     * but could be mapped to any table in the database are stored in
     * <code>$xls_only_tables</code>
     *
     * @param resource $conn database connection
     *
     * @see $db_only_tables, $xls_only_tables
     */
    function match($conn) {

        profile_start('match_directory');

        $db_only  = $this->model->names();
        $xls_only = array();

        foreach($this->forms as $formid=>$form) {

            $node = $this->model->get($form->name);

            if ($node !== null) {
                $idx = array_search($form->name, $db_only);
                unset($db_only[$idx]);

                $form->match($conn, $node);

            } else {
                array_push($xls_only, $formid);
            }
        }

        profile_stop('match_directory');
    }

    function dump() {
        print '<ul>';
        foreach($this->forms as $id=>$form) {
?>
            <li><code><?php echo $id; ?></code></li>
            <div style="margin-left:1cm;">
            <?php echo $form->dump(); ?>
            </div>
<?php
        }
        print '</ul>';
    }

    /**
     * get form by id
     *
     * @return OdkForm or NULL
     */
    function get($formid) {
        return @$this->forms[strtoupper($formid)];
    }

    function exists($formid) {
        return array_key_exists(strtoupper($formid), $this->forms);
    }

}
?>
