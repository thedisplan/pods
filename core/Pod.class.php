<?php
/*
==================================================
Pod.class.php

http://pods.uproot.us/codex/
==================================================
*/
class Pod
{
    var $id;
    var $sql;
    var $data;
    var $result;
    var $datatype;
    var $datatype_id;
    var $total_rows;
    var $rel_table;
    var $rel_tbl_row_ids;
    var $detail_page;
    var $rpp = 15;
    var $page;

    function Pod($datatype = null, $id = null)
    {
        $this->id = pods_sanitize($id);
        $this->datatype = pods_sanitize($datatype);
        $this->page = empty($_GET['pg']) ? 1 : intval($_GET['pg']);

        if (null != $this->datatype)
        {
            $result = pod_query("SELECT id, detail_page FROM @wp_pod_types WHERE name = '$this->datatype' LIMIT 1");
            $row = mysql_fetch_assoc($result);
            $this->datatype_id = $row['id'];
            $this->detail_page = $row['detail_page'];

            if (null != $this->id)
            {
                return $this->getRecordById($this->id);
            }
        }
    }

    /*
    ==================================================
    Output the SQL resultset
    ==================================================
    */
    function fetchRecord()
    {
        if ($this->data = mysql_fetch_assoc($this->result))
        {
            $this->data['type'] = $this->datatype;
            return $this->data;
        }
        return false;
    }

    /*
    ==================================================
    Return the value of a single field (return arrays)
    ==================================================
    */
    function get_field($name, $orderby = null)
    {
        $this->rel_table = $this->datatype_id;

        if (isset($this->data[$name]) && empty($orderby))
        {
            return $this->data[$name];
        }
        elseif ('created' == $name || 'modified' == $name)
        {
            $pod_id = $this->get_pod_id();
            $result = pod_query("SELECT created, modified FROM @wp_pod WHERE id = $pod_id LIMIT 1");
            $row = mysql_fetch_assoc($result);
            $this->data['created'] = $row['created'];
            $this->data['modified'] = $row['modified'];
            return $this->data[$name];
        }
        else
        {
            // Dot-traversal
            $tmp_results = $this->data[$name];
            $this->rel_tbl_row_ids = $this->data['id'];
            $traverse = (false !== strpos($name, '.')) ? explode('.', $name) : array($name);
            foreach ($traverse as $key => $column_name)
            {
                $result = pod_query("SELECT id, pickval FROM @wp_pod_fields WHERE datatype = '$this->rel_table' AND name = '$column_name' AND coltype = 'pick' LIMIT 1");
                if (0 < mysql_num_rows($result))
                {
                    $row = mysql_fetch_assoc($result);
                    $tmp_results = $this->rel_lookup($row['id'], $row['pickval'], $orderby);

                    if (false === $tmp_results)
                    {
                        return false;
                    }

                    // Get the next datatype id if not the last traversal item
                    if ($key < count($traverse) - 1)
                    {
                        $result = pod_query("SELECT id FROM @wp_pod_types WHERE name = '" . $row['pickval'] . "' LIMIT 1");
                        $this->rel_table = mysql_result($result, 0);
                    }
                }
                else
                {
                    if ($key == count($traverse) - 1)
                    {
                        // If it's a single item, output the scalar value
                        if (1 == count($tmp_results))
                        {
                            $results = $tmp_results[0][$column_name];
                        }
                        // Otherwise, output the values as an array
                        elseif (is_array($tmp_results))
                        {
                            foreach ($tmp_results as $key => $val)
                            {
                                $results[] = $val[$column_name];
                            }
                        }
                        return $results;
                    }
                }
            }
            return $tmp_results;
        }
    }

    /*
    ==================================================
    Store user-generated data
    ==================================================
    */
    function set_field($name, $data)
    {
        return $this->data[$name] = $data;
    }

    /*
    ==================================================
    Run a helper within a Pod Page or WP template
    ==================================================
    */
    function pod_helper($helper, $value = null, $name = null)
    {
        $helper = mysql_real_escape_string(trim($helper));
        $result = pod_query("SELECT phpcode FROM @wp_pod_helpers WHERE name = '$helper' LIMIT 1");
        if (0 < mysql_num_rows($result))
        {
            $phpcode = mysql_result($result, 0);

            ob_start();
            eval("?>$phpcode");
            return ob_get_clean();
        }
    }

