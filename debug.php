<?php



$f = './lock';

touch($f, time()+10); 

echo "Current Time: \n" . time();

echo "\n\nFSTAT Time: \n";
var_dump(filemtime($f));
return;
sleep(1);
clearstatcache();
echo PHP_EOL;

var_dump(filemtime($f));


sleep(1);
clearstatcache();
echo PHP_EOL;


touch($f); 
var_dump(filemtime($f));