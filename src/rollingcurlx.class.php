<?php
/*
        ---------- RollingCurlX 1.0.0 -----------
        an easy to use curl_multi wrapper for php

            Copyright (c) 2015 Marcus Leath
                    License: MIT
        https://github.com/marcushat/RollingCurlX
*/


Class RollingCurlX {
    private $_maxConcurrent = 0; //max. number of simultaneous connections allowed
    private $_options = []; //shared cURL options
    private $_headers = []; //shared cURL request headers
    private $_callback = NULL; //default callback
    private $_timeout = 5000; //all requests must be completed by this time
    public $requests = []; //request_queue

    function __construct($max_concurrent = 10) {
        $this->setMaxConcurrent($max_concurrent);
    }

    public function setMaxConcurrent($max_requests) {
        if($max_requests > 0) {
            $this->_maxConcurrent = $max_requests;
        }
    }

    public function setOptions(array $options) {
        $this->_options = $options;
    }

    public function setHeaders(array $headers) {
        if(is_array($headers) && count($headers)) {
            $this->_headers = $headers;
        }
    }

    public function setCallback(callable $callback) {
        $this->_callback = $callback;
    }

    public function setTimeout($timeout) { //in milliseconds
        if($timeout > 0) {
            $this->_timeout = $timeout/1000; //to seconds
        }
    }

    //Add a request to the request queue
    public function addRequest(
                        $url,
                        $post_data = NULL,
                        callable $callback = NULL, //individual callback
                        $user_data = NULL,
                        array $options = NULL, //individual cURL options
                        array $headers = NULL //individual cURL request headers
    ) { //Add to request queue
        $this->requests[] = [
            'url' => $url,
            'post_data' => ($post_data) ? $post_data : NULL,
            'callback' => ($callback) ? $callback : $this->_callback,
            'user_data' => ($user_data) ? $user_data : NULL,
            'options' => ($options) ? $options : NULL,
            'headers' => ($headers) ? $headers : NULL
        ];
        return count($this->requests) - 1; //return request number/index
    }

    //Reset request queue
    public function reset() {
        $this->requests = [];
    }

    private function normalize_headers(array $headers) {
        $normalized = [];
        foreach($headers as $key => $header) {
            if(is_string($key)) {
                $normal = "$key: $header";
            } else {
                $header;
            }
            $normalized = [];
        }
    }

    //Execute the request queue
    public function execute() {
        if(count($this->requests) < $this->_maxConcurrent) {
            $this->_maxConcurrent = count($this->requests);
        }
        //the request map that maps the request queue to request curl handles
        $requests_map = [];
        $multi_handle = curl_multi_init();

        //start processing the initial request queue
        for($i = 0; $i < $this->_maxConcurrent; $i++) {
            $this->init_request($i, $multi_handle, $requests_map);
        }

        do{
            do{
                $mh_status = curl_multi_exec($multi_handle, $active);
            } while($mh_status == CURLM_CALL_MULTI_PERFORM);
            if($mh_status != CURLM_OK) {
                break;
            }

            //a request is just completed, find out which one
            while($completed = curl_multi_info_read($multi_handle)) {
                $this->process_request($completed, $multi_handle, $requests_map);

                //add/start a new request to the request queue
                if($i < count($this->requests) && isset($this->requests[$i])) { //if requests left
                    $this->init_request($i, $multi_handle, $requests_map);
                    $i++;
                }
            }

            usleep(15); //save CPU cycles, prevent continuous checking
        } while ($active || count($requests_map)); //End do-while

        $this->reset();
        curl_multi_close($multi_handle);
    }



    //Build individual cURL options for a request
    private function buildOptions(array $request) {
        $url = $request['url'];
        $post_data = $request['post_data'];
        $individual_opts = $request['options'];
        $individual_headers = $request['headers'];

        $options = ($individual_opts) ? $individual_opts + $this->_options : $this->_options; //merge shared and individual request options
        $headers = ($individual_headers) ? $individual_headers + $this->_headers : $this->_headers; //merge shared and individual request headers

        //the below will overide the corresponding default or individual options
        $options[CURLOPT_RETURNTRANSFER] = true;

        $options[CURLOPT_NOSIGNAL] = 1;
        $options[CURLOPT_CONNECTTIMEOUT] = max(1, $this->_timeout/1000); //minimum of 1 second
        $options[CURLOPT_TIMEOUT] = $this->_timeout/1000;

        if($url) {
            $options[CURLOPT_URL] = $url;
        }

        if($headers) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        // enable POST method and set POST parameters
        if($post_data) {
            $options[CURLOPT_POST] = 1;
            $options[CURLOPT_POSTFIELDS] = is_array($post_data)? http_build_query($post_data) : $post_data;
        }
        return $options;
    }


    private function init_request($request_num, $multi_handle, &$requests_map) {
        $request =& $this->requests[$request_num];
        $this->addTimer($request);

        $ch = curl_init();
        $opts_set = curl_setopt_array($ch, $this->buildOptions($request));
        if(!$opts_set) {
            echo 'options not set';
            exit;
        }
        curl_multi_add_handle($multi_handle, $ch);

        //add curl handle of a new request to the request map
        $ch_hash = (string) $ch;
        $requests_map[$ch_hash] = $request_num;
    }


    private function process_request($completed, $multi_handle, array &$requests_map) {
        $ch = $completed['handle'];
        $ch_hash = (string) $ch;
        $request =& $this->requests[$requests_map[$ch_hash]]; //map handler to request index to get request info

        $request_info = curl_getinfo($ch);
        $request_info['curle'] = $completed['result'];
        $request_info['curle_msg'] = $this->curle_msgs[$completed['result']];
        $request_info['handle'] = $ch;
        $request_info['time'] = $time = $this->stopTimer($request); //record request time
        $request_info['url_raw'] = $url = $request['url'];
        $request_info['user_data'] = $user_data = $request['user_data'];

        if(curl_errno($ch) !== 0 || intval($request_info['http_code']) !== 200) { //if server responded with http error
            $response = false;
        } else { //sucessful response
            $response = curl_multi_getcontent($ch);
        }

        //get request info
        $callback = $request['callback'];
        $options = $request['options'];

        if($response && (isset($this->_options[CURLOPT_HEADER]) || isset($options[CURLOPT_HEADER]))) {
            $k = intval($request_info['header_size']);
            $request_info['response_header'] = substr($response, 0, $k);
            $response = substr($response, $k);
        }

        //remove completed request and its curl handle
        unset($requests_map[$ch_hash]);
        curl_multi_remove_handle($multi_handle, $ch);

        //call the callback function and pass request info and user data to it
        if($callback) {
            call_user_func($callback, $response, $url, $request_info, $user_data, $time);
        }
        $request = NULL; //free up memory now just incase response was large
    }


    private function check_for_timeouts($mh) {
        $now = microtime($true);
        $request_map = $this->_request_map;
        $requests = $this->_request_map;
        foreach($request_maps as $ch_hash => $request_num) {
            $request = $requests[$request_num];
            $timeout = $request->timeout;
            $start_time = $request->start_time;
            $ch = $request->handle;
            if($now >=  $start_time + $timeout) {
                curl_multi_remove_handle($mh, $ch);
            }
        }
    }


    private function addTimer(array &$request) { //adds timer object to request
        $request['timer'] = microtime(true);
        $request['time'] = false; //default if not overridden by time later
    }

    private function stopTimer(array &$request) {
        $elapsed = $request['time'] = microtime(true) - $request['timer'];
        unset($request['timer']);
        return $elapsed;
    }

    private $curle_msgs = [CURLE_OK => 'OK', CURLE_UNSUPPORTED_PROTOCOL => 'UNSUPPORTED_PROTOCOL', CURLE_FAILED_INIT => 'FAILED_INIT', CURLE_URL_MALFORMAT => 'URL_MALFORMAT', CURLE_URL_MALFORMAT_USER => 'URL_MALFORMAT_USER', CURLE_COULDNT_RESOLVE_PROXY => 'COULDNT_RESOLVE_PROXY', CURLE_COULDNT_RESOLVE_HOST => 'COULDNT_RESOLVE_HOST', CURLE_COULDNT_CONNECT => 'COULDNT_CONNECT', CURLE_FTP_WEIRD_SERVER_REPLY => 'FTP_WEIRD_SERVER_REPLY', CURLE_FTP_ACCESS_DENIED => 'FTP_ACCESS_DENIED', CURLE_FTP_USER_PASSWORD_INCORRECT => 'FTP_USER_PASSWORD_INCORRECT', CURLE_FTP_WEIRD_PASS_REPLY => 'FTP_WEIRD_PASS_REPLY', CURLE_FTP_WEIRD_USER_REPLY => 'FTP_WEIRD_USER_REPLY', CURLE_FTP_WEIRD_PASV_REPLY => 'FTP_WEIRD_PASV_REPLY', CURLE_FTP_WEIRD_227_FORMAT => 'FTP_WEIRD_227_FORMAT', CURLE_FTP_CANT_GET_HOST => 'FTP_CANT_GET_HOST', CURLE_FTP_CANT_RECONNECT => 'FTP_CANT_RECONNECT', CURLE_FTP_COULDNT_SET_BINARY => 'FTP_COULDNT_SET_BINARY', CURLE_PARTIAL_FILE => 'PARTIAL_FILE', CURLE_FTP_COULDNT_RETR_FILE => 'FTP_COULDNT_RETR_FILE', CURLE_FTP_WRITE_ERROR => 'FTP_WRITE_ERROR', CURLE_FTP_QUOTE_ERROR => 'FTP_QUOTE_ERROR', CURLE_HTTP_NOT_FOUND => 'HTTP_NOT_FOUND', CURLE_WRITE_ERROR => 'WRITE_ERROR', CURLE_MALFORMAT_USER => 'MALFORMAT_USER', CURLE_FTP_COULDNT_STOR_FILE => 'FTP_COULDNT_STOR_FILE', CURLE_READ_ERROR => 'READ_ERROR', CURLE_OUT_OF_MEMORY => 'OUT_OF_MEMORY', CURLE_OPERATION_TIMEOUTED => 'OPERATION_TIMEOUTED', CURLE_FTP_COULDNT_SET_ASCII => 'FTP_COULDNT_SET_ASCII', CURLE_FTP_PORT_FAILED => 'FTP_PORT_FAILED', CURLE_FTP_COULDNT_USE_REST => 'FTP_COULDNT_USE_REST', CURLE_FTP_COULDNT_GET_SIZE => 'FTP_COULDNT_GET_SIZE', CURLE_HTTP_RANGE_ERROR => 'HTTP_RANGE_ERROR', CURLE_HTTP_POST_ERROR => 'HTTP_POST_ERROR', CURLE_SSL_CONNECT_ERROR => 'SSL_CONNECT_ERROR', CURLE_FTP_BAD_DOWNLOAD_RESUME => 'FTP_BAD_DOWNLOAD_RESUME', CURLE_FILE_COULDNT_READ_FILE => 'FILE_COULDNT_READ_FILE', CURLE_LDAP_CANNOT_BIND => 'LDAP_CANNOT_BIND', CURLE_LDAP_SEARCH_FAILED => 'LDAP_SEARCH_FAILED', CURLE_LIBRARY_NOT_FOUND => 'LIBRARY_NOT_FOUND', CURLE_FUNCTION_NOT_FOUND => 'FUNCTION_NOT_FOUND', CURLE_ABORTED_BY_CALLBACK => 'ABORTED_BY_CALLBACK', CURLE_BAD_FUNCTION_ARGUMENT => 'BAD_FUNCTION_ARGUMENT', CURLE_BAD_CALLING_ORDER => 'BAD_CALLING_ORDER', CURLE_HTTP_PORT_FAILED => 'HTTP_PORT_FAILED', CURLE_BAD_PASSWORD_ENTERED => 'BAD_PASSWORD_ENTERED', CURLE_TOO_MANY_REDIRECTS => 'TOO_MANY_REDIRECTS', CURLE_UNKNOWN_TELNET_OPTION => 'UNKNOWN_TELNET_OPTION', CURLE_TELNET_OPTION_SYNTAX => 'TELNET_OPTION_SYNTAX', CURLE_OBSOLETE => 'OBSOLETE', CURLE_SSL_PEER_CERTIFICATE => 'SSL_PEER_CERTIFICATE', CURLE_GOT_NOTHING => 'GOT_NOTHING', CURLE_SSL_ENGINE_NOTFOUND => 'SSL_ENGINE_NOTFOUND', CURLE_SSL_ENGINE_SETFAILED => 'SSL_ENGINE_SETFAILED', CURLE_SEND_ERROR => 'SEND_ERROR', CURLE_RECV_ERROR => 'RECV_ERROR', CURLE_SHARE_IN_USE => 'SHARE_IN_USE', CURLE_SSL_CERTPROBLEM => 'SSL_CERTPROBLEM', CURLE_SSL_CIPHER => 'SSL_CIPHER', CURLE_SSL_CACERT => 'SSL_CACERT', CURLE_BAD_CONTENT_ENCODING => 'BAD_CONTENT_ENCODING', CURLE_LDAP_INVALID_URL => 'LDAP_INVALID_URL', CURLE_FILESIZE_EXCEEDED => 'FILESIZE_EXCEEDED', CURLE_FTP_SSL_FAILED => 'FTP_SSL_FAILED', CURLE_SSH => 'SSH'
    ];
}
?>