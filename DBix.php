<?php

namespace DBix;

function array_repeat($needle, $times) {
    $ret = array();
    for ($i = 0; $i < $times; $i++) {
        $ret []= $needle;
    }
    return $ret;
}

function lazy_params($params, $func_get_args) {
    if(!is_array($params)) {
        if (count($func_get_args) > 2) {
            $params = array_splice($func_get_args, 1, 1000);
        } else {
            $params = (array)$params;
        };
    }
    return $params;
}

class Connection {
    public function __construct($url) {
        $this->verbose = false;
        $u = parse_url($url);

        @mysql_pconnect($u['host'], $u['user'], $u['pass']);
        if(mysql_error()) throw new \Exception ('MySQL can not connect');

        @mysql_select_db(substr($u['path'], 1, 1000));
        if(mysql_error()) throw new \Exception ('MySQL can not select db');

        return $this;
    }

    public function query($query, $params = null) {
        if(!$query) throw new \Exception ('Needs query');
        $params = lazy_params($params, func_get_args());

        $q = new ActiveRecordQuery($query, $params);
        $q->verbose = $this->verbose;
        $q->db = $this;
        return $q;
    }

    public function execute($query, $params = null) {
        return $this->query($query, $params)->run();
    }

    public function insert($table, $row) {
        if(!$table) throw new \Exception ('Needs table');
        $keys_holders = join(', ', array_repeat('`?`', count($row)));
        $keys_values = join(', ', array_repeat('?', count($row)));
        $values = array_merge(
            (array)$table, array_keys($row), array_values($row)
            );

        $sql = 'INSERT INTO `?` ('.$keys_holders.') VALUES ('.$keys_values.')';
        return $this->execute($sql, $values);
    }

    # array('?=?', '?=?',)
    private function k_eq_q($kv) {
        $ret = array();
        $placeholders = array();
        foreach($kv as $k=>$v) {
            $placeholders []= '`?`=?';
            $ret []= $k;
            $ret []= $v;
        }
        return array($placeholders, $ret);
    }

    public function insert_update($table, $insert, $update) {
        if(!$table) throw new \Exception ('Needs table');
        $keys_holders = join(', ', array_repeat('`?`', count($insert)));
        $keys_values = join(', ', array_repeat('?', count($insert)));

        list($update_holders, $update_keys_values) = $this->k_eq_q($update);
        $values = array_merge(
            (array)$table,
            array_keys($insert), array_values($insert),
            $update_keys_values
            );

        $sql = 'INSERT INTO `?` ('.$keys_holders.') VALUES ('.$keys_values.') '.
            'ON DUPLICATE KEY UPDATE '.join(', ', $update_holders);
        return $this->execute($sql, $values);
    }

    public function update_where($table, $update, $where) {
        if(!$table) throw new \Exception ('Needs table');
        list($update_holders, $update_keys_values) = $this->k_eq_q($update);
        list($where_holders,  $where_keys_values) = $this->k_eq_q($where);

        $values = array_merge(
            (array)$table,
            $update_keys_values,
            $where_keys_values
            );

        $sql = 'UPDATE `?` SET '.join(', ', $update_holders).' '.
            'WHERE '.join(' AND ', $where_holders);
        return $this->execute($sql, $values);
    }

}

class Query {
    public function __construct($query, $params = null) {
        $params = lazy_params($params, func_get_args());
        $this->query = $this->sql_query($query, $params);
        $this->has_run = false;
        $this->db = null;
    }

    public function get_sql() {
        return $this->query;
    }

    public function affected() {
        return @mysql_affected_rows();
    }

    public function num_rows() {
        return @mysql_num_rows($this->rq);
    }

    private function sql_query($query, $params = null) {
        if($params === null) $params = array();
        $regex_to_find = '(\?|`\?`)';
        $regex = '#' . $regex_to_find . '#is';

        $m = array();
        $num_found = preg_match_all($regex, $query, $m);

        if($num_found != count($params))
            throw new \Exception (sprintf('Number of placeholders(%d) didn\'t '.
                    'match number of params(%d)', $num_found, count($params)));

        $replacer = function($m) use ($params) {
            static $calls;
            if(!isset($calls)) $calls = 0;
            $replace = $params[(int)$calls];
            $calls++;
            if($replace === null) {
                return 'NULL';
            } else {
                if($m[1] == '?') {
                    return '"' . @mysql_real_escape_string($replace) . '"';
                } elseif($m[1] == '`?`') {
                    return '`' . @mysql_real_escape_string($replace) . '`';
                } else {
                    throw new Exception ('Unhandled placeholder');
                }
            }
        };

        return preg_replace_callback($regex, $replacer, $query);
    }

