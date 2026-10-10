<?php
// Database settings — fill in your MySQL credentials.
define('DB_HOST', 'localhost');
define('DB_NAME', 'business_data');
define('DB_USER', 'root');
define('DB_PASS', '');

// Optional shared secret. Leave '' to accept any request.
// When set, callers must send header "X-API-Key: <value>" or "?key=<value>".
define('API_KEY', '');

// Max records accepted in one POST.
define('MAX_RECORDS_PER_REQUEST', 500);