    /*
    ==================================================
    Get the pod id
    ==================================================
    */
    function get_pod_id($traverse = false)
    {
        if ($traverse)
        {
            $result = pod_query("SELECT id FROM @wp_pod WHERE datatype = '$this->rel_table' AND tbl_row_id IN ($this->rel_tbl_row_ids)");
            if (0 < mysql_num_rows($result))
            {
                while ($row = mysql_fetch_assoc($result))
                {
                    $pod_ids[] = $row['id'];
                }
                return implode(',', $pod_ids);
            }
            return 0;
        }
        else
        {
            if (empty($this->data['pod_id']))
            {
                $this->data['pod_id'] = 0;
                $tbl_row_id = $this->data['id'];
                $result = pod_query("SELECT id FROM @wp_pod WHERE datatype = '$this->datatype_id' AND tbl_row_id = '$tbl_row_id' LIMIT 1");
                if (0 < mysql_num_rows($result))
                {
                    $this->data['pod_id'] = mysql_result($result, 0);
                }
            }
            return $this->data['pod_id'];
        }
    }

    /*
    ==================================================
    Lookup values from a single relationship field
    ==================================================
    */
    function rel_lookup($field_id, $table = null, $orderby = null)
    {
        $orderby = empty($orderby) ? '' : "ORDER BY $orderby";

        $pod_ids = $this->get_pod_id(true);
        $result = pod_query("SELECT tbl_row_id FROM @wp_pod_rel WHERE pod_id IN ($pod_ids) AND field_id = $field_id");

        // Find all related IDs
        if (0 < mysql_num_rows($result))
        {
            $term_ids = array();
            while ($row = mysql_fetch_assoc($result))
            {
                $term_ids[] = $row['tbl_row_id'];
            }
            $this->rel_tbl_row_ids = $term_ids = implode(',', $term_ids);
        }
        else
        {
            return false;
        }

        // WP category
        if (is_numeric($table))
        {
            $result = pod_query("SELECT term_id AS id, name FROM @wp_terms WHERE term_id IN ($term_ids) $orderby");
        }
        // WP page or post
        elseif ('wp_page' == $table || 'wp_post' == $table)
        {
            $result = pod_query("SELECT ID as id, post_title AS name FROM @wp_posts WHERE ID IN ($term_ids) $orderby");
        }
        // WP user
        elseif ('wp_user' == $table)
        {
            $result = pod_query("SELECT ID as id, display_name AS name FROM @wp_users WHERE ID IN ($term_ids) $orderby");
        }
        // Pod table
        else
        {
            $result = pod_query("SELECT * FROM `@wp_pod_tbl_$table` WHERE id IN ($term_ids) $orderby");
        }

        // Put all related items into an array
        while ($row = mysql_fetch_assoc($result))
        {
            $data[] = $row;
        }
        return $data;
    }

    /*
    ==================================================
    Get pod or category dropdown values
    ==================================================
    */
    function get_dropdown_values($table = null, $field_name = null, $tbl_row_ids = null, $unique_vals = false, $pick_filter = null, $pick_orderby = null)
    {
        $orderby = empty($pick_orderby) ? 'name ASC' : $pick_orderby;

        // Category dropdown
        if (is_numeric($table))
        {
            $where = (false !== $unique_vals) ? "AND id NOT IN ($unique_vals)" : '';
            if (!empty($pick_filter))
            {
                if (!empty($where))
                {
                    $where .= ' AND ';
                }
                $where .= $pick_filter;
            }

            $sql = "
            SELECT
                t.term_id AS id, t.name
            FROM
                @wp_term_taxonomy tx
            INNER JOIN
                @wp_terms t ON t.term_id = tx.term_id
            WHERE
                tx.parent = $table AND tx.taxonomy = 'category' $where
            ORDER BY
                $orderby
            ";
        }
        // WP page or post dropdown
        elseif ('wp_page' == $table || 'wp_post' == $table)
        {
            $post_type = substr($table, 3);
            $where = (false !== $unique_vals) ? "AND id NOT IN ($unique_vals)" : '';
            if (!empty($pick_filter))
            {
                $where .= " AND $pick_filter";
            }

            $sql = "SELECT ID as id, post_title AS name FROM @wp_posts WHERE post_type = '$post_type' $where ORDER BY $orderby";
        }
        // WP user dropdown
        elseif ('wp_user' == $table)
        {
            $where = (false !== $unique_vals) ? "WHERE id NOT IN ($unique_vals)" : '';
            if (!empty($pick_filter))
            {
                $where .= (empty($where) ? ' WHERE ' : ' AND ') . $pick_filter;
            }

            $sql = "SELECT ID as id, display_name AS name FROM @wp_users $where ORDER BY $orderby";
        }
        // Pod table dropdown
        else
        {
            $where = (false !== $unique_vals) ? "WHERE id NOT IN ($unique_vals)" : '';
            if (!empty($pick_filter))
            {
                $where .= (empty($where) ? ' WHERE ' : ' AND ') . $pick_filter;
            }

            $sql = "SELECT * FROM `@wp_pod_tbl_$table` $where ORDER BY $orderby";
        }

        $result = pod_query($sql);
        while ($row = mysql_fetch_assoc($result))
        {
            if (!empty($tbl_row_ids))
            {
                $row['active'] = in_array($row['id'], $tbl_row_ids);
            }
            else
            {
                $row['active'] = ($row['id'] == $_GET[$field_name]) ? true : false;
            }
            $val[] = $row;
        }
        return $val;
    }

