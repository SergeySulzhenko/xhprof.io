<?php

if (xhprof_init()) {
    // The following registers an anonymous shutdown function that then registers another (end of stack)
    // shutdown function that calls our actual function. Ensuring we run absolutely last.
    register_shutdown_function(function () {
        register_shutdown_function("xhprof_shutdown");
    });
}


function xhprof_init()
{
    global $xhprofMainConfig;

    if (!extension_loaded('xhprof')) {
        return false;
    }

    // do not profile debugging sessions (ZendDebugger)
    if (!empty($_COOKIE['start_debug'])) {
        return false;
    }

    // check for an app specific config which may override the global config:
    $appConfig = array();

    // Walk up the fielpath up to the DOCUMENT_ROOT to find the optional app config file
    // First file found on the path will be used
    $fileServed = $_SERVER['SCRIPT_FILENAME'];
    $dir = dirname($fileServed);
    do {
        $cfgFile = $dir .DIRECTORY_SEPARATOR. 'xhprof.inc.php';

        if (file_exists($cfgFile)) {
            $appConfig = require $cfgFile;
            break;
        }

        $dir = dirname($dir);
    } while (!empty($dir) && stripos($dir, $_SERVER["DOCUMENT_ROOT"]) !== false);

    // disabled by app-config?
    if (isset($appConfig['enabled']) && !$appConfig['enabled']) {
        return false;
    }

    $xhprofMainConfig = require __DIR__ . '/../xhprof/includes/config.inc.php';

    // check the global enable switch, but only when no app-config enable switch was defined
    if (!isset($appConfig['enabled']) && isset($xhprofMainConfig['profiler_enabled']) && !$xhprofMainConfig['profiler_enabled']) {
        return false;
    }

    xhprof_enable(XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_CPU);

    return true;
}

function xhprof_shutdown()
{
    global $xhprofMainConfig;

    $xhprof_data	= xhprof_disable();

    if(function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    //Check to see if xhprof data exceed minimum set profile times
    if (!xhprof_check_min_run_values($xhprof_data, $xhprofMainConfig)) {
            return;
    }

    try {
        require_once __DIR__ . '/../xhprof/classes/data.php';

        $xhprof_data_obj	= new \ay\xhprof\Data($xhprofMainConfig['pdo']);
        $xhprof_data_obj->save($xhprof_data);
    } catch (Exception $e) {
        // old php versions don't like Exceptions in shutdown functions
        // -> log them to have some usefull info in the php-log
        if (PHP_VERSION_ID < 504000) {
            if (function_exists('log_exception')) {
                log_exception($e);
            } else {
                error_log($e->__toString());
            }
        }
        // re-throw to show the caller something went wrong
        throw $e;
    }
}

// Check profile run times and opt not to store the data if they are below
// set thresholds.  This should reduce amount of stored data for production
// instances.
function xhprof_check_min_run_values($profile_data, $xhprofMainConfig) {
    $store_flag = true;

    $lastElement = end($profile_data);

    $wallTimeExists = isset($lastElement['wt']) && isset($xhprofMainConfig['min_wall_time']);

    if ($wallTimeExists && $lastElement['wt'] < $xhprofMainConfig['min_wall_time']) {
            $store_flag = false;
    }

    $cpuTimeExists = isset($lastElement['cpu']) && isset($xhprofMainConfig['min_cpu_time']);
    if ($store_flag && $cpuTimeExists && $lastElement['cpu'] < $xhprofMainConfig['min_cpu_time']) {
            $store_flag = false;
    }

    $memUsageExists = isset($lastElement['mu']) && isset($xhprofMainConfig['min_mem_usage']);
    if ($store_flag && $memUsageExists && $lastElement['mu'] < $xhprofMainConfig['min_mem_usage']) {
            $store_flag = false;
    }

    $peakMemUsageExists = isset($lastElement['pmu']) && isset($xhprofMainConfig['min_peak_mem_usage']);
    if ($store_flag && $peakMemUsageExists && $lastElement['pmu'] < $xhprofMainConfig['min_peak_mem_usage']) {
            $store_flag = false;
    }

    return $store_flag;
}