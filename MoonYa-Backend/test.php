<?php
echo 'Hello World!';
echo '<br>';
echo 'Current directory: ' . getcwd();
echo '<br>';
echo 'Files in current directory:';
echo '<pre>';
print_r(scandir('.'));
echo '</pre>';