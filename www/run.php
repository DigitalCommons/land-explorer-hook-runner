<?php
/*
 * Job runner - runs a command selected by a target parameter
 * and optionally display the output in real time (stdout + stderr).
 *
 * Adapted from: https://stackoverflow.com/a/9468428
 */
define('BUF_SIZ', 1024);        # max buffer size
define('FD_WRITE', 0);        # stdin
define('FD_READ', 1);        # stdout
define('FD_ERR', 2);        # stderr
header('Content-type: text/plain');
header('Content-Encoding: none');
ob_end_flush(); // Send output as it is printed, with no buffering
ob_implicit_flush(true); // Belt...
flush(); // and...
echo str_repeat(' ', 1024); // Braces

function timestamp() {
  return date('c');
}

$start_ts = date('c', $_SERVER["REQUEST_TIME"]); // This should match apache logs more closely
$log_dir = getenv('LOG_DIR');
if (isset($log_dir))
  $log_file = "$log_dir/$start_ts.run.log";

function locallog($msg) {
  global $log_file;
  if (isset($log_file)) {
    $ts = timestamp();
    file_put_contents($log_file, json_encode([$ts,$msg])."\n", FILE_APPEND);
  }
}

$echo = false;
function echo_out($msg) {
  global $echo;
  if ($echo)
    echo($msg);
  else
    echo('.');
}

$version = trim(strtok(file_get_contents(dirname(__FILE__).'/VERSION.TXT'), PHP_EOL)) ?: '?';
locallog(['when' => 'begin', 'timestamp' => $start_ts, 'version' => $version,
          'request' => $_REQUEST, 'server' => $_SERVER]);

/*
 * Wrapper for proc_*() functions.
 * The first parameter $cmd is the command line to execute.
 * The second is the environment to set.
 * Return the exit code of the process.
 */
function proc_exec($cmd, $env, $cwd)
{
  if (!isset($cmd) || count($cmd) == 0 || empty($cmd[0])) {
    throw new Exception("not enough parameters: ".json_encode($cmd));
  }

  $descriptorspec = array(
    0 => array("pipe", "r"),
    1 => array("pipe", "w"),
    2 => array("pipe", "w")
  );

  $ptr = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env);
  if (!is_resource($ptr)) {
    throw new Exception("Error: not enough FD or out of memory.\n");
  }

  while (($buffer = fgets($pipes[FD_READ], BUF_SIZ)) != NULL
         || ($errbuf = fgets($pipes[FD_ERR], BUF_SIZ)) != NULL) {
    if (!isset($flag)) {
      $pstatus = proc_get_status($ptr);
      $first_exitcode = $pstatus["exitcode"];
      $flag = true;
    }
    if (strlen($buffer))
      echo_out($buffer);
    if (isset($errbuf) && strlen($errbuf))
      echo_out("ERR: " . $errbuf);
  }

  foreach ($pipes as $pipe)
    fclose($pipe);

  /* Get the expected *exit* code to return the value */
  $pstatus = proc_get_status($ptr);
  if (!strlen($pstatus["exitcode"]) || $pstatus["running"]) {
    /* we can trust the retval of proc_close() */
    if ($pstatus["running"])
      proc_terminate($ptr);
    $ret = proc_close($ptr);
  } else {
    if (!isset($first_exitcode))
      $ret = $pstatus["exitcode"];
    elseif ((($first_exitcode + 256) % 256) == 255
            && (($pstatus["exitcode"] + 256) % 256) != 255)
      $ret = $pstatus["exitcode"];
    elseif ((($first_exitcode + 256) % 256) != 255)
      $ret = $first_exitcode;
    else
      $ret = 0; /* we "deduce" an EXIT_SUCCESS ;) */
    proc_close($ptr);
  }

  $ret = ($ret + 256) % 256;

  if ($ret == 127)
    throw new Exception("Command not found: $cmd[0]\n");

  return $ret;
}


// Given an array and a path of the form 'some.parameter.names' split
// the path in to components, and recursively descend the array using
// the components as keys, and returning the final value retrieved,
// or $default if a dead end is reached.
function array_dig($ary, $path, $default = NULL) {
  if (gettype($ary) !== 'array')
    return $default;
  if (gettype($path) !== 'string')
    return $default;
  $components = explode(".", $path);
  foreach($components as $component) {
    if (!isset($ary[$component]))
      return $default;
    $ary = $ary[$component];
  }

  return $ary;
}

// Trims any leading "!" and returns true if it did
function strip_excl(&$string) {
  if (strpos($string, "!") !== 0) return false;
  
  $string = substr($string, 1);
  return true;
}

