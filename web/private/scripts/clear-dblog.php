<?php

//Clear all cache
echo "Delete watchdog messages.\n";
passthru('drush watchdog:delete all -y');