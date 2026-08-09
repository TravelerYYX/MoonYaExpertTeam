<?php
// Test: does PHP output the \n after ?>
echo "LINE1\n";
echo "LINE2\n";
?>
LINE3
<?php echo "INLINE\n"; ?>
LINE4
<?php echo "AFTER\n"; ?>
LINE5
