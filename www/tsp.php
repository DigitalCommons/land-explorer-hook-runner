<?php
header('Content-type: text/plain');
if (isset($_GET['output'])) {
  echo(shell_exec("tsp -c $id")); 
}
else {
  echo(shell_exec("tsp -i $id"));
}
?>
