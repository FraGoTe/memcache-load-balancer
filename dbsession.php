<?php
if (isset($DBSESSION_DECLARED)) {
    return;
}

require_once($DOCUMENT_ROOT . "/common_cache.php");

if (function_exists("sessionOpen")) {
    return;
}

function sessionOpen($save_path, $session_name)
{
    global $HANDLER;
    global $MC_HANDLER;

    // Open database
    if (!isset($HANDLER)) {
        if (!($HANDLER = db_connect("payments"))) {
            die;
        }
    }

    // create memcached handle
    $MC_HANDLER = new Memcache;

    // connect to memcached servers
    $MC_HANDLER->addServer('localhost', 11211);

    // if we made it here memcached connection was good
    return true;
}

function sessionClose()
{
    return true;
}

function sessionDeclare($session_key, $session_defvalue = "")
{
    global $HTTP_GET_VARS;

    if (session_is_registered("$session_key")) {
        $value = $GLOBALS["$session_key"];
        return $value;
    }

    if (empty($session_defvalue)) {
        return "";
    }

    $GLOBALS["$session_key"] = $session_defvalue;

    session_register("$session_key");

    return $session_defvalue;
}

function sessionRead($session_key)
{
    global $session;
    global $MC_HANDLER;

    $session_key = addslashes($session_key);

    // try memcached first
    if ($result = $MC_HANDLER->get("MCSESS_" . $session_key)) {
        return stripslashes($result);
    }

    // if this key isn't in memcached - try the database
    $session_session_value = mysql_query("SELECT session_value FROM payments.sessions WHERE session_key = '$session_key'");

    if (mysql_numrows($session_session_value) == 1) {
        //echo mysql_result($session_session_value,0);
        return utf8_decode(mysql_result($session_session_value, 0));
    } else {
        return false;
    }
}

function sessionWrite($session_key, $val)
{
    global $session;
    global $MC_HANDLER;

    // exit if we didnt get a key for some odd reason
    if (empty($session_key)) return 0;

    $session_key = addslashes($session_key);
    $val = addslashes($val);

    // base64 encode on the way in for binary storage
    //$val = base64_encode($val);

    $session = @mysql_result(mysql_query("SELECT COUNT(*) FROM payments.sessions WHERE session_key = '$session_key'"), 0);
    $mc_session = $MC_HANDLER->get("MCSESS_" . $session_key);

    if ($session == 0) {
        // insert key into memcache for 5 minutes
        $MC_HANDLER->add("MCSESS_" . $session_key, $val, false, 300);

        // as well as the database
        $return = @mysql_query("INSERT INTO payments.sessions (session_key, session_expire, session_value)
								VALUES (_utf8\"" . /*mysql_real_escape_string*/
            (utf8_encode($session_key)) . "\", UNIX_TIMESTAMP(NOW()), '$val')");
    } else {
        // update memcache key
        $MC_HANDLER->replace("MCSESS_" . $session_key, $val, false, 300);

        // as well as the database
        $return = @mysql_query("UPDATE payments.sessions SET session_value = _utf8\"" . /*mysql_real_escape_string*/
            (utf8_encode($val)) . "\", session_expire = UNIX_TIMESTAMP(NOW())
								WHERE session_key = _utf8\"" . /*mysql_real_escape_string*/
            (utf8_encode($session_key)) . "\"");

        if (@mysql_affected_rows() < 0) {
            // echo "Failed updating session key $session_key";
        }
    }

    return $return;
}

function sessionDestroyer($session_key)
{
    global $session;
    global $MC_HANDLER;

    $session_key = addslashes($session_key);

    // delete key from memcache
    $MC_HANDLER->delete("MCSESS_" . $session_key);

    // and the database
    $return = mysql_query("DELETE FROM payments.sessions WHERE session_key = _utf8\"" . /*mysql_real_escape_string*/
    (utf8_encode($session_key)) . "\"");

    return $return;
}

function sessionGc($maxlifetime)
{
    global $session;

    $expirationTime = time() - $maxlifetime;

    // memcache keys expire on their own - just clean up the db
    $return = mysql_query("DELETE FROM payments.sessions WHERE session_expire < $expirationTime");

    return $return;
}

session_set_save_handler
(
    'sessionOpen',
    'sessionClose',
    'sessionRead',
    'sessionWrite',
    'sessionDestroyer',
    'sessionGc'
);