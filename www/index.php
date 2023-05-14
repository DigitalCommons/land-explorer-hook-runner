<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>LX Hook Runner</title>
    <style>
      .trunc {
        display: inline-block;
        width: 20em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
    </style>
  </head>
  <body>
    <h1>LX Hook Runner</h1>
    <h2>Targets</h2>
    <p>Click to run immediately (i.e. not on the job queue). This will
    show the output, and attempt to suppress the constraints, but may
    not work if the constraints include some marked un-forcable.</p>
    <ul>
      <?php
        $dir = dirname(__FILE__);
        $config_dir = "$dir/config";
        $entries = scandir($config_dir);

        foreach($entries as $entry) {
          if($entry == '.' || $entry == '..')
            continue;
          if (!is_file("$dir/config/$entry"))
            continue;
          if (!preg_match('/^(\w[\w+.-]+)[.]json$/', $entry, $matches))
            continue;
          $target = $matches[1];
      ?>
        <li>
          <a href="run.php?echo&force&target=<?= urlencode($target) ?>">
            <?= htmlentities($target) ?>
          </a>
        </li>
      <?php
        }
      ?>
    </ul>
    
    <h2>Jobs</h2>
    <p>This is essentially the raw output of the task-spooler command</p>
    <?php

      $tspout = shell_exec("tsp");
      $html = preg_replace(
        '/^(ID)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.*)$/m',
        '<tr><th>$1</th><th>$2</th><th>$3</th><th>$4</th><th>$5</th><th>$6</th></tr>',
        $tspout
      );
      $html = preg_replace(
        '/^(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.*)$/m',
        '<tr><td>$1</td><td><a href="tsp.php?id=$1">$2</a></td><td><a href="tsp.php?id=$1&output">$3</a></td><td>$4</td><td>$5</td><td><tt class="trunc" title="$6">$6</tt></td></tr>',
        $html
      );
    ?>
    <table>
      <?= $html ?>
    </table>
  </body>
</html>
