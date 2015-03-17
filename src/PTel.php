<?php

namespace PTel;

use PTel\TelnetException,
    PTel\SocketClientException;

define('TEL_IAC', chr(255)); // Interpret as command
define('TEL_DO', chr(253)); // Use option
define('TEL_DONT', chr(254)); // Don't use option
define('TEL_WILL', chr(251)); // Will use option
define('TEL_WONT', chr(252)); // Won't use option
define('TEL_GA', chr(3)); // Go ahead
define('TEL_ECHO', chr(1)); // Echo
define('TEL_SUB', chr(250)); // Subnegotiation (suboption)
define('TEL_SUBEND', chr(240)); // Subnegotiation (suboption) end
define('TEL_TTYPE', chr(24)); // Terminal type
define('TEL_BIN', chr(0)); // 8-bit binary data
define('TEL_NAWS', chr(31)); // Negotiate about window size
define('TEL_TSPEED', chr(32)); // terminal speed
define('TEL_RFLOW', chr(33)); // remote flow control
define('TEL_LINEMODE', chr(34)); // Linemode option
define('TEL_NEWENV', chr(39)); // New - Environment variables
define('TEL_STATUS', chr(5)); // Give status
define('TEL_XDISPLOC', chr(35)); // X Display Location

/**
 * PHP Telnet class which I wrote for my daily use.
 *
 * @package  PTel
 * @author   Alex Bo <thebosha@gmail.com>
 */

class PTel
{

    private

        /**
        /**
         * Connection handler
         */
        $_sock = null,

        /**
         * @var string  Global buffer with socket output
         */
        $_buff = null,

        /**
         * @var string  Temporary global buffer with socket output
         */
        $_tmpbuff = null,

        /**
         * @var int  Inbound Terminal speed
         */
         $_tspeed_in = '38000',

        /**
         * @var int  Outbound Terminal speed
         */
         $_tspeed_out = '38000',

        /**
         * @var string  Default terminal type
         */
         $_termtype = 'xterm',

        /**
         * @var bool  Set blocking/non-blocking modes
         */
        $_blocking = true,

        /**
         * @var int  Telnet timeout while waiting new data
         */
        $_timeout = 18000,

        /**
         * $var bool  Determine to enable/disable telnet negotiation
         */
        $_enableNegotiation = true;

    public

        /**
         * @var string  Return carriage symbols
         */
        $retcarriage = "\n",


        /**
         * @var string  Page delimiters
         */
        $page_delimiter = "(ctrl\+C|--more--|quit\))",

        /**
         * @var string  Telnet prompt
         */
        $prompt = null;

    /**
     * Constructor. Settings options.
     *
     * @param   $blocking   bool    Blocking mode
     * @param   $timeout    int     Timeout while waiting new data from socket
     */
    public function __construct($blocking = true, $timeout = 380000) {
        $this->_blocking = $blocking;
        $this->_timeout = $timeout;
    }

    /**
     * Connect to given host/port
     *
     * @param   $host       string   IP-Address or host to connect
     * @param   $port       int      Port
     * @param   $timeout    int      Connection timeout
     *
     * @throws  SocketClientException   On socket connection error
     * @throws  TelnetException         On error while resolving IP-address
     */
    public function connect($host, $port = null, $timeout = null) {

        $port = (empty($port)) ? 23 : $port;
        $timeout = (empty($timeout)) ? 1 : $timeout;

        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = gethostbyname($host);
            if ($ip !== $host) {
                $host = $ip;
            } else {
                throw new TelnetException('Could not resolve IP-address');
            }
        }

