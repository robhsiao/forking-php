#!/usr/local/bin/php
<?php

/*

Copyright (c) 2009, dealnews.com, Inc.
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

 * Redistributions of source code must retain the above copyright notice,
   this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.
 * Neither the name of dealnews.com, Inc. nor the names of its contributors
   may be used to endorse or promote products derived from this software
   without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
POSSIBILITY OF SUCH DAMAGE.

 */

/**
 * Start option checking
 */
$opts = getopt("c:f:hoqz:");

if(isset($opts["h"])){
    prefork_usage();
}

if(empty($opts["f"]) || empty($opts["c"])){
    prefork_usage("You must provide a file and a class to be used.");
}

$file = $opts["f"];

if(!file_exists($file) || !is_readable($file)){
    prefork_usage("$file does not exist or is not readable.");
} else {
    include_once($file);
}

$fork_class = $opts["c"];
if(!class_exists($fork_class)){
    prefork_usage("class $fork_class does not exist in $file.");
}

if(isset($opts["z"])){
    $snooze = (int)$opts["z"];
    if($snooze<1){
        prefork_usage("-z must be numeric and greater than 0.");
    }
}

$onepass = isset($opts["o"]);
$verbose = !isset($opts["q"]);

/**
 * End option checking
 */



/**
 * MAIN PROCESS LOOP
 */

// start up the children
global $PREFORK;
prefork_startup();

// setup signal handlers
declare(ticks = 1);
pcntl_signal(SIGTERM, "prefork_sig_handler");
pcntl_signal(SIGHUP,  "prefork_sig_handler");

// loop and monitor children
while(count($pid_arr)){
    foreach($pid_arr as $key => $pid){
        $exited = pcntl_waitpid( $pid, $status, WNOHANG );
        if($exited) {
            unset($pid_arr[$key]);
            prefork_log("Child $pid exited");
            if(method_exists($PREFORK, "after_death")){
                $PREFORK->after_death($pid);
            }
        }
    }

    if(!$onepass && count($pid_arr)<$PREFORK->children){
        prefork_start_child();
    }

    // php will eat up your cpu
    // if you don't have this
    usleep(500000);
}


prefork_log("Shutting down");
exit();






/*************** FUNCTIONS ***************/


/**
 * Start children
 */

function prefork_startup() {

    global $PREFORK, $fork_class;

    prefork_log("Creating $fork_class object");
    $PREFORK = new $fork_class();

    for($x=1;$x<=$PREFORK->children;$x++){

        prefork_start_child();

        // PHP will lock if you
        // start too many to fast
        usleep(500);

    }

    prefork_log("$PREFORK->children children started");

}

/**
 * Starts a child process and records its pid
 */

function prefork_start_child() {

    global $pid_arr, $snooze, $PREFORK;

    $cnt = count($pid_arr)+1;

    prefork_log("Starting child $cnt");

    if(method_exists($PREFORK, "before_fork")){
        $PREFORK->before_fork();
    }

    $pid = pcntl_fork();
    if ($pid == -1){
        prefork_log("could not fork");
        prefork_stop_children();
    } else {

        if ($pid) {
            // parent
            $pid_arr[] = $pid;

            if(method_exists($PREFORK, "after_fork")){
                $PREFORK->after_fork($pid);
            }

        } else {

            //child
            if(!empty($snooze)){
                sleep($snooze);
            }
            $PREFORK->is_parent = false;
            $PREFORK->fork();

            exit();
        }
    }

}

/**
 * Shutsdown children
 */

function prefork_stop_children() {
    global $pid_arr;

    prefork_log("Stopping children");

    foreach($pid_arr as $key => $pid){
        prefork_log("killing $pid");
        posix_kill($pid, SIGKILL);
    }
}

/**
 * Signal function
 */

// signal handler function
function prefork_sig_handler($signo) {

    global $PREFORK;

    switch ($signo) {
        case SIGTERM:
            prefork_log("SIGTERM caught");
            $PREFORK->children = 0;
            prefork_stop_children();
            break;
        case SIGHUP:
            prefork_log("SIGHUP caught");
            prefork_stop_children();
            prefork_startup();
            break;
        default:
        // handle all other signals
    }

}

/**
 * Logging function
 */
function prefork_log($log) {

    global $verbose;

    if($verbose) {
        echo "$log\n";
        flush();
    }
}

/**
 * Usage function
 */

function prefork_usage($error=""){
    if($error){
        echo "Error: $error\n";
    }
    echo "usage: ".__FILE__." -f FILE -c FORK-CLASS [-z SNOOZE] [-qo]\n";
    echo "usage: ".__FILE__." -h\n";
    echo "Options:\n";
    echo "  -c  The name of the class to be used.\n";
    echo "  -f  The PHP file to be used for forking.\n";
    echo "  -h  Show this help.\n";
    echo "  -o  If set, new children will not be started as children die off.\n      This is useful for one time processing uses.\n";
    echo "  -q  Be quiet.\n";
    echo "  -z  Seconds for each child to sleep before running its function.\n      (Forking can be taxing and this can help performance)\n";
    echo "\n";
    exit();
}

?>
