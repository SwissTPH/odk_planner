<?php
/**
 * The classes in this file allow to access data contained in the ODK MySQL
 * database using identifiers from .xls files that have the same format that
 * can be used with XLSForm.
 *
 * The class `OdkDirectory` reads in a whole directory of .xls forms and creates
 * a `OdkForm` object for every form present in the database. Different methods
 * such as `OdkForm::get_rlike()` and `OdkForm::get_values()` can then be used
 * to retreive data from the database using field names from the .xls files.
 *
 * @see OdkDirectory
 * @see OdkForm::get_rlike()
 * @see OdkForm::get_values()
 */

if (!defined('MAGIC')) die('!?');

require_once 'lib/phpexcelreader/Excel/reader.php';

/**
 * Exception risen in various data-returning methods of OdkForm.
 */
class OdkException extends Exception
{
    /**
     * Construct a new exception optionally with a MySQL error.
     *
     * @param string $mysql_error Error string from MySQL that will be appended
     *    to the error message.
     */
    public function __construct($message, $mysql_error = false, $code = 0, Exception $previous = null) {
        if ($mysql_error)
            $message .= ' -- mysql_error="'.mysql_error().'"';
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Simple file based cache that saves/restores multiple subsets of properties of
 * an object.
 *
 * After the cache is created on a given object with
 * `$cache = new JsonCache($path, $obj)`, some properties of the object (in this
 * example the properties `$obj->property1` and `$obj->property2`) can be
 * added to the cache with `$cache->save('prefix1', ['property1', 'property2'])`
 * and later restored with `$cache->load('prefix1')`.
 *
 * Usually the object `$obj` is created from a file and the modification time
 * of that file is used to determine whether the cached data can be used.
 */
class JsonCache {

    /** The file from which the cached object is created. */
    var $fname;

    /** Object to which the cache is associated. */
    var $obj;

    /**
     * Create a new cache.
     *
     * @param string $fname File from which the object `$obj` is created. The
     *    modification time of `$fname` will be used to determine whether the
     *    cached values are still valid. The cache itself will be stored in
     *    the file `"$fname.cache.json"`.
     *
     * @param object $obj The object that provides the properties to be cached.
     *
     * @param boolean $use_cache Setting this to `false` will make the
     *    properties be only saved to disk and never restored (use e.g. to force
     *    cache refresh).
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
     * Checks whether cached values are still valid.
     *
     * @param string $prefix Specifies the group of cached values that should
     *    be checked.
     *
     * @param int $mtime Usually the modification time is taken from the file
     *    `$this->fname` and this parameter is `null`.
     *
     * @return boolean `true` if the modification time of the file
     *    `$this->fname` is further in the past than the last call to
     *    `save($prefix, ...)`.
     */
    function valid($prefix, $mtime=null) {
        if(!$this->use_cache || !array_key_exists($prefix, $this->cache))
            return false;
        if ($mtime === null) {
            if ($this->fname and file_exists($this->fname)) {
                $mtime = filemtime($this->fname);
            } else {
                $mtime = 0;
            }
        }
        return $mtime < $this->cache[$prefix]['$mtime'];
    }

    /**
     * Writes cache to disk.
     */
    function flush() {
        profile_start('json_cache_flush');
        $fh = fopen($this->cfname, 'w');
        fwrite($fh, json_encode($this->cache));
        fclose($fh);
        profile_stop('json_cache_flush');
    }

    /**
     * Store specified object properties in cache.
     *
     * @param string $prefix The name under which the object properties are
     *    grouped.
     *
     * @param array $names Object property names to be cached.
     *
     * @param boolean $flush Whether fo flush the cache to disk.
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
     * Load specified object properties from cache.
     *
     * @param string $prefix Name of the group of properties that should be read
     *    from the cache and stored in the object.
     */
    function load($prefix) {
        foreach($this->cache[$prefix] as $key=>$value) {
            $this->obj->$key = $value;
        }
    }

}


/**
 * Helper class that represents a node in the tree of the FormDataModel.
 *
 * A node is identified by its "path" that is an array of enclosing
 * group names, ending with the element's name (e.g. `[outer_group, inner_group,
 * field_name]`)
 */
class FormDataNode {

    /**
     * Create new node.
     *
     * @param string $uri Identifying this node.
     * @param string $parent_uri `uri` of node of which this node is a child.
     * @param array $data Node data (containing keys `type`, `name`, `table`,
     *    and `column`).
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
     * Adds child to the children of this node.
     */
    function add($child) {
        if (!in_array($child, $this->children))
            array_push($this->children, $child);
    }

    /**
     * Tries to add node as a child node to this node or any of its descendants.
     *
     * @return boolean `true` if node could be attached.
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
     * Returns the node corresponding to the specified field or null.
     *
     * @param array $path path of a field (i.e. `[group1, ..., field_name]`).
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
     * Get array of paths ([group1, ..., field_name]) under this node.
     */
    function paths(&$ret=null, $path=null) {
        if ($ret === null) $ret = array();
        if ($path === null) {
            // Don't include name of root element.
            $path = array();
        } else {
            array_push($path, $this->data['name']);
        }
        if ($this->data['type'] === 'GROUP') {
            // Descend only into groups (and not images, geopoints, ...)
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
 * Represents data contained in the _form_data_model table.
 *
 * The table `_form_data_model` describes the hierarchical structure of
 * the fields from the different forms contained in the database. every form
 * is the root node of one of these trees (`$roots`).
 */
class FormDataModel {

    /**
     * List of root nodes that represent the forms in the database.
     *
     * Every element is of type `FormDataNode` and represents a form in the
     * database. The child nodes of these nodes correspond to the group inside
     * the forms and the fields themselves.
     *
     * Remarks about the different elements:
     *
     * - The root nodes have the forms filename as `name` and `type="GROUP"`.
     *   Note that this form name can be different from the formid.
     * - Groups also have `type="GROUP"` and `column=NULL`.
     * - `type="GEOPOINT"` also have `column=NULL` and contain four child nodes
     *   with the location specification (with `type=DECIMAL`).
     * - Select_one have `type="STRING"`
     * - Select_multiple have `type="SELECTN"`, specify the additional
     *   table in `table` and have `column=NULL`.
     * - Images have `type="BINARY"`, specify the additional table in in
     *   `table`, have `column=NULL`, and contain a child-node
     *   `type="BINARY_CONTENT_REF_BLOB"` and finally a grandchild-node
     *   `type="REF_BLOB"` with each a new `table` and `column=NULL`.
     */
    var $roots = array();

    /**
     * Reads the model from the database.
     *
     * Parses the table `_form_data_model` in the database and fills the values
     * in `$this->roots` accordingly.
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
     * Get a form, a group within a form or a field of a form.
     *
     * Returns the root path with the specified form name; optionally
     * a field can be specified, in which case the node corresponding to
     * the field is returned (equivalent to calling `get()` of the
     * root node).
     *
     * @param string $name Name of the form (not necessarily the same as the
     *    formid; see explanation under `$this->roots`).
     * @param array $path Path of a field (i.e. `[group1, ..., field_name]`).
     *
     * @return object An `FormDataNode` or `NULL`.
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
     * Get array of root form names (that is formids).
     */
    function names() {
        $f = function($root) { return $root->data['name']; };
        return array_map($f, $this->roots);
    }

    /**
     * Helper function for method dump().
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
     * Dump information about all data found to standard output.
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
 * Reads a form description from an Excel spreadsheet.
 *
 * Parse an Excel file that was used (using {@link http://opendatakit.org/use/xlsform/
 * XLSForm}) to create the .xml file as uploaded to Aggregate. The Description
 * of the Excel file can be used to structure/display data contained in the
 * database.
 *
 * Some remarks about the database structure :
 *
 * - Long forms spread across `FORM_CORE, FORM_CORE2, ...`.
 * - Column name is `GROUPNAME_FIELDNAME` and unique across all cores. If group
 *   and/or fieldname is long the column name can be "mangled" omitting some of
 *   the letters.
 * - `_URI` is unique key in every core.
 * - Cores are linked with via `FORM_COREx._TOP_LEVEL_AURI=FORM_CORE._URI`.
 */
class OdkForm {

    /**
     * The FORM_ID as taken from the .xls settings sheet (and UPPERCASED).
     */
    var $id;

    /**
     * The title as taken from the .xls settings sheet.
     */
    var $title;

    /**
     * The .xls file name.
     */
    var $fname;

    /**
     * The name of the form.
     *
     * `$fname` without extension. This name is used the root nodes in
     * `FormDataModel` and can be different from the formid `$this->id`.
     */
    var $name;

    /**
     * Character that is used to connect the group to the field name to construct
     * the PATH.
     */
    var $lig = '_';

    /**
     * Dictionary of fields, indexed by PATH.
     *
     * A PATH has the form GROUPNAME_FIELDNAME and is used the reference a field
     * within a form.
     *
     * Every field is itself is a dictionary with some attributes
     * (such as `name, type, label, hint` from the .xls file). The special
     * key `access` controls what user can see the content of this field.
     */
    var $fields;

    /**
     * Array of groups as found in .xls file.
     *
     * Every group itself consists of an array starting with group name then
     * COLUMNNAMES (as keys in `$columns`).
     *
     * Fields not within a group are storead as string in the `$groups` * array.
     */
    var $groups;

    /**
     * (UPPERCASED) column name of field identifying record.
     *
     * This is not neccessarily a unique identifier for a row in the different
     * tables, because ODK lets users upload a form with the same "id" several
     * times. To uniquely identify form uploads the URI is used.
     *
     * @see OdkForm::get_uris()
     * @see OdkForm::get_where()
     */
    var $id_column;

    /**
     * (UPPERCASED) column name of field containing submission date.
     *
     * `odk_planner` will use this field to identify when a form was created;
     * if the field specified by its name is not found it will automatically
     * fall back on the submission date (`_SUBMISSION_DATE`, generated by ODK).
     */
    var $date_column = '_SUBMISSION_DATE';

    /**
     * Whether fields are already matched (mapped).
     */
    var $matched;

    /**
     * Associative array that maps a PATH to TABLE and COLUMN.
     *
     * - For select_multiple, COLUMN is NULL.
     * - For images, only the common part of TABLE is saved (i.e. without 
     *   `_{BN|BLOB|REF}`).
     * - For geopoints, only the common part of COLUMN is saved (i.e. without
     *   `_{LNG|LAT|ALT|ACC}`).
     *
     * This array is filled in the method `$this->match()`.
     */
    var $mapping;

    /**
     * List of columns that were found in database but not listed in .xls file.
     */
    var $db_only;

    /**
     * List of fields that are listed in .xls file but were not found in
     * database.
     */
    var $xls_only;

    /**
     * Initialize new form from .xls file
     *
     * @param string $fname Where to find Excel file describing form.
     *
     * @param string $id_name Name of field to be used as ID.
     *
     * @param boolean $use_cache Whether to read values from cache (specifying
     *    `false` will force a cache refresh regardless of age of cache file
     *    -- do this e.g. after the configuration changed and invalidated the
     *    cache).
     */
    function OdkForm($fname, $id_name, $date_name, $use_cache=true) {

        // Properties not set in constructor.
        $this->fname = $fname;
        $this->name = basename($fname, '.xls');
        $this->matched = false;
        $this->mapping = array();

        // Load from cache if newer than .xls
        $this->cache = new JsonCache($fname, $this, $use_cache);
        if ($this->cache->valid('constructor')) {
            $this->cache->load('constructor');
            return;
        }

        // Not loaded from cache -- parse .xls
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

        // Save values to cache.
        $this->cache->save('constructor', array(
            'id', 'title', 'fields', 'groups', 'id_column', 'date_column'));
    }

    /**
     * Look up sheet by (case insensitive) name.
     */
    function sheet_by_name($wb, $name) {
        foreach($wb->boundsheets as $i=>$ws)
            if (strcasecmp($ws['name'], $name) == 0)
                return $wb->sheets[$i];
        return NULL;
    }

    /**
     * Look up cell value.
     *
     * @param int $row Number of the row (starting at 1).
     * @param string $name Name of the column (i.e. value of the cell in the
     *    first row of that column).
     *
     * @return string Trimmed value or `NULL` if row/column not found.
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
     * Match fields described in .xls file with database tables and columns
     * as described in ODK's _form_data_model table.
     *
     * Property `$this->mapping` will be filled accordingly.
     *
     * All fields that could not be matched are saved in `$xls_only` and all
     * database columns that were not found in the excel form are saved in
     * `$db_only`.
     *
     * Values will be loaded from and stored to `$this->cache`.
     *
     * @param resource $conn Database connection.
     * @param object $root Root `FormDataNode` that describes ODK's datamodel.
     */
    function match($conn, $root) {

        profile_start('match_form');

        // Load from cache if newer than .xls
        if ($this->cache->valid('match')) {
            $this->cache->load('match');
            return;
        }

        $paths = $root->paths();
        $imploded_paths = array();
        foreach($paths as $path)
            array_push($imploded_paths, strtoupper(implode($this->lig, $path)));

        $xls_only = array();
        foreach($this->fields as $path=>$field) {
            // Search database column description matching xls field.
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

        // Save to cache.
        $this->cache->save('match', array(
            'db_only', 'xls_only', 'matched', 'mapping'));

        profile_stop('match_form');
    }

    /**
     * Get the PATH of a field.
     *
     * @param string $name The field's name, can also be a full PATH.
     *
     * @return string PATH of the field. For example, if there is a field
     *    "name" inside a group "personal_information" then the PATH
     *    "PERSONAL_INFORMATION_NAME" will be returned. Note that it is possible
     *    to have several fields with the same name in different groups. The
     *    first match would be returend in such a case.
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
     * Returns an MySQL-escaped TABLE.COLUMN for given PATH.
     *
     * Raises `OdkException` if path is not found in `$this->mapping`.
     */
    function escape($path) {
         if (!array_key_exists($path, $this->mapping)) {
             throw new OdkException("cannot escape '$path' : ".
                 "not in mapping of '{$this->id}'");
         }

         return '`' . implode('`.`', $this->mapping[$path]) . '`';
    }

    /**
     * Get an array of URIs for the id field where the field matches a MySQL
     * RLIKE expression.
     *
     * @param resource $conn Database connection.
     * @param string $id_rlike MySQL `RLIKE` expression to filter id fields.
     *
     * Raises `OdkException` if an error occurs.
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
     * Get values limited by MySQL WHERE clause.
     *
     * @param resource $conn MySQL connection
     * @param string $where MySQL `WHERE` clause.
     * @param array $mapping Array describing the mapping from PATH to table
     *    and columns in database (like `$this->mapping`).
     * @param array $acls List of access permissions by which the result set is
     *    filtered. A field will be unset in the returned result unless `$acls`
     *    contains one of the names listed in the fields `access` list.
     *
     * @return array Dictionary indexed by `_URI` of dictionaries that map
     *    PATH to parameter value.
     *
     * Raises `OdkException` if an error is encountered.
     *
     * @see OdkForm::get_rlike()
     */
    function get_where($conn, $where, $mapping=null, $acls=null) {

         if (!array_key_exists($this->id_column, $this->mapping))
             throw new OdkException("cannot get_where because id column '$this->id_column' not found");
         $id_table = $this->mapping[$this->id_column][0];

         $bt = function($table, $column) {
             return '`'.$table.'`.'.'`'.$column.'`';
         };

         $select_multiple = array(); // Paths that will be processed later.

         $select = array($bt($id_table, '_URI'));
         $keys = array(); // i.e. indexes in the returned $data.
         $paths = array(); // i.e. indexes into $mapping.
         $tables = array(); // Table for JOIN.

         // First loop through the mapping and look up what columns have to be
         // read from which tables (and how to join them).
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
                continue; // We don't want to JOIN these tables.

             } else if ($type === 'geopoint') {
                 // Is actually two values.
                 array_push($select, $bt($table, $column.'_LAT'));
                 array_push($keys, $path . '_LAT');
                 array_push($paths, $path);
                 array_push($select, $bt($table, $column.'_LNG'));
                 array_push($keys, $path . '_LNG');
                 array_push($paths, $path);

             } else if ($type === 'image') {
                 // For images we want the filename.
                 $table = $table . '_BN'; #FIXME should be done in match()
                 array_push($select, $bt($table, 'UNROOTED_FILE_PATH'));
                 array_push($keys, $path);
                 array_push($paths, $path);

             } else {
                 // Default
                 array_push($select, $bt($table, $column));
                 array_push($keys, $path);
                 array_push($paths, $path);
             }

             // Add to unique list of tables.
             if ($table !== $id_table && !in_array($table, $tables))
                 array_push($tables, $table);
         }

         // Now generate SQL.
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

         // Fetch data.
         $ret = array();
         while($row = mysql_fetch_row($curs)) {
             // Every row accessed by its uri.
             $uri = array_shift($row);
             // Map results from row.
             $ret[$uri] = array_combine($keys, $row);
         }

         // Add values from select_multiple and filter based on ACL.
         foreach(array_keys($ret) as $uri) {

             // Every select_multiple creates array.
             foreach($select_multiple as $path) {
                 $table = $this->mapping[$path][0];
                 $sql = "SELECT VALUE FROM `$table` WHERE _PARENT_AURI='$uri'";
                 $curs = mysql_query_($sql, $conn);
                 if ($curs === FALSE) {
                     throw new OdkException("Cannot perform get_where " .
                         "(select_multiple $table)", true);
                 }
                 $ret[$uri][$path] = array();
                 while($row = mysql_fetch_row($curs)) {
                     array_push($ret[$uri][$path], $row[0]);
                 }
             }

             // Filter based on access column in .xls sheet.
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
     * Get form values for ids matched by regular expression.
     *
     * Wrapper for `$this->get_where`.
     *
     * @param resource $conn MySQL connection.
     * @param string $id_rlike A MySQL `RLIKE` expression that is applied on the
     *    id column to filter relevant entries. For example `"80.*"` would get
     *    all the forms that have a id starting with `80`.
     * @param array $params list of fields (PATHs) that should be returned.
     * @param array $acls List of access permissions by which the result set is
     *    filtered. A field will be unset in the returned result unless `$acls`
     *    contains one of the names listed in the fields `access` list.
     *
     * @return array Dictionary indexed by `_URI` of dictionaries that map
     *    PATH to parameter value.
     *
     * Raises `OdkException` if an error is encountered.
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
     * Get the form values for a specific entry.
     *
     * @param resource $conn MySQL connection.
     * @param string $uri URI that identifies the form upload
     * @param array $acls List of access permissions by which the result set is
     *    filtered. A field will be unset in the returned result unless `$acls`
     *    contains one of the names listed in the fields `access` list.
     *
     * @return array Dictionary that map PATH to parameter value.
     *
     * Raises `OdkException` if an error is encountered.
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
     * Outputs image over a HTTP connection.
     *
     * Note: *Stops the program* on success.
     *
     * @param resource $conn MySQL connection
     * @param string $uri URI that identifies the form upload
     * @param string $path PATH that identifies field of type image.
     * @param array $acls List of access lists the user is member of.
     *
     * Raises `OdkException` if error or `$acls` don't allow retrieval of data.
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
     * Prints information about form (& matching) as HTML.
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
 * Parse all .xls files contained in a directory.
 *
 * The property `$this->forms` is a dictionary that maps formid to instances
 * of `OdkForm`.
 *
 * @see OdkForm
 * @see OdkDirectory::$forms
 */
class OdkDirectory {

    /**
     * Describes the databases data model.
     *
     * An instance of `FormDataModel` that is used by the `OdkForm` to match
     * the fields to the database.
     */
    var $model;

    /**
     * Associative array mapping formid to instances of OdkForm.
     */
    var $forms;

    /**
     * Array of form names that could not be matched with .xls files.
     *
     * Note that it is the form *name* (i.e. filename of .xls file without
     * extension) and not the id.
     */
    var $db_only;

    /**
     * Array of formids that could not be matched database.
     */
    var $xls_only;

    /**
     * Parse all .xls files in given directory and try to match tables in
     * database with .xls forms.
     *
     * @param resource $conn MySQL database connection.
     * @param string $path Directory containing .xls files.
     * @param string $id Id field that is used to match entries across
     *    forms ("patient id").
     * @param boolean $use_cache Whether to read values from cache if available.
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
     * Match tables in database with forms loaded from directory.
     *
     * All tables that could not be matched with any form will be saved in the
     * array `$this->db_only` and all forms that are found in directory but
     * could be mapped to any table in the database are stored in
     * `$this->xls_only_tables`.
     *
     * @param resource $conn MySQL database connection.
     *
     * @see OdkDirectory::$db_only_tables
     * @see OdkDirectory::$xls_only_tables
     */
    function match($conn) {

        profile_start('match_directory');

        $this->db_only  = $this->model->names();
        $this->xls_only = array();

        foreach($this->forms as $formid=>$form) {

            $node = $this->model->get($form->name);

            if ($node !== null) {
                $idx = array_search($form->name, $this->db_only);
                unset($this->db_only[$idx]);

                $form->match($conn, $node);

            } else {
                array_push($this->xls_only, $formid);
            }
        }

        profile_stop('match_directory');
    }

    /**
     * Get form by formid
     *
     * @return object A `OdkForm` or `NULL`.
     */
    function get($formid) {
        return @$this->forms[strtoupper($formid)];
    }

    /**
     * Checks whether a form with the specified formid exists.
     */
    function exists($formid) {
        return array_key_exists(strtoupper($formid), $this->forms);
    }

    /**
     * Dumps the content of all forms to standard output as HTML.
     */
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
}