        $this->_sock = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$this->_sock) {
            throw new SocketClientException('Failed to connect to '.$host.":".$port.'!');
        }

        stream_set_timeout($this->_sock, 0, $this->_timeout);
        stream_set_blocking($this->_sock, $this->_blocking);

        if ($this->_enableNegotiation) {
            $topts =  TEL_IAC.TEL_DO.TEL_GA.         // DO Go ahead
                      TEL_IAC.TEL_WILL.TEL_TTYPE.    // WILL Terminal type
                      TEL_IAC.TEL_WILL.TEL_NAWS.     // WILL Negotiate about window size
                      TEL_IAC.TEL_WILL.TEL_TSPEED.   // WILL Terminal speed
                      TEL_IAC.TEL_WILL.TEL_RFLOW.    // WILL Remote flow control
                      TEL_IAC.TEL_WILL.TEL_LINEMODE. // WILL Linemode
                      TEL_IAC.TEL_WILL.TEL_NEWENV.   // WILL New environment
                      TEL_IAC.TEL_DO.TEL_STATUS.     // DO Status
                      TEL_IAC.TEL_WILL.TEL_XDISPLOC; // WILL X Display location

            $this->send($topts, false);
        }
    }

    /**
     * Login to device.
     *
     * @param string    $user   Username
     * @param string    $pass   Password
     *
     * @throws  TelnetException         On wrong username/password
     * @throws  SocketClientException   On socket communication error
     * @return  bool                    True on success login
     */
    public function login($user, $pass, $maxtimeout = 10) {
        try {
            $this->expect('((U|u)ser|(L|l)ogin)((N|n)ame|)(:|)', $user);
            $this->expect('(P|p)ass((W|w)ord|)(:|)', $pass);
        } catch (Exception $e) {
            throw new TelnetException('Could not find password request. Login failed.');
        }

        $timestart = time();
        $buff = '';
        while (true) {
            $buff = $this->recvLine();
            $timerun = time() - $timestart;

            if (preg_match("/(fail|wrong|incorrect|failed)/i", $buff)) {
                throw new TelnetException("Username or password wrong! Login failed");
            }

            if (preg_match("/(#|>|\$)/", $buff)) {
                break;
            }

            if ($timerun >= $maxtimeout) {
                throw new TelnetException("Could not get reply from device. Login failed.");
            }
        }

        $lines = explode("\n", $this->getBuffer());
        $prompt = array_slice($lines, -1);
        $this->prompt = $prompt[0];
        return $this;
    }

    /**
     * Send data to socket
     *
     * @param   $data       mixed   Can be anything: string, int, hex, binary
     * @param   $newline    bool    Determines send or not carriage return
     *
     * @throws  SocketClientException   On socket communication error
     *
     * @return  int   Bytes written
     */
    public function send($data, $newline = true) {
        if (!$this->_sock) { throw new SocketClientException("Connection unexpectedly closed!"); }
        if ($newline) { $data = $data.$this->retcarriage; }
        if (! $wr = fwrite($this->_sock, $data)) {
            throw new SocketClientException('Error while sending data!');
        }
        return $wr;
    }

    /**
     * Get char from socket or call negotiation method if found telnet
     * negotiaton command.
     *
     * @throws  SocketClientException   On socket communication error
     * @return  char                    Char from socket connection
     */
     public function recvChr() {
        if (!$this->_sock) { throw new SocketClientException("Connection gone!"); }
        $char = fgetc($this->_sock);
        if ($this->stream_eof()) { return false; }
        if ($char === TEL_IAC && $this->_enableNegotiation) {
            $this->_negotiate(fgetc($this->_sock));
            return "";
        }
        $this->_buff .= $char;
        return $char;
     }

    /**
     * Receive line from connection
     *
     * @param   string  $delimiter  Line delimiter
     *
     * @throws  SocketClientException   On socket communication error
     * @return  string                  String from socket connection
     */
    public function recvLine($delimiter = "\n") {
        $str = '';
        while (!$this->stream_eof()) {
            $char = $this->recvChr();
            $str .= $char;
            if ($char === false) { return $str; }
            if (strpos($str, $delimiter) !== false) { return $str; }
        }
        return $str;
    }

    /**
     * Receive all till end from socket connection
     *
     * @throws  SocketClientException   On socket communication error
     * @return  string                  Recieve all from socket connection
     */
    public function recvAll() {
        $return = '';
        while (!$this->stream_eof() && !$this->timedOut()) {
            $return .= $this->recvChr();
        }
        return $return;
    }

    /**
    * Search for given string/regexp in stream output
    *
    * @param    string  $str    String or regexp for search
    *
    * @throws   SocketClientException   On socket communication error
    * @return   string|bool   Line with search match, or false if not found/timeout
    */
    public function find($str) {
        while ($line = $this->recvLine()) {
            if (preg_match("/{$str}/", $line, $matches)) {
                return $matches[0];
            }
        }
        return false;
    }

    /**
     * Receive all data, and search through global buffer
     *
     * @param string    $str    String to search (regex supported)
     *
     * @return bool|string  False if not found, string contains search
     */
    public function findAll($str) {
        $this->recvAll();
        $output_as_array = explode($this->retcarriage, $this->getBuffer());
        foreach ($output_as_array as $line) {
            if (preg_match("/{$str}/", $line, $matches)) {
                return $matches[0];
            }
        }
        return false;
    }

    /**
     * Search for given occurance and send given command if found
     *
     * @param    string  $str        String/regexp for search
     * @param    string  $cmd        Command to send
     * @param    bool    $newline    Send new line character
     *
     * @throws   SocketClientException   On socket communication error
     * @throws   TelnetException         If wait timeout is reached
     * @return   bool    True on search and send success, false otherwise
     */
    public function expect($str, $cmd, $newline = true) {
        if ($this->waitFor($str)) {
            $this->send($cmd, $newline);
            return true;
        }
    }

    /**
     * Checking is connection alive
     *
     * @throws   SocketClientException   On socket communication error
     * @return   bool    True if socket EOF, false otherwise
     */
    public function stream_eof() {
        if (!$this->_sock) { throw new SocketClientException("Connection gone!"); }
        return feof($this->_sock);
    }

    /**
     * Checking for output waiting timeout
     *
     * @throws   SocketClientException   On socket communication error
     * @return   bool                    True if connection timed out, false otherwise
     */
    public function timedOut() { return $this->getMetaData('timed_out'); }

    /**
     * Return number of unread bytes from socket stream
     *
     * @throws   SocketClientException   On socket communication error
     * @return   int                     Unread bytes from stream
     */
    public function unreadBytes() { return $this->getMetaData('unread_bytes'); }

    /**
     * Return stream meta data parameter
     *
     * @param    $param      string      Parameter name
     *
     * @throws   SocketClientException   On socket communication error
     * @return   mixed                   Value of parameter
     */
    public function getMetaData($param) {
        if (!$this->_sock) { throw new SocketClientException("Connection gone!"); }
        $info = stream_get_meta_data($this->_sock);
        return $info[$param];
    }

    /**
     * Return global buffer
     *
     * @return   string      Global buffer contents
     */
    public function getBuffer() { return $this->_buff; }

    /**
     * Clear global buffer
     */
    public function clearBuffer() { $this->_buff = null; }

    /**
     * Telnet negotiation method. Run appropriate method based on type of
     * received command (DO or WILL)
     *
     * @param    $char   binary      Binary representation of command char
     */
    private function _negotiate($char) {
        switch ($char) {
            case TEL_DO:
                $this->_negotiateDo(fgetc($this->_sock));
                break;
            case TEL_WILL:
                $this->_negotiateWill(fgetc($this->_sock));
                break;
        }
    }

    /**
     * Telnet DO negotiaion
     *
     * @param    char   $cmd    Binary representation of command char
     *
     * @throws   SocketClientException   On socket communication error
     * @return   int                     Bytes written
     */
    private function _negotiateDo($cmd) {
        switch ($cmd) {
            case TEL_TTYPE: // Send terminal type
                $term = (binary) $this->_termtype;
                return $this->send(TEL_IAC.TEL_SUB.TEL_TTYPE.TEL_BIN.$term.
                            TEL_IAC.TEL_SUBEND,false);
            case TEL_XDISPLOC: // Send display location
                $hostname = (binary) php_uname('n').':0.0';
                return $this->send(TEL_IAC.TEL_SUB.TEL_XDISPLOC.TEL_BIN.$hostname.
                            TEL_IAC.TEL_SUBEND, false);
            case TEL_NEWENV: // Send new environment name
                $env = (binary) 'DISPLAY ' . php_uname('n'). ':0.0';
                return $this->send(TEL_IAC.TEL_SUB.TEL_NEWENV.TEL_BIN.$env.
                            TEL_IAC.TEL_SUBEND, false);
            case TEL_TSPEED: // Send terminal speed
                $tspeed = (binary) $this->_tspeed_in . ','. $this->_tspeed_out;
                return $this->send(TEL_IAC.TEL_SUB.TEL_TSPEED.TEL_BIN.$tspeed.
                            TEL_IAC.TEL_SUBEND, false);
            case TEL_GA:
                break;
            case TEL_ECHO: // This is workaround for some strange thing
                return $this->send(TEL_IAC.TEL_WONT.TEL_ECHO, false);
            default: // In case we didn't implement - tell that we will don't
                     // use this option
                return $this->send(TEL_IAC.TEL_DONT.$cmd, false);
        }
    }

    /**
     * Telnet WILL negotiaion
     *
     * @param    char   $cmd    Binary representation of command char
     *
     * @throws   SocketClientException   On socket communication error
     * @return   int                     Bytes written
     */
    private function _negotiateWill($cmd) {
        switch ($cmd) {
            case TEL_GA:
                break;
            case TEL_ECHO:
                break;
            default:
                return $this->send(TEL_IAC.TEL_WONT.$cmd, false);
        }
    }

    /**
     * Wait for reply from socket
     *
     * @param int $timeout  Max timeout to wait reply
     *
     * @throws SocketClientException    On socket communication error
     * @return bool     True if found reply, false if timeout reached
     */
    public function waitReply($timeout = 10) {
        $timestart = time();
        while (true) {
            $char = $this->recvChr();
            $timerun = time() - $timestart;
            if ($timerun >= $timeout) {
                return false;
            }
            if(!empty($char)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wait for specified message from socket till timeout
     *
     * @param str   $str            String to wait
     * @param int   $maxtimeout     Maximum timeout to wait
     *
     * @throws  SocketClientException    On socket communication error
     * @throws  TelnetException          If maxtimeout reached
     * @return  bool    True if found
     */
    public function waitFor($str, $maxtimeout = 10) {
        $timestart = time();
        $buff = '';
        while (true) {
            $buff .= $this->recvChr();
            $timerun = time() - $timestart;

            if (preg_match("/$str/", $buff, $matches)) {
                return true;
            }

            if ($timerun >= $maxtimeout) {
                throw new TelnetException("Could not find occurance [ $str ] within timeout");
            }
        }
        return false;
    }

    /**
     * Get only output of running command
     *
     * @param string    $cmd            Command to execute
     * @param bool      $newline        Add new line character, or not
     * @param int       $maxtimeout     Maximum timeout to wait command execution
     *
     * @throws SocketClientException    On socket communication error
     * @throws TelnetException          If maximum timeout reached
     *
     * @return string   Result of command
     */
    public function getOutputOf($cmd, $newline = true, $maxtimeout = 10) {
        $return = array();
        $this->recvAll();
        $this->send($cmd, $newline);
        $timestart = time();
        while (true) {
            $buff = $this->recvLine();
            $timerun = time() - $timestart;

            if (strpos($buff, $this->prompt) !== false) {
                break;
            }
            if (preg_match("/$this->page_delimiter/i", $buff)) {
                $this->send(" ", false);
                $timestart = time();
                continue;
            }

            if ($timerun >= $maxtimeout) {
                throw new TelnetException("Timeout reached while waiting to execute command: [ $cmd ]");
            }
            $return[] = $buff;
        }

        $newret = array_slice($return, 1, -1);
        $newret = implode("\r", $newret);
        return $newret;
    }

    /**
    * Set terminal type
    *
    * @param    string  $term   Terminal type
    */
    public function setTerm($term) { $this->_termtype = $term; }

    /**
    * Setting terminal speed
    *
    * @param    string  $in     String with inbound terminal speed
    * @param    string  $out    String with outbound terminal speed
    */
    public function setTermSpeed($in, $out) {
        $this->_tspeed_in = $in;
        $this->_tspeed_out = $out;
    }

    /**
     * Set the prompt manually
     *
     * @param string    $prompt     Prompt
     */
    public function setPrompt($prompt) { $this->prompt = $prompt; }

    /**
     * Return currently used prompt
     *
     * @return string   Prompt
     */
    public function getPrompt() { return $this->prompt; }

    /**
     * Disable telnet negotiation (will send/recive as plain text)
     *
     * @return PTel   Current class instance
     */
    public function disableNegotiation() {
        $this->_enableNegotiation = false;
        return $this;
    }

    /**
    * Closing socket
    */
    public function disconnect() {
        if ($this->_sock) {
            fclose($this->_sock);
            $this->_sock = null;
        }
    }

    /**
    * Disconnecting
    */
    public function __destruct() { $this->disconnect(); }

} // END: class PTel {}