    /*
    ==================================================
    Return a single record
    ==================================================
    */
    function getRecordById($id)
    {
        $datatype = $this->datatype;
        if (!empty($datatype))
        {
            if (is_numeric($id))
            {
                $result = pod_query("SELECT * FROM `@wp_pod_tbl_$datatype` WHERE id = $id LIMIT 1");
            }
            else
            {
                // Get the slug column
                $result = pod_query("SELECT name FROM @wp_pod_fields WHERE coltype = 'slug' AND datatype = $this->datatype_id LIMIT 1");
                if (0 < mysql_num_rows($result))
                {
                    $field_name = mysql_result($result, 0);
                    $result = pod_query("SELECT * FROM `@wp_pod_tbl_$datatype` WHERE `$field_name` = '$id' LIMIT 1");
                }
            }

            if (0 < mysql_num_rows($result))
            {
                $this->data = mysql_fetch_assoc($result);
                $this->data['type'] = $datatype;
                return $this->data;
            }
            $this->data = false;
        }
        else
        {
            die('Error: Datatype not set');
        }
    }

    /*
    ==================================================
    Search and filter records
    ==================================================
    */
    function findRecords($orderby = 'id DESC', $rows_per_page = 15, $where = null, $sql = null)
    {
        $page = $this->page;
        $datatype = $this->datatype;
        $datatype_id = $this->datatype_id;
        $limit = ($rows_per_page * ($page - 1)) . ', ' . $rows_per_page;
        $where = empty($where) ? '' : "AND $where";
        $this->rpp = $rows_per_page;
        $i = 0;

        // Handle search
        if (!empty($_GET['search']))
        {
            $val = mysql_real_escape_string(trim($_GET['search']));
            $search = "AND (t.name LIKE '%$val%')";
        }

        // Add "t." prefix to $orderby if needed
        if (false !== strpos($orderby, ',') && false === strpos($orderby, '.'))
        {
            $orderby = 't.' . $orderby;
        }

        // Get this pod's fields
        $result = pod_query("SELECT id, name, pickval FROM @wp_pod_fields WHERE datatype = $datatype_id AND coltype = 'pick' ORDER BY weight");
        while ($row = mysql_fetch_assoc($result))
        {
            $i++;
            $field_id = $row['id'];
            $field_name = $row['name'];
            $table = $row['pickval'];

            // Handle any $_GET variables
            if (!empty($_GET[$field_name]))
            {
                $val = mysql_real_escape_string(trim($_GET[$field_name]));
                if (is_numeric($table))
                {
                    $where .= " AND `$field_name`.term_id = $val";
                }
                else
                {
                    $where .= " AND `$field_name`.id = $val";
                }
            }

            // Performance improvement - only use PICK columns mentioned in ($orderby, $where, $search)
            $haystack = "$orderby $where $search";
            if (false === strpos($haystack, $field_name . '.') && false === strpos($haystack, "`$field_name`."))
            {
                continue;
            }

            if (is_numeric($table))
            {
                $join .= "
                LEFT JOIN
                    @wp_pod_rel r$i ON r$i.field_id = $field_id AND r$i.pod_id = p.id
                LEFT JOIN
                    @wp_terms `$field_name` ON `$field_name`.term_id = r$i.tbl_row_id
                ";
            }
            elseif ('wp_page' == $table || 'wp_post' == $table)
            {
                $join .= "
                LEFT JOIN
                    @wp_pod_rel r$i ON r$i.field_id = $field_id AND r$i.pod_id = p.id
                LEFT JOIN
                    @wp_posts `$field_name` ON `$field_name`.ID = r$i.tbl_row_id
                ";
            }
            elseif ('wp_user' == $table)
            {
                $join .= "
                LEFT JOIN
                    @wp_pod_rel r$i ON r$i.field_id = $field_id AND r$i.pod_id = p.id
                LEFT JOIN
                    @wp_users `$field_name` ON `$field_name`.ID = r$i.tbl_row_id
                ";
            }
            else
            {
                $join .= "
                LEFT JOIN
                    @wp_pod_rel r$i ON r$i.field_id = $field_id AND r$i.pod_id = p.id
                LEFT JOIN
                    `@wp_pod_tbl_$table` `$field_name` ON `$field_name`.id = r$i.tbl_row_id
                ";
            }
        }

        if (empty($sql))
        {
            $sql = "
            SELECT
                SQL_CALC_FOUND_ROWS DISTINCT t.*
            FROM
                @wp_pod p
            $join
            INNER JOIN
                `@wp_pod_tbl_$datatype` t ON t.id = p.tbl_row_id
            WHERE
                p.datatype = $datatype_id
                $search
                $where
            ORDER BY
                $orderby
            LIMIT
                $limit
            ";
        }
        $this->sql = $sql;
        $this->result = pod_query($sql);
        $this->total_rows = pod_query("SELECT FOUND_ROWS()");
    }