    public function run() {
        if(!$this->has_run) {
            $this->has_run = true;
            $this->rq = @mysql_query($this->get_sql());
            if(mysql_error())
                throw new \Exception ('Query:' . $this->get_sql() . "\n" .
                                      'MySQL error: ' . mysql_error());

            if($this->verbose)
                print sprintf("<div style='background: gray; font: 11px Arial;
                color: silver; padding: 5px; margin-bottom:
                10px;'>%s<br>Affected %d; num_rows: %d</div>",
                    $this->get_sql(), $this->affected(), $this->num_rows());

        }
        return $this;
    }

    public function fetch_all() {
        $this->run();
        $ret = array();
        while($o = @mysql_fetch_assoc($this->rq)) {
            $ret []= $o;
        }
        return $ret;
    }

    public function fetch_row() {
        $this->run();
        $ret = array();
        if($this->num_rows() < 1)
            throw new Exception ('There were no results');

        $o = @mysql_fetch_assoc($this->rq);
        return $o;
    }

    public function fetch_column() {
        $this->run();
        $ret = array();

        while($o = @mysql_fetch_row($this->rq)) {
            $ret []= $o[0];
        }
        return $ret;
    }

    public function fetch_cell() {
        $this->run();
        $ret = array();
        if($this->num_rows() < 1)
            throw new Exception ('There were no results');

        $o = @mysql_fetch_row($this->rq);
        return $o[0];
    }

}

class Model {
    public $__meta;

    public function __construct($db, $table, $item) {
        $this->__meta = array();

        $this->set_meta('db', $db);
        $this->set_meta('table', $table);
        $this->set_meta('item', $item);

        $pk = array();
        foreach($this->primary_key() as $p) {
            if(!isset($p)) throw new \Exception ('One of the primary keys '.
                                                 'fields wasn\'t fetched');
            $pk[$p] = $item[$p];
        }
        $this->set_meta('primary_key', $pk);
    }

    private function set_meta($k, $v) {
        $this->__meta[$k] = $v;
    }

    private function get_meta($k) {
        return $this->__meta[$k];
    }

    public function __set($k, $v) {
        if(isset($this->__meta['item'][$k])) {
            $this->__meta['item'][$k] = $v;
        } else {
            throw new \Exception (sprintf('Field "%s" doesn\'t exist', $k));
        }
    }

    public function __get($k) {
        if(isset($this->__meta['item'][$k])) {
            return $this->__meta['item'][$k];
        } else {
            throw new \Exception (sprintf('Field "%s" doesn\'t exist', $k));
        }
    }

    public function primary_key() {
        return array('id');
    }

    public function __toString() {
        $r = '';
        foreach($this->get_meta('item') as $k => $v) {
            if($r) $r .= ', ';
            $r .= sprintf('%s = "%s"', $k, $v);
        }
        return sprintf("%s(%s)", ucfirst($this->get_meta('table')), $r);
    }

    public function save() {
        $where = $this->get_meta('primary_key');
        $table = $this->get_meta('table');
        $q = $this->get_meta('db')->update_where($table,
                                            $this->get_meta('item'), $where);
        if($q->affected() != 1)
            throw new Exception (sprintf('ActiveRecord update went wrong: '.
                'there were %d rows updated instead of 1', $q->affected()));
    }

}

class ActiveRecordQuery extends Query {
    public function __construct($query, $params = null) {
        parent::__construct($query, $params);
    }

    public function extract_table() {
        preg_match('#^\s*select.*?from\s+`(.*?)`#is', $this->get_sql(), $m);
        return $m[1];
    }

    public function fetch_active() {
        $this->run();
        $row = @mysql_fetch_assoc($this->rq);
        if($row) {
            $m = 'DBix\Model';
            $m = new $m($this->db, $this->extract_table(), $row);
            return $m;
        } else {
            return false;
        }
    }
}