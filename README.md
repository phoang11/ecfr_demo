# eCFR Analytics Demo

This project contains a custom Drupal module, `ecfr_regulations`, that ingests
and analyzes Electronic Code of Federal Regulations (eCFR) content for agency
oversight and reporting.

## eCFR Regulations Module

### Key Functionalities
- Imports agency metadata from the public [eCFR REST API](https://www.ecfr.gov/developers/documentation/api/v1) and persists it as
	custom `ecfr_agency` entities, retaining parent/child relationships and title
	coverage.
- Extracts regulation text from downloaded title XML snapshots, stores it in
	`ecfr_regulation` entities, and performs word-counting plus lexical
	diversity analysis for each chapter or subtitle.
- Exposes JSON endpoints:
	- `/api/ecfr/agencies`: Lists all agencies and their information.
	- `/api/ecfr/agencies/{slug}`: Provides agency-specific information. 
		For example: for Department of Defense `/api/ecfr/agencies/defense-department`
	- `/api/ecfr/titles`: Lists title information.
- Provides analytics dashboards at `/ecfr/agencies`, per-agency drilldowns, and
	regulation text views (including top word frequencies) for authenticated
	users with the `access ecfr regulations analytics` permission.
- Offers a configuration form at `/admin/config/services/ecfr-regulations` to
	set API credentials, adjust cache lifetime, trigger bulk title downloads, and
	launch regulation import batches.
- Supplies Drush tooling to warm caches, download title XML files, batch import
	regulations, extract chapters on demand, purge stored data, and clear module
	caches.

## Installation

1. **Install DDEV**  
	Ensure [DDEV](https://ddev.com/get-started/) is installed on your system.

2. **Start the Development Environment**  
	Run the following command in the project directory `ecfr`:
	```bash
	ddev start
	```

3. **Install Project Dependencies**  
	```bash
	ddev install
	```

> **Note:** You might have issues installing DDEV and running `ddev install` with local network firewall/proxy for ecfr.gov API requests and retrieving the information.