    /*
    ==================================================
    Fetch the total row count
    ==================================================
    */
    function getTotalRows()
    {
        if (false === is_numeric($this->total_rows))
        {
            if ($row = mysql_fetch_array($this->total_rows))
            {
                $this->total_rows = $row[0];
            }
        }
        return $this->total_rows;
    }

    /*
    ==================================================
    Display HTML for all datatype fields
    ==================================================
    */
    function showform($pod_id = null, $public_columns = null, $label = 'Save changes', $form_count = 1)
    {
        $datatype = $this->datatype;
        $datatype_id = $this->datatype_id;
        $this->data['pod_id'] = $pod_id;

        $where = '';
        if (!empty($public_columns))
        {
            foreach ($public_columns as $key => $val)
            {
                if (is_array($public_columns[$key]))
                {
                    $where[] = $key;
                    $attributes[$key] = $val;
                }
                else
                {
                    $where[] = $val;
                    $attributes[$val] = array();
                }
            }
            $where = "AND name IN ('" . implode("','", $where) . "')";
        }

        $result = pod_query("SELECT * FROM @wp_pod_fields WHERE datatype = $datatype_id $where ORDER BY weight ASC");
        while ($row = mysql_fetch_assoc($result))
        {
            $fields[$row['name']] = $row;
        }

        if (!empty($pod_id))
        {
            $sql = "
            SELECT
                t.*
            FROM
                @wp_pod p
            INNER JOIN
                `@wp_pod_tbl_$datatype` t ON t.id = p.tbl_row_id
            WHERE
                p.id = $pod_id
            LIMIT
                1
            ";
            $result = pod_query($sql);
            if (0 < mysql_num_rows($result))
            {
                $tbl_cols = mysql_fetch_assoc($result);
            }
        }
        $uri_hash = md5($_SERVER['REQUEST_URI']);

        foreach ($fields as $key => $field)
        {
            // Replace field attributes with public form attributes
            if (is_array($attributes[$key]))
            {
                $field = array_merge($field, $attributes[$key]);
            }

            // Replace the input helper name with the helper code
            $input_helper = $field['input_helper'];
            if (!empty($input_helper))
            {
                $result = pod_query("SELECT phpcode FROM @wp_pod_helpers WHERE name = '$input_helper' LIMIT 1");
                $field['input_helper'] = mysql_result($result, 0);
            }

            if (empty($field['label']))
            {
                $field['label'] = ucwords($key);
            }

            if (1 == $field['required'])
            {
                $field['label'] .= ' <span class="red">*</span>';
            }

            if (!empty($field['pickval']))
            {
                $val = array();
                $tbl_row_ids = array();
                $table = $field['pickval'];

                $result = pod_query("SELECT id FROM @wp_pod_fields WHERE datatype = $datatype_id AND name = '$key' LIMIT 1");
                $field_id = mysql_result($result, 0);

                $result = pod_query("SELECT tbl_row_id FROM @wp_pod_rel WHERE pod_id = $pod_id AND field_id = $field_id");
                while ($row = mysql_fetch_assoc($result))
                {
                    $tbl_row_ids[] = $row['tbl_row_id'];
                }

                // Use default values for public forms
                if (empty($tbl_row_ids) && !empty($field['default']))
                {
                    $tbl_row_ids = $field['default'];
                    if (!is_array($field['default']))
                    {
                        $tbl_row_ids = explode(',', $tbl_row_ids);
                        foreach ($tbl_row_ids as $row_key => $row_val)
                        {
                            $tbl_row_ids[$row_key] = trim($row_val);
                        }
                    }
                }

                // If the PICK column is unique, get values already chosen
                $unique_vals = false;
                if (1 == $field['unique'])
                {
                    $exclude = empty($pod_id) ? '' : "pod_id != $pod_id AND";
                    $result = pod_query("SELECT tbl_row_id FROM @wp_pod_rel WHERE $exclude field_id = $field_id");
                    if (0 < mysql_num_rows($result))
                    {
                        $unique_vals = array();
                        while ($row = mysql_fetch_assoc($result))
                        {
                            $unique_vals[] = $row['tbl_row_id'];
                        }
                        $unique_vals = implode(',', $unique_vals);
                    }
                }
                $this->data[$key] = $this->get_dropdown_values($table, null, $tbl_row_ids, $unique_vals, $field['pick_filter'], $field['pick_orderby']);
            }
            else
            {
                // Set a default value if no value is entered
                if (empty($this->data[$key]) && !empty($field['default']))
                {
                    $this->data[$key] = $field['default'];
                }
                else
                {
                    $this->data[$key] = empty($tbl_cols[$key]) ? null : $tbl_cols[$key];
                }
                $this->get_field($key);
            }
            $this->build_field_html($field);
        }
        $uri_hash = md5($_SERVER['REQUEST_URI']);
?>
    <div>
    <input type="hidden" class="form num pod_id" value="<?php echo $pod_id; ?>" />
    <input type="hidden" class="form txt datatype" value="<?php echo $datatype; ?>" />
    <input type="hidden" class="form txt form_count" value="<?php echo $form_count; ?>" />
    <input type="hidden" class="form txt token" value="<?php echo pods_generate_key($datatype, $uri_hash, $public_columns, $form_count); ?>" />
    <input type="hidden" class="form txt uri_hash" value="<?php echo $uri_hash; ?>" />
    <input type="button" class="button" value="<?php echo $label; ?>" onclick="saveForm(<?php echo $form_count; ?>)" />
    </div>
<?php
    }