// Checks whether the constraints configured in $constraints pass
//
// Currently supports:
//
// type `request` which is an assoc array mapping $_REQUEST parameters
// to required values
//
// type `github` which is an assoc array mapping github payload IDs to
// required values. Dotted ID components indicate nested parameters.
function check_constraints($constraints, $is_forced) {
  $success = true;
  $checks = [];
  
  foreach($constraints as $type => $config) {
    switch($type) {
    case 'request':
      if (gettype($config) !== 'array') {
        $success = false;
        $checks[$type] = ['failed' => 'malformed config', 'config' => $config];
        break;
      }

      $request_checks = [];
      foreach($config as $param => $expected) {
        // Skip if parameter can be forced, and force is requested
        if (!strip_excl($param) && $is_forced) {
          $request_checks[$param] = true;
          continue;
        }
        
        if (isset($_REQUEST[$param]) && $_REQUEST[$param] === $expected) {
          $request_checks[$param] = true;
          continue;
        }

        $success = false;
        $request_checks[$param] = false;
      }
      $checks[$type] = $request_checks;
      break;

    case 'github':        
      if (gettype($config) !== 'array') {
        $success = false;
        $checks[$type] = ['failed' => 'malformed config', 'config' => $config];
        break;
      }
      $payload = json_decode(file_get_contents('php://input'), true);
      if (gettype($payload) !== 'array') {
        if ($is_forced) {
          $payload = []; // If we are forced and the payload is invalid, make it usable.
        }
        else { // Otherwise, constraint fails.
          $success = false;
          $checks[$type] = ['failed' => 'malformed payload', 'payload' => $payload];
          break;
        }
      }
      
      $github_checks = [];
      foreach($config as $param => $expected) {
        // Skip if parameter can be forced, and force is requested
        if (!strip_excl($param) && $is_forced) {
          $request_checks[$param] = true;
          continue;
        }
        echo(">> $param = ".json_encode($payload)."\n");
        if (array_dig($payload, $param) === $expected) {
          $github_checks[$param] = true;
          continue;
        }

        $success = false;
        $github_checks[$param] = false;
      }
      $checks[$type] = $github_checks;
      break;
      
      break;
      
    default:
      $success = false;
      $checks[$type] = ['failed' => 'unrecognised check type', 'type' => $type];
      break;
    }
  }

  return ['passed' => $success, 'checks' => $checks];
}

function mk_interpolator($getenv = 'getenv') {

  $interpolator = function($val) use ($getenv) {
    $cb = function($matches) use ($getenv) {
      $name = $matches[2];
      
      // Validate the name
      if ($matches === '')
        $name = $matches[3];
      if ($name === '' || preg_match('/[^A-Z_]/i', $name))
        throw new Exception("invalid environment variable name: '$name'");
      
      // Get and check the value
      $val = $getenv($name);
      if ($val != false)
        return $val;
      throw new Exception("attempt to expand an undefined environment variable \${$name}");
    };
    
    $interpolated = preg_replace_callback('/\$(([A-Z_]+)|\{(.*)\})/i', $cb, $val);
    return $interpolated;
  };

  return $interpolator;
}


// Expands and validates the assoc array $env_config for use as environment variables
//
// String, boolean, integer, double, and null values are allowed, and
// get converted to strings. (NULL is converted to the empty string),
//
// Anything else is an error which throws and exception.
//
// Any variable interpolations of the form "$var" or "${var}" are
// expanded using the current environment.  So "$PATH:something" would
// append ":something" to the current environment variable
// PATH. Variable names must consist of alphanumeric or underscore
// characters only. Anything else is an error.
//
// Variables are not interpolated from other values in the $env_config
// array, as this can cause circular references we don't want to
// be obliged to detect and resolve.
//
// Returns the expanded values as another assoc array, on success.
function load_env($env_config, $interpolator) {
  $env_raw = [];
  // validate $env_config
  foreach($env_config as $name => $val) {
    $type = gettype($val);
    switch($type) {
    case "string":
      break;
    case "boolean":
    case "integer":
    case "double":
      $val = "$val";
      break;
    case "NULL":
      $val = "";
      break;
    default:
      throw new Exception("invalid environment variable definition, value cannot be type $type");
    }
    $env_raw[$name] = $val;
  }
  
  // interpolate values from existing variables
  $env_interpolated = [];
  foreach($env_raw as $name => $val) {
    $interpolated = $interpolator($val);
    if (!isset($interpolated))
      throw new Exception("error interpolating $val");
    $env_interpolated[$name] = $interpolated;
  }
  
  return $env_interpolated;
}

// A prefix to add to commands
$cmd_prefix = [];

// Turn on output if `echo` paramter present
if (isset($_GET['echo'])) {
  $echo = true;
}
else {
  // Otherwise, run using task spooler.
  // This task-spooler prefix, causes jobs to on a queue
  // Assumes task-spooler is installed!
  $cmd_prefix = ['tsp'];
}

