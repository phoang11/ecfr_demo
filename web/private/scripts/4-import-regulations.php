<?php

echo "Importing ecfr_regulations data...\n";
passthru('drush ecfr:import --limit=15');
