<?php
if (!defined('GREENAPI_INSTANCE')) {
    define('GREENAPI_INSTANCE', getenv('GREENAPI_INSTANCE') ?: '');
}

if (!defined('GREENAPI_TOKEN')) {
    define('GREENAPI_TOKEN', getenv('GREENAPI_TOKEN') ?: '');
}