// Get 'target' parameter and split on commas into multiple targets
// Guard against garbage in these target parameters
$targets = [];
$config_files = [];
$invalid_targets = [];
if (isset($_GET['target'])) {
  $targets = explode(',', $_GET['target']);
  foreach($targets as $target) {  
    $config_file = getcwd()."/config/$target.json";
    if (preg_match('/^\w[\w+.-]+$/', $target) && file_exists($config_file)) {
      // Not garbage.
      $config_files[$target] = $config_file;
    }
    else {
      $invalid_targets[] = $target;
    }
  }
}

if (count($invalid_targets) === 0) {
  echo(":runner start: version=$version, start=$start_ts\n");
  echo(":runner logfile: ". basename($log_file) ."\n");
  echo(":runner remote IP: $_SERVER[REMOTE_ADDR]\n");
  echo(":runner targets: ". join(", ", $targets) ."\n");
  locallog(['when' => 'targets validated', 'targets' => $targets]);

  $status = [];
  $print_status = function($target) {
    global $status;
    $summary = isset($status[$target]) ? $status[$target]['result'] : '?';
    return "$target => $summary";
  };

  foreach($targets as $target) {
    $config_file = $config_files[$target];
    try {
      echo(":target $target: starting:\n");

      $raw_config = file_get_contents($config_file);    
      $config = json_decode($raw_config, true);
    
      if (!$config) {
        die("Failed to decode the config: $config_file\n");
      }
      if (!$cmd = $config['cmd']) {
        die("The config does not define 'cmd': $config_file\n");
      }
      if (!is_array($cmd)) {
        die("The config does not define 'cmd as an array': $config_file\n");
      }

      // If there are constraints, check if they pass
      $constraints = [];
      $constraints_passed = false;
      if (isset($config['constraints'])) {
        $constraints = $config['constraints'];
        if (!is_array($constraints))
          die("The config constrains is not an array: $config_file");
        $is_forced = isset($_GET['force']);
        $checks = check_constraints($constraints, $is_forced); // Also logs the constraints
        $success = $checks['passed'];
        locallog(['when' => 'constraints', 'passed' => $success, 'forced' => $is_forced,
                  'checks' => $checks, 'request' => $_REQUEST, 'target' => $target]);

        $constraints_passed  = $checks['passed'];
      }
      else {
        echo(":target $target: no constraints\n");
        $constraints_passed = true;
      }

      if (!$constraints_passed) {
        echo(":target $target: runner not executing, constraints not satisfied\n");
        $status[$target] = ['result' => 'rejected'];
      }
      else {
        // Go ahead

        // Add cmd prefix
        $cmd = array_merge($cmd_prefix, $cmd);

        // Export the selected global environment vars if
        // RUNNER_EXPORT_VARS is defined
        $env = [];
        $import_vars = getenv("RUNNER_EXPORT_VARS");
        if (isset($import_vars)) {
          foreach(explode(' ', $import_vars) as $import_var) {
            if ($import_var !== '') {
              $env[$import_var] = getenv($import_var);
            }
          }
        }
        
        // Add some environment variables of our own
        $config_env = $config['env'];
        if (gettype($config_env) === 'array') {
          $env = array_merge($env, $config_env);
        }
        locallog(['when' => 'start', 'cmd' => $cmd, 'env' => $env, 'target' => $target]);

        $dir = $env['CWD'] ?? '';
        if (!isset($dir) || $dir == '')
          $dir = getcwd(); // dir is $PWD env var, or if unset, the current working dir

        putenv("RUNNER_DIR=$dir");
        putenv("RUNNER_TARGET=$target");
      
        // Make a variable interpolator - uses getenv by default
        $interpolator = mk_interpolator();
        
        // Get the environment to set, if any
        $env = load_env($env, $interpolator);
        
        // expand variables in $cmd
        $cmd = array_map($interpolator, $cmd);
        
        locallog(['when' => 'executing', 'cmd' => $cmd,
                  'env' => $env, 'constraints' => $constraints,
                  'target' => $target]);
        echo(":target $target:command: ". join(" ", $cmd)."\n");
        echo(":target $target:pwd: $dir\n\n");
        
        $rc = proc_exec($cmd, $env, $dir);
      
        locallog(['when' => 'exit', 'rc' => $rc, 'target' => $target]);
        echo("\n:target $target:stopping: returned $rc\n");
        $status[$target] = ['result' => $rc];
      }
    }
    catch(Exception $e) {
      echo("\n:target $target:stopping: exception, see logs\n");
      locallog(['when' => 'exception', 'exception' => "$e", 'target' => $target]);
      $status[$target] = ['result' => 'exception'];
    }
  }

  $summary = join("; ", array_map($print_status, $targets));
  echo(":finished: $summary"); 
  locallog(['when' => 'finished', 'status' => $status]);
}
else {
  // Garbage.
  http_response_code(400);
  $bad_targets = join(", ", $invalid_targets);
  locallog(['when' => 'invalid target', 'code' => 400,
            'message' => "bad or invalid query parameter targets: $bad_targets"]);
  echo ":targets not found: $bad_targets";
}
?>
