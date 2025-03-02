<?php

/**
 * +----------------------------------------------------------------------+
 * | PHP version 5                                                        |
 * +----------------------------------------------------------------------+
 * | Copyright (c) 1998-2008 Manuel Lemos, Tomas V.V.Cox,                 |
 * | Stig. S. Bakken, Lukas Smith                                         |
 * | All rights reserved.                                                 |
 * +----------------------------------------------------------------------+
 * | MDB2 is a merge of PEAR DB and Metabases that provides a unified DB  |
 * | API as well as database abstraction for PHP applications.            |
 * | This LICENSE is in the BSD license style.                            |
 * |                                                                      |
 * | Redistribution and use in source and binary forms, with or without   |
 * | modification, are permitted provided that the following conditions   |
 * | are met:                                                             |
 * |                                                                      |
 * | Redistributions of source code must retain the above copyright       |
 * | notice, this list of conditions and the following disclaimer.        |
 * |                                                                      |
 * | Redistributions in binary form must reproduce the above copyright    |
 * | notice, this list of conditions and the following disclaimer in the  |
 * | documentation and/or other materials provided with the distribution. |
 * |                                                                      |
 * | Neither the name of Manuel Lemos, Tomas V.V.Cox, Stig. S. Bakken,    |
 * | Lukas Smith nor the names of his contributors may be used to endorse |
 * | or promote products derived from this software without specific prior|
 * | written permission.                                                  |
 * |                                                                      |
 * | THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS  |
 * | "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT    |
 * | LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS    |
 * | FOR A PARTICULAR PURPOSE ARE DISCLAIMED.  IN NO EVENT SHALL THE      |
 * | REGENTS OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,          |
 * | INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, |
 * | BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS|
 * |  OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED  |
 * | AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT          |
 * | LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY|
 * | WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE          |
 * | POSSIBILITY OF SUCH DAMAGE.                                          |
 * +----------------------------------------------------------------------+
 * | Author: Paul Cooper <pgc@ucecom.com>                                 |
 * +----------------------------------------------------------------------+
 */

/**
 * MDB2 PostGreSQL driver
 *
 * @category Database
 * @package  MDB2
 * @author   Paul Cooper <pgc@ucecom.com>
 * @license  http://opensource.org/licenses/bsd-license.php BSD-2-Clause
 */
// @codingStandardsIgnoreLine
class MDB2_Driver_pgsql extends MDB2_Driver_Common
{
    // {{{ properties

    public $string_quoting = array('start' => "'", 'end' => "'", 'escape' => "'", 'escape_pattern' => '\\');

    public $identifier_quoting = array('start' => '"', 'end' => '"', 'escape' => '"');

    protected $restore_error_handler = false;

    // }}}
    // {{{ constructor

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->phptype = 'pgsql';
        $this->dbsyntax = 'pgsql';

        $this->supported['sequences'] = true;
        $this->supported['indexes'] = true;
        $this->supported['affected_rows'] = true;
        $this->supported['summary_functions'] = true;
        $this->supported['order_by_text'] = true;
        $this->supported['transactions'] = true;
        $this->supported['savepoints'] = true;
        $this->supported['current_id'] = true;
        $this->supported['limit_queries'] = true;
        $this->supported['LOBs'] = true;
        $this->supported['replace'] = 'emulated';
        $this->supported['sub_selects'] = true;
        $this->supported['triggers'] = true;
        $this->supported['auto_increment'] = 'emulated';
        $this->supported['primary_key'] = true;
        $this->supported['result_introspection'] = true;
        $this->supported['prepared_statements'] = true;
        $this->supported['identifier_quoting'] = true;
        $this->supported['pattern_escaping'] = true;
        $this->supported['new_link'] = true;

