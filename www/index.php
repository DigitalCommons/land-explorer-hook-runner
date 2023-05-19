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
    <p>A list of the 10 most recent jobs, newest at the top.</p>
    <?php
      $html = "<tr><th>Start time</th><th>Target</th><th>RC</th><th>Output</th><th>Commit (if triggered by GitHub)</th></tr>\n";
      $f = fopen("jobs.csv", "r");
      while (($csvArray[] = fgetcsv($f)) !== false);
      fclose($f);
      // The last element will be empty so slice 11 elements
      foreach (array_reverse(array_slice($csvArray, -11)) as $row) {
        if (!empty($row)) {
          $output_link = ($row[3] == 'NA') ? 'Not available' : '<a href="'.$row[3].'">View</a>';
          $git_commit_short = substr(basename($row[4]), 0, 7);
          $github_link = ($row[4] == 'NA') ? '' : '<a href="'.$row[4].'" target="_blank">'.$git_commit_short.'</a>';
          $html .= "<tr><td>".$row[0]."</td><td>".$row[1]."</td><td>".$row[2]."</td><td>".$output_link."</td><td>".$github_link."</td></tr>\n";
        }
      }
    ?>
    <table border="1" cellpadding="10">
      <?= $html ?>
    </table>
  </body>
</html>
