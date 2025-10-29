<?php

// ecfr_regulations module.
echo "Purging ecfr_regulations data...\n";
passthru('drush ecfr:purge -y');

echo "Uninstalling ecfr_regulations module...\n";
passthru('drush pmu ecfr_regulations -y');

echo "Enabling ecfr_regulations module...\n";
passthru('drush en ecfr_regulations -y');
