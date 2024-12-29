<?php
    ini_set('display_errors', 'off');
    http_response_code(503);
    header( 'Retry-After: 600' );
?> 