        $this->options['DBA_username'] = false;
        $this->options['DBA_password'] = false;
        $this->options['multi_query'] = false;
        $this->options['disable_smart_seqname'] = true;
        $this->options['max_identifiers_length'] = 63;
    }

    // }}}
    // {{{ errorInfo()

    /**
     * This method is used to collect information about an error
     *
     * @param integer $error
     * @return array
     */
    public function errorInfo($error = null)
    {
        // Fall back to MDB2_ERROR if there was no mapping.
        $error_code = MDB2_ERROR;

        $native_msg = '';
        if (is_a($error, 'PgSql\Result') || is_resource($error)) {
            $native_msg = @pg_result_error($error);
        } elseif ($this->connection) {
            $native_msg = @pg_last_error($this->connection);
            if (!$native_msg && @pg_connection_status($this->connection) === PGSQL_CONNECTION_BAD) {
                $native_msg = 'Database connection has been lost.';
                $error_code = MDB2_ERROR_CONNECT_FAILED;
            }
        } elseif ($this->restore_error_handler) {
            $php_error = error_get_last();
            if ($php_error) {
                $native_msg = $php_error['message'];
            }
        }

        $this->restoreErrorHandler();

        static $error_regexps;
        if (empty($error_regexps)) {
            $error_regexps = array(
                '/column .* (of relation .*)?does not exist/i'
                    => MDB2_ERROR_NOSUCHFIELD,
                '/(relation|sequence|table).*does not exist|class .* not found/i'
                    => MDB2_ERROR_NOSUCHTABLE,
                '/database .* does not exist/'
                    => MDB2_ERROR_NOT_FOUND,
                '/constraint .* does not exist/'
                    => MDB2_ERROR_NOT_FOUND,
                '/index .* does not exist/'
                    => MDB2_ERROR_NOT_FOUND,
                '/database .* already exists/i'
                    => MDB2_ERROR_ALREADY_EXISTS,
                '/relation .* already exists/i'
                    => MDB2_ERROR_ALREADY_EXISTS,
                '/(divide|division) by zero$/i'
                    => MDB2_ERROR_DIVZERO,
                '/pg_atoi: error in .*: can\'t parse /i'
                    => MDB2_ERROR_INVALID_NUMBER,
                '/invalid input syntax for( type)? (integer|numeric)/i'
                    => MDB2_ERROR_INVALID_NUMBER,
                '/value .* is out of range for type \w*int/i'
                    => MDB2_ERROR_INVALID_NUMBER,
                '/integer out of range/i'
                    => MDB2_ERROR_INVALID_NUMBER,
                '/value too long for type character/i'
                    => MDB2_ERROR_INVALID,
                '/attribute .* not found|relation .* does not have attribute/i'
                    => MDB2_ERROR_NOSUCHFIELD,
                '/column .* specified in USING clause does not exist in (left|right) table/i'
                    => MDB2_ERROR_NOSUCHFIELD,
                '/parser: parse error at or near/i'
                    => MDB2_ERROR_SYNTAX,
                '/syntax error at/'
                    => MDB2_ERROR_SYNTAX,
                '/column reference .* is ambiguous/i'
                    => MDB2_ERROR_SYNTAX,
                '/permission denied/'
                    => MDB2_ERROR_ACCESS_VIOLATION,
                '/violates not-null constraint/'
                    => MDB2_ERROR_CONSTRAINT_NOT_NULL,
                '/violates [\w ]+ constraint/'
                    => MDB2_ERROR_CONSTRAINT,
                '/referential integrity violation/'
                    => MDB2_ERROR_CONSTRAINT,
                '/more expressions than target columns/i'
                    => MDB2_ERROR_VALUE_COUNT_ON_ROW,
            );
        }
        if (is_numeric($error) && $error < 0) {
            $error_code = $error;
        } else {
            foreach ($error_regexps as $regexp => $code) {
                if (preg_match($regexp, $native_msg)) {
                    $error_code = $code;
                    break;
                }
            }
        }
        return array($error_code, null, $native_msg);
    }

    // }}}
    // {{{ restoreErrorHandler()

    protected function restoreErrorHandler()
    {
        if ($this->restore_error_handler) {
            restore_error_handler();
            $this->restore_error_handler = false;
        }
    }

    // }}}
    // {{{ useDefaultErrorHandler()

    protected function useDefaultErrorHandler()
    {
        if (!$this->restore_error_handler) {
            set_error_handler(null);
            $this->restore_error_handler = true;
        }
    }

    // }}}
    // {{{ escape()

    /**
     * Quotes a string so it can be safely used in a query. It will quote
     * the text so it can safely be used within a query.
     *
     * @param   string  the input string to quote
     * @param   bool    escape wildcards
     *
     * @return  string  quoted string
     */
    public function escape($text, $escape_wildcards = false)
    {
        if ($escape_wildcards) {
            $text = $this->escapePattern($text);
        }
        $connection = $this->getConnection();
        if (MDB2::isError($connection)) {
            return $connection;
        }

        if ((is_a($connection, 'PgSql\Connection') || is_resource($connection))) {
            $text = @pg_escape_string($connection, $text);
        }
        return $text;
    }

    // }}}
    // {{{ beginTransaction()

    /**
     * Start a transaction or set a savepoint.
     *
     * @param   string  name of a savepoint to set
     * @return  mixed   MDB2_OK on success, a MDB2 error on failure
     */
    public function beginTransaction($savepoint = null)
    {
        $this->debug('Starting transaction/savepoint', __FUNCTION__, array('is_manip' => true, 'savepoint' => $savepoint));
        if (null !== $savepoint) {
            if (!$this->in_transaction) {
                return $this->raiseError(
                    MDB2_ERROR_INVALID,
                    null,
                    null,
                    'savepoint cannot be released when changes are auto committed',
                    __FUNCTION__
                );
            }
            $query = 'SAVEPOINT '.$savepoint;
            return $this->doQuery($query, true);
        }
        if ($this->in_transaction) {
            return MDB2_OK;  //nothing to do
        }
        if (!$this->destructor_registered && $this->opened_persistent) {
            $this->destructor_registered = true;
            register_shutdown_function('MDB2_closeOpenTransactions');
        }
        $result = $this->doQuery('BEGIN', true);
        if (MDB2::isError($result)) {
            return $result;
        }
        $this->in_transaction = true;
        return MDB2_OK;
    }

    // }}}
    // {{{ commit()

    /**
     * Commit the database changes done during a transaction that is in
     * progress or release a savepoint. This function may only be called when
     * auto-committing is disabled, otherwise it will fail. Therefore, a new
     * transaction is implicitly started after committing the pending changes.
     *
     * @param   string  name of a savepoint to release
     * @return  mixed   MDB2_OK on success, a MDB2 error on failure
     */
    public function commit($savepoint = null)
    {
        $this->debug('Committing transaction/savepoint', __FUNCTION__, array('is_manip' => true, 'savepoint' => $savepoint));
        if (!$this->in_transaction) {
            return $this->raiseError(
                MDB2_ERROR_INVALID,
                null,
                null,
                'commit/release savepoint cannot be done changes are auto committed',
                __FUNCTION__
            );
        }
        if (null !== $savepoint) {
            $query = 'RELEASE SAVEPOINT '.$savepoint;
            return $this->doQuery($query, true);
        }

        $result = $this->doQuery('COMMIT', true);
        if (MDB2::isError($result)) {
            return $result;
        }
        $this->in_transaction = false;
        return MDB2_OK;
    }

    // }}}
    // {{{ rollback()

    /**
     * Cancel any database changes done during a transaction or since a specific
     * savepoint that is in progress. This function may only be called when
     * auto-committing is disabled, otherwise it will fail. Therefore, a new
     * transaction is implicitly started after canceling the pending changes.
     *
     * @param   string  name of a savepoint to rollback to
     * @return  mixed   MDB2_OK on success, a MDB2 error on failure
     */
    public function rollback($savepoint = null)
    {
        $this->debug('Rolling back transaction/savepoint', __FUNCTION__, array('is_manip' => true, 'savepoint' => $savepoint));
        if (!$this->in_transaction) {
            return $this->raiseError(
                MDB2_ERROR_INVALID,
                null,
                null,
                'rollback cannot be done changes are auto committed',
                __FUNCTION__
            );
        }
        if (null !== $savepoint) {
            $query = 'ROLLBACK TO SAVEPOINT '.$savepoint;
            return $this->doQuery($query, true);
        }

        $query = 'ROLLBACK';
        $result = $this->doQuery($query, true);
        if (MDB2::isError($result)) {
            return $result;
        }
        $this->in_transaction = false;
        return MDB2_OK;
    }

    // }}}
    // {{{ function setTransactionIsolation()

    /**
     * Set the transacton isolation level.
     *
     * @param   string  standard isolation level
     *                  READ UNCOMMITTED (allows dirty reads)
     *                  READ COMMITTED (prevents dirty reads)
     *                  REPEATABLE READ (prevents nonrepeatable reads)
     *                  SERIALIZABLE (prevents phantom reads)
     * @param   array some transaction options:
     *                  'wait' => 'WAIT' | 'NO WAIT'
     *                  'rw'   => 'READ WRITE' | 'READ ONLY'
     *
     * @return  mixed   MDB2_OK on success, a MDB2 error on failure
     *
     * @since   2.1.1
     */
    public function setTransactionIsolation($isolation, $options = array())
    {
        $this->debug('Setting transaction isolation level', __FUNCTION__, array('is_manip' => true));
        switch ($isolation) {
        case 'READ UNCOMMITTED':
        case 'READ COMMITTED':
        case 'REPEATABLE READ':
        case 'SERIALIZABLE':
            break;
        default:
            return $this->raiseError(
                MDB2_ERROR_UNSUPPORTED,
                null,
                null,
                'isolation level is not supported: ' . $isolation,
                __FUNCTION__
            );
        }

        $query = "SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL $isolation";
        return $this->doQuery($query, true);
    }

    // }}}
    // {{{ doConnect()

    /**
     * Do the grunt work of connecting to the database
     *
     * @return mixed connection resource on success, MDB2 Error Object on failure
     */
    protected function doConnect($username, $password, $database_name, $persistent = false)
    {
        if (!extension_loaded($this->phptype)) {
            return $this->raiseError(
                MDB2_ERROR_NOT_FOUND,
                null,
                null,
                'extension ' . $this->phptype . ' is not compiled into PHP',
                __FUNCTION__
            );
        }

        if ($database_name == '') {
            $database_name = 'template1';
        }

        $protocol = $this->dsn['protocol'] ? $this->dsn['protocol'] : 'tcp';

        $params = array('');
        if ($protocol == 'tcp') {
            if ($this->dsn['hostspec']) {
                $params[0].= 'host=' . $this->dsn['hostspec'];
            }
            if ($this->dsn['port']) {
                $params[0].= ' port=' . $this->dsn['port'];
            }
        } elseif ($protocol == 'unix') {
            // Allow for pg socket in non-standard locations.
            if ($this->dsn['socket']) {
                $params[0].= 'host=' . $this->dsn['socket'];
            }
            if ($this->dsn['port']) {
                $params[0].= ' port=' . $this->dsn['port'];
            }
        }
        if ($database_name) {
            $params[0].= ' dbname=\'' . addslashes($database_name) . '\'';
        }
        if ($username) {
            $params[0].= ' user=\'' . addslashes($username) . '\'';
        }
        if ($password) {
            $params[0].= ' password=\'' . addslashes($password) . '\'';
        }
        if (!empty($this->dsn['options'])) {
            $params[0].= ' options=' . $this->dsn['options'];
        }
        if (!empty($this->dsn['tty'])) {
            $params[0].= ' tty=' . $this->dsn['tty'];
        }
        if (!empty($this->dsn['connect_timeout'])) {
            $params[0].= ' connect_timeout=' . $this->dsn['connect_timeout'];
        }
        if (!empty($this->dsn['sslmode'])) {
            $params[0].= ' sslmode=' . $this->dsn['sslmode'];
        }
        if (!empty($this->dsn['service'])) {
            $params[0].= ' service=' . $this->dsn['service'];
        }

        if ($this->isNewLinkSet()) {
            $params[] = PGSQL_CONNECT_FORCE_NEW;
        }

        // PostgreSQL connection errors will use standard PHP errors rather
        // than libpq errors. Restore the built-in error handler so we can
        // capture connection error messages.
        $this->useDefaultErrorHandler();
        $connect_function = $persistent ? 'pg_pconnect' : 'pg_connect';
        $connection = @call_user_func_array($connect_function, $params);
        if (!$connection) {
            return $this->raiseError(
                MDB2_ERROR_CONNECT_FAILED,
                null,
                null,
                'unable to establish a connection',
                __FUNCTION__
            );
        }
        $this->restoreErrorHandler();

        if (empty($this->dsn['disable_iso_date'])) {
            if (!@pg_query($connection, "SET SESSION DATESTYLE = 'ISO'")) {
                return $this->raiseError(
                    null,
                    null,
                    null,
                    'Unable to set date style to iso',
                    __FUNCTION__
                );
            }
        }

        if (!empty($this->dsn['charset'])) {
            $result = $this->setCharset($this->dsn['charset'], $connection);
            if (MDB2::isError($result)) {
                return $result;
            }
        }

        // Enable extra compatibility settings on 8.2 and later
        if (function_exists('pg_parameter_status')) {
            $version = pg_parameter_status($connection, 'server_version');
            if ($version == false) {
                return $this->raiseError(
                    null,
                    null,
                    null,
                    'Unable to retrieve server version',
                    __FUNCTION__
                );
            }
            $version = explode('.', $version);
            if ($version['0'] > 8
                || ($version['0'] == 8 && $version['1'] >= 2)
            ) {
                if (!@pg_query($connection, "SET SESSION STANDARD_CONFORMING_STRINGS = OFF")) {
                    return $this->raiseError(
                        null,
                        null,
                        null,
                        'Unable to set standard_conforming_strings to off',
                        __FUNCTION__
                    );
                }

                if (!@pg_query($connection, "SET SESSION ESCAPE_STRING_WARNING = OFF")) {
                    return $this->raiseError(
                        null,
                        null,
                        null,
                        'Unable to set escape_string_warning to off',
                        __FUNCTION__
                    );
                }
            }
        }

        return $connection;
    }

    // }}}
    // {{{ connect()

    /**
     * Connect to the database
     *
     * @return true on success, MDB2 Error Object on failure
     */
    public function connect()
    {
        if (is_a($this->connection, 'PgSql\Connection')
            || is_resource($this->connection)
        ) {
            //if (count(array_diff($this->connected_dsn, $this->dsn)) == 0
            if (MDB2::areEquals($this->connected_dsn, $this->dsn)
                && $this->connected_database_name == $this->database_name
                && ($this->opened_persistent == $this->options['persistent'])
            ) {
                return MDB2_OK;
            }
            $this->disconnect(false);
        }

        if ($this->database_name) {
            $connection = $this->doConnect(
                $this->dsn['username'],
                $this->dsn['password'],
                $this->database_name,
                $this->options['persistent']
            );

            if (MDB2::isError($connection)) {
                return $connection;
            }

            $this->connection = $connection;
            $this->connected_dsn = $this->dsn;
            $this->connected_database_name = $this->database_name;
            $this->opened_persistent = $this->options['persistent'];
            $this->dbsyntax = $this->dsn['dbsyntax'] ? $this->dsn['dbsyntax'] : $this->phptype;
        }

        return MDB2_OK;
    }

    // }}}
    // {{{ setCharset()

    /**
     * Set the charset on the current connection
     *
     * @param string    charset
     * @param resource  connection handle
     *
     * @return true on success, MDB2 Error Object on failure
     */
    public function setCharset($charset, $connection = null)
    {
        if (null === $connection) {
            $connection = $this->getConnection();
            if (MDB2::isError($connection)) {
                return $connection;
            }
        }
        if (is_array($charset)) {
            $charset   = array_shift($charset);
            $this->warnings[] = 'postgresql does not support setting client collation';
        }
        $result = @pg_set_client_encoding($connection, $charset);
        if ($result == -1) {
            return $this->raiseError(
                null,
                null,
                null,
                'Unable to set client charset: ' . $charset,
                __FUNCTION__
            );
        }
        return MDB2_OK;
    }

    // }}}
    // {{{ databaseExists()

    /**
     * check if given database name is exists?
     *
     * @param string $name    name of the database that should be checked
     *
     * @return mixed true/false on success, a MDB2 error on failure
     */
    public function databaseExists($name)
    {
        $res = $this->doConnect(
            $this->dsn['username'],
            $this->dsn['password'],
            $this->escape($name),
            $this->options['persistent']
        );

        if (!MDB2::isError($res)) {
            return true;
        }

        return false;
    }

    // }}}
    // {{{ disconnect()

    /**
     * Log out and disconnect from the database.
     *
     * @param  boolean $force if the disconnect should be forced even if the
     *                        connection is opened persistently
     * @return mixed true on success, false if not connected and error
     *                object on error
     */
    public function disconnect($force = true)
    {
        if (is_a($this->connection, 'PgSql\Connection')
            || is_resource($this->connection)
        ) {
            if ($this->in_transaction) {
                $dsn = $this->dsn;
                $database_name = $this->database_name;
                $persistent = $this->options['persistent'];
                $this->dsn = $this->connected_dsn;
                $this->database_name = $this->connected_database_name;
                $this->options['persistent'] = $this->opened_persistent;
                $this->rollback();
                $this->dsn = $dsn;
                $this->database_name = $database_name;
                $this->options['persistent'] = $persistent;
            }

            if (!$this->opened_persistent || $force) {
                $ok = @pg_close($this->connection);
                if (!$ok) {
                    return $this->raiseError(
                        MDB2_ERROR_DISCONNECT_FAILED,
                        null,
                        null,
                        null,
                        __FUNCTION__
                    );
                }
            }
        } else {
            return false;
        }
        return parent::disconnect($force);
    }

    // }}}
    // {{{ standaloneQuery()

    /**
     * execute a query as DBA
     *
     * @param string $query the SQL query
     * @param mixed   $types  array that contains the types of the columns in
     *                        the result set
     * @param boolean $is_manip  if the query is a manipulation query
     * @return mixed MDB2_OK on success, a MDB2 error on failure
     */
    public function standaloneQuery($query, $types = null, $is_manip = false)
    {
        $user = $this->options['DBA_username']? $this->options['DBA_username'] : $this->dsn['username'];
        $pass = $this->options['DBA_password']? $this->options['DBA_password'] : $this->dsn['password'];
        $connection = $this->doConnect($user, $pass, $this->database_name, $this->options['persistent']);
        if (MDB2::isError($connection)) {
            return $connection;
        }

        $offset = $this->offset;
        $limit = $this->limit;
        $this->offset = $this->limit = 0;
        $query = $this->modifyQuery($query, $is_manip, $limit, $offset);

        $result = $this->doQuery($query, $is_manip, $connection, $this->database_name);
        if (!MDB2::isError($result)) {
            if ($is_manip) {
                $result =  $this->affectedRows($connection, $result);
            } else {
                $result = $this->wrapResult($result, $types, true, true, $limit, $offset);
            }
        }

        @pg_close($connection);
        return $result;
    }

    // }}}
    // {{{ doQuery()

    /**
     * Execute a query
     * @param string $query  query
     * @param boolean $is_manip  if the query is a manipulation query
     * @param resource $connection
     * @param string $database_name
     * @return result or error object
     */
    protected function doQuery($query, $is_manip = false, $connection = null, $database_name = null)
    {
        $this->last_query = $query;
        $result = $this->debug($query, 'query', array('is_manip' => $is_manip, 'when' => 'pre'));
        if ($result) {
            if (MDB2::isError($result)) {
                return $result;
            }
            $query = $result;
        }
        if ($this->options['disable_query']) {
            $result = $is_manip ? 0 : null;
            return $result;
        }

        if (null === $connection) {
            $connection = $this->getConnection();
            if (MDB2::isError($connection)) {
                return $connection;
            }
        }

        $function = $this->options['multi_query'] ? 'pg_send_query' : 'pg_query';
        $result = @$function($connection, $query);
        if (!$result) {
            $err = $this->raiseError(
                null,
                null,
                null,
                'Could not execute statement',
                __FUNCTION__
            );
            return $err;
        } elseif ($this->options['multi_query']) {
            if (!($result = @pg_get_result($connection))) {
                $err = $this->raiseError(
                    null,
                    null,
                    null,
                    'Could not get the first result from a multi query',
                    __FUNCTION__
                );
                return $err;
            }
        }

        $this->debug($query, 'query', array('is_manip' => $is_manip, 'when' => 'post', 'result' => $result));
        return $result;
    }

    // }}}
    // {{{ affectedRows()

    /**
     * Returns the number of rows affected
     *
     * @param resource $result
     * @param resource $connection
     * @return mixed MDB2 Error Object or the number of rows affected
     */
    protected function affectedRows($connection, $result = null)
    {
        if (null === $connection) {
            $connection = $this->getConnection();
            if (MDB2::isError($connection)) {
                return $connection;
            }
        }
        return @pg_affected_rows($result);
    }

    // }}}
    // {{{ modifyQuery()

    /**
     * Changes a query string for various DBMS specific reasons
     *
     * @param string $query  query to modify
     * @param boolean $is_manip  if it is a DML query
     * @param integer $limit  limit the number of rows
     * @param integer $offset  start reading from given offset
     * @return string modified query
     */
    protected function modifyQuery($query, $is_manip, $limit, $offset)
    {
        if ($limit > 0
            && !preg_match('/LIMIT\s*\d(?:\s*(?:,|OFFSET)\s*\d+)?(?:[^\)]*)?$/i', $query)
        ) {
            $query = rtrim($query);
            if (mb_substr($query, -1) === ';') {
                $query = mb_substr($query, 0, -1);
            }
            if ($is_manip) {
                $query = $this->modifyManipQuery($query, $limit);
            } else {
                $query.= " LIMIT $limit OFFSET $offset";
            }
        }
        return $query;
    }

    // }}}
    // {{{ modifyManipQuery()

    /**
     * Changes a manip query string for various DBMS specific reasons
     *
     * @param string $query  query to modify
     * @param integer $limit  limit the number of rows
     * @return string modified query
     */
    protected function modifyManipQuery($query, $limit)
    {
        $pos = mb_strpos(mb_strtolower($query), 'where');
        $where = $pos ? mb_substr($query, $pos) : '';

        $manip_clause = '(\bDELETE\b\s+(?:\*\s+)?\bFROM\b|\bUPDATE\b)';
        $from_clause  = '([\w\.]+)';
        $where_clause = '(?:(.*)\bWHERE\b\s+(.*))|(.*)';
        $pattern = '/^'. $manip_clause . '\s+' . $from_clause .'(?:\s)*(?:'. $where_clause .')?$/i';
        $matches = preg_match($pattern, $query, $match);
        if ($matches) {
            $manip = $match[1];
            $from  = $match[2];
            $what  = (count($matches) === 6) ? $match[5] : $match[3];
            return $manip.' '.$from.' '.$what.' WHERE ctid=(SELECT ctid FROM '.$from.' '.$where.' LIMIT '.$limit.')';
        }
        //return error?
        return $query;
    }

    // }}}
    // {{{ getServerVersion()

    /**
     * return version information about the server
     *
     * @param bool   $native  determines if the raw version string should be returned
     * @return mixed array/string with version information or MDB2 error object
     */
    public function getServerVersion($native = false)
    {
        $query = 'SHOW SERVER_VERSION';
        if ($this->connected_server_info) {
            $server_info = $this->connected_server_info;
        } else {
            $server_info = $this->queryOne($query, 'text');
            if (MDB2::isError($server_info)) {
                return $server_info;
            }
        }
        // cache server_info
        $this->connected_server_info = $server_info;
        if (!$native && !MDB2::isError($server_info)) {
            $tmp = explode('.', $server_info, 3);
            if (empty($tmp[2])
                && isset($tmp[1])
                && preg_match('/(\d+)(.*)/', $tmp[1], $tmp2)
            ) {
                $server_info = array(
                    'major' => $tmp[0],
                    'minor' => $tmp2[1],
                    'patch' => null,
                    'extra' => $tmp2[2],
                    'native' => $server_info,
                );
            } else {
                $server_info = array(
                    'major' => isset($tmp[0]) ? $tmp[0] : null,
                    'minor' => isset($tmp[1]) ? $tmp[1] : null,
                    'patch' => isset($tmp[2]) ? $tmp[2] : null,
                    'extra' => null,
                    'native' => $server_info,
                );
            }
        }
        return $server_info;
    }

    // }}}
    // {{{ prepare()

    /**
     * Prepares a query for multiple execution with execute().
     * With some database backends, this is emulated.
     * prepare() requires a generic query as string like
     * 'INSERT INTO numbers VALUES(?,?)' or
     * 'INSERT INTO numbers VALUES(:foo,:bar)'.
     * The ? and :name and are placeholders which can be set using
     * bindParam() and the query can be sent off using the execute() method.
     * The allowed format for :name can be set with the 'bindname_format' option.
     *
     * @param string $query the query to prepare
     * @param mixed   $types  array that contains the types of the placeholders
     * @param mixed   $result_types  array that contains the types of the columns in
     *                        the result set or MDB2_PREPARE_RESULT, if set to
     *                        MDB2_PREPARE_MANIP the query is handled as a manipulation query
     * @param mixed   $lobs   key (field) value (parameter) pair for all lob placeholders
     * @return mixed resource handle for the prepared query on success, a MDB2
     *        error on failure
     * @see bindParam, execute
     */
    public function prepare($query, $types = null, $result_types = null, $lobs = array())
    {
        if ($this->options['emulate_prepared']) {
            return parent::prepare($query, $types, $result_types, $lobs);
        }
        $is_manip = ($result_types === MDB2_PREPARE_MANIP);
        $offset = $this->offset;
        $limit = $this->limit;
        $this->offset = $this->limit = 0;
        $result = $this->debug($query, __FUNCTION__, array('is_manip' => $is_manip, 'when' => 'pre'));
        if ($result) {
            if (MDB2::isError($result)) {
                return $result;
            }
            $query = $result;
        }
        $pgtypes = function_exists('pg_prepare') ? false : array();
        if ($pgtypes !== false && !empty($types)) {
            $this->loadModule('Datatype', null, true);
        }
        $query = $this->modifyQuery($query, $is_manip, $limit, $offset);
        $placeholder_type_guess = $placeholder_type = null;
        $question = '?';
        $colon = ':';
        $positions = array();
        $position = $parameter = 0;
        while ($position < mb_strlen($query)) {
            $q_position = mb_strpos($query, $question, $position);
            $c_position = mb_strpos($query, $colon, $position);
            //skip "::type" cast ("select id::varchar(20) from sometable where name=?")
            $doublecolon_position = mb_strpos($query, '::', $position);
            if ($doublecolon_position !== false && $doublecolon_position == $c_position) {
                $c_position = mb_strpos($query, $colon, $position + 2);
            }
            if ($q_position && $c_position) {
                $p_position = min($q_position, $c_position);
            } elseif ($q_position) {
                $p_position = $q_position;
            } elseif ($c_position) {
                $p_position = $c_position;
            } else {
                break;
            }
            if (null === $placeholder_type) {
                $placeholder_type_guess = $query[$p_position];
            }

            $new_pos = $this->skipDelimitedStrings($query, $position, $p_position);
            if (MDB2::isError($new_pos)) {
                return $new_pos;
            }
            if ($new_pos != $position) {
                $position = $new_pos;
                continue; //evaluate again starting from the new position
            }

            if ($query[$position] == $placeholder_type_guess) {
                if (null === $placeholder_type) {
                    $placeholder_type = $query[$p_position];
                    $question = $colon = $placeholder_type;
                    if (!empty($types) && is_array($types)) {
                        if ($placeholder_type == ':') {
                        } else {
                            $types = array_values($types);
                        }
                    }
                }
                if ($placeholder_type_guess == '?') {
                    $length = 1;
                    $name = $parameter;
                } else {
                    $regexp = '/^.{'.($position+1).'}('.$this->options['bindname_format'].').*$/s';
                    $param = preg_replace($regexp, '\\1', $query);
                    if ($param === '') {
                        $err = $this->raiseError(
                            MDB2_ERROR_SYNTAX,
                            null,
                            null,
                            'named parameter name must match "bindname_format" option',
                            __FUNCTION__
                        );
                        return $err;
                    }
                    $length = mb_strlen($param) + 1;
                    $name = $param;
                }
                if ($pgtypes !== false) {
                    if (is_array($types) && array_key_exists($name, $types)) {
                        $pgtypes[] = $this->datatype->mapPrepareDatatype($types[$name]);
                    } elseif (is_array($types) && array_key_exists($parameter, $types)) {
                        $pgtypes[] = $this->datatype->mapPrepareDatatype($types[$parameter]);
                    } else {
                        $pgtypes[] = 'text';
                    }
                }
                if (($key_parameter = array_search($name, $positions)) !== false) {
                    //$next_parameter = 1;
                    $parameter = $key_parameter + 1;
                    //foreach ($positions as $key => $value) {
                    //    if ($key_parameter == $key) {
                    //        break;
                    //    }
                    //    ++$next_parameter;
                    //}
                } else {
                    ++$parameter;
                    //$next_parameter = $parameter;
                    $positions[] = $name;
                }
                $query = mb_substr($query, 0, $position) . '$' . $parameter . mb_substr($query, $position + $length);
                $position = $p_position + mb_strlen($parameter);
            } else {
                $position = $p_position;
            }
        }
        $connection = $this->getConnection();
        if (MDB2::isError($connection)) {
            return $connection;
        }
        static $prep_statement_counter = 1;
        $statement_name = sprintf($this->options['statement_format'], $this->phptype, $prep_statement_counter++ . sha1(microtime() + mt_rand()));
        $statement_name = mb_substr(mb_strtolower($statement_name), 0, $this->options['max_identifiers_length']);
        if (false === $pgtypes) {
            $result = @pg_prepare($connection, $statement_name, $query);
            if (!$result) {
                $err = $this->raiseError(
                    null,
                    null,
                    null,
                    'Unable to create prepared statement handle',
                    __FUNCTION__
                );
                return $err;
            }
        } else {
            $types_string = '';
            if ($pgtypes) {
                $types_string = ' ('.implode(', ', $pgtypes).') ';
            }
            $query = 'PREPARE '.$statement_name.$types_string.' AS '.$query;
            $statement = $this->doQuery($query, true, $connection);
            if (MDB2::isError($statement)) {
                return $statement;
            }
        }

        $class_name = 'MDB2_Statement_'.$this->phptype;
        $obj = new $class_name($this, $statement_name, $positions, $query, $types, $result_types, $is_manip, $limit, $offset);
        $this->debug($query, __FUNCTION__, array('is_manip' => $is_manip, 'when' => 'post', 'result' => $obj));
        return $obj;
    }

    // }}}
    // {{{ function getSequenceName($sqn)

    /**
     * adds sequence name formatting to a sequence name
     *
     * @param   string  name of the sequence
     *
     * @return  string  formatted sequence name
     */
    public function getSequenceName($sqn)
    {
        if (false === $this->options['disable_smart_seqname']) {
            if (mb_strpos($sqn, '_') !== false) {
                list($table, $field) = explode('_', $sqn, 2);
            }
            $schema_list = $this->queryOne("SELECT array_to_string(current_schemas(false), ',')");
            if (MDB2::isError($schema_list) || empty($schema_list) || count($schema_list) < 2) {
                $order_by = ' a.attnum';
                $schema_clause = ' AND n.nspname=current_schema()';
            } else {
                $schemas = explode(',', $schema_list);
                $schema_clause = ' AND n.nspname IN ('.$schema_list.')';
                $counter = 1;
                $order_by = ' CASE ';
                foreach ($schemas as $schema) {
                    $order_by .= ' WHEN n.nspname='.$schema.' THEN '.$counter++;
                }
                $order_by .= ' ELSE '.$counter.' END, a.attnum';
            }

            $query = "SELECT substring((SELECT substring(pg_get_expr(d.adbin, d.adrelid) for 128)
                    	    FROM pg_attrdef d
                    	   WHERE d.adrelid = a.attrelid
                    	     AND d.adnum = a.attnum
                    	     AND a.atthasdef
                    	 ) FROM 'nextval[^'']*''([^'']*)')
                        FROM pg_attribute a
                    LEFT JOIN pg_class c ON c.oid = a.attrelid
                    LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum AND a.atthasdef
                    LEFT JOIN pg_namespace n ON c.relnamespace = n.oid
                       WHERE (c.relname = ".$this->quote($sqn, 'text');
            if (!empty($field)) {
                $query .= " OR (c.relname = ".$this->quote($table, 'text')." AND a.attname = ".$this->quote($field, 'text').")";
            }
            $query .= "      )"
                         .$schema_clause."
                         AND NOT a.attisdropped
                         AND a.attnum > 0
                         AND pg_get_expr(d.adbin, d.adrelid) LIKE 'nextval%'
                    ORDER BY ".$order_by;
            $seqname = $this->queryOne($query);
            if (!MDB2::isError($seqname) && !empty($seqname) && is_string($seqname)) {
                return $seqname;
            }
        }

        return parent::getSequenceName($sqn);
    }

    // }}}
    // {{{ nextID()

    /**
     * Returns the next free id of a sequence
     *
     * @param string $seq_name name of the sequence
     * @param boolean $ondemand when true the sequence is
     *                          automatic created, if it
     *                          not exists
     * @return mixed MDB2 Error Object or id
     */
    public function nextID($seq_name, $ondemand = true)
    {
        $sequence_name = $this->quoteIdentifier($this->getSequenceName($seq_name), true);
        $query = "SELECT NEXTVAL('$sequence_name')";
        $this->pushErrorHandling(PEAR_ERROR_RETURN);
        $this->expectError(MDB2_ERROR_NOSUCHTABLE);
        $result = $this->queryOne($query, 'integer');
        $this->popExpect();
        $this->popErrorHandling();
        if (MDB2::isError($result)) {
            if ($ondemand && $result->getCode() == MDB2_ERROR_NOSUCHTABLE) {
                $this->loadModule('Manager', null, true);
                $result = $this->manager->createSequence($seq_name);
                if (MDB2::isError($result)) {
                    return $this->raiseError(
                        $result,
                        null,
                        null,
                        'on demand sequence could not be created',
                        __FUNCTION__
                    );
                }
                return $this->nextId($seq_name, false);
            }
        }
        return $result;
    }

    // }}}
    // {{{ lastInsertID()

    /**
     * Returns the autoincrement ID if supported or $id or fetches the current
     * ID in a sequence called: $table.(empty($field) ? '' : '_'.$field)
     *
     * @param string $table name of the table into which a new row was inserted
     * @param string $field name of the field into which a new row was inserted
     * @return mixed MDB2 Error Object or id
     */
    public function lastInsertID($table = null, $field = null)
    {
        if (empty($table) && empty($field)) {
            return $this->queryOne('SELECT lastval()', 'integer');
        }
        $seq = $table.(empty($field) ? '' : '_'.$field);
        $sequence_name = $this->quoteIdentifier($this->getSequenceName($seq), true);
        return $this->queryOne("SELECT currval('$sequence_name')", 'integer');
    }

    // }}}
    // {{{ currID()

    /**
     * Returns the current id of a sequence
     *
     * @param string $seq_name name of the sequence
     * @return mixed MDB2 Error Object or id
     */
    public function currID($seq_name)
    {
        $sequence_name = $this->quoteIdentifier($this->getSequenceName($seq_name), true);
        return $this->queryOne("SELECT last_value FROM $sequence_name", 'integer');
    }

    // }}}
}

?>