    /*
    ==================================================
    Display the pagination controls
    ==================================================
    */
    function getPagination($label = 'Go to page:')
    {
        if ($this->rpp < $this->getTotalRows())
        {
            include realpath(dirname(__FILE__) . '/pagination.php');
        }
    }

    /*
    ==================================================
    Display the list filters
    ==================================================
    */
    function getFilters($filters = null, $label = 'Filter', $action = '')
    {
        include realpath(dirname(__FILE__) . '/list_filters.php');
    }

    /*
    ==================================================
    Build public input form
    ==================================================
    */
    function publicForm($public_columns = null, $label = 'Save changes')
    {
        include realpath(dirname(__FILE__) . '/form.php');
    }

    /*
    ==================================================
    Build HTML for a single field
    ==================================================
    */
    function build_field_html($field)
    {
        include realpath(dirname(__FILE__) . '/input_fields.php');
    }

    /*
    ==================================================
    Display the page template
    ==================================================
    */
    function showTemplate($tpl, $code = null)
    {
        ob_start();

        if (empty($code))
        {
            // Backwards compatibility
            if ('list' == $tpl || 'detail' == $tpl)
            {
                $tpl = $this->datatype . "_$tpl";
            }

            $result = pod_query("SELECT code FROM @wp_pod_templates WHERE name = '$tpl' LIMIT 1");
            $row = mysql_fetch_assoc($result);
            $code = $row['code'];
        }

        if (!empty($code))
        {
            // Only detail templates need $this->id
            if (empty($this->id))
            {
                while ($this->fetchRecord())
                {
                    $out = preg_replace_callback("/({@(.*?)})/m", array($this, "magic_swap"), $code);
                    eval("?>$out");
                }
            }
            else
            {
                $out = preg_replace_callback("/({@(.*?)})/m", array($this, "magic_swap"), $code);
                eval("?>$out");
            }
        }
        return ob_get_clean();
    }

    /*
    ==================================================
    Replace magic tags with their values
    ==================================================
    */
    function magic_swap($in)
    {
        $name = $in[2];
        $before = $after = '';
        if (false !== strpos($name, ','))
        {
            list($name, $helper, $before, $after) = explode(',', $name);
        }
        if ('detail_url' == $name)
        {
            return get_bloginfo('url') . '/' . preg_replace_callback("/({@(.*?)})/m", array($this, "magic_swap"), $this->detail_page);
        }
        else
        {
            $value = $this->get_field($name);

            // Use helper if necessary
            if (!empty($helper))
            {
                $value = $this->pod_helper($helper, $value, $name);
            }
            if (null != $value && false !== $value)
            {
                return $before . $value . $after;
            }
        }
    }
